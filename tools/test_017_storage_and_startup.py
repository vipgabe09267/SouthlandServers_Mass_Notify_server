#!/usr/bin/python3
"""Private interruption, persistent-lock and startup configuration regressions."""
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import subprocess
import sys
import tempfile
import types
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'
sys.path.insert(0, str(RUNTIME))
import sls_atomic_json as atomic
import sls_mail_admission as mail
import sls_phone_admission as phone


def candidate_updater(root, account):
    helper = Path(root) / 'candidate-sls-update-status.py'
    helper.write_bytes((RUNTIME / 'sls_update_status.py').read_bytes())
    os.chown(helper, account.pw_uid, account.pw_gid)
    helper.chmod(0o500)
    return helper


class StorageAndStartup(unittest.TestCase):
    def test_failed_atomic_status_commit_preserves_previous_bytes_and_lock(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'status.json'
            with atomic.locked_json(path) as data:
                data['retained'] = 'previous worker'
            before = path.read_bytes()
            inode = path.with_name('status.json.lock').stat().st_ino
            with mock.patch.object(atomic.os, 'replace', side_effect=OSError('injected failure')):
                with self.assertRaises(OSError), atomic.locked_json(path) as data:
                    data['retained'] = 'replacement'
            self.assertEqual(path.read_bytes(), before)
            self.assertEqual(path.with_name('status.json.lock').stat().st_ino, inode)
            with atomic.locked_json(path) as data:
                data['second'] = 'another worker'
            self.assertEqual(json.loads(path.read_text()), {'retained':'previous worker','second':'another worker'})

    def test_atomic_status_special_files_and_duplicate_keys_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'status.json'
            for kind in ('fifo','symlink','hardlink','oversize','duplicate'):
                if kind == 'fifo': os.mkfifo(path)
                elif kind == 'symlink': path.symlink_to(Path(directory)/'elsewhere')
                elif kind == 'hardlink':
                    other=Path(directory)/'other';other.write_text('{}');os.link(other,path)
                elif kind == 'oversize': path.write_bytes(b'x'*1025)
                else: path.write_text('{"kept":1,"kept":0}')
                if kind != 'symlink': path.chmod(0o640)
                with self.assertRaises((OSError,RuntimeError,ValueError)), atomic.locked_json(path,maximum=1024): pass
                path.unlink()

    def test_atomic_transaction_rejects_in_place_change_during_read(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'status.json'
            with atomic.locked_json(path) as data:
                data['retained'] = 'original'
            target_inode = path.stat().st_ino
            original_fstat = os.fstat
            observations = 0

            def changed_after_read(descriptor):
                nonlocal observations
                info = original_fstat(descriptor)
                if info.st_ino != target_inode:
                    return info
                observations += 1
                if observations != 2:
                    return info
                fields = {name: getattr(info, name) for name in (
                    'st_mode', 'st_ino', 'st_dev', 'st_nlink', 'st_uid', 'st_gid', 'st_size',
                    'st_atime', 'st_mtime', 'st_ctime', 'st_atime_ns', 'st_mtime_ns', 'st_ctime_ns')}
                fields['st_mtime_ns'] += 1
                return types.SimpleNamespace(**fields)

            with mock.patch.object(atomic.os, 'fstat', side_effect=changed_after_read):
                with self.assertRaisesRegex(RuntimeError, 'json_storage_changed'):
                    with atomic.locked_json(path):
                        pass

    def test_collector_heartbeats_preserve_retained_ledger_and_reject_stale_incarnations(self):
        with tempfile.TemporaryDirectory() as directory:
            tick=[1000.0]
            store=phone.PhoneAdmissionStore(Path(directory),clock=lambda:tick[0],boot_id='fixture-boot')
            store.collector_heartbeat('a'*32)
            path=Path(directory)/'phone-admission.json';before=path.read_bytes();inode=path.stat().st_ino
            for _ in range(10):
                tick[0]+=5;store.collector_heartbeat('a'*32)
            self.assertEqual(path.read_bytes(),before);self.assertEqual(path.stat().st_ino,inode)
            state=phone.read_private_json('phone-admission.json',Path(directory))
            self.assertEqual(store.require_collector(state)['heartbeat_tick'],tick[0])
            tick[0]+=16
            with self.assertRaises(phone.PhoneAdmissionError): store.require_collector(state)
            store.collector_heartbeat('b'*32)
            with self.assertRaises(phone.PhoneAdmissionError): store.require_collector(state)
            new=phone.read_private_json('phone-admission.json',Path(directory))
            self.assertEqual(store.require_collector(new)['generation'],'b'*32)

    def test_empty_mail_initialization_resumes_without_refunding_submission_history(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);account=pwd.getpwnam('asterisk');os.chown(root,account.pw_uid,account.pw_gid)
            marker=root/mail.LOCK;marker.write_bytes(b'');os.chown(marker,account.pw_uid,account.pw_gid);marker.chmod(0o640)
            initial={'schema':1,'last_at':1000,'windows':{str(period):[] for period in mail.LIMITS},'probe':None}
            intent=root/'.mail-admission-initializing';intent.write_text(json.dumps({'schema':1,'initial':initial}));intent.chmod(0o640);os.chown(intent,account.pw_uid,account.pw_gid)
            for present in (False,True):
                if present:
                    (root/mail.STATE).write_text(json.dumps(initial));(root/mail.STATE).chmod(0o640);os.chown(root/mail.STATE,account.pw_uid,account.pw_gid)
                mail.reserve(directory=root,clock=lambda:1000,queue_inspector=lambda:0)
                self.assertFalse(intent.exists());self.assertEqual(marker.read_bytes(),mail.MARKER)
                state=json.loads((root/mail.STATE).read_text())
                self.assertEqual(state['windows']['60'][0]['counts']['total'],[1,1])
                if not present:
                    # A new, separately isolated unfinished empty initialization.
                    (root/mail.STATE).unlink();marker.write_bytes(b'');intent.write_text(json.dumps({'schema':1,'initial':initial}));intent.chmod(0o640);os.chown(intent,account.pw_uid,account.pw_gid)
            intent.write_text(json.dumps({'schema':1,'initial':initial}));intent.chmod(0o640);os.chown(intent,account.pw_uid,account.pw_gid)
            before=(root/mail.STATE).read_bytes()
            with self.assertRaises(mail.MailAdmissionBlocked): mail.reserve(directory=root,clock=lambda:1000,queue_inspector=lambda:0)
            self.assertEqual((root/mail.STATE).read_bytes(),before)

    def test_asterisk_restart_includes_templates_and_noload_precedence(self):
        source=(ROOT/'tools/install_release.sh').read_text()
        code=source.split("<<'PYASTERISKSTART'\n",1)[1].split('\nPYASTERISKSTART',1)[0]
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);config=root/'modules.conf'
            config.write_text('[modules]\nautoload=yes\n#include "custom.conf"\n')
            custom=root/'custom.conf';custom.write_text('noload => app_read.so\n')
            def run(): return subprocess.run(['python3','-I','-c',code,'app_read.so',str(config)],capture_output=True,text=True,timeout=3)
            self.assertEqual(run().returncode,1)
            custom.write_text('load => app_read\n');self.assertEqual(run().returncode,0)
            config.write_text('[base](!)\nautoload=no\n[modules](base)\n#tryinclude missing*.conf\n#include "custom.conf"\n')
            self.assertEqual(run().returncode,0)
            custom.write_text('noload => app_read\n');self.assertEqual(run().returncode,1)
            custom.write_text('#include modules.conf\n');self.assertEqual(run().returncode,2)
            custom.write_text('#exec /bin/true\n');self.assertEqual(run().returncode,2)
            custom.write_text('[modules](+)\nload=app_read.so\n');self.assertEqual(run().returncode,0)

    def test_signer_preserves_shared_parent_and_existing_lock_inode(self):
        source=(ROOT/'slsmassnotifyserver/bin/sign_sls_mass_notify_local_sig.sh').read_text()
        function=re.search(r'^acquire_signing_lock\(\) \{.*?(?=^prepare_signing_home\(\))',source,re.M|re.S).group(0)
        with tempfile.TemporaryDirectory() as directory:
            parent=Path(directory)/'shared';parent.mkdir();parent.chmod(0o1777)
            lock=parent/'signing.lock';lock.touch(mode=0o600);inode=lock.stat().st_ino
            harness='set -euo pipefail\nlog() { :; }\nvalidate_absolute_path() { :; }\n'+function+'\nLOCK_FILE="$1"; acquire_signing_lock\n'
            result=subprocess.run(['bash','-c',harness,'fixture',str(lock)],capture_output=True,text=True,timeout=4)
            self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(parent.stat().st_mode&0o7777,0o1777);self.assertEqual(lock.stat().st_ino,inode)
            lock.unlink();os.mkfifo(lock)
            result=subprocess.run(['bash','-c',harness,'fixture',str(lock)],capture_output=True,text=True,timeout=4)
            self.assertNotEqual(result.returncode,0);self.assertEqual(parent.stat().st_mode&0o7777,0o1777)

    def test_updater_display_writer_drops_root_and_rejects_links(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);account=pwd.getpwnam('asterisk');os.chown(root,account.pw_uid,account.pw_gid)
            helper=candidate_updater(root,account)
            path=root/'update-status.json'
            command=['runuser','-u','asterisk','--','python3','-I',str(helper),str(path)]
            def run(): return subprocess.run(command,input='{"state":"complete"}',capture_output=True,text=True,timeout=3)
            result=run();self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(path.stat().st_uid,account.pw_uid)
            inode=path.with_name(path.name+'.lock').stat().st_ino
            self.assertEqual(run().returncode,0);self.assertEqual(path.with_name(path.name+'.lock').stat().st_ino,inode)
            path.unlink();other=root/'other';other.write_text('preserved');path.symlink_to(other)
            self.assertNotEqual(run().returncode,0);self.assertEqual(other.read_text(),'preserved')
            path.unlink();os.link(other,path);self.assertNotEqual(run().returncode,0)

    def test_updater_accepts_trusted_pbx_ancestor_but_not_writable_journal(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);account=pwd.getpwnam('asterisk')
            os.chown(root,0,account.pw_gid);root.chmod(0o2775)
            helper=candidate_updater(root,account)
            data=root/'data';data.mkdir();os.chown(data,account.pw_uid,account.pw_gid);data.chmod(0o750)
            path=data/'update-status.json'
            command=['runuser','-u','asterisk','--','python3','-I',str(helper),str(path)]
            def run():return subprocess.run(command,input='{"state":"complete"}',capture_output=True,text=True,timeout=3)
            result=run();self.assertEqual(result.returncode,0,result.stderr)
            saved=path.read_bytes();inode=path.with_name(path.name+'.lock').stat().st_ino
            self.assertEqual(root.stat().st_mode&0o7777,0o2775)
            data.chmod(0o2770);self.assertNotEqual(run().returncode,0);data.chmod(0o750)
            os.chown(root,0,65534);self.assertNotEqual(run().returncode,0);os.chown(root,0,account.pw_gid)
            self.assertEqual(path.read_bytes(),saved);self.assertEqual(path.with_name(path.name+'.lock').stat().st_ino,inode)

    def test_updater_replaced_named_lease_preserves_old_status(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);account=pwd.getpwnam('asterisk');os.chown(root,account.pw_uid,account.pw_gid)
            helper=candidate_updater(root,account)
            code=r'''
import importlib.util, json, os, pathlib, sys
spec=importlib.util.spec_from_file_location('writer',sys.argv[2])
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
root=pathlib.Path(sys.argv[1]);path=root/'update-status.json';module.write(path,{'state':'retained'})
saved=path.read_bytes();stat=module.os.stat;changed=False
def replace_lease(name,*args,**kwargs):
 global changed
 if name==path.name and kwargs.get('dir_fd') is not None and not changed:
  changed=True;lock=path.with_name(path.name+'.lock');os.rename(lock,root/'old-lock');lock.touch(mode=0o640)
 return stat(name,*args,**kwargs)
module.os.stat=replace_lease
try:module.write(path,{'state':'new'})
except ValueError as error:
 assert 'lock changed' in str(error)
else:raise RuntimeError('Detached updater lock was accepted.')
assert changed and path.read_bytes()==saved
assert not list(root.glob('.update-state-*'))
print('Private updater lease replacement refused; retained status and temporary cleanup verified.')
'''
            result=subprocess.run(['runuser','-u','asterisk','--','python3','-I','-c',code,str(root),str(helper)],capture_output=True,text=True,timeout=4)
            self.assertEqual(result.returncode,0,result.stdout+result.stderr)


if __name__ == '__main__': unittest.main()
