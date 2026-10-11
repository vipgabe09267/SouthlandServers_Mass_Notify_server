#!/usr/bin/env python3
"""Offline authenticated teardown fixtures; never uninstall the host PBX."""
import importlib.util
import fcntl
import json
import os
from pathlib import Path
import pwd
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import unittest

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'


def load(name):
    spec = importlib.util.spec_from_file_location('uninstall_fixture_' + name, RUNTIME / (name + '.py'))
    value = importlib.util.module_from_spec(spec); spec.loader.exec_module(value)
    return value


TRUST, CRYPTO = load('sls_module_trust'), load('sls_config_crypto')


class OfflineUninstaller(unittest.TestCase):
    def setUp(self):
        self.workspace = tempfile.TemporaryDirectory(prefix='sls-uninstall-offline-', dir='/run')
        self.addCleanup(self.workspace.cleanup)
        self.base = Path(self.workspace.name); self.base.chmod(0o755)
        self.account = pwd.getpwnam('asterisk')
        self.store = self.base / 'trust'
        self.installed = self.base / 'runtime'; self.installed.mkdir(mode=0o700)
        self.web = self.base / 'web'
        self.data = self.base / 'data'; self.data.mkdir(mode=0o750)
        os.chown(self.data, self.account.pw_uid, self.account.pw_gid)
        self.names = {
            'bin/sls_mass_notify_uninstall.sh': ROOT / 'tools/uninstall_release.sh',
            'bin/sign_sls_mass_notify_local_sig.sh': ROOT / 'slsmassnotifyserver/bin/sign_sls_mass_notify_local_sig.sh',
            'bin/sls_mass_notify/sls_module_trust.py': RUNTIME / 'sls_module_trust.py',
            'bin/sls_mass_notify/sls_config_crypto.php': RUNTIME / 'sls_config_crypto.php',
            'PhoneEventService.php': ROOT / 'slsmassnotifyserver/PhoneEventService.php',
        }
        for name in ('sls_install_guard', 'sls_install_idle', 'sls_audio_state', 'sls_config_crypto', 'sls_legacy_permissions'):
            self.names['bin/sls_mass_notify/' + name + '.py'] = RUNTIME / (name + '.py')
        files = {name: path.read_bytes() for name, path in self.names.items()}
        # Only private channel/spool/module locations are adapted. The actual
        # authenticated idle checker, guard and all safety branches run intact.
        self.channels = self.base / 'channels.txt'; self.channels.write_text('')
        self.outgoing = self.base / 'outgoing'; self.outgoing.mkdir()
        self.spool_tmp = self.base / 'spool-tmp'; self.spool_tmp.mkdir()
        self.proc = self.base / 'stopped-proc'; self.proc.mkdir()
        self.unit = self.base / 'collector.service'
        idle_name = 'bin/sls_mass_notify/sls_install_idle.py'
        idle_source = files[idle_name].decode().replace("['/usr/sbin/asterisk', '-rx', 'core show channels concise']", "['/usr/bin/cat', " + repr(str(self.channels)) + "]")
        idle_source = idle_source.replace('/var/spool/asterisk/outgoing', str(self.outgoing)).replace('/var/spool/asterisk/tmp', str(self.spool_tmp))
        idle_source = idle_source.replace('/var/www/html/admin/modules/slsmassnotifyserver/module.xml', str(self.web / 'admin/modules/slsmassnotifyserver/module.xml'))
        files[idle_name] = idle_source.encode()
        data = {'schema': 1, 'module': 'slsmassnotifyserver', 'version': '0.1.5-beta',
                'source': {'kind': 'publisher-ed25519', 'archive_sha256': 'a' * 64},
                'files': {name: {'sha256': TRUST.digest(body), 'target': 'admin/modules/slsmassnotifyserver/' + name}
                          for name, body in files.items()}}
        raw = json.dumps(data).encode()
        self.generation = self.store / TRUST.store_manifest(self.store, data, raw)
        for name, body in files.items():
            target = self.generation / 'module' / name
            target.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
            target.write_bytes(body); target.chmod(0o600)
            installed = self.installed / Path(name).name
            installed.write_bytes(body); installed.chmod(0o700)
        # Replace only absolute deployment locations in a private script copy.
        # Authentication, source validation and helper snapshots remain real.
        body = (ROOT / 'tools/uninstall_release.sh').read_text()
        body = body.replace('/var/lib/sls-mass-notify-trust', str(self.store))
        body = body.replace('/var/www/html', str(self.web))
        body = body.replace('/etc/systemd/system/sls-mass-notify-phone-events.service', str(self.unit))
        body = body.replace("Path('/proc')", 'Path(' + repr(str(self.proc)) + ')')
        body = body.replace("os.readlink('/proc/' + name + '/exe')", 'os.readlink(' + repr(str(self.proc) + '/') + " + name + '/exe')")
        for name, path in {
            'bin/sls_mass_notify_uninstall.sh': '/usr/local/bin/sls_mass_notify/sls_mass_notify_uninstall.sh',
            'bin/sign_sls_mass_notify_local_sig.sh': '/usr/local/sbin/sign_sls_mass_notify_local_sig.sh',
            'bin/sls_mass_notify/sls_module_trust.py': '/usr/local/bin/sls_mass_notify/sls_module_trust.py',
            'bin/sls_mass_notify/sls_config_crypto.php': '/usr/local/bin/sls_mass_notify/sls_config_crypto.php',
            **{'bin/sls_mass_notify/' + name + '.py': '/usr/local/bin/sls_mass_notify/' + name + '.py'
               for name in ('sls_install_guard', 'sls_install_idle', 'sls_audio_state', 'sls_config_crypto', 'sls_legacy_permissions')},
        }.items():
            body = body.replace(path, str(self.installed / Path(name).name))
        body = body.replace(' /usr/local/bin/sls_mass_notify \\\n', ' ' + str(self.installed) + ' \\\n')
        self.script = self.base / 'private-uninstall.sh'; self.script.write_text(body); self.script.chmod(0o700)

    def shell(self, body, *, env=None):
        return subprocess.run(['bash', '-c', 'source "$1"\nDATA_DIR="$2"\n' + body,
                               'fixture', str(self.script), str(self.data)],
                              capture_output=True, text=True, timeout=20,
                              env=dict(os.environ, **(env or {})))

    def test_signed_teardown_accepts_missing_operational_and_required_helpers(self):
        for path in self.installed.iterdir(): path.unlink()
        missing_installer = self.installed / 'sls_privileged_install.py'
        self.assertFalse(missing_installer.exists())
        result = self.shell('admit_root_runtime\nprintf "%s\\n" "$UNINSTALL_APPROVED_SOURCE"\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), str(self.generation / 'module'))
        self.assertFalse(missing_installer.exists())
        self.assertFalse((self.installed / 'sls_phone_events.py').exists())

    def test_tampered_and_linked_teardown_helpers_are_rejected(self):
        path = self.installed / 'sls_config_crypto.php'
        path.write_text('<?php /* changed */')
        result = self.shell('admit_root_runtime\n')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('differs from its signed release', result.stderr)
        path.unlink(); path.symlink_to(self.generation / 'module/bin/sls_mass_notify/sls_config_crypto.php')
        self.assertNotEqual(self.shell('admit_root_runtime\n').returncode, 0)

    def test_snapshot_and_ami_capture_survive_runtime_deletion(self):
        settings = {'ami': {'username': 'fixture_sls_ami', 'password': 'not-output'}}
        config = self.data / 'mass-notifications.config'
        CRYPTO._atomic_write(config, CRYPTO.encode_config(settings), self.account.pw_uid, self.account.pw_gid)
        before = config.stat(); content = config.read_bytes()
        result = self.shell('''admit_root_runtime
trap cleanup_recovery_signer EXIT
snapshot_local_signer
rm -rf "$(dirname "$RECOVERY_CONFIG_DECODER")/nonexistent"
rm -rf "''' + str(self.installed) + '''"
capture_configured_ami_username
printf '%s\\n' "$CONFIGURED_AMI_USERNAME"
test -x "$RECOVERY_SIGNER"
test "$(stat -c '%a %U:%G' "$RECOVERY_SIGNER")" = '700 root:root'
test "$(stat -c '%a %U:%G' "$RECOVERY_CONFIG_DECODER")" = '640 root:asterisk'
bash -n "$RECOVERY_SIGNER"
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), 'fixture_sls_ami')
        self.assertEqual(config.read_bytes(), content)
        self.assertEqual((config.stat().st_ino, config.stat().st_mode), (before.st_ino, before.st_mode))
        source = self.script.read_text()
        for first, last in [('remove_freepbx_manager_users()', 'remove_bundled_system_recordings()'),
                            ('verify_freepbx_cleanup()', 'verify_stock_module()')]:
            function = source[source.index(first):source.index(last)]
            self.assertNotIn('sls_config_crypto.php', function)
            self.assertIn('SLS_CONFIGURED_AMI_USERNAME', function)

    def test_default_preserves_full_history_in_place_and_purge_is_explicit(self):
        for purge in ['0', '1']:
            with self.subTest(purge=purge):
                path = self.data / 'phone-admission.json'; path.write_text('{"history":"retained"}')
                os.chown(path, self.account.pw_uid, self.account.pw_gid); path.chmod(0o644)
                before = path.stat(); content = path.read_bytes()
                # All host paths are intercepted. Only the disposable DATA
                # directory may be removed, exercising the actual purge branch.
                result = self.shell('''PURGE_CONFIG="$SLS_TEST_PURGE"
remove_piper_wrapper() { :; }
remove_managed_sound_link() { :; }
rm() {
  local value
  for value in "$@"; do
    if [ "$value" = "$DATA_DIR" ]; then /bin/rm -rf -- "$DATA_DIR"; fi
  done
}
remove_runtime_files
''', env={'SLS_TEST_PURGE': purge})
                self.assertEqual(result.returncode, 0, result.stderr)
                if purge == '1': self.assertFalse(self.data.exists())
                else:
                    self.assertEqual(path.read_bytes(), content)
                    self.assertEqual((path.stat().st_ino, path.stat().st_mode), (before.st_ino, before.st_mode))

    def test_new_and_legacy_cron_jobs_removed_without_touching_other_jobs(self):
        fixture = self.base / 'cron'; fixture.mkdir()
        jobs = ['* * * * * /usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_cron_runner.py maintenance >/dev/null 2>&1',
                '* * * * * /bin/bash /usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh',
                '* * * * * /usr/bin/php /usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php',
                '* * * * * /usr/bin/php /usr/local/bin/sls_mass_notify/sls_mass_notify_announcement_worker.php --reconcile',
                '17 * * * * /usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_cron_runner.py update >/dev/null 2>&1',
                '* * * * * /usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_cron_runner.py weather >/dev/null 2>&1',
                '* * * * * /usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_cron_runner.py scheduling >/dev/null 2>&1',
                '* * * * * /usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_cron_runner.py announcement_reconcile >/dev/null 2>&1',
                '0 4 * * * /usr/local/bin/independent-backup']
        for user in ['root', 'asterisk']: (fixture / user).write_text('\n'.join(jobs) + '\n')
        result = self.shell('''crontab() {
  if [ "$3" = '-l' ]; then cat "$SLS_TEST_CRON/$2"; else cp -- "$3" "$SLS_TEST_CRON/$2"; fi
}
remove_cron
''', env={'SLS_TEST_CRON': str(fixture)})
        self.assertEqual(result.returncode, 0, result.stderr)
        for user in ['root', 'asterisk']:
            self.assertEqual((fixture / user).read_text(), '0 4 * * * /usr/local/bin/independent-backup\n')

    def test_database_errors_do_not_look_like_absent_module_registration(self):
        result = self.shell('''module_registry_exists() { return 2; }
rm() { printf 'UNSAFE DELETE\\n'; }
remove_module_registration
''')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('registry could not be read', result.stdout)
        self.assertNotIn('UNSAFE DELETE', result.stdout)

    def test_cron_read_failures_never_authorize_any_replacement(self):
        result = self.shell('''crontab() {
  if [ "$3" = '-l' ]; then
    if [ "$2" = root ]; then printf '* * * * * /usr/local/bin/sls_mass_notify/sls_cron_runner.py maintenance >/dev/null 2>&1\\n'; return 0; fi
    printf 'temporary spool failure\\n' >&2; return 2
  fi
  printf 'UNSAFE CRON REPLACEMENT\\n'
}
remove_cron
''')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('could not be read', result.stdout)
        self.assertNotIn('UNSAFE CRON REPLACEMENT', result.stdout)

    def test_exact_absent_crontab_does_not_need_an_empty_replacement(self):
        result = self.shell('''crontab() {
  if [ "$3" = '-l' ]; then printf 'no crontab for %s\\n' "$2" >&2; return 1; fi
  printf 'UNEXPECTED CRON REPLACEMENT\\n'; return 9
}
remove_cron
verify_removed_cron
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn('UNEXPECTED CRON REPLACEMENT', result.stdout)

    def test_cron_write_failure_is_reported_and_originals_retained(self):
        result = self.shell('''crontab() {
  if [ "$3" = '-l' ]; then printf '* * * * * /usr/local/bin/sls_mass_notify/sls_cron_runner.py maintenance >/dev/null 2>&1\\n'; return 0; fi
  return 2
}
remove_cron
''')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Original crontabs were retained', result.stdout)

    def test_cron_verification_reads_long_output_without_sigpipe_false_negative(self):
        result = self.shell('''crontab() {
  printf '* * * * * /usr/local/bin/sls_mass_notify/sls_cron_runner.py maintenance >/dev/null 2>&1\\n'
  for ((i=0; i<10000; i++)); do printf '0 4 * * * /usr/local/bin/unrelated-job-%d\\n' "$i"; done
}
verify_removed_cron
''')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('scheduled job remains', result.stdout)

    def prepare_web_inventories(self):
        module = self.web / 'admin/modules/slsmassnotifyserver'
        shutil.copytree(self.generation / 'module', module)
        for name in ['dashboard', 'framework']:
            directory = self.web / 'admin/modules' / name; directory.mkdir()
            files = {'safe.php': b'<?php /* independently reviewed stock file */'}
            uninstall = {'remove': [], 'replace': {}}
            if name == 'dashboard':
                files['sections/SlsMassNotifyAnnouncement.class.php'] = b'<?php /* approved SLS widget */'
                uninstall['remove'] = ['sections/SlsMassNotifyAnnouncement.class.php']
            else:
                files['amp_conf/htdocs/admin/views/menu_items.php'] = b'<?php /* reviewed menu with SLS entry */'
                uninstall['replace'] = {'amp_conf/htdocs/admin/views/menu_items.php': TRUST.digest(b'<?php /* reviewed menu after SLS removal */')}
            data = {'schema': 1, 'module': name, 'version': '17.0',
                    'source': {'kind': 'reviewed-upstream-and-overlays', 'archive_sha256': 'b' * 64},
                    'files': {}, 'uninstall': uninstall}
            for filename, body in files.items():
                target = filename[16:] if filename.startswith('amp_conf/htdocs/') else 'admin/modules/' + name + '/' + filename
                path = self.web / target; path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(body)
                data['files'][filename] = {'sha256': TRUST.digest(body), 'target': target}
            TRUST.store_manifest(self.store, data, json.dumps(data).encode())

    def inventory_shell(self, body):
        return self.shell('''admit_root_runtime
trap cleanup_recovery_signer EXIT
snapshot_local_signer
module_registry_exists() { test ! -e "$DATA_DIR/registry-absent"; }
''' + body)

    def test_interrupted_teardown_can_resume_only_exact_approved_uninstall_variants(self):
        self.prepare_web_inventories()
        result = self.inventory_shell('''verify_uninstall_inventories
rm -rf "''' + str(self.web / 'admin/modules/slsmassnotifyserver') + '''"
touch "$DATA_DIR/registry-absent"
rm "''' + str(self.web / 'admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php') + '''"
printf '<?php /* reviewed menu after SLS removal */' >"''' + str(self.web / 'admin/views/menu_items.php') + '''"
false
''')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.inventory_shell('verify_uninstall_inventories\n').returncode, 0)
        (self.web / 'admin/modules/dashboard/safe.php').write_bytes(b'<?php /* unknown local change */')
        result = self.inventory_shell('verify_uninstall_inventories\n')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('approved installed nor approved uninstall', result.stdout)

    def test_absent_module_with_present_registry_still_refuses_teardown(self):
        self.prepare_web_inventories()
        shutil.rmtree(self.web / 'admin/modules/slsmassnotifyserver')
        result = self.inventory_shell('verify_uninstall_inventories\n')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('registry row remains', result.stdout)

    def test_stopped_pbx_does_not_start_or_require_reload_after_removal(self):
        result = self.shell('''PBX_RUNNING=0
run_fwconsole() { printf 'UNEXPECTED PBX COMMAND\\n'; return 9; }
reload_removed_pbx_integration
test "$PBX_RELOAD_WARNING" = 1
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('SLS removal was verified', result.stdout)
        self.assertIn('run fwconsole reload', result.stdout)
        self.assertNotIn('UNEXPECTED PBX COMMAND', result.stdout)

    def test_running_pbx_reload_failure_reports_removal_and_retains_log(self):
        log = self.base / 'reload.log'
        result = self.shell('''PBX_RUNNING=1
MODULE_UNINSTALL_LOG="$SLS_TEST_LOG"
MODULE_UNINSTALL_LOG_PATH="$SLS_TEST_LOG"
run_fwconsole() { test "$1" = reload; printf 'unrelated PBX reload failure\\n'; return 9; }
reload_removed_pbx_integration
test "$PBX_RELOAD_WARNING" = 1
''', env={'SLS_TEST_LOG': str(log)})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('SLS removal was verified', result.stdout)
        self.assertIn('PBX integration reload failed', result.stdout)
        self.assertEqual(log.read_text(), 'unrelated PBX reload failure\n')

    def test_read_only_pbx_precheck_and_verification_precede_reload(self):
        source = self.script.read_text(); main = source[source.index('main() {'):]
        self.assertLess(main.index('capture_pbx_running_state'), main.index('remove_phone_collector_service'))
        self.assertLess(main.index('verify_freepbx_cleanup'), main.index('reload_removed_pbx_integration'))
        self.assertNotIn('systemctl start', source[source.index('capture_pbx_running_state()'):source.index('capture_configured_ami_username()')])

    def test_preflight_authentication_and_capture_precede_destructive_steps(self):
        source = self.script.read_text(); main = source[source.index('main() {'):]
        self.assertLess(main.index('admit_root_runtime'), main.index('acquire_maintenance_coordination'))
        self.assertLess(main.index('capture_configured_ami_username'), main.index('remove_module_registration'))
        self.assertNotIn('preserve_user_data\n', main)
        self.assertNotIn('curl ', main); self.assertNotIn('apt-get ', main)
        self.assertLess(main.index('verify_uninstall_inventories'), main.index('remove_phone_collector_service'))
        self.assertLess(main.index('verify_uninstall_cron_access'), main.index('remove_module_registration'))
        self.assertLess(main.index('acquire_uninstall_workers'), main.index('inspect_uninstall_idle'))
        self.assertLess(main.index('inspect_uninstall_idle'), main.index('remove_phone_collector_service'))
        self.assertLess(main.index('assert_uninstall_workers'), main.index('remove_phone_collector_service'))
        self.assertEqual((ROOT / 'tools/uninstall_release.sh').read_bytes(),
                         (ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_uninstall.sh').read_bytes())

    def guarded_shell(self, body, *, running='1'):
        return self.shell('''admit_root_runtime
trap cleanup_recovery_signer EXIT
snapshot_local_signer
PBX_RUNNING=''' + running + '''
acquire_uninstall_workers
inspect_uninstall_idle
''' + body)

    def state(self, name, value, mode=0o640):
        path = self.data / name; path.write_text(json.dumps(value))
        os.chown(path, self.account.pw_uid, self.account.pw_gid); path.chmod(mode)
        return path

    def collector_unit_body(self):
        import re
        service = (ROOT / 'slsmassnotifyserver/PhoneEventService.php').read_bytes()
        statement = re.search(rb"return <<<'UNIT'\n.*?\nUNIT;", service, re.S)[0]
        # Interpret the actual fixed heredoc with PHP so this fixture does not
        # repeat the snapshot parser's assumptions about trailing newlines.
        return subprocess.run(['/usr/bin/php', '-r', statement.replace(b'return', b'echo', 1).decode()],
                              check=True, capture_output=True, timeout=3).stdout

    def test_signed_unit_snapshot_matches_actual_php_template_bytes(self):
        result = self.shell('''admit_root_runtime
trap cleanup_recovery_signer EXIT
snapshot_local_signer
cat "$RECOVERY_SIGNER_DIR/collector-unit.txt"
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.encode(), self.collector_unit_body())

    def test_offline_guards_preserve_state_without_privileged_installer_or_collector(self):
        for path in self.installed.iterdir(): path.unlink()
        config = self.data / 'mass-notifications.config'
        CRYPTO._atomic_write(config, CRYPTO.encode_config({'enterprise_cluster': {'enabled': False}}), self.account.pw_uid, self.account.pw_gid)
        lock = self.data / 'mass-notifications.config.lock'; lock.touch(); os.chown(lock, self.account.pw_uid, self.account.pw_gid); lock.chmod(0o640)
        batch = {'status': 'closed', 'service': 'announcement', 'generation': 'b' * 32, 'boot_id': 'fixture-boot',
                 'targets': {'1000': {'dials': ['PJSIP/fixture']}}, 'channels': {},
                 **{key: 1 for key in ('created_at', 'updated_at', 'start_deadline', 'created_tick', 'updated_tick', 'start_deadline_tick')}}
        history = self.state('phone-admission.json', {'schema': 1, 'collector': None, 'batches': {'a' * 32: batch}})
        phone_lock = self.data / 'phone-admission.lock'; phone_lock.touch(); os.chown(phone_lock, self.account.pw_uid, self.account.pw_gid); phone_lock.chmod(0o640)
        originals = [(path, path.read_bytes(), path.stat().st_ino) for path in (config, lock, history, phone_lock)]
        result = self.guarded_shell('''assert_uninstall_workers
test ! -e "''' + str(self.installed / 'sls_privileged_install.py') + '''"
/usr/bin/python3 -I - "$DATA_DIR" <<'PY'
import fcntl, os, sys
from pathlib import Path
for name in ('enterprise-worker.lock', 'announcement-activity.lock', 'schedule-runner.lock', 'live-paging-slots/global-15.lock'):
    with (Path(sys.argv[1]) / name).open('r+b') as fd:
        try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError: continue
        raise SystemExit('A delivery lease was not held: ' + name)
# FreePBX bootstrap may still read/take its independent configuration mutex;
# the outer activity lease fences writes without nested bootstrap deadlock.
with (Path(sys.argv[1]) / 'mass-notifications.config.lock').open('r+b') as fd:
    fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
PY
release_uninstall_workers
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        for path, content, inode in originals:
            self.assertEqual(path.read_bytes(), content); self.assertEqual(path.stat().st_ino, inode)
        with (self.data / 'announcement-activity.lock').open('r+b') as fd:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)

    def test_known_legacy_worker_mode_is_tightened_without_inode_replacement(self):
        lock = self.data / 'enterprise-worker.lock'; lock.touch(); lock.chmod(0o644)
        os.chown(lock, self.account.pw_uid, self.account.pw_gid); inode = lock.stat().st_ino
        result = self.guarded_shell('assert_uninstall_workers\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(lock.stat().st_mode & 0o777, 0o640); self.assertEqual(lock.stat().st_ino, inode)

    def test_prior_signed_idle_helper_without_new_keyword_remains_usable(self):
        name = 'bin/sls_mass_notify/sls_install_idle.py'
        previous = self.generation
        bodies = {key: (previous / 'module' / key).read_bytes() for key in self.names}
        source = bodies[name].decode().replace(', *, allow_pending_voicemail=False', '')
        source = source.replace('if not allow_pending_voicemail and self.pending_voicemail(batch):', 'if False and self.pending_voicemail(batch):')
        self.assertNotIn('allow_pending_voicemail', source)
        bodies[name] = source.encode()
        inventory = json.loads((previous / 'inventory.json').read_bytes())
        inventory['files'][name]['sha256'] = TRUST.digest(bodies[name])
        self.generation = self.store / TRUST.store_manifest(self.store, inventory, json.dumps(inventory).encode())
        for key, body in bodies.items():
            target = self.generation / 'module' / key; target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            target.write_bytes(body); target.chmod(0o600)
        (self.installed / 'sls_install_idle.py').write_bytes(bodies[name])
        result = self.guarded_shell('assert_uninstall_workers\n')
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_guard_drains_existing_worker_before_allowing_teardown(self):
        lock = self.data / 'enterprise-worker.lock'; lock.touch(); lock.chmod(0o640)
        os.chown(lock, self.account.pw_uid, self.account.pw_gid)
        fd = lock.open('r+b'); fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
        process = subprocess.Popen(['bash', '-c', 'source "$1"\nDATA_DIR="$2"\nadmit_root_runtime\ntrap cleanup_recovery_signer EXIT\nsnapshot_local_signer\nacquire_uninstall_workers\nprintf "GUARD READY\\n"\n', 'fixture', str(self.script), str(self.data)], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        try:
            time.sleep(.25); self.assertIsNone(process.poll())
            fd.close(); out, err = process.communicate(timeout=10)
            self.assertEqual(process.returncode, 0, err); self.assertIn('GUARD READY', out)
        finally:
            fd.close()
            if process.poll() is None: process.kill(); process.communicate()

    def test_live_unknown_and_pending_delivery_state_blocks_without_mutation(self):
        cases = [
            ('channel', 'Local/1000@sls-phone-origin!' + '!'.join(['active'] * 12)),
            ('phone-admission.json', {'schema': 1, 'batches': {'b' * 32: {'status': 'active'}}}),
            ('phone-admission.json', {'schema': 1, 'batches': {'b' * 32: {'status': 'reserved'}}}),
            ('phone-admission.json', {'schema': 1, 'batches': []}),
            ('audio-reservations.json', {'recipients': {'1000': time.time() + 600}, 'media': {}}),
        ]
        for name, value in cases:
            with self.subTest(name=name, value=value):
                if name == 'channel': self.channels.write_text(value); path = self.channels
                else: path = self.state(name, value)
                before = path.read_bytes(); inode = path.stat().st_ino
                result = self.guarded_shell('printf "UNSAFE TEARDOWN\\n"\n')
                self.assertNotEqual(result.returncode, 0); self.assertNotIn('UNSAFE TEARDOWN', result.stdout)
                self.assertEqual(path.read_bytes(), before); self.assertEqual(path.stat().st_ino, inode)
                if name == 'channel': self.channels.write_text('')
                else: path.unlink()
        jobs = self.data / 'announcement-jobs'; jobs.mkdir()
        pending = jobs / ('pending_job_' + 'c' * 32 + '.mark'); pending.touch()
        self.assertNotEqual(self.guarded_shell('printf "UNSAFE TEARDOWN\\n"\n').returncode, 0)
        self.assertTrue(pending.exists()); pending.unlink()
        call = self.outgoing / 'sls_fixture.call'; call.write_text('Retain this queued call')
        self.assertNotEqual(self.guarded_shell('printf "UNSAFE TEARDOWN\\n"\n').returncode, 0)
        self.assertEqual(call.read_text(), 'Retain this queued call')

    def test_unsafe_worker_links_and_changed_retained_guard_are_rejected(self):
        victim = self.base / 'victim'; victim.touch(); victim.chmod(0o640)
        os.chown(victim, self.account.pw_uid, self.account.pw_gid)
        lock = self.data / 'enterprise-worker.lock'
        for kind in ('symlink', 'hardlink', 'oversized', 'wrong-owner'):
            with self.subTest(kind=kind):
                if kind == 'symlink': lock.symlink_to(victim)
                elif kind == 'hardlink': os.link(victim, lock)
                else:
                    lock.write_bytes(b'x' * 5000 if kind == 'oversized' else b'')
                    lock.chmod(0o640)
                    os.chown(lock, self.account.pw_uid if kind == 'oversized' else 12345, self.account.pw_gid)
                self.assertNotEqual(self.guarded_shell('printf "UNSAFE TEARDOWN\\n"\n').returncode, 0)
                lock.unlink()
        signed = self.generation / 'module/bin/sls_mass_notify/sls_install_guard.py'; signed.write_text('raise SystemExit(0)')
        self.assertNotEqual(self.guarded_shell('printf "UNSAFE TEARDOWN\\n"\n').returncode, 0)

    def test_guard_death_is_reported_and_retry_reacquires_same_lock_inode(self):
        result = self.guarded_shell('''kill "$UNINSTALL_WORKER_PID"
wait "$UNINSTALL_WORKER_PID" || true
assert_uninstall_workers
printf 'UNSAFE TEARDOWN\\n'
''')
        self.assertNotEqual(result.returncode, 0); self.assertIn('guard exited', result.stdout)
        self.assertNotIn('UNSAFE TEARDOWN', result.stdout)
        inode = (self.data / 'announcement-activity.lock').stat().st_ino
        result = self.guarded_shell('assert_uninstall_workers\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((self.data / 'announcement-activity.lock').stat().st_ino, inode)

    def test_stopped_pbx_is_inspected_without_channel_command(self):
        self.channels.write_text('This must not be read for a genuinely stopped PBX')
        result = self.guarded_shell('assert_uninstall_workers\n', running='0')
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_running_pbx_with_unavailable_control_socket_never_bypasses_calls(self):
        process = self.proc / '123'; process.mkdir(); (process / 'exe').symlink_to('/usr/sbin/asterisk')
        result = self.guarded_shell('printf "UNSAFE TEARDOWN\\n"\n', running='0')
        self.assertNotEqual(result.returncode, 0); self.assertIn('channel inventory is unavailable', result.stderr)
        self.assertNotIn('UNSAFE TEARDOWN', result.stdout)

    def test_parent_death_releases_worker_leases_without_deleting_inodes(self):
        process = subprocess.Popen(['bash', '-c', 'source "$1"\nDATA_DIR="$2"\nadmit_root_runtime\ntrap cleanup_recovery_signer EXIT\nsnapshot_local_signer\nacquire_uninstall_workers\nprintf "GUARD READY\\n"\nread -r ignored\n',
                                    'fixture', str(self.script), str(self.data)], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, start_new_session=True)
        try:
            while True:
                line = process.stdout.readline()
                self.assertTrue(line, 'Guard exited before readiness')
                if line == 'GUARD READY\n': break
            lock = self.data / 'announcement-activity.lock'; inode = lock.stat().st_ino
            process.kill(); process.wait(timeout=5)
            deadline = time.monotonic() + 5
            with lock.open('r+b') as fd:
                while True:
                    try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB); break
                    except BlockingIOError:
                        self.assertLess(time.monotonic(), deadline, 'Guard lease survived parent death')
                        time.sleep(.02)
            self.assertEqual(lock.stat().st_ino, inode)
        finally:
            try: os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError: pass
            process.wait(timeout=5)
            for pipe in (process.stdin, process.stdout, process.stderr): pipe.close()

    def test_collector_stop_rechecks_final_calls_before_deleting_service(self):
        self.unit.write_bytes(self.collector_unit_body()); self.unit.chmod(0o644)
        commands = self.base / 'commands'; commands.mkdir()
        systemctl = commands / 'systemctl'
        state = self.base / 'collector-state'; state.write_text('active')
        calls = self.base / 'systemctl-calls'
        systemctl.write_text('''#!/bin/bash
printf '%s\\n' "$*" >>"$SLS_TEST_CALLS"
case "$1:$2" in
  stop:*) printf 'inactive' >"$SLS_TEST_STATE"; printf '%s\\n' 'Local/1000@sls-phone-origin!a!b!c!d!e!f!g!h!i!j!k' >"$SLS_TEST_CHANNELS" ;;
  start:*) printf 'active' >"$SLS_TEST_STATE" ;;
  show:--property=ActiveState) cat "$SLS_TEST_STATE" ;;
  show:--property=FragmentPath) printf '%s\\n' "$SLS_TEST_UNIT" ;;
  show:--property=DropInPaths) : ;;
  show:--property=UnitFileState) printf 'enabled\\n' ;;
  *) exit 9 ;;
