#!/usr/bin/env python3
"""Dashboard/notifier lifecycle agreement, without changing operational history."""
import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'
sys.path.insert(0, str(RUNTIME))

def load(name):
    spec = importlib.util.spec_from_file_location(name, RUNTIME / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

NOTIFY = load('sls_system_notifications')
STORAGE = load('sls_storage_maintenance')
NOW = 1800000000

def stamp(at):
    return datetime.fromtimestamp(at, timezone.utc).isoformat()

CONFIG = {'enabled': '1', 'nws_zones': [{'id': 'county'}],
          'xweather': {'enabled': True, 'groups': [{'id': 'campus', 'enabled': '1'}]},
          'scheduled_announcements': [{'id': 'bell', 'enabled': True}]}

def php(method, arguments):
    path = json.dumps(str(ROOT / 'slsmassnotifyserver/StatusHealth.php'))
    code = ('require ' + path + '; $a=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);'
            'echo json_encode(\\FreePBX\\modules\\SlsStatusHealth::' + method + '(...$a));')
    result = subprocess.run(['php', '-r', code], input=json.dumps(arguments), text=True, capture_output=True, check=True)
    return json.loads(result.stdout)

class Lifecycle(unittest.TestCase):
    def project(self, status, config=None, now=NOW):
        config = copy.deepcopy(CONFIG if config is None else config)
        before = copy.deepcopy(status)
        py = NOTIFY.project_status(status, config, now)
        actual = php('project', [status, config, now])
        # PHP encodes an empty associative array as [] on this internal boundary.
        if actual.get('xweather_groups') == []:
            actual['xweather_groups'] = {}
        self.assertEqual(actual, py)
        self.assertEqual(status, before, 'Reading health must not erase delivery evidence')
        return py

    def test_delivery_warning_expires_but_source_evidence_is_retained(self):
        status = {'last_fault_at': stamp(NOW - 899), 'last_fault_stage': 'tts', 'last_fault_source': 'nws',
                  'last_delivery_at': stamp(NOW - 899), 'last_delivery_status': 'fault'}
        self.assertTrue(self.project(status)['last_fault_at'])
        older = self.project(status, now=NOW + 2)
        self.assertEqual(older['last_fault_at'], '')
        self.assertEqual(older['last_delivery_status'], '')

    def test_disabled_removed_and_test_sources_are_inapplicable(self):
        base = {'last_fault_at': stamp(NOW), 'last_fault_source': 'nws', 'last_fault_stage': 'api', 'last_fault_group_id': 'county'}
        for config in ({}, dict(CONFIG, nws_zones=[])):
            self.assertEqual(self.project(base, config)['last_fault_at'], '')
        for source in ('test', 'manual_test', 'dry_run'):
            self.assertEqual(self.project(dict(base, last_fault_source=source))['last_fault_at'], '')

    def test_only_matching_newer_health_resolves_poll_fault(self):
        status = {'last_fault_at': stamp(NOW-3600), 'last_fault_source': 'nws', 'last_fault_stage': 'api',
                  'last_fault_group_id': 'county', 'last_poll_status': 'ok', 'last_poll_ok_at': stamp(NOW)}
        self.assertTrue(self.project(status)['last_fault_at'])
        status['nws_groups'] = {'county': {'last_poll_status': 'ok', 'last_poll_ok_at': stamp(NOW)}}
        self.assertEqual(self.project(status)['last_fault_at'], '')

    def test_external_uncertainty_is_not_resolved_by_local_success_or_age(self):
        status = {'last_fault_at': stamp(NOW-3600), 'last_fault_source': 'nws', 'last_fault_stage': 'external',
                  'last_delivery_at': stamp(NOW), 'last_delivery_status': 'queued'}
        self.assertTrue(self.project(status)['last_fault_at'])

    def test_disabled_lightning_and_scheduler_do_not_keep_old_warnings(self):
        status = {'last_xweather_poll_status': 'fault', 'last_xweather_external_status': 'fault',
                  'last_schedule_worker_status': 'fault', 'xweather_groups': {'campus': {
                      'last_xweather_external_status': 'fault', 'last_xweather_delivery_status': 'fault',
                      'last_xweather_delivery_at': stamp(NOW - 3600)}}}
        active = self.project(status)
        self.assertEqual(active['xweather_groups']['campus']['last_xweather_external_status'], 'fault')
        self.assertEqual(active['xweather_groups']['campus']['last_xweather_delivery_status'], '')
        disabled = self.project(status, {'xweather': {'enabled': '0'}})
        self.assertEqual(disabled['last_xweather_external_status'], '')
        self.assertEqual(disabled['last_schedule_worker_status'], '')

    def test_announcement_history_is_distinct_from_current_worker_health(self):
        state = {'state': 'failed', 'updated_at': stamp(NOW-20), 'failure_category': 'worker_bootstrap_failed'}
        probe = {'ok': True, 'bootstrap': True, 'module_loaded': True, 'checked_at': stamp(NOW)}
        self.assertFalse(php('announcementFailure', [state, probe, NOW]))
        state['failure_category'] = 'channel_submission_failed'
        self.assertTrue(php('announcementFailure', [state, probe, NOW]))
        state['finished_at'] = stamp(NOW-901)
        self.assertFalse(php('announcementFailure', [state, probe, NOW]))
        # Updating historical receipt metadata must not reset the warning age.
        state['updated_at'] = stamp(NOW)
        self.assertFalse(php('announcementFailure', [state, probe, NOW]))
        self.assertTrue(php('recent', [stamp(NOW+300), NOW]))

    def test_recent_queue_counts_age_without_deleting_history(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            (directory / 'mass-notifications.config').write_text('{"enabled":"0"}')
            (directory / 'mass-notifications.config').chmod(0o640)
            weather = directory / 'weather-delivery.json'
            external = directory / 'nws-external-deliveries.json'
            weather.write_text(json.dumps({'jobs': {'old': {'state': 'failed', 'updated_at': NOW-3600},
                'new': {'state': 'expired', 'updated_at': NOW, 'deadline_missed': True}}}))
            external.write_text(json.dumps({'deliveries': {'old': {'terminal_status': 'expired', 'completed_at': NOW-3600},
                'new': {'terminal_status': 'expired', 'completed_at': NOW}}}))
            before = [weather.read_bytes(), external.read_bytes()]
            current = STORAGE.storage_summary(directory, now=NOW)
            later = STORAGE.storage_summary(directory, now=NOW+901)
            self.assertEqual(current['expired_external'], 2)
            self.assertEqual(current['recent_expired_external'], 1)
            self.assertEqual(current['recent_expired_weather'], 1)
            self.assertEqual(current['recent_failed_weather'], 0)
            self.assertEqual(later['recent_weather_deadline_misses'], 0)
            self.assertEqual(later['expired_weather'], 1)
            self.assertEqual([weather.read_bytes(), external.read_bytes()], before)
            worker = {'ok': True, 'checked_at': stamp(NOW+901)}
            self.assertEqual(NOTIFY.collect_health_faults(worker, later, now=NOW+901), {})

if __name__ == '__main__':
    unittest.main()
