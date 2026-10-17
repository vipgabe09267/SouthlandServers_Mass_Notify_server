#!/usr/bin/env python3
"""Verify actual inherited storage and disposable overlays without host writes."""
import os
from pathlib import Path
import subprocess
import unittest

if os.environ.get('SLS_TEST_NAMESPACE') != 'entered' or any(
    os.environ.get('SLS_TEST_PARENT_' + label + '_NS') in (None, '', os.readlink('/proc/self/ns/' + kind))
    for label, kind in [('MOUNT', 'mnt'), ('NET', 'net'), ('PID', 'pid')]
):
    raise SystemExit('Use the private fixture runner; no storage probes were made.')


class ReadOnlyIsolation(unittest.TestCase):
    def test_network_fixtures_refuse_missing_empty_and_current_parent_markers(self):
        names = [
            'test_api_security.py', 'test_enterprise_control_http.py', 'test_017_portal_csp.py',
            'test_scheduled_recipient_envelope_ui.py', 'test_voicemail_controls_ui.py',
            'test_schedule_recording_browser_ui.py', 'test_control_idempotency_http.py',
            'test_voicemail_asterisk.py',
            'test_enterprise_cluster_deployment.py', 'test_enterprise_identity_http.py',
            'test_outbound_playback_asterisk.py', 'test_live_paging_asterisk.py',
            'test_operator_portal_http.py', 'test_beta_notice_ui.py',
            'test_enterprise_browser_ui.py', 'test_lightning_voicemail_asterisk.py',
            'test_enterprise_controller_http.py',
            'test_served_php_probe.py', 'test_trigger_actions.py',
            'test_private_channel_handoffs.py', 'test_external_monitor.py',
            'test_installation_doctor.py',
            'test_weather_voicemail_producers.py',
        ]
        current = os.readlink('/proc/self/ns/net')
        for name in names:
            for invalid in [None, '', current]:
                environment = {key: value for key, value in os.environ.items()
                               if not key.startswith('SLS_BUILD_')}
                environment.pop('SLS_TEST_PARENT_NET_NS', None)
                if invalid is not None:
                    environment['SLS_TEST_PARENT_NET_NS'] = invalid
                with self.subTest(fixture=name, marker=invalid):
                    result = subprocess.run(['python3', '-B', str(Path(__file__).with_name(name))],
                                            env=environment, capture_output=True, text=True, timeout=3)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertRegex(result.stdout + result.stderr, 'isolat|private|production Asterisk')

    def test_imported_api_helper_refuses_unverified_network_before_setup(self):
        import importlib.util
        from unittest import mock
        path = Path(__file__).with_name('test_api_security.py')
        spec = importlib.util.spec_from_file_location('private_api_fixture_guard', path)
        helper = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(helper)
        current = os.readlink('/proc/self/ns/net')
        for invalid in [None, '', current]:
            environment = dict(os.environ)
            environment.pop('SLS_TEST_PARENT_NET_NS', None)
            if invalid is not None:
                environment['SLS_TEST_PARENT_NET_NS'] = invalid
            with self.subTest(marker=invalid), mock.patch.dict(os.environ, environment, clear=True):
                fixture = helper.ApiSecurityTests()
                with self.assertRaisesRegex(RuntimeError, 'verified private network'):
                    fixture.start_api()
                self.assertFalse(hasattr(fixture, 'directory'), 'Unverified fixture entered setup')

    def test_storage_fixtures_refuse_missing_empty_and_current_namespace_markers(self):
        before = {kind: os.readlink('/proc/self/ns/' + kind) for kind in ['mnt', 'net', 'pid']}
        for name, interpreter in [('test_installer_dependencies.sh', 'bash'),
                                  ('test_installer_preparation.py', 'python3'),
                                  ('test_weather_audit_regular.py', 'python3'),
                                  ('test_voice_download_bounds.py', 'python3'),
                                  ('test_runtime_account_environment.py', 'python3'),
                                  ('test_served_php_fpm_cache.py', 'python3'),
                                  ('test_enterprise_identity_http.py', 'python3'),
                                  ('test_live_paging_asterisk.py', 'python3'),
                                  ('test_phone_capacity_asterisk.py', 'python3'),
                                  ('test_desktop_http_fleet.py', 'python3'),
                                  ('test_served_php_probe.py', 'python3'),
                                  ('test_trigger_actions.py', 'python3'),
                                  ('test_private_channel_handoffs.py', 'python3'),
                                  ('test_voicemail_asterisk.py', 'python3'),
                                  ('test_lightning_voicemail_asterisk.py', 'python3'),
                                  ('test_weather_voicemail_producers.py', 'python3'),
                                  ('test_device_acceptance.php', 'php'),
                                  ('test_enterprise_cluster_deployment.py', 'python3')]:
            for label, kind in [('MOUNT', 'mnt'), ('NET', 'net'), ('PID', 'pid')]:
                key = 'SLS_TEST_PARENT_' + label + '_NS'
                for invalid in [None, '', before[kind]]:
                    environment = dict(os.environ)
                    environment.pop(key, None)
                    if invalid is not None:
                        environment[key] = invalid
                    with self.subTest(fixture=name, namespace=kind, value=invalid):
                        result = subprocess.run([interpreter, str(Path(__file__).with_name(name))],
                                                env=environment, capture_output=True, text=True, timeout=3)
                        self.assertNotEqual(result.returncode, 0)
                        self.assertRegex(result.stderr, 'isolation verification failed|private fixture runner|run_isolated_tests')
                        self.assertEqual(result.stdout, '')
        self.assertEqual(before, {kind: os.readlink('/proc/self/ns/' + kind) for kind in before})

    def test_original_source_and_host_storage_are_readonly(self):
        source = Path(os.environ['SLS_TEST_READONLY_SOURCE'])
        self.assertTrue(source.is_dir())
        for path in [source, Path('/'), Path('/home'), Path('/etc'), Path('/usr'), Path('/var/www')]:
            if path.is_dir():
                with self.subTest(path=path):
                    self.assertTrue(os.statvfs(path).f_flag & os.ST_RDONLY, 'Inherited host storage remains writable')

    def test_private_workspaces_remain_writable(self):
        for path in ['/tmp', '/run', '/root', '/var/lib/asterisk', '/var/spool/asterisk', '/etc/asterisk', '/var/log']:
            with self.subTest(path=path):
                self.assertFalse(os.statvfs(path).f_flag & os.ST_RDONLY)
        if Path('/dev/shm').is_dir():
            self.assertFalse(os.statvfs('/dev/shm').f_flag & os.ST_RDONLY)


if __name__ == '__main__':
    unittest.main()