esac
''')
        systemctl.chmod(0o700)
        result = self.shell('''admit_root_runtime
trap cleanup_recovery_signer EXIT
snapshot_local_signer
PBX_RUNNING=1
acquire_uninstall_workers
inspect_uninstall_idle
remove_phone_collector_service
printf 'UNSAFE MODULE REMOVAL\\n'
''', env={'PATH': str(commands) + ':/usr/sbin:/usr/bin:/bin', 'SLS_TEST_CHANNELS': str(self.channels),
         'SLS_TEST_STATE': str(state), 'SLS_TEST_UNIT': str(self.unit), 'SLS_TEST_CALLS': str(calls)})
        self.assertNotEqual(result.returncode, 0); self.assertNotIn('UNSAFE MODULE REMOVAL', result.stdout)
        self.assertTrue(self.unit.exists()); self.assertIn('notification channel is active', result.stderr)
        self.assertEqual(state.read_text(), 'active'); self.assertIn('start ', calls.read_text())
        self.assertNotIn('disable ', calls.read_text()); self.assertNotIn('enable ', calls.read_text())

    def test_data_absent_purge_retry_requires_removed_entry_points(self):
        shutil.rmtree(self.data); shutil.rmtree(self.installed)
        result = self.shell('''admit_root_runtime
