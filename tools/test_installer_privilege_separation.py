#!/usr/bin/env python3
"""Exercise installer privilege boundaries and signed offline admission in isolation."""
import hashlib
import fcntl
import signal
import time
import json
import os
from pathlib import Path
import pwd
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT/'tools/install_release.sh').read_text()

class InstallerPrivilegeTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='sls-installer-privilege-')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.installer = self.root/'installer.sh'
        self.installer.write_text(SOURCE)
        self.guard=self.root/'sls_install_guard.py'
        self.guard.write_bytes((ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_install_guard.py').read_bytes())
        self.guard.chmod(0o700)

    def shell(self, code, **env):
        return subprocess.run(['bash','-c','source "$1"; WORKER_GUARD_TOOL="$FIXTURE_GUARD_PATH"; '+code,'fixture',str(self.installer)],
            env={**os.environ,'FIXTURE_GUARD_PATH':str(self.guard),**env},capture_output=True,text=True,timeout=20)

    @unittest.skipUnless(os.geteuid()==0,'requires root to exercise real privilege dropping')
    def test_php_executes_as_real_nonroot_asterisk(self):
        expected=pwd.getpwnam('asterisk').pw_uid
        result=self.shell("php -r 'echo posix_geteuid();'")
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(result.stdout,str(expected))
        self.assertNotEqual(expected,0)

    def test_missing_or_zero_uid_refuses_execution(self):
        for answer in ('0','invalid'):
            result=self.shell(f'id() {{ printf "%s\\n" {answer}; }}; log() {{ :; }}; php -r \'echo "UNSAFE";\'')
            self.assertNotEqual(result.returncode,0)
            self.assertNotIn('UNSAFE',result.stdout)

    def test_fwconsole_root_dependent_commands_are_refused(self):
        for action in ('chown','start','stop','restart'):
            result=self.shell('log() { :; }; sls_as_asterisk() { echo EXECUTED; }; fwconsole '+action)
            self.assertNotEqual(result.returncode,0)
            self.assertNotIn('EXECUTED',result.stdout)

    def test_private_copy_never_changes_source_or_follows_links(self):
        source=self.root/'asset'; source.write_bytes(b'fixture'); source.chmod(0o644)
        target=self.root/'copy'
        result=self.shell('copy_private_release_asset "$ASSET" "$TARGET" 20',ASSET=str(source),TARGET=str(target))
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(source.stat().st_mode & 0o777,0o644)
        self.assertEqual(target.stat().st_mode & 0o777,0o600)
        target.unlink()
        link=self.root/'link'; link.symlink_to(source)
        result=self.shell('copy_private_release_asset "$ASSET" "$TARGET" 20',ASSET=str(link),TARGET=str(target))
        self.assertNotEqual(result.returncode,0)
        os.link(source,self.root/'hardlink')
        result=self.shell('copy_private_release_asset "$ASSET" "$TARGET" 20',ASSET=str(source),TARGET=str(target))
        self.assertNotEqual(result.returncode,0)
        self.assertFalse(target.exists())

    @unittest.skipUnless(os.geteuid()==0,'requires root to validate service-owned worker locks')
    def test_worker_locks_block_concurrent_process_then_release(self):
        data=self.root/'data'; data.mkdir()
        probe=self.root/'probe.py'
        probe.write_text("import fcntl,sys\nf=open(sys.argv[1], 'r+')\ntry: fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)\nexcept BlockingIOError: sys.exit(17)\n")
        result=self.shell("DATA_DIR=\"$DATA\"; ensure_data_directory() { :; }; acquire_worker_coordination; trap release_worker_coordination EXIT; if python3 \"$PROBE\" \"$DATA/schedule-runner.lock\"; then exit 90; else [ \"$?\" = 17 ]; fi; release_worker_coordination; python3 \"$PROBE\" \"$DATA/schedule-runner.lock\"",DATA=str(data),PROBE=str(probe))
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        for name in ('schedule-runner.lock','announcement-activity.lock','weather-dispatch-worker.lock'):
            self.assertEqual((data/name).stat().st_uid,pwd.getpwnam('asterisk').pw_uid)

    @unittest.skipUnless(os.geteuid()==0,'requires root for actual worker and service-account descendants')
    def test_parent_death_releases_guard_while_descendant_remains_alive(self):
        # Exercise both the explicit child wrapper and ordinary PHP/fwconsole's
        # service-account path. No explicit release is sent: EOF must suffice.
        for wrapper in ('run_without_install_maintenance_lock', 'sls_as_asterisk'):
            with self.subTest(wrapper=wrapper):
                directory=self.root/wrapper; directory.mkdir(mode=0o755); directory.chmod(0o755)
                self.root.chmod(0o755)
                data=directory/'data'; data.mkdir()
                marker=directory/'child.json'; marker.touch(); marker.chmod(0o666)
                child="import json,os,pathlib,sys,time; p=pathlib.Path('/proc/self/fd/'+sys.argv[2]); inherited=p.exists() and os.readlink(p)==sys.argv[3]; pathlib.Path(sys.argv[1]).write_text(json.dumps({'pid':os.getpid(),'inherited':inherited})); time.sleep(30)"
                script='''source "$1"
WORKER_GUARD_TOOL="$FIXTURE_GUARD_PATH"; DATA_DIR="$DATA"
ensure_data_directory() { :; }
acquire_worker_coordination
pipe_target="$(readlink /proc/$$/fd/$WORKER_LOCK_KEEPALIVE_FD)"
"$WRAPPER" /usr/bin/python3 -c 'import subprocess,sys; subprocess.Popen([sys.executable,"-c",*sys.argv[1:]],close_fds=False)' "$CHILD" "$MARKER" "$WORKER_LOCK_KEEPALIVE_FD" "$pipe_target"
for attempt in {1..100}; do [ ! -s "$MARKER" ] || break; sleep 0.02; done
[ -s "$MARKER" ] || exit 91
kill -KILL "$$"
'''
                process=subprocess.Popen(['bash','-c',script,'fixture',str(self.installer)],
                    env={**os.environ,'FIXTURE_GUARD_PATH':str(self.guard),'DATA':str(data),
                         'MARKER':str(marker),'WRAPPER':wrapper,'CHILD':child},
                    stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
                try:
                    self.assertEqual(process.wait(timeout=8),-signal.SIGKILL)
                    metadata=json.loads(marker.read_text())
                    self.assertFalse(metadata['inherited'],'descendant inherited worker keepalive')
                    os.kill(metadata['pid'],0)  # Child is still alive when guard must release.
                    deadline=time.monotonic()+4
                    with (data/'schedule-runner.lock').open('r+') as handle:
                        while True:
                            try:
                                fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
                                break
                            except BlockingIOError:
                                if time.monotonic()>=deadline:
                                    self.fail('worker guard retained locks after installer death')
                                time.sleep(0.02)
                finally:
                    try: os.killpg(process.pid,signal.SIGKILL)
                    except ProcessLookupError: pass
                    process.wait(timeout=5)

    @unittest.skipUnless(os.geteuid()==0,'requires root to validate worker lock safety')
    def test_worker_lock_symlink_refused_without_touching_target(self):
        data=self.root/'data'; data.mkdir()
        target=self.root/'sentinel'; target.write_bytes(b'preserve'); target.chmod(0o600)
        (data/'schedule-runner.lock').symlink_to(target)
        result=self.shell('DATA_DIR="$DATA"; ensure_data_directory() { :; }; acquire_worker_coordination',DATA=str(data))
        self.assertNotEqual(result.returncode,0)
        self.assertEqual(target.read_bytes(),b'preserve')
        self.assertEqual(target.stat().st_uid,0)

    @unittest.skipUnless(os.geteuid()==0,'requires root to validate paging slot coordination')
    def test_live_paging_cannot_start_while_installer_holds_all_slots(self):
        data=self.root/'data'; data.mkdir()
        helper=ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_live_paging.php'
        probe=self.root/'paging.php'
        probe.write_text('<?php require '+repr(str(helper))+'; $state = new \\SLS\\MassNotify\\LivePagingState($argv[1]); echo $state->acquireCallSlot("1000") ? "yes" : "no";')
        result=self.shell('DATA_DIR="$DATA"; ensure_data_directory() { :; }; acquire_worker_coordination; trap release_worker_coordination EXIT; [ "$(/usr/bin/php \"$PROBE\" \"$DATA\")" = no ]; release_worker_coordination; [ "$(/usr/bin/php \"$PROBE\" \"$DATA\")" = yes ]',DATA=str(data),PROBE=str(probe))
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)

    @unittest.skipUnless(os.geteuid()==0,'requires root for authenticated staging ownership')
    def test_module_activation_sets_service_owner_and_preserves_prior_tree(self):
        modules=self.root/'modules'; modules.mkdir()
        prior=modules/'slsmassnotifyserver'; prior.mkdir(); (prior/'old').write_text('previous')
        stage=self.root/'stage'; stage.mkdir(mode=0o700)
        source=stage/'slsmassnotifyserver'; source.mkdir(); (source/'module.xml').write_text('candidate')
        self.installer.write_text(SOURCE.replace('/var/www/html/admin/modules',str(modules)))
        result=self.shell('STAGING_DIR="$STAGE"; activate_staged_module; printf \'%s\\n\' \"$MODULE_BACKUP_DIR\"; [ \"$MODULE_ACTIVATED\" = 1 ]',STAGE=str(stage))
        self.assertEqual(result.returncode,0,result.stderr)
        backup=Path(result.stdout.strip())
        self.addCleanup(lambda: __import__('shutil').rmtree(backup,ignore_errors=True))
        self.assertEqual((backup/'slsmassnotifyserver/old').read_text(),'previous')
        self.assertEqual((modules/'slsmassnotifyserver/module.xml').stat().st_uid,pwd.getpwnam('asterisk').pw_uid)
        self.assertFalse(source.exists())

    def test_fixed_phase_order_and_no_mutable_root_promotion(self):
        main=SOURCE.split('main() {',1)[1]
        phases=['verify_tgz','stage_module_directory','prepare_installer_bootstrap','acquire_worker_coordination','prepare_authenticated_installer','protected_install_phase prepare --apply',
                'protected_install_phase dependencies --apply','activate_staged_module',
                'install_module_with_autoenable','protected_install_phase activate --apply','fwconsole reload',
                'protected_install_phase verify','verify_install','INSTALL_COMMITTED=1']
        positions=[main.index(value) for value in phases]
        self.assertEqual(positions,sorted(positions))
        self.assertNotIn('fwconsole chown\n',main)
        signer=SOURCE.split('ensure_local_signer() {',1)[1].split('\nsign_and_verify_touched_modules()',1)[0]
        self.assertIn('protected_install_phase admit',signer)
        self.assertNotIn('install -m',signer)
        rollback=SOURCE.split('rollback_module_install() {',1)[1].split('\nguard_config_on_exit()',1)[0]
        for forbidden in ('->install(', '->uninstall(', 'fwconsole ma', 'refresh_module_install', 'fwconsole reload'):
            self.assertNotIn(forbidden,rollback)

    def test_offline_release_requires_real_signature_and_both_artifact_hashes(self):
        key=self.root/'key.pem'; public=self.root/'public.pem'
        subprocess.run(['openssl','genpkey','-algorithm','Ed25519','-out',str(key)],check=True,capture_output=True)
        subprocess.run(['openssl','pkey','-in',str(key),'-pubout','-out',str(public)],check=True,capture_output=True)
        # Replace only the test copy's pinned public key, never production source.
        start=SOURCE.index("public_key = b'"); end=SOURCE.index('\ntry:',start)
        fixture=SOURCE[:start]+'public_key = '+repr(public.read_bytes())+SOURCE[end:]
        self.installer.write_text(fixture)
        package=self.root/'input.tgz'; package.write_bytes(b'isolated signed package')
        manifest=self.root/'release-manifest.json'; signature=self.root/'release-manifest.sig'
        data={'schema':1,'version':'0.1.5-beta','tag':'slsmassnotifyserver-0.1.5-beta',
              'package':'slsmassnotifyserver-0.1.5-beta.tgz','package_sha256':hashlib.sha256(package.read_bytes()).hexdigest(),
              'installer_sha256':hashlib.sha256(self.installer.read_bytes()).hexdigest()}
        manifest.write_text(json.dumps(data))
        subprocess.run(['openssl','pkeyutl','-sign','-rawin','-inkey',str(key),'-in',str(manifest),'-out',str(signature)],check=True,capture_output=True)
        def verify():
            download=self.root/('download-'+str(len(list(self.root.glob('download-*'))))); download.mkdir()
            return self.shell('DOWNLOAD_DIR="$PRIVATE"; TGZ="$PACKAGE"; URL=""; verify_publisher_release',
                PRIVATE=str(download),PACKAGE=str(package),SLS_MASS_NOTIFY_TGZ=str(package))
        result=verify(); self.assertEqual(result.returncode,0,result.stderr)
        package.write_bytes(b'tampered package')
        self.assertNotEqual(verify().returncode,0)
        package.write_bytes(b'isolated signed package')
        signature.write_bytes(b'0'*64)
        self.assertNotEqual(verify().returncode,0)

if __name__=='__main__': unittest.main()
