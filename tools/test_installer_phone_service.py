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

    def test_uninstallers_keep_the_same_bounded_cleanup(self):
        helper = SOURCE.split('remove_phone_collector_service() {', 1)[1].split('\n}\n', 1)[0]
        for filename in ['tools/uninstall_release.sh', 'slsmassnotifyserver/bin/sls_mass_notify_uninstall.sh']:
            source = (ROOT / filename).read_text()
            self.assertEqual(source.split('remove_phone_collector_service() {', 1)[1].split('\n}\n', 1)[0], helper)
            main = source.split('main() {', 1)[1]
            self.assertLess(main.index('remove_phone_collector_service'), main.index('remove_freepbx_manager_users'))


if __name__ == '__main__':
    unittest.main()
