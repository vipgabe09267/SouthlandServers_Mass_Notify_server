#!/usr/bin/python3
"""Actual bounded lease contention/release; no PBX commands or state touched."""
import fcntl
import importlib.util
import os
import pwd
from pathlib import Path
import select
import shlex
import subprocess
import tempfile
import unittest

import sys
sys.dont_write_bytecode = True

ROOT=Path(__file__).resolve().parents[1]
HELPER=ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_install_guard.py'

class GuardTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='sls-guard-fixture-')
        self.data=Path(self.tmp.name)
        self.children=[]
    def tearDown(self):
        for child in self.children:
            if child.poll() is None:
                child.communicate(b'release\n',timeout=5)
        self.tmp.cleanup()
    def start(self, timeout='0.3', settings=True):
        command=['/usr/bin/python3','-I',str(HELPER),'--data',str(self.data),'--timeout',timeout]
        if settings: command.append('--settings')
        child=subprocess.Popen(command+['hold'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        self.children.append(child)
        return child
    def ready(self, child):
        self.assertTrue(select.select([child.stdout],[],[],5)[0])
        line=child.stdout.readline()
        self.assertEqual(line,b'ready\n')
    def lock(self, path):
        path.parent.mkdir(exist_ok=True)
        fd=os.open(path,os.O_CREAT|os.O_RDWR,0o640)
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        return fd
    def test_live_slot_and_settings_are_excluded_until_parent_release(self):
        child=self.start();self.ready(child)
        names=['enterprise-worker.lock','announcement-activity.lock','schedule-runner.lock','mass-notifications.config.lock']
        names += ['live-paging-slots/global-'+str(i)+'.lock' for i in range(16)]
        for name in names:
            with open(self.data/name,'r+') as handle:
                with self.assertRaises(BlockingIOError): fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
        child.communicate(b'release\n',timeout=5);self.assertEqual(child.returncode,0)
        for name in names:
            fd=self.lock(self.data/name);os.close(fd)
    def test_worker_paging_and_settings_contention_never_becomes_ready(self):
        for name in ['enterprise-worker.lock','announcement-activity.lock','live-paging-slots/global-15.lock','mass-notifications.config.lock']:
            with self.subTest(name=name):
                fd=self.lock(self.data/name)
                try:
                    child=self.start();out,err=child.communicate(timeout=5)
                    self.assertNotEqual(child.returncode,0);self.assertNotIn(b'ready',out)
                    self.assertIn(b'active worker did not become idle',err)
                finally: os.close(fd)
    def test_lifecycle_lease_is_acquired_before_inner_activity_locks(self):
        fd=self.lock(self.data/'enterprise-worker.lock')
        try:
            child=self.start();out,err=child.communicate(timeout=5)
            self.assertNotEqual(child.returncode,0);self.assertNotIn(b'ready',out)
            self.assertIn(b'enterprise-worker.lock',err)
            for name in ('schedule-runner.lock','announcement-activity.lock','mass-notifications.config.lock'):
                self.assertFalse((self.data/name).exists(),name)
        finally:os.close(fd)
    def test_parent_pipe_eof_releases_locks(self):
        (self.data/'audio-reservations.json').write_text('{"recipients":{},"media":{}}')
        (self.data/'audio-reservations.json').chmod(0o640)
        child=self.start();self.ready(child)
        child.stdin.close();child.stdin=None
        child.communicate(timeout=5);self.assertEqual(child.returncode,0)
        fd=self.lock(self.data/'announcement-activity.lock');os.close(fd)
        for name in ('audio-reservations.lock','audio-reservations.json'):
            fd=self.lock(self.data/name);os.close(fd)

    def test_audio_both_generations_excluded_and_idle_shared_reader_coexists(self):
        path=self.data/'audio-reservations.json'
        original=b'{"recipients":{},"media":{}}'
        path.write_bytes(original);path.chmod(0o640)
        inode=path.stat().st_ino
        child=self.start();self.ready(child)
        for name in ('audio-reservations.lock','audio-reservations.json'):
            with open(self.data/name,'r+') as handle:
                with self.assertRaises(BlockingIOError):fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
                fcntl.flock(handle,fcntl.LOCK_SH|fcntl.LOCK_NB)
        spec=importlib.util.spec_from_file_location('guard_idle_fixture',HELPER.with_name('sls_install_idle.py'))
        idle=importlib.util.module_from_spec(spec);spec.loader.exec_module(idle)
        check=idle.IdleCheck.__new__(idle.IdleCheck)
        check.data=self.data;check.spools=[];check.clock=lambda:1000;check.channels=lambda:''
        check.no_playback()
        self.assertEqual(path.read_bytes(),original)
        self.assertEqual(path.stat().st_ino,inode)
        child.communicate(b'release\n',timeout=5)
        for name in ('audio-reservations.lock','audio-reservations.json'):
            fd=self.lock(self.data/name);os.close(fd)

    def test_legacy_and_new_writer_block_guard_before_ready(self):
        for name in ('audio-reservations.json','audio-reservations.lock'):
            with self.subTest(name=name):
                fd=self.lock(self.data/name)
                try:
                    child=self.start();out,err=child.communicate(timeout=5)
                    self.assertNotEqual(child.returncode,0)
                    self.assertNotIn(b'ready',out)
                    self.assertIn(b'active worker did not become idle',err)
                finally:os.close(fd)

    def test_fresh_guard_creates_only_empty_runtime_owned_sidecar(self):
        if os.geteuid()==0:
            account=pwd.getpwnam('asterisk')
            os.chown(self.data,account.pw_uid,account.pw_gid)
        child=self.start();self.ready(child)
        self.assertFalse((self.data/'audio-reservations.json').exists())
        lock=self.data/'audio-reservations.lock'
        self.assertEqual(lock.read_bytes(),b'')
        self.assertEqual((lock.stat().st_uid,lock.stat().st_gid),(self.data.stat().st_uid,self.data.stat().st_gid))
        child.communicate(b'release\n',timeout=5)
        self.assertFalse((self.data/'audio-reservations.json').exists())

    def test_unsafe_audio_sidecar_or_legacy_state_refused(self):
        for name in ('audio-reservations.lock','audio-reservations.json'):
            for kind in ('symlink','hardlink','fifo'):
                with self.subTest(name=name,kind=kind):
                    path=self.data/name
                    if path.exists() or path.is_symlink():path.unlink()
                    victim=self.data/'audio-victim';victim.write_bytes(b'preserved');victim.chmod(0o640)
                    if kind=='symlink':path.symlink_to(victim)
                    elif kind=='hardlink':os.link(victim,path)
                    else:os.mkfifo(path,0o640)
                    child=self.start();out,err=child.communicate(timeout=5)
                    self.assertNotEqual(child.returncode,0);self.assertNotIn(b'ready',out)
                    self.assertEqual(victim.read_bytes(),b'preserved')
                    path.unlink()

    def test_audio_mode_size_and_owner_match_reader_protocol(self):
        for name in ('audio-reservations.lock','audio-reservations.json'):
            for kind in ('writable','special_mode','oversized','foreign_owner'):
                if kind=='foreign_owner' and os.geteuid()!=0:continue
                with self.subTest(name=name,kind=kind):
                    path=self.data/name
                    if path.exists():path.unlink()
                    contents=b'x'*(1048577 if name.endswith('.json') else 100) if kind=='oversized' else b''
                    path.write_bytes(contents)
                    path.chmod(0o666 if kind=='writable' else 0o4640 if kind=='special_mode' else 0o640)
                    if kind=='foreign_owner':os.chown(path,65534,65534)
                    before=path.stat()
                    child=self.start();out,err=child.communicate(timeout=5)
                    self.assertNotEqual(child.returncode,0);self.assertNotIn(b'ready',out)
                    after=path.stat()
                    self.assertEqual((after.st_uid,after.st_mode,after.st_ino),(before.st_uid,before.st_mode,before.st_ino))
                    self.assertEqual(path.read_bytes(),contents)
                    path.unlink()
    def test_hardlinked_lock_refused_without_victim_mutation(self):
        victim=self.data/'victim';victim.write_bytes(b'preserved');victim.chmod(0o600)
        before=victim.stat();os.link(victim,self.data/'announcement-activity.lock')
        child=self.start();out,err=child.communicate(timeout=5)
        self.assertNotEqual(child.returncode,0);self.assertNotIn(b'ready',out)
        after=victim.stat();self.assertEqual((before.st_uid,before.st_mode),(after.st_uid,after.st_mode));self.assertEqual(victim.read_bytes(),b'preserved')

class MaintenanceGuardTests(unittest.TestCase):
    def test_real_shell_guard_releases_and_detects_pending_edits(self):
        source=(ROOT/'slsmassnotifyserver/bin/sls_mass_notify_maintenance.sh').read_text()
        definitions=source[:source.index('[ "${EUID:-$(id -u)}" -eq 0 ] || exit 1')]
        for changed in (False, True):
            with self.subTest(changed=changed), tempfile.TemporaryDirectory(prefix='sls-maint-guard-') as name:
                base=Path(name);runtime=base/'runtime';runtime.mkdir()
                (runtime/'sls_install_guard.py').write_bytes(HELPER.read_bytes())
                (runtime/'sls_install_idle.py').write_text('print(\'{"ok":true}\')\n')
                data=base/'data';data.mkdir()
                (data/'mass-notifications.config').write_text('active preserved')
                (data/'mass-notifications.pending.config').write_text('pending preserved')
                script=definitions.replace('/run/sls-maintenance-idle.XXXXXXXX',str(base/'idle.XXXXXXXX'))
                script += '\nRUNTIME_DIR="$1/runtime"; CONFIG_FILE="$1/data/mass-notifications.config"; LOG_FILE="$1/log"\n'
                script += 'acquire_mutation_coordination\n'
                script += "run_without_maintenance_lock /usr/bin/python3 -c " + shlex.quote("import os,sys; assert not os.path.exists('/proc/self/fd/'+sys.argv[1])") + ' "$MUTATION_KEEPALIVE_FD"\n'
                if changed: script += 'printf changed > "$1/data/mass-notifications.pending.config"\n'
                script += 'if verify_mutation_idle; then echo VERIFIED; else echo REJECTED; fi\nrelease_mutation_coordination\n'
                result=subprocess.run(['/bin/bash','-c',script,'_',str(base)],capture_output=True,text=True,timeout=8)
                self.assertEqual(result.returncode,0,result.stderr)
                self.assertIn('REJECTED' if changed else 'VERIFIED',result.stdout)
                self.assertEqual(bool(list(base.glob('idle.*/before.json'))),changed)
                for entry in ('announcement-activity.lock','mass-notifications.config.lock','live-paging-slots/global-15.lock'):
                    with open(data/entry,'r+') as handle: fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
                self.assertEqual((data/'mass-notifications.config').read_text(),'active preserved')
                self.assertEqual((data/'mass-notifications.pending.config').read_text(),'changed' if changed else 'pending preserved')

if __name__=='__main__': unittest.main()
