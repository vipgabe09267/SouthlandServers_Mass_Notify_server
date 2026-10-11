#!/usr/bin/env python3
"""Mock all service commands while checking cleanup and health validation."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'tools/install_release.sh').read_text()


class PhoneServiceTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='sls-installer-phone-service-')
        self.root = Path(self.temporary.name)
        self.unit = self.root / 'collector.service'
        self.source = self.root / 'installer.sh'
        self.source.write_text(SOURCE.replace('/etc/systemd/system/sls-mass-notify-phone-events.service', str(self.unit)))
        self.mock = self.root / 'systemctl'
        self.mock.write_text('''#!/bin/sh
printf '%s\\n' "$*" >> "$SLS_PHONE_TEST_ROOT/calls"
case "$1" in
  disable) [ ! -e "$SLS_PHONE_TEST_ROOT/fail-stop" ] ;;
  show) if [ -e "$SLS_PHONE_TEST_ROOT/still-active" ]; then printf 'active\\n'; else printf 'inactive\\n'; fi ;;
  daemon-reload) exit 0 ;;
  *) exit 99 ;;
esac
''')
        self.mock.chmod(0o755)
        self.env = {**os.environ, 'PATH': str(self.root) + ':' + os.environ['PATH'], 'SLS_PHONE_TEST_ROOT': str(self.root)}

    def tearDown(self):
        self.temporary.cleanup()

    def cleanup(self):
        return subprocess.run(['bash', '-c', 'source "$1"; log() { :; }; remove_phone_collector_service', '_', str(self.source)], env=self.env, capture_output=True, text=True, timeout=10)

    def managed(self):
        self.unit.write_text('# Managed by SLS Mass Notify Server\n[Unit]\nDescription=Fixture\n')
        self.unit.chmod(0o644)

    def test_managed_unit_stops_before_removal(self):
        self.managed()
        self.assertEqual(self.cleanup().returncode, 0)
        self.assertFalse(self.unit.exists())
        self.assertEqual((self.root / 'calls').read_text().splitlines(), [
            'disable --now sls-mass-notify-phone-events.service',
            'show --property=ActiveState --value sls-mass-notify-phone-events.service', 'daemon-reload'])

    def test_failed_stop_preserves_recovery_unit(self):
        self.managed()
        (self.root / 'fail-stop').touch()
        self.assertNotEqual(self.cleanup().returncode, 0)
        self.assertTrue(self.unit.exists())

    def test_still_active_unit_is_not_deleted(self):
        self.managed()
        (self.root / 'still-active').touch()
        self.assertNotEqual(self.cleanup().returncode, 0)
        self.assertTrue(self.unit.exists())

    def test_unrelated_or_linked_unit_never_calls_systemd(self):
        self.unit.write_text('[Unit]\nDescription=Other\n')
        self.assertNotEqual(self.cleanup().returncode, 0)
        self.unit.unlink()
        target = self.root / 'unrelated'
        target.write_text('preserve')
        self.unit.symlink_to(target)
        self.assertNotEqual(self.cleanup().returncode, 0)
        self.unit.unlink()
        self.unit.hardlink_to(target)
        self.assertNotEqual(self.cleanup().returncode, 0)
        self.assertEqual(target.read_text(), 'preserve')
        self.assertFalse((self.root / 'calls').exists())

    def test_absent_unit_needs_no_systemd(self):
        self.assertEqual(self.cleanup().returncode, 0)
        self.assertFalse((self.root / 'calls').exists())

    def test_installer_health_schema_rejects_stale_or_missing_evidence(self):
        path = self.root / 'health.json'
        healthy = {'ok': True, 'connected': True, 'heartbeat_age_seconds': 2, 'active_batches': 1, 'reserved_contacts': 25}
        cases = [('health', healthy, True), ('ami', {'ok': True, 'ping': True, 'show_dialplan': True}, True),
                 ('ami', {'ok': True, 'ping': True}, False), ('health', {**healthy, 'heartbeat_age_seconds': 16}, False),
                 ('health', {**healthy, 'heartbeat_age_seconds': True}, False), ('health', {**healthy, 'heartbeat_age_seconds': float('nan')}, False),
                 ('health', {**healthy, 'reserved_contacts': '25'}, False), ('health', {**healthy, 'connected': False}, False)]
        for kind, result, expected in cases:
            with self.subTest(kind=kind, result=result):
                path.write_text(json.dumps(result))
                completed = subprocess.run(['bash', '-c', 'source "$1"; validate_phone_collector_probe_file "$2" "$3"', '_', str(self.source), kind, str(path)], env=self.env, capture_output=True, text=True, timeout=10)
                self.assertEqual(completed.returncode == 0, expected, completed.stderr)

    def test_uninstaller_twins_preserve_authenticated_worker_and_service_ordering(self):
        # Offline teardown has stronger lifecycle requirements than installer
        # rollback. Its actual guard, failure recovery and idle behavior are
        # exercised by test_uninstaller_transaction.py.
        source = (ROOT / 'tools/uninstall_release.sh').read_text()
        self.assertEqual(source, (ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_uninstall.sh').read_text())
        main = source.split('main() {', 1)[1]
        ordered = ['admit_root_runtime', 'snapshot_local_signer', 'verify_uninstall_inventories',
                   'acquire_uninstall_workers', 'inspect_uninstall_idle', 'assert_uninstall_workers',
                   'remove_phone_collector_service', 'remove_freepbx_manager_users',
                   'remove_sls_chown_exclusion', 'remove_runtime_files', 'cleanup_recovery_signer']
        positions = [main.index(step) for step in ordered]
        self.assertEqual(positions, sorted(positions))
        service = source.split('remove_phone_collector_service() {', 1)[1].split('\n}\n', 1)[0]
        self.assertLess(service.index('verify_uninstall_collector_unit'), service.index('systemctl stop'))
        self.assertLess(service.index('systemctl stop'), service.index('inspect_uninstall_idle'))
        self.assertLess(service.index('inspect_uninstall_idle'), service.index('systemctl disable'))
        self.assertLess(service.index('systemctl disable'), service.index('rm -- "$unit"'))
        self.assertIn('/usr/bin/timeout --kill-after=2 20 systemctl stop', service)
        self.assertIn('/usr/bin/timeout --kill-after=2 20 systemctl disable', service)

    def previous_collector(self, mode='modern', enabled='enabled', active='active', overrides=False, healthy=True):
        approval={'source':'fresh-install','files':{}} if mode=='fresh' else {
            'source':'publisher-verified-previous-release','files':{
                '/usr/local/bin/sls_mass_notify/sls_phone_events.py':'a'*64,
                '/usr/local/bin/sls_mass_notify/sls_phone_admission.py':'b'*64} if mode=='modern' else {'/usr/local/bin/sls_mass_notify/old.py':'c'*64}}
        (self.root/'previous-root-approval.json').write_text(json.dumps(approval))
        recovery=self.root/'recovery.py'
        recovery.write_text('import json\nprint('+repr(json.dumps({'ok':True,'service_state':{'enabled':enabled,'active':active}}))+')\n')
        harness=r'''
source "$1"
UPGRADE_TRUST_DIR="$SLS_PHONE_TEST_ROOT"; RECOVERY_TOOL="$SLS_PHONE_TEST_ROOT/recovery.py"
AUTHENTICATED_INSTALLER_APPROVED=1
systemctl() {
 printf '%s\n' "$*" >>"$SLS_PHONE_TEST_ROOT/previous-calls"
 if [ "$1" = show ]; then
  printf 'User=asterisk\nGroup=asterisk\nFragmentPath=%s/collector.service\nDropInPaths=%s\n' "$SLS_PHONE_TEST_ROOT" "$FIXTURE_OVERRIDES"
 fi
}
verify_phone_collector() { printf 'probe\n' >>"$SLS_PHONE_TEST_ROOT/previous-calls"; [ "$FIXTURE_HEALTHY" = 1 ]; }
restore_previous_phone_collector
'''
        result=subprocess.run(['bash','-c',harness,'_',str(self.source)],env={**self.env,
            'FIXTURE_OVERRIDES':'/custom/override.conf' if overrides else '',
            'FIXTURE_HEALTHY':'1' if healthy else '0'},capture_output=True,text=True,timeout=10)
        calls=self.root/'previous-calls'
        output=calls.read_text().splitlines() if calls.exists() else []
        calls.unlink(missing_ok=True)
        return result, output

    def test_previous_collector_is_required_only_for_approved_collector_releases(self):
        for mode in ('fresh','old'):
            result,calls=self.previous_collector(mode)
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual(calls,[])

    def test_healthy_previous_collector_is_verified_without_restart(self):
        result,calls=self.previous_collector()
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(calls,['show sls-mass-notify-phone-events.service --property=User,Group,FragmentPath,DropInPaths','probe'])

    def test_only_enabled_inactive_approved_collector_is_started(self):
        for active in ('inactive','failed'):
            result,calls=self.previous_collector(active=active)
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual(calls[-2:],['start sls-mass-notify-phone-events.service','probe'])
        for enabled in ('disabled','not-found','masked'):
            result,calls=self.previous_collector(enabled=enabled,active='inactive')
            self.assertEqual(result.returncode,1,result.stderr)
            self.assertEqual(calls,[])

    def test_overridden_or_unhealthy_previous_collector_blocks_upgrade(self):
        result,calls=self.previous_collector(active='failed',overrides=True)
        self.assertEqual(result.returncode,1,result.stderr)
        self.assertFalse(any(line.startswith('start ') for line in calls))
        result,calls=self.previous_collector(healthy=False)
        self.assertEqual(result.returncode,1,result.stderr)
        self.assertEqual(calls[-1],'probe')
        self.assertIn('No candidate runtime or maintenance schedule was activated',result.stdout)

    @unittest.skipUnless(os.geteuid()==0,'requires root for protected chown snapshot fixtures')
    def test_failed_pretransaction_restores_original_chown_file_or_absence(self):
        helper=ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_legacy_permissions.py'
        approval=self.root/'previous-root-approval.json'
        approval.write_text(json.dumps({'schema':1,'source':'fresh-install','files':{},'root_jobs':[]}));approval.chmod(0o600)
        for existed in (False,True):
            with self.subTest(existed=existed):
                config=self.root/'freepbx_chown.conf'
                original=b'; preserved local comment\n[blacklist]\ndirectory = /other/private\n'
                if existed: config.write_bytes(original); config.chmod(0o640)
                snapshot=self.root/'freepbx-chown.snapshot';snapshot.unlink(missing_ok=True)
                harness=r'''
source "$1"
UPGRADE_TRUST_DIR="$SLS_PHONE_TEST_ROOT"; LEGACY_PERMISSION_TOOL="$HELPER"
FREEPBX_CHOWN_CONFIG="$SLS_PHONE_TEST_ROOT/freepbx_chown.conf"
AUTHENTICATED_INSTALLER_APPROVED=1; INSTALL_NOTIFICATION_SIDE_EFFECTS=0
record_install_failure() { :; }
protect_freepbx_chown_permissions
trap guard_config_on_exit EXIT
exit 37
'''
                result=subprocess.run(['bash','-c',harness,'_',str(self.source)],env={**self.env,'HELPER':str(helper)},capture_output=True,text=True,timeout=10)
                self.assertEqual(result.returncode,37,result.stdout+result.stderr)
                if existed:
                    self.assertEqual(config.read_bytes(),original)
                    self.assertEqual(config.stat().st_mode & 0o777,0o640)
                    config.unlink()
                else: self.assertFalse(config.exists())

    @unittest.skipUnless(os.geteuid()==0,'requires root for protected chown snapshot fixtures')
    def test_failed_install_preserves_snapshot_when_admin_changes_chown_file(self):
        helper=ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_legacy_permissions.py'
        approval=self.root/'previous-root-approval.json'
        approval.write_text(json.dumps({'schema':1,'source':'fresh-install','files':{},'root_jobs':[]}));approval.chmod(0o600)
        config=self.root/'freepbx_chown.conf';config.write_bytes(b'; original\n')
        harness=r'''
source "$1"
UPGRADE_TRUST_DIR="$SLS_PHONE_TEST_ROOT"; LEGACY_PERMISSION_TOOL="$HELPER"
FREEPBX_CHOWN_CONFIG="$SLS_PHONE_TEST_ROOT/freepbx_chown.conf"
AUTHENTICATED_INSTALLER_APPROVED=1; INSTALL_NOTIFICATION_SIDE_EFFECTS=0
record_install_failure() { :; }
protect_freepbx_chown_permissions
printf '; administrator edit\n' >>"$FREEPBX_CHOWN_CONFIG"
trap guard_config_on_exit EXIT
exit 38
'''
        result=subprocess.run(['bash','-c',harness,'_',str(self.source)],env={**self.env,'HELPER':str(helper)},capture_output=True,text=True,timeout=10)
        self.assertEqual(result.returncode,38,result.stdout+result.stderr)
        self.assertTrue(config.read_bytes().endswith(b'; administrator edit\n'))
        self.assertTrue((self.root/'freepbx-chown.snapshot').exists())
        self.assertIn('original FreePBX chown configuration could not be restored',result.stdout)


if __name__ == '__main__':
    unittest.main()
