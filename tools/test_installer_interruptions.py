#!/usr/bin/python3
"""Actual shell signals and durable evidence in private installer fixtures."""
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import tempfile
import time
import unittest
import tarfile
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
BIN=ROOT/'slsmassnotifyserver/bin/sls_mass_notify'
SOURCE=ROOT/'tools/install_release.sh'
HARNESS=r'''
source "$1"
fixture="$2"; scenario="$3"
LOG_FILE="$fixture/log"; INSTALL_NOTIFICATION_SIDE_EFFECTS=0
INSTALL_TRANSACTION_ROOT="$fixture/journal"
RECOVERY_DIR="$fixture/recovery"; MODULE_BACKUP_DIR="$fixture/old-module"; STAGING_DIR="$fixture/stage"
mkdir -m700 "$RECOVERY_DIR" "$MODULE_BACKUP_DIR" "$STAGING_DIR"
STATIC_MUTATION_STARTED=1
log() { printf '%s\n' "$*" >>"$fixture/log"; }
rollback_module_install() {
 ROLLBACK_ATTEMPTED=1
 printf 'rollback\n' >>"$fixture/trace"
 if [ "$scenario" = rollback-failed ]; then ROLLBACK_FAILED=1; else ROLLBACK_VERIFIED=1; fi
}
record_install_failure() { printf 'failure:%s\n' "$INSTALL_EXIT_CODE" >>"$fixture/trace"; }
trap guard_config_on_exit EXIT
trap 'handle_install_signal TERM 143' TERM
trap 'handle_install_signal INT 130' INT
trap 'handle_install_signal HUP 129' HUP
printf 'ready\n'
if [ "$scenario" = premature-zero ]; then exit 0; fi
if [ "$scenario" = committed ]; then INSTALL_COMMITTED=1; exit 0; fi
sleep .35
printf 'child-finished\n' >>"$fixture/trace"
exit 0
'''


