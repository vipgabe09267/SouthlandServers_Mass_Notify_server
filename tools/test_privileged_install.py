#!/usr/bin/env python3
"""Authenticated root operations against a disposable filesystem and fake services."""
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
BIN = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'
def module(name):
    spec = importlib.util.spec_from_file_location(name, BIN / (name + '.py'))
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result
TRUST = module('sls_module_trust')
INSTALL = module('sls_privileged_install')

class Services:
    def __init__(self):
        self.cron = b'# independent administrator job\n2 * * * * /root/independent-backup\n'
        self.calls = []
        self.fail = None
    def __call__(self, args, **kwargs):
        self.calls.append((args, kwargs))
        if self.fail and self.fail in args:
            raise INSTALL.InstallError('fixture system operation failed')
        if args == ['/usr/bin/crontab', '-u', 'root', '-l']:
            return subprocess.CompletedProcess(args, 0, self.cron, b'')
        if args == ['/usr/bin/crontab', '-u', 'root', '-']:
            self.cron = kwargs['input']
        return subprocess.CompletedProcess(args, 0, b'', b'')

class PrivilegedInstall(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory(prefix='sls-root-install-')
        self.addCleanup(temp.cleanup)
        self.base = Path(temp.name)
        self.fs = INSTALL.Files(self.base / 'filesystem')
        self.services = Services()
        self.store = self.base / 'trust'
        self.package = {
            'module.xml': b'<module><rawname>slsmassnotifyserver</rawname><version>0.1.5-beta</version></module>',
            'bin/sls_mass_notify_maintenance.sh': b'#!/bin/bash\n# authenticated maintenance\n',
            'bin/sls_mass_notify_update.sh': b'#!/bin/bash\n# authenticated updater\n',
            'bin/sls_mass_notify_install_piper_voices.sh': b'#!/bin/bash\n# authenticated dependency operations\n',
            'bin/sign_sls_mass_notify_local_sig.sh': b'#!/bin/bash\n# authenticated signer\n',
            'bin/sls_mass_notify/sls_phone_events.py': b'# authenticated collector\n',
            'bin/slsconsole': b'#!/usr/bin/python3 -I\n# authenticated console\n',
            'bin/sls_mass_notify/sls_console.py': b'# authenticated runtime controller\n',
            'bin/sls_mass_notify/sls_runtime_state.php': b'<?php /* authenticated admission */',
            'bin/sls_mass_notify/sls_privileged_install.py': b'# authenticated helper\n',
            'bin/sls_mass_notify/sls_module_trust.py': b'# authenticated trust\n',
            'api/sipnotify/index.php': b'<?php /* authenticated API */',
            'api/sls-mass-notify/index.php': b'<?php /* authenticated API */',
            'api/sls-mass-notify/.htaccess': b'Options -Indexes\n',
            'api/sls-mass-notify/sms/Service.php': b'<?php require __DIR__."/vendor/provider/Validator.php";',
            'api/sls-mass-notify/sms/vendor/provider/Validator.php': b'<?php echo "nested API readable";',
            'bin/sls_mass_notify/package/helpers/reader.py': b'# approved nested runtime\n',
            'assets/css/sls.css': b'/* approved CSS */',
            'sounds/tones/default.wav': b'RIFF approved fixture bytes',
        }
        private = self.base / 'key.pem'
        subprocess.run(['/usr/bin/openssl', 'genpkey', '-algorithm', 'ED25519', '-out', str(private)], check=True, capture_output=True)
        public = subprocess.run(['/usr/bin/openssl', 'pkey', '-in', str(private), '-pubout'], check=True, capture_output=True).stdout
        patch = mock.patch.object(TRUST, 'PUBLIC_KEY', public)
        patch.start(); self.addCleanup(patch.stop)
        archive = self.base / 'slsmassnotifyserver-0.1.5-beta.tgz'
        with tarfile.open(archive, 'w:gz') as output:
            for name, body in self.package.items():
                info = tarfile.TarInfo('slsmassnotifyserver/' + name)
                info.size = len(body)
                output.addfile(info, io.BytesIO(body))
        manifest = self.base / 'manifest.json'
        manifest.write_text(json.dumps({'schema':1, 'version':'0.1.5-beta', 'tag':'slsmassnotifyserver-0.1.5-beta', 'package': archive.name, 'package_sha256':TRUST.digest(archive.read_bytes())}))
        signature = self.base / 'manifest.sig'
        subprocess.run(['/usr/bin/openssl','pkeyutl','-sign','-rawin','-inkey',str(private),'-in',str(manifest),'-out',str(signature)],check=True,capture_output=True)
        TRUST.enroll_sls(self.store, archive, manifest, signature)
        self.fs.mkdir(INSTALL.DATA, 0o750, user=True)
        self.configuration = b'{"log_retention_days":30,"paging_pin":"5432","schedule":"preserved"}\n'
        self.fs.write(INSTALL.CONFIG, self.configuration, 0o640, user=True)
        self.action = self.new_action()
    def new_action(self):
        return INSTALL.PrivilegedInstall(TRUST, self.store, files=self.fs, run=self.services)
    def test_timezone_applies_only_a_verified_configured_iana_name(self):
        self.action.prepare(); self.services.calls.clear()
        config=json.loads(self.configuration);config['pbx_timezone']='America/Chicago'
        self.fs.write(INSTALL.CONFIG,json.dumps(config).encode(),0o640,user=True)
        self.action.timezone()
        self.assertEqual([call[0] for call in self.services.calls],[['/usr/bin/timedatectl','set-timezone','America/Chicago']])
        for value in ('../etc/passwd','America/Chicago; touch /tmp/unsafe','',None,True):
            config['pbx_timezone']=value;self.fs.write(INSTALL.CONFIG,json.dumps(config).encode(),0o640,user=True)
            self.services.calls.clear()
            with self.assertRaises(INSTALL.InstallError):self.action.timezone()
            self.assertEqual(self.services.calls,[])
    def test_timezone_rejects_untrusted_runtime_and_unsafe_config(self):
        self.action.prepare();self.services.calls.clear()
        config=json.loads(self.configuration);config['pbx_timezone']='America/Chicago'
        self.fs.write(INSTALL.CONFIG,json.dumps(config).encode(),0o666,user=True)
        with self.assertRaises(INSTALL.InstallError):self.action.timezone()
        self.assertEqual(self.services.calls,[])
    def test_prepare_preserves_state_and_uses_only_authenticated_source(self):
        self.fs.write(INSTALL.DATA + '/history.json', b'prior delivery receipts', 0o600)
        self.fs.write(INSTALL.WEB + '/admin/modules/slsmassnotifyserver/bin/evil.sh', b'never execute web code', 0o755, user=True)
        self.fs.write(INSTALL.DATA + '/sounds/tones/default.wav', b'administrator custom audio', 0o644, user=True)
        self.fs.mkdir(INSTALL.RUNTIME + '/piper', 0o755)
        self.fs.write(INSTALL.RUNTIME + '/piper/installed', b'preserved dependency', 0o644)
        self.action.prepare()
        self.assertEqual(self.fs.read(INSTALL.CONFIG), self.configuration)
        self.assertEqual(self.fs.read(INSTALL.DATA + '/history.json'), b'prior delivery receipts')
        self.assertEqual(self.fs.metadata(INSTALL.DATA + '/history.json').st_uid, self.fs.account.pw_uid)
        self.assertEqual(self.fs.read(INSTALL.DATA + '/sounds/tones/default.wav'), b'administrator custom audio')
        self.assertEqual(self.fs.read(INSTALL.RUNTIME + '/piper/installed'), b'preserved dependency')
        self.assertEqual(self.fs.read(INSTALL.RUNTIME + '/sls_mass_notify_update.sh'), self.package['bin/sls_mass_notify_update.sh'])
        self.assertIn(b'/root/independent-backup', self.services.cron)
        before = self.services.cron
        self.action.prepare()
        self.assertEqual(self.services.cron, before)
        self.assertTrue(all(call[0][0] == '/usr/bin/crontab' for call in self.services.calls))
    def test_event_recovery_evidence_stays_private_during_state_repair(self):
        self.fs.mkdir(INSTALL.DATA + '/event-log-recovery', 0o700, user=True)
        self.fs.write(INSTALL.DATA + '/event-log-recovery/original.backup', b'preserved evidence', 0o600, user=True)
        self.fs.secure_tree(INSTALL.DATA)
        directory = self.fs.path(INSTALL.DATA + '/event-log-recovery')
        self.assertEqual(directory.stat().st_mode & 0o777, 0o700)
        self.assertEqual((directory / 'original.backup').stat().st_mode & 0o777, 0o600)
        self.assertEqual((directory / 'original.backup').read_bytes(), b'preserved evidence')
        self.fs.mkdir(INSTALL.DATA + '/sms', 0o700, user=True)
        self.fs.write(INSTALL.DATA + '/sms/deliveries.sqlite', b'fixture opaque state', 0o600, user=True)
        self.fs.secure_tree(INSTALL.DATA)
        self.assertEqual(stat.S_IMODE(self.fs.path(INSTALL.DATA + '/sms').stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE(self.fs.path(INSTALL.DATA + '/sms/deliveries.sqlite').stat().st_mode), 0o600)
        for folder in ('recovery-archives', '.freepbx-restore-stage-fixture'):
            self.fs.mkdir(INSTALL.DATA + '/' + folder, 0o700, user=True)
            self.fs.write(INSTALL.DATA + '/' + folder + '/evidence', b'recovery material', 0o600, user=True)
            self.fs.secure_tree(INSTALL.DATA)
            self.assertEqual(stat.S_IMODE(self.fs.path(INSTALL.DATA + '/' + folder).stat().st_mode), 0o700)
            self.assertEqual(stat.S_IMODE(self.fs.path(INSTALL.DATA + '/' + folder + '/evidence').stat().st_mode), 0o600)

    def test_nested_api_directories_are_traversable_under_restrictive_umask(self):
        # Apache's existing document-root ancestors belong to the platform.
        for path in ('/', '/var', '/var/www', '/var/www/html', '/var/www/html/api'):
            self.fs.mkdir(path, 0o755)
        previous = os.umask(0o077)
        try:
            self.action.prepare()
            for path, user in self.action.copy_directories():
                metadata = self.fs.path(path).stat()
                self.assertEqual(stat.S_IMODE(metadata.st_mode), 0o755, path)
                self.assertEqual(metadata.st_uid, self.fs.account.pw_uid if user else 0, path)
            # Reproduce the old installer state and prove repair handles it.
            directory = self.fs.path(INSTALL.WEB + '/api/sls-mass-notify/sms/vendor')
            os.chown(directory, 0, 0); os.chmod(directory, 0o750)
            self.action.prepare()
            self.assertEqual(stat.S_IMODE(directory.stat().st_mode), 0o755)
            self.assertEqual(directory.stat().st_uid, self.fs.account.pw_uid)
            self.assertEqual(stat.S_IMODE(self.fs.path(INSTALL.DATA).stat().st_mode), 0o750)
            self.assertEqual(stat.S_IMODE(self.fs.path(INSTALL.CONFIG).stat().st_mode), 0o640)
            if os.geteuid() == 0:
                # Only disposable fixture parents become traversable. Execute
                # the installed nested PHP include as the real service account.
                for parent in [self.base, self.fs.prefix]: os.chmod(parent, 0o755)
                result = subprocess.run(['/usr/sbin/runuser', '-u', self.fs.account.pw_name, '--',
                    '/usr/bin/php', str(self.fs.path(INSTALL.WEB + '/api/sls-mass-notify/sms/Service.php'))],
                    capture_output=True, text=True, timeout=5)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout, 'nested API readable')
        finally:
            os.umask(previous)

    def test_changed_or_extra_generation_code_fails_before_destinations(self):
        _, generation = TRUST.load(self.store, 'slsmassnotifyserver')
        path = generation / 'module/bin/extra.sh'
        path.write_bytes(b'unapproved')
        with self.assertRaisesRegex(INSTALL.InstallError, 'additional code'): self.new_action()
        self.assertFalse(self.fs.path(INSTALL.RUNTIME).exists())
        path.unlink()
        path = generation / 'module/bin/sls_mass_notify_update.sh'
        path.write_bytes(b'altered')
        with self.assertRaisesRegex(INSTALL.InstallError, 'changed'): self.new_action()
        self.assertEqual(self.services.calls, [])
    def test_known_legacy_aliases_removed_without_following_targets(self):
        self.fs.mkdir(INSTALL.DATA + '/links', 0o750, user=True)
        links = self.fs.path(INSTALL.DATA + '/links')
        victim = self.base / 'unrelated-target'
        victim.write_bytes(b'preserve unrelated bytes'); victim.chmod(0o600)
        (links / 'mass-notify-api').symlink_to(victim)
        (links / 'administrator-note').write_bytes(b'preserved note')
        self.action.prepare()
        self.assertFalse((links / 'mass-notify-api').is_symlink())
        self.assertEqual(victim.read_bytes(), b'preserve unrelated bytes')
        self.assertEqual(stat.S_IMODE(victim.stat().st_mode), 0o600)
        self.assertEqual((links / 'administrator-note').read_bytes(), b'preserved note')
        (links / 'unknown-link').symlink_to(victim)
        with self.assertRaisesRegex(INSTALL.InstallError, 'unsafe SLS state entry'):
            self.action.prepare()
        self.assertTrue((links / 'unknown-link').is_symlink())
    def test_unsafe_existing_file_types_do_not_touch_link_victim(self):
        for kind in ('symlink','hardlink','fifo'):
            with self.subTest(kind=kind):
                victim = self.base / ('victim-' + kind)
                victim.write_bytes(b'preserve victim'); victim.chmod(0o600)
                target = self.fs.path(INSTALL.RUNTIME + '/sls_mass_notify_update.sh')
                target.parent.mkdir(parents=True, exist_ok=True)
                if kind == 'symlink': target.symlink_to(victim)
                elif kind == 'hardlink': os.link(victim,target)
                else: os.mkfifo(target)
                with self.assertRaises((INSTALL.InstallError,OSError)): self.action.prepare()
                self.assertEqual(victim.read_bytes(),b'preserve victim')
                self.assertEqual(stat.S_IMODE(victim.stat().st_mode),0o600)
                target.unlink()
    def test_parent_symlink_cannot_redirect_root_install(self):
        target = self.fs.path('/usr/local/bin')
        target.parent.mkdir(parents=True)
        elsewhere = self.base / 'elsewhere'; elsewhere.mkdir()
        target.symlink_to(elsewhere)
        with self.assertRaises(OSError): self.action.prepare()
        self.assertEqual(list(elsewhere.iterdir()),[])
    def test_state_hardlink_never_changes_outside_owner_or_permissions(self):
        victim = self.base / 'victim'; victim.write_bytes(b'private'); victim.chmod(0o600)
        path = self.fs.path(INSTALL.DATA + '/receipt.json'); os.link(victim,path)
        with self.assertRaisesRegex(INSTALL.InstallError,'unsafe SLS state'): self.action.prepare()
        self.assertEqual(victim.stat().st_uid,0)
        self.assertEqual(stat.S_IMODE(victim.stat().st_mode),0o600)
    def test_unrelated_service_configuration_is_not_overwritten(self):
        for path in (INSTALL.SERVICE_PATH,INSTALL.APACHE_PATH,INSTALL.LOGROTATE_PATH):
            self.fs.write(path,b'administrator unrelated content',0o644)
            with self.assertRaisesRegex(INSTALL.InstallError,'unrelated'): self.action.prepare()
            self.assertEqual(self.fs.read(path),b'administrator unrelated content')
            self.fs.path(path).unlink()
    def test_dependencies_reject_changed_runtime_before_execution(self):
        self.action.prepare(); self.services.calls.clear()
        self.fs.path(INSTALL.RUNTIME + '/sls_mass_notify_install_piper_voices.sh').write_bytes(b'untrusted')
        with self.assertRaisesRegex(INSTALL.InstallError,'differs'): self.action.dependencies()
        self.assertEqual(self.services.calls,[])
    def test_dependency_failure_is_propagated_without_service_activation(self):
        self.action.prepare(); self.services.calls.clear()
        self.services.fail = '--runtime-only'
        with self.assertRaisesRegex(INSTALL.InstallError,'fixture'): self.action.dependencies()
        self.assertEqual(len(self.services.calls),1)
        self.assertEqual(self.services.calls[0][0],['/bin/bash',INSTALL.RUNTIME + '/sls_mass_notify_install_piper_voices.sh','--runtime-only','--workers-paused'])
    def test_activation_and_root_verification_never_bootstrap_pbx(self):
        self.action.prepare(); self.action.dependencies(); self.action.activate(); self.assertTrue(self.action.verify())
        self.assertTrue(any(args == ['/usr/bin/systemctl','restart',INSTALL.SERVICE] for args,_ in self.services.calls))
        self.assertFalse(any(any('php' in item or 'fwconsole' in item for item in args) for args,_ in self.services.calls))
        self.assertEqual(self.fs.read(INSTALL.CONFIG),self.configuration)
    def test_root_runtime_rejects_service_owned_or_writable_parent(self):
        parent = self.fs.path('/usr/local/bin')
        parent.mkdir(parents=True)
        os.chown(parent,self.fs.account.pw_uid,self.fs.account.pw_gid)
        parent.chmod(0o775)
        with self.assertRaisesRegex(INSTALL.InstallError,'ancestor'): self.action.prepare()
        self.assertEqual(parent.stat().st_uid,self.fs.account.pw_uid)
        self.assertFalse((parent/'sls_mass_notify').exists())
    def test_scheduling_waits_until_activation_succeeds(self):
        before = self.services.cron
        self.action.prepare()
        self.assertEqual(self.services.cron,before)
        self.services.fail = 'restart'
        with self.assertRaises(INSTALL.InstallError): self.action.activate()
        self.assertEqual(self.services.cron,before)
        self.services.fail = None
        self.action.activate()
        self.assertTrue(all(line.encode() in self.services.cron for line in INSTALL.CRON_LINES))
    def test_actual_isolated_cli_does_not_write_generation_bytecode(self):
        entry = self.base/'entrypoint'
        entry.mkdir(mode=0o700)
        for name in ('sls_privileged_install.py','sls_module_trust.py','sls_config_crypto.py'):
            (entry/name).write_bytes((BIN/name).read_bytes())
            (entry/name).chmod(0o600)
        env = dict(os.environ); env.pop('PYTHONDONTWRITEBYTECODE',None)
        result = subprocess.run(['/usr/bin/python3','-I',str(entry/'sls_privileged_install.py'),'--trust-root',str(self.store),'plan'],env=env,capture_output=True,text=True,timeout=5)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertFalse(any(self.base.rglob('__pycache__')))
        self.assertFalse(json.loads(result.stdout)['php_bootstrap'])
    def test_large_existing_log_keeps_bytes_and_requires_only_safe_metadata(self):
        path = self.fs.path('/var/log/sls_mass_notify.log'); path.parent.mkdir(parents=True)
        with path.open('wb') as output:
            output.write(b'preserved log beginning')
            output.truncate(80*1024*1024)
        self.action.prepare()
        self.assertEqual(path.stat().st_size,80*1024*1024)
        with path.open('rb') as input: self.assertEqual(input.read(23),b'preserved log beginning')
    def test_system_runner_bounds_output_during_execution_and_propagates_errors(self):
        for fd in (1,2):
            with self.subTest(fd=fd):
                with self.assertRaisesRegex(INSTALL.InstallError,'exceeded 1 MiB'):
                    INSTALL.runner(['/usr/bin/python3','-I','-c',f'import os; os.write({fd},b"x"*(2*1024*1024))'],timeout=2)
        with self.assertRaises(subprocess.TimeoutExpired):
            INSTALL.runner(['/usr/bin/python3','-I','-c','import time; time.sleep(2)'],timeout=0.05)
        result=INSTALL.runner(['/usr/bin/python3','-I','-c','import sys; sys.stdout.buffer.write(sys.stdin.buffer.read())'],input=b'preserved root cron input\n')
        self.assertEqual(result.stdout,b'preserved root cron input\n')
        with self.assertRaisesRegex(INSTALL.InstallError,'exit 23: concrete failure'):
            INSTALL.runner(['/usr/bin/python3','-I','-c','import sys; print("concrete failure",file=sys.stderr); sys.exit(23)'])
    def test_protected_dependencies_receive_only_a_safe_standard_scratch_directory(self):
        with mock.patch.dict(os.environ, {'TMPDIR':'/var/tmp'}):
            result = INSTALL.runner(['/usr/bin/python3','-I','-c','import os; print(os.environ["TMPDIR"])'])
            self.assertEqual(result.stdout.strip(),b'/var/tmp')
        with mock.patch.dict(os.environ, {'TMPDIR':'/home/asterisk/writable'}):
            with self.assertRaisesRegex(INSTALL.InstallError,'unsupported'):
                INSTALL.runner(['/bin/true'])
    def test_bad_retention_is_rejected_before_root_changes(self):
        for value in ('1\n/etc/shadow {}', 366, True, 0):
            self.fs.write(INSTALL.CONFIG,json.dumps({'log_retention_days':value}).encode(),0o640,user=True)
            with self.assertRaises(INSTALL.InstallError): self.action.prepare()
            self.assertFalse(self.fs.path(INSTALL.RUNTIME).exists())
    def test_compatibility_venv_is_never_chowned_or_traversed(self):
        self.fs.mkdir(INSTALL.DATA + '/piper/venv',0o755)
        directory = self.fs.path(INSTALL.DATA + '/piper/venv')
        (directory/'approved-link').symlink_to('/unavailable/root/runtime')
        with self.assertRaises(INSTALL.InstallError): self.action.prepare()
        self.assertEqual(directory.stat().st_uid,0)
        self.assertTrue((directory/'approved-link').is_symlink())
        self.assertFalse(self.fs.path(INSTALL.RUNTIME).exists())
    def test_no_crontab_error_does_not_overwrite_inaccessible_schedule(self):
        def bad(args,**kwargs): return subprocess.CompletedProcess(args,1,b'',b'permission denied')
        self.action.run=bad
        with self.assertRaisesRegex(INSTALL.InstallError,'read root crontab'): self.action.root_cron(apply=True)

    def legacy_alias(self):
        self.fs.mkdir(INSTALL.DATA + '/piper',0o750,user=True)
        self.fs.mkdir(INSTALL.DATA + '/piper/venv',0o755,user=True)
        self.fs.mkdir(INSTALL.DATA + '/piper/venv/bin',0o755,user=True)
        directory=self.fs.path(INSTALL.DATA + '/piper/venv')
        (directory/'bin/piper').symlink_to('/usr/local/bin/piper')
        os.chown(directory/'bin/piper',self.fs.account.pw_uid,self.fs.account.pw_gid,follow_symlinks=False)
        return directory

    def test_exact_legacy_service_owned_alias_is_recreated_and_recovery_metadata_retained(self):
        directory=self.legacy_alias(); old_inode=directory.stat().st_ino
        self.fs.write(INSTALL.DATA+'/receipts.json',b'preserved current receipts',0o640,user=True)
        self.action.preflight()
        self.assertEqual(directory.stat().st_uid,self.fs.account.pw_uid)
        self.action.prepare()
        self.assertNotEqual(directory.stat().st_ino,old_inode)
        for path in (directory,directory/'bin'):
            self.assertEqual((path.stat().st_uid,path.stat().st_gid,stat.S_IMODE(path.stat().st_mode)),(0,0,0o755))
        self.assertEqual(os.readlink(directory/'bin/piper'),'/usr/local/bin/piper')
        self.assertEqual((directory/'bin/piper').lstat().st_uid,0)
        saved=list(self.fs.path('/var/lib/sls-mass-notify-piper-migrations').glob('*.json'))
        self.assertEqual(len(saved),1)
        self.assertEqual(json.loads(saved[0].read_bytes())['layout']['venv']['uid'],self.fs.account.pw_uid)
        self.assertEqual(self.fs.read(INSTALL.DATA+'/receipts.json'),b'preserved current receipts')
        self.assertEqual(self.fs.read(INSTALL.CONFIG),self.configuration)
        self.action.prepare(); self.assertEqual(len(list(saved[0].parent.glob('*.json'))),1)

    def test_legacy_alias_with_real_environment_or_wrong_target_is_rejected_before_runtime_copy(self):
        directory=self.legacy_alias()
        (directory/'pyvenv.cfg').write_bytes(b'unknown executable environment')
        with self.assertRaises(INSTALL.InstallError): self.action.prepare()
        self.assertEqual(directory.stat().st_uid,self.fs.account.pw_uid)
        self.assertFalse(self.fs.path(INSTALL.RUNTIME).exists())
        (directory/'pyvenv.cfg').unlink(); (directory/'bin/piper').unlink(); (directory/'bin/piper').symlink_to('/tmp/untrusted-piper')
        with self.assertRaises(INSTALL.InstallError): self.action.prepare()
        self.assertEqual(os.readlink(directory/'bin/piper'),'/tmp/untrusted-piper')

    def test_known_wrapper_without_execute_bit_passes_preflight_without_executing_it(self):
        script=(BIN.parent/'sls_mass_notify_install_piper_voices.sh').read_bytes()
        import re
        expected=re.search(rb"expected=\"\$\(cat <<'EOF'\n(.*?)\nEOF\n\)\"",script,re.S).group(1)+b'\n'
        self.action.contents['bin/sls_mass_notify_install_piper_voices.sh']=script
        self.fs.write('/usr/local/bin/piper',expected,0o644)
        self.action.preflight()
        self.assertEqual(stat.S_IMODE(self.fs.metadata('/usr/local/bin/piper').st_mode),0o644)
        self.assertEqual(self.services.calls,[])
        self.fs.write('/usr/local/bin/piper',expected+b'unreviewed command\n',0o644)
        with self.assertRaises(INSTALL.InstallError): self.action.preflight()

    def test_alias_can_be_restored_after_failed_prepare_and_migrated_on_retry(self):
        directory=self.legacy_alias()
        recovery=module('sls_installer_recovery')
        private=self.base/'recovery';private.mkdir(mode=0o700)
        command=lambda args,check=True: 'LoadState=not-found\nUnitFileState=not-found\nActiveState=inactive'
        helper=recovery.Recovery(private/'static',self.fs.prefix,cron=lambda body=None:b'',user_cron=lambda body=None:b'',command=command)
        helper.snapshot_create()
        self.action.prepare()
        helper.restore()
        self.assertEqual(directory.stat().st_uid,self.fs.account.pw_uid)
        self.assertEqual((directory/'bin/piper').lstat().st_uid,self.fs.account.pw_uid)
        self.action.prepare()
        self.assertEqual(directory.stat().st_uid,0)
        self.assertEqual(self.fs.read(INSTALL.CONFIG),self.configuration)

if __name__ == '__main__': unittest.main()
