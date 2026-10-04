#!/usr/bin/env python3
"""Exercise installer rollback against private trees and mocked PBX commands."""
import os
from pathlib import Path
import pwd
import shutil
import stat
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'tools/install_release.sh').read_text()
MODULE = 'slsmassnotifyserver'
HARNESS = r'''
source "$1"
fixture="$2"
scenario="$3"
LOG_FILE="$fixture/commands.log"
CONFIG_FILE="$fixture/config"
CONFIG_SNAPSHOT="$fixture/config.snapshot"
CONFIG_HASH_BEFORE="$(sha256sum "$CONFIG_SNAPSHOT" | cut -d ' ' -f1)"
MODULE_BACKUP_DIR="$fixture/backup"
STAGING_DIR="$fixture/stage"
DOWNLOAD_DIR="$fixture/download"
RECOVERY_DIR="$fixture/recovery"
RECOVERY_TOOL="$fixture/recover.py"
INSTALL_BOOTSTRAP_DIR="$fixture/bootstrap"
MODULE_ACTIVATED=1
STATIC_MUTATION_STARTED=1
HAD_EXISTING_MODULE=1
MODULE_WAS_ENABLED=1
INSTALL_NOTIFICATION_SIDE_EFFECTS=0
[[ "$scenario" != fresh ]] || HAD_EXISTING_MODULE=0
[[ "$scenario" != pre-activation ]] || MODULE_ACTIVATED=0
log() { printf '%s\n' "$*" >>"$fixture/messages.log"; }
ensure_data_directory() { return 0; }
safe_config_restore() {
  [[ "$scenario" != config-failure ]] || return 9
  cp -- "$1" "$2"
}
record_install_failure() {
  printf '%s %s\n' "$INSTALL_EXIT_CODE" "$INSTALL_ERROR_CATEGORY" >"$fixture/failure-marker"
}
sls_as_asterisk() { printf "restored-asterisk-config\n" >>"$LOG_FILE"; }
verify_install_idle_history() { printf "retained-phone-history\n" >>"$LOG_FILE"; }
verify_local_api_route() { printf "read-only-api-health\n" >>"$LOG_FILE"; }
fwconsole() { printf 'FORBIDDEN fwconsole %s\n' "$*" >>"$LOG_FILE"; return 99; }
php() { printf 'FORBIDDEN PHP hook\n' >>"$LOG_FILE"; return 99; }
refresh_module_install() { printf 'FORBIDDEN old install\n' >>"$LOG_FILE"; return 99; }
sign_and_verify_touched_modules() { printf 'FORBIDDEN signer\n' >>"$LOG_FILE"; return 99; }
restore_module_registration() {
  printf 'restore-module-registration\n' >>"$LOG_FILE"
  [[ "$scenario" != registration-failure ]] || return 8
}
if [[ "$scenario" == move-failure ]]; then
  restore_previous_module_tree() { return 19; }
fi
trap guard_config_on_exit EXIT
exit 73
'''


class RollbackTests(unittest.TestCase):
    def run_scenario(self, scenario):
        with tempfile.TemporaryDirectory(prefix='sls-rollback-fixture-') as name:
            fixture = Path(name)
            installed = fixture / 'modules' / MODULE
            installed.mkdir(parents=True)
            (installed / 'payload').write_text('failed new release')
            backup = fixture / 'backup'
            backup.mkdir(mode=0o700)
            if scenario != 'fresh':
                previous = backup / MODULE
                previous.mkdir()
                (previous / 'payload').write_text('original release')
            for directory in ('stage', 'download', 'recovery', 'bootstrap'):
                (fixture / directory).mkdir()
            (fixture/'recovery/module-registration.json').write_text('{"schema":1,"row":null}')
            (fixture/'recover.py').write_text('import pathlib,sys\np=pathlib.Path(sys.argv[2]).parent.parent\nwith (p/"commands.log").open("a") as f: f.write("static-"+sys.argv[3]+"\\n")\n')
            (fixture/'config').write_text('changed by failed install')
            snapshot = fixture/'config.snapshot'
            snapshot.write_text('original protected config')
            (fixture/'delivery-ledger').write_text('new receipt committed during install')
            installer = fixture/'installer.sh'
            installer.write_text(SOURCE.replace('/var/www/html/admin/modules', str(fixture/'modules')))
            result = subprocess.run(['bash', '-c', HARNESS, '_', str(installer), str(fixture), scenario],
                capture_output=True, text=True, timeout=20, env={**os.environ,'SLS_MASS_NOTIFY_MODULE':MODULE})
            self.assertEqual(result.returncode,73,result.stdout+result.stderr)
            self.assertEqual((fixture/'failure-marker').read_text().strip(),'73 install_rollback_failed')
            for path in (backup,snapshot,fixture/'stage',fixture/'recovery',fixture/'bootstrap'):
                self.assertTrue(path.exists(),str(path))
            self.assertFalse((fixture/'download').exists())
            log=(fixture/'commands.log').read_text()
            self.assertNotIn('FORBIDDEN',log)
            self.assertIn('static-restore',log)
            self.assertIn('static-verify',log)
            self.assertIn('restore-module-registration',log)
            self.assertEqual((fixture/'delivery-ledger').read_text(),'new receipt committed during install')
            if scenario != 'config-failure':
                self.assertEqual((fixture/'config').read_text(),'original protected config')
            if scenario == 'fresh': self.assertFalse(installed.exists())
            elif scenario not in ('move-failure','pre-activation'):
                self.assertEqual((installed/'payload').read_text(),'original release')
            if scenario not in ('move-failure','pre-activation'):
                self.assertEqual((backup/'failed-module/payload').read_text(),'failed new release')
            self.assertIn('root maintenance remains disabled',(fixture/'messages.log').read_text())

    def test_static_recovery_never_runs_old_module_hooks(self):
        for scenario in ('normal','fresh','pre-activation','config-failure','registration-failure','move-failure'):
            with self.subTest(scenario=scenario): self.run_scenario(scenario)