class Interruptions(unittest.TestCase):
    def run_signal(self,received,expected,failed=False):
        with tempfile.TemporaryDirectory() as name:
            fixture=Path(name)
            process=subprocess.Popen(['bash','-c',HARNESS,'fixture',str(SOURCE),str(fixture),'rollback-failed' if failed else 'signal'],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
            self.assertEqual(process.stdout.readline(),'ready\n')
            os.kill(process.pid,received)
            output,error=process.communicate(timeout=8)
            self.assertEqual(process.returncode,expected,error+output)
            trace=(fixture/'trace').read_text()
            self.assertIn('rollback',trace)
            self.assertIn('failure:'+str(expected),trace)
            for item in ('recovery','old-module','stage'):
                self.assertEqual((fixture/item).exists(),failed)
    def test_term_after_successful_child_never_skips_rollback(self): self.run_signal(signal.SIGTERM,143)
    def test_int_after_successful_child_never_skips_rollback(self): self.run_signal(signal.SIGINT,130)
    def test_hup_after_successful_child_never_skips_rollback(self): self.run_signal(signal.SIGHUP,129)
    def test_failed_recovery_preserves_all_evidence(self): self.run_signal(signal.SIGTERM,143,True)
    def test_successful_exit_without_commit_is_an_abort(self):
        with tempfile.TemporaryDirectory() as name:
            result=subprocess.run(['bash','-c',HARNESS,'fixture',str(SOURCE),name,'premature-zero'],capture_output=True,text=True,timeout=8)
            self.assertEqual(result.returncode,1,result.stderr)
            self.assertIn('rollback',(Path(name)/'trace').read_text())
    def test_explicit_commit_allows_cleanup(self):
        with tempfile.TemporaryDirectory() as name:
            result=subprocess.run(['bash','-c',HARNESS,'fixture',str(SOURCE),name,'committed'],capture_output=True,text=True,timeout=8)
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertFalse((Path(name)/'trace').exists())
            self.assertFalse((Path(name)/'recovery').exists())


class Journal(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        spec=importlib.util.spec_from_file_location('transaction_fixture',BIN/'sls_install_transaction.py')
        cls.module=importlib.util.module_from_spec(spec);spec.loader.exec_module(cls.module)
    def test_killed_transaction_survives_and_blocks_new_mutation(self):
        with tempfile.TemporaryDirectory() as name:
            root=Path(name)/'journal'; transaction=self.module.begin(root)
            self.module.update(transaction,'mutating',{'STATIC_MUTATION_STARTED':'1'})
            self.assertEqual(self.module.pending(root)[0]['phase'],'mutating')
            with self.assertRaises(self.module.TransactionError): self.module.begin(root)
            # Only verified finalization changes this record; no live hashes are
            # inferred or accepted during next-start discovery.
            self.module.update(transaction,'rolled_back',{'STATIC_MUTATION_STARTED':'1'})
            self.assertEqual(self.module.pending(root),[])
            with self.assertRaises(self.module.TransactionError): self.module.update(transaction,'mutating',{})
    def test_durable_commit_prunes_only_known_generation_evidence_on_retry(self):
        with tempfile.TemporaryDirectory() as name:
            root=Path(name)/'journal'; transaction=self.module.begin(root)
            package=transaction/'package.tgz';package.write_bytes(b'previous authenticated archive')
            backup=transaction/'sls-mass-notify-recovery.ABC123';backup.mkdir(mode=0o700)
            (backup/'snapshot').write_bytes(b'protected snapshot')
            outside=Path(name)/'outside';outside.write_bytes(b'preserved')
            (transaction/'config.snapshot').symlink_to(outside)
            self.module.update(transaction,'committed',{})
            self.module.begin(root)
            self.assertEqual(list(transaction.iterdir()),[transaction/'transaction.json'])
            self.assertEqual(outside.read_bytes(),b'preserved')
            # A nonterminal generation remains untouched and blocks admission.
            pending=self.module.pending(root)[0]
            staging=Path(pending['directory'])/'package.tgz';staging.write_bytes(b'needed for recovery')
            with self.assertRaises(self.module.TransactionError): self.module.begin(root)
            self.assertEqual(staging.read_bytes(),b'needed for recovery')

    def test_completed_generation_with_unknown_files_requires_review(self):
        with tempfile.TemporaryDirectory() as name:
            root=Path(name)/'journal'; transaction=self.module.begin(root)
            known=transaction/'package.tgz';known.write_bytes(b'preserve')
            unknown=transaction/'administrator-notes';unknown.write_bytes(b'preserve')
            self.module.update(transaction,'rolled_back',{})
            with self.assertRaisesRegex(self.module.TransactionError,'unknown'):self.module.begin(root)
            self.assertTrue(known.exists());self.assertTrue(unknown.exists())

    def test_unknown_or_unprotected_evidence_is_never_adopted(self):
        with tempfile.TemporaryDirectory() as name:
            root=Path(name)/'journal';transaction=self.module.begin(root)
            (transaction/'transaction.json').chmod(0o666)
            with self.assertRaises(self.module.TransactionError): self.module.pending(root)
            (transaction/'transaction.json').chmod(0o600)
            (root/'unknown').mkdir()
            with self.assertRaises(self.module.TransactionError): self.module.pending(root)
    def test_symlink_or_parent_write_permission_rejects_journal(self):
        with tempfile.TemporaryDirectory() as name:
            base=Path(name);(base/'real').mkdir();(base/'journal').symlink_to(base/'real',target_is_directory=True)
            with self.assertRaises(OSError): self.module.begin(base/'journal')
            (base/'journal').unlink();base.chmod(0o777)
            with self.assertRaises(self.module.TransactionError): self.module.begin(base/'journal')
    def test_control_characters_and_unrecognized_shell_fields_are_rejected(self):
        with tempfile.TemporaryDirectory() as name:
            transaction=self.module.begin(Path(name)/'journal')
            with self.assertRaises(self.module.TransactionError): self.module.update(transaction,'mutating',{'BASH_ENV':'evil'})
            with self.assertRaises(self.module.TransactionError): self.module.update(transaction,'mutating',{'CONFIG_SNAPSHOT':'foo\nbar'})

    def test_durable_stage_changes_preserve_recovery_authority_and_legacy_metadata(self):
        with tempfile.TemporaryDirectory() as name:
            root=Path(name)/'journal';transaction=self.module.begin(root)
            self.assertNotIn('stage',self.module.state(transaction))
            self.module.update(transaction,'mutating',{'STATIC_MUTATION_STARTED':'1'},'module activation')
            value=self.module.state(transaction)
            self.assertEqual(value['stage'],'module activation');self.assertEqual(value['stage_at'],value['updated_at'])
            self.assertEqual(value['settings'],{'STATIC_MUTATION_STARTED':'1'})
            before=(transaction/'transaction.json').read_bytes()
            with self.assertRaises(self.module.TransactionError):self.module.update(transaction,'mutating',{},'secret=never')
            self.assertEqual((transaction/'transaction.json').read_bytes(),before)
            value['stage_at']=value['updated_at']+1
            self.module.atomic(transaction/'transaction.json',value)
            with self.assertRaises(self.module.TransactionError):self.module.state(transaction)

    def test_recovery_helpers_and_installer_require_the_saved_publisher_signature(self):
        with tempfile.TemporaryDirectory() as name:
            root=Path(name);transaction=self.module.begin(root/'journal')
            bootstrap=transaction/'bootstrap';bootstrap.mkdir(mode=0o700)
            key=root/'key'
            subprocess.run(['openssl','genpkey','-algorithm','Ed25519','-out',str(key)],check=True,capture_output=True)
            public=subprocess.run(['openssl','pkey','-in',str(key),'-pubout'],check=True,capture_output=True).stdout
            source=(BIN/'sls_release_trust.py').read_text()
            prefix="ANCHOR_PEM = ";begin=source.index(prefix);end=source.index('\n',begin)
            source=source[:begin]+prefix+repr(public)+source[end:]
            source=source.replace("DIRECTORY = Path('/var/lib/sls-mass-notify-trust')","DIRECTORY = Path("+repr(str(root/'unused-policy'))+")")
            contents={'sls_release_trust.py':source.encode(),'sls_install_transaction.py':(BIN/'sls_install_transaction.py').read_bytes()}
            for filename,body in contents.items():(bootstrap/filename).write_bytes(body);(bootstrap/filename).chmod(0o700)
            package=transaction/'package.tgz'
            with tarfile.open(package,'w:gz') as archive:
                for filename,body in contents.items():
                    member=tarfile.TarInfo('slsmassnotifyserver/bin/sls_mass_notify/'+filename);member.size=len(body);archive.addfile(member,io.BytesIO(body))
            installer=transaction/'installer.sh';installer.write_bytes(b'authenticated fixture installer')
            import hashlib
            manifest=transaction/'release-manifest.json'
            manifest.write_text(json.dumps({'schema':1,'version':'0.1.6-beta','package':'slsmassnotifyserver-0.1.6-beta.tgz','installer_sha256':hashlib.sha256(installer.read_bytes()).hexdigest(),'package_sha256':hashlib.sha256(package.read_bytes()).hexdigest()}))
            subprocess.run(['openssl','pkeyutl','-sign','-rawin','-inkey',str(key),'-in',str(manifest),'-out',str(transaction/'release-manifest.sig')],check=True,capture_output=True)
            for filename in ('package.tgz','installer.sh','release-manifest.json','release-manifest.sig'):(transaction/filename).chmod(0o600)
            self.module.update(transaction,'mutating',{'INSTALL_BOOTSTRAP_DIR':str(bootstrap),'STATIC_MUTATION_STARTED':'1'})
            with patch.object(self.module,'__file__',str(bootstrap/'sls_install_transaction.py')):
                self.assertEqual(self.module.authenticate(transaction)['phase'],'mutating')
                (bootstrap/'sls_install_transaction.py').write_bytes(b'unknown recovery program')
                with self.assertRaisesRegex(self.module.TransactionError,'differs'):self.module.authenticate(transaction)
                (bootstrap/'sls_install_transaction.py').write_bytes(contents['sls_install_transaction.py'])
                installer.write_bytes(b'unknown installer program')
                with self.assertRaisesRegex(self.module.TransactionError,'publisher'):self.module.authenticate(transaction)

    @unittest.skipUnless(os.geteuid()==0,'requires root for authenticated durable permission snapshots')
    def test_sigkill_after_native_permission_protection_has_durable_recovery(self):
        with tempfile.TemporaryDirectory(dir='/run') as name:
            root=Path(name);bootstrap=root/'bootstrap';bootstrap.mkdir(mode=0o700)
            approval=root/'approval';approval.mkdir(mode=0o700)
            (approval/'previous-root-approval.json').write_text(json.dumps({'schema':1,'source':'fresh-install','files':{},'root_jobs':[]}))
            (approval/'previous-root-approval.json').chmod(0o600)
            config=root/'freepbx_chown.conf';original=b'; keep native settings\n'
            config.write_bytes(original);config.chmod(0o640)
            key=root/'key'
            subprocess.run(['openssl','genpkey','-algorithm','Ed25519','-out',str(key)],check=True,capture_output=True)
            public=subprocess.run(['openssl','pkey','-in',str(key),'-pubout'],check=True,capture_output=True).stdout
            names=('sls_module_trust.py','sls_release_trust.py','sls_privileged_install.py','sls_installer_recovery.py',
                'sls_install_transaction.py','sls_install_idle.py','sls_install_guard.py','sls_audio_state.py',
                'sls_config_crypto.py','sls_upgrade_trust.py','sls_legacy_permissions.py','freepbx-mirror-signing.pub')
            contents={name:(BIN/name).read_bytes() for name in names}
            verifier=contents['sls_release_trust.py'].decode()
            prefix='ANCHOR_PEM = ';begin=verifier.index(prefix);end=verifier.index('\n',begin)
            verifier=verifier[:begin]+prefix+repr(public)+verifier[end:]
            verifier=verifier.replace("DIRECTORY = Path('/var/lib/sls-mass-notify-trust')","DIRECTORY = Path("+repr(str(root/'unused-policy'))+")")
            contents['sls_release_trust.py']=verifier.encode()
            for filename,body in contents.items():
                (bootstrap/filename).write_bytes(body);(bootstrap/filename).chmod(0o700)
            # Recovery authenticates the exact saved programs against this
            # fixture's signed package, including the permission helper.
            installer=root/'installer.sh'
            installer.write_text(SOURCE.read_text().replace('/usr/local/bin/sls_mass_notify/sls_release_trust.py',
                str(root/'recovery-verifier.py')))
            (root/'recovery-verifier.py').write_bytes(contents['sls_release_trust.py'])
            installer.chmod(0o600)
            package=root/'package.tgz'
            with tarfile.open(package,'w:gz') as archive:
                for filename,body in contents.items():
                    member=tarfile.TarInfo('slsmassnotifyserver/bin/sls_mass_notify/'+filename)
                    member.size=len(body);archive.addfile(member,io.BytesIO(body))
            download=root/'download';download.mkdir(mode=0o700)
            import hashlib
            manifest=download/'release-manifest.json'
            manifest.write_text(json.dumps({'schema':1,'version':'0.1.6-beta','package':'slsmassnotifyserver-0.1.6-beta.tgz',
                'installer_sha256':hashlib.sha256(installer.read_bytes()).hexdigest(),'package_sha256':hashlib.sha256(package.read_bytes()).hexdigest()}))
            subprocess.run(['openssl','pkeyutl','-sign','-rawin','-inkey',str(key),'-in',str(manifest),
                '-out',str(download/'release-manifest.sig')],check=True,capture_output=True)
            harness=r'''
source "$1"
fixture="$2"; INSTALL_TRANSACTION_ROOT="$fixture/journal"
INSTALL_BOOTSTRAP_DIR="$fixture/bootstrap"; UPGRADE_TRUST_DIR="$fixture/approval"
TGZ="$fixture/package.tgz"; DOWNLOAD_DIR="$fixture/download"
DATA_DIR="$fixture/absent-data"; FREEPBX_CHOWN_CONFIG="$fixture/freepbx_chown.conf"
AUTHENTICATED_INSTALLER_APPROVED=1
begin_install_transaction
protect_freepbx_chown_permissions
printf 'READY:%s\n' "$INSTALL_TRANSACTION_DIR"
kill -KILL "$$"
'''
            killed=subprocess.run(['bash','-c',harness,'fixture',str(installer),str(root)],capture_output=True,text=True,timeout=10)
            self.assertEqual(killed.returncode,-signal.SIGKILL,killed.stdout+killed.stderr)
            pending=self.module.pending(root/'journal');self.assertEqual(len(pending),1)
            transaction=Path(pending[0]['directory'])
            state=self.module.state(transaction)
            self.assertEqual(state['phase'],'prepared')
            self.assertEqual(state['settings']['CONFIG_SNAPSHOT'],'')
            self.assertEqual(state['settings']['PENDING_CONFIG_STATE'],'')
            self.assertTrue((transaction/'approval/freepbx-chown.snapshot').is_file())
            self.assertNotEqual(config.read_bytes(),original)
            recovery=r'''
source "$1"
fixture="$2"; INSTALL_TRANSACTION_ROOT="$fixture/journal"
INSTALLER_SOURCE_PATH="$3/installer.sh"
DATA_DIR="$fixture/absent-data"; FREEPBX_CHOWN_CONFIG="$fixture/freepbx_chown.conf"
LOG_FILE="$fixture/recovery.log"; INSTALL_NOTIFICATION_SIDE_EFFECTS=0
acquire_update_coordination() { :; }
acquire_maintenance_coordination() { :; }
acquire_worker_coordination() { printf worker >>"$fixture/trace"; }
acquire_settings_coordination() { :; }
clear_install_failure() { :; }
record_install_failure() { :; }
recover_interrupted_installation "$3"
'''
            restored=subprocess.run(['bash','-c',recovery,'fixture',str(transaction/'installer.sh'),str(root),str(transaction)],
                capture_output=True,text=True,timeout=10)
            self.assertEqual(restored.returncode,0,restored.stdout+restored.stderr)
            self.assertEqual(config.read_bytes(),original)
            self.assertEqual(config.stat().st_mode & 0o777,0o640)
            self.assertFalse((root/'absent-data').exists())
            self.assertEqual(self.module.pending(root/'journal'),[])
            self.assertEqual(self.module.state(transaction)['phase'],'rolled_back')


if __name__=='__main__': unittest.main()