trap cleanup_recovery_signer EXIT
snapshot_local_signer
module_registry_exists() { return 1; }
PBX_RUNNING=0
acquire_uninstall_workers
inspect_uninstall_idle
assert_uninstall_workers
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(self.data.exists())
        self.installed.symlink_to(self.base / 'absent-runtime')
        result = self.shell('''admit_root_runtime
trap cleanup_recovery_signer EXIT
snapshot_local_signer
module_registry_exists() { return 1; }
acquire_uninstall_workers
''')
        self.assertNotEqual(result.returncode, 0)
        self.installed.unlink(); self.installed.mkdir()
        result = self.shell('''admit_root_runtime
trap cleanup_recovery_signer EXIT
snapshot_local_signer
module_registry_exists() { return 1; }
acquire_uninstall_workers
''')
        self.assertNotEqual(result.returncode, 0); self.assertIn('entry points remain', result.stdout)

    def test_collector_recovery_preserves_disabled_and_inactive_state(self):
        original = self.collector_unit_body()
        commands = self.base / 'commands'; commands.mkdir()
        systemctl = commands / 'systemctl'; calls = self.base / 'systemctl-calls'; state = self.base / 'collector-state'
        systemctl.write_text('''#!/bin/bash
printf '%s\\n' "$*" >>"$SLS_TEST_CALLS"
case "$1:$2" in
  stop:*) printf 'inactive' >"$SLS_TEST_STATE"; printf '%s\\n' 'Local/1000@sls-phone-origin!a!b!c!d!e!f!g!h!i!j!k' >"$SLS_TEST_CHANNELS" ;;
  start:*) printf 'active' >"$SLS_TEST_STATE" ;;
  show:--property=ActiveState) cat "$SLS_TEST_STATE" ;;
  show:--property=FragmentPath) printf '%s\\n' "$SLS_TEST_UNIT" ;;
  show:--property=DropInPaths) printf '%s' "$SLS_TEST_DROPINS" ;;
  show:--property=UnitFileState) printf '%s\\n' "$SLS_TEST_ENABLED" ;;
  *) exit 9 ;;
esac
'''); systemctl.chmod(0o700)
        for active, enabled, dropins, change in [('active', 'disabled', '', False), ('inactive', 'disabled', '', False),
                                                 ('failed', 'enabled', '', False), ('active', 'masked', '', False),
                                                 ('active', 'enabled', '/etc/systemd/system/local.conf', False),
                                                 ('active', 'enabled', '', True)]:
            with self.subTest(active=active, enabled=enabled, dropins=dropins, changed=change):
                self.unit.write_bytes(original + (b'# local override\n' if change else b'')); self.unit.chmod(0o644)
                state.write_text(active); calls.write_text(''); self.channels.write_text('')
                result = self.shell('''admit_root_runtime
trap cleanup_recovery_signer EXIT
snapshot_local_signer
PBX_RUNNING=1
acquire_uninstall_workers
inspect_uninstall_idle
remove_phone_collector_service
printf 'UNSAFE REMOVAL\\n'
''', env={'PATH': str(commands) + ':/usr/sbin:/usr/bin:/bin', 'SLS_TEST_CHANNELS': str(self.channels),
         'SLS_TEST_STATE': str(state), 'SLS_TEST_UNIT': str(self.unit), 'SLS_TEST_CALLS': str(calls),
         'SLS_TEST_ENABLED': enabled, 'SLS_TEST_DROPINS': dropins})
                self.assertNotEqual(result.returncode, 0); self.assertNotIn('UNSAFE REMOVAL', result.stdout)
                self.assertTrue(self.unit.exists()); commands_run = calls.read_text()
                self.assertNotIn('enable ', commands_run); self.assertNotIn('disable ', commands_run)
                unsupported = enabled == 'masked' or dropins or change
                if unsupported:
                    self.assertNotIn('stop ', commands_run); self.assertEqual(state.read_text(), active)
                elif active == 'active':
                    self.assertIn('start ', commands_run); self.assertEqual(state.read_text(), 'active')
                else:
                    self.assertNotIn('start ', commands_run); self.assertEqual(state.read_text(), 'inactive')


if __name__ == '__main__': unittest.main()