@unittest.skipUnless(os.geteuid() == 0, 'Root is required to reproduce root-owned umask027 directories with an unprivileged PHP reader.')
class ApiDirectoryPermissionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-api-permissions-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.root.chmod(0o755)
        self.reader = pwd.getpwnam('nobody')
        self.web = self.root / 'web'; self.web.mkdir(mode=0o711)
        self.web.chmod(0o711)
        self.module = self.root / 'modules' / MODULE
        self.source = self.module / 'api'
        for name in ('sipnotify', 'sls-mass-notify'):
            source = self.source / name
            (source / 'nested').mkdir(parents=True)
            (source / 'index.php').write_text("<?php require __DIR__ . '/nested/body.php';")
            (source / 'nested/body.php').write_text("<?php echo 'api-readable';")
            (source / '.htaccess').write_text('Options -Indexes\n')
        text = (ROOT / 'slsmassnotifyserver/Slsmassnotifyserver.class.php').read_text()
        methods = []
        for name in ('copyRuntimeFile', 'copyRuntimeDirectory'):
            start = text.index('\tprivate function ' + name + '(')
            end = text.index('\n\tprivate function ', start + 1)
            methods.append(text[start:end].replace('private function', 'public function', 1))
        self.php = self.root / 'copy.php'
        self.php.write_text('<?php\numask(0027);\nclass CopyFixture {\n' + '\n'.join(methods) +
            '\n}\n(new CopyFixture())->copyRuntimeDirectory($argv[1], $argv[2], 0644, true, $argv[3] === "public" ? 0755 : null);\n')
        self.installer = self.root / 'installer.sh'
        self.installer.write_text(SOURCE.replace('/var/www/html/admin/modules', str(self.root / 'modules'))
                                       .replace('/var/www/html/api', str(self.web / 'api'))
                                       .replace('/var/www/html/mass-notify', str(self.web / 'mass-notify')))

    def copy(self, name, public=False):
        target = self.web / 'api' / name
        result = subprocess.run(['php', str(self.php), str(self.source/name), str(target), 'public' if public else 'legacy'],
                                capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        return target

    def read_as_web_user(self, target):
        def drop_privileges():
            os.setgroups([])
            os.setgid(self.reader.pw_gid)
            os.setuid(self.reader.pw_uid)
        return subprocess.run(['php', str(target/'index.php')], capture_output=True, text=True,
                              timeout=10, preexec_fn=drop_privileges)

    def prepare_legacy_copy(self):
        # The real FreePBX API parent already exists and is traversable. The
        # legacy copier newly creates only its module-owned children as0750.
        (self.web/'api').mkdir(mode=0o755)
        (self.web/'api').chmod(0o755)
        targets = [self.copy(name) for name in ('sipnotify', 'sls-mass-notify')]
        for target in targets:
            self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o750)
            self.assertEqual(target.stat().st_uid, 0)
            self.assertNotEqual(self.read_as_web_user(target).returncode, 0)
        return targets

    def repair(self):
        return subprocess.run(['bash', '-c', 'source "$1"; repair_restored_api_directory_access', '_', str(self.installer)],
                              capture_output=True, text=True, timeout=10,
                              env={**os.environ, 'SLS_MASS_NOTIFY_MODULE': MODULE})

    def prepare_portal_copy(self):
        source = self.module / 'portal'
        source.mkdir()
        (source / 'index.php').write_text("<?php echo 'portal-readable';")
        (source / '.htaccess').write_text('Options -Indexes\n')
        target = self.web / 'mass-notify'
        result = subprocess.run(['php', str(self.php), str(source), str(target), 'legacy'],
                                capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o750)
        self.assertNotEqual(self.read_as_web_user(target).returncode, 0)
        return target

    def test_new_copy_under_restrictive_umask_is_immediately_readable(self):
        for name in ('sipnotify', 'sls-mass-notify'):
            target = self.copy(name, public=True)
            self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o755)
            self.assertEqual(stat.S_IMODE((target/'nested').stat().st_mode), 0o755)
            result = self.read_as_web_user(target)
            self.assertEqual((result.returncode, result.stdout), (0, 'api-readable'), result.stderr)
        self.assertEqual(stat.S_IMODE(self.web.stat().st_mode), 0o711)

    def test_explicit_public_copy_repairs_existing_owned_directories(self):
        targets = self.prepare_legacy_copy()
        for target in targets:
            self.copy(target.name, public=True)
            self.assertEqual(self.read_as_web_user(target).stdout, 'api-readable')

    def test_real_legacy_rollback_repair_preserves_private_data_and_shared_parents(self):
        targets = self.prepare_legacy_copy()
        protected = self.root/'protected.config'
        protected.write_text('private fixture config'); protected.chmod(0o640)
        before = (protected.read_bytes(), protected.stat().st_mode, self.web.stat().st_mode)
        result = self.repair()
        self.assertEqual(result.returncode, 0, result.stderr)
        for target in targets:
            read = self.read_as_web_user(target)
            self.assertEqual((read.returncode, read.stdout), (0, 'api-readable'), read.stderr)
        self.assertEqual(before, (protected.read_bytes(), protected.stat().st_mode, self.web.stat().st_mode))

    def test_source_mismatch_fails_before_changing_either_api_tree(self):
        targets = self.prepare_legacy_copy()
        (targets[1]/'index.php').write_text('unexpected modified PHP')
        self.assertNotEqual(self.repair().returncode, 0)
        for target in targets:
            self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o750)

    def test_rollback_repairs_portal_and_apis_when_restored_release_has_portal(self):
        targets = self.prepare_legacy_copy()
        portal = self.prepare_portal_copy()
        protected = self.root / 'protected.config'
        protected.write_text('private fixture config'); protected.chmod(0o640)
        before = (protected.read_bytes(), protected.stat().st_mode, self.web.stat().st_mode)
        result = self.repair()
        self.assertEqual(result.returncode, 0, result.stderr)
        for target, expected in [(target, 'api-readable') for target in targets] + [(portal, 'portal-readable')]:
            read = self.read_as_web_user(target)
            self.assertEqual((read.returncode, read.stdout), (0, expected), read.stderr)
        self.assertEqual(before, (protected.read_bytes(), protected.stat().st_mode, self.web.stat().st_mode))

    def test_portal_mismatch_rejects_rollback_before_any_public_permissions_change(self):
        targets = self.prepare_legacy_copy()
        portal = self.prepare_portal_copy()
        (portal / 'index.php').write_text('unexpected portal PHP')
        self.assertNotEqual(self.repair().returncode, 0)
        for target in targets + [portal]:
            self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o750)

    def test_symlink_target_cannot_change_directory_outside_owned_api(self):
        targets = self.prepare_legacy_copy()
        outside = self.root/'outside'; outside.mkdir(mode=0o700)
        shutil.rmtree(targets[1]/'nested')
        (targets[1]/'nested').symlink_to(outside, target_is_directory=True)
        self.assertNotEqual(self.repair().returncode, 0)
        self.assertEqual(stat.S_IMODE(outside.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE(targets[0].stat().st_mode), 0o750)

    def test_hardlinked_or_extra_file_rejects_unverified_tree(self):
        targets = self.prepare_legacy_copy()
        os.link(targets[1]/'index.php', self.root/'hardlink')
        self.assertNotEqual(self.repair().returncode, 0)
        (self.root/'hardlink').unlink()
        (targets[1]/'extra.php').write_text('unpackaged file')
        self.assertNotEqual(self.repair().returncode, 0)
        self.assertEqual(stat.S_IMODE(targets[0].stat().st_mode), 0o750)


if __name__ == '__main__':
    unittest.main()
