#!/usr/bin/env python3
"""Real gate, cache, quota and polling entrypoint; all I/O uses disposable fixtures."""
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
RUNTIME = Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'
sys.path.insert(0, str(RUNTIME))
spec = importlib.util.spec_from_file_location('gate_policy', RUNTIME / 'sls_mass_notify_xweather_poll.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class GatePolicy(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.directory = Path(self.tmp.name)
        for name, value in dict(DATA_DIR=self.directory, STATE_FILE=self.directory/'state.json',
                                STATE_FILE_EXPLICIT=True, LEGACY_STATE_FILE=self.directory/'legacy.json',
                                STATUS_FILE=self.directory/'status.json', QUOTA_STATE_FILE=self.directory/'quota.json',
                                CURRENT_GROUP_ID='area', CURRENT_GROUP_NAME='Area', LAST_RATE_LIMIT={}).items():
            p = patch.object(m, name, value); p.start(); self.addCleanup(p.stop)
        for name in ('log', '_nws_json'):
            p = patch.object(m, name, side_effect=RuntimeError('offline') if name == '_nws_json' else None)
            p.start(); self.addCleanup(p.stop)
        self.cfg = dict(enabled='1', location='40,-100', client_id='fixture', client_secret='fixture',
                        recipients=['1000'], id='area', adaptive_gate_failure_policy='bounded_poll', adaptive_fallback_minutes=30)
        self.now = 10000

    def gate(self, state, now=None, cfg=None):
        return m.adaptive_storm_gate(state, self.now if now is None else now, 5, '', {}, cfg or self.cfg)

    def cache(self, checked, active=False):
        (self.directory/'nws-forecast-gate-area.json').write_text(json.dumps({
            'coverage_schema': 2, 'configuration_identity': m.lightning_area_identity({}, self.cfg), 'checked_at': checked,
            'expires_at': checked+600, 'active': active, 'message': 'fixture forecast'}))

    def poll(self, now, payload=None):
        with patch.dict(os.environ, {'XWEATHER_TEST_NOW': str(now), 'XWEATHER_TEST_EVENT': '', 'XWEATHER_VERIFY_ONLY': '0'}), \
             patch.object(m, 'load_config', return_value=({}, self.cfg)), \
             patch.object(m, 'select_group', return_value=self.cfg), \
             patch.object(m, 'fetch_payload', return_value=payload or {'success': True, 'response': []}) as fetch:
            m.main()
            return fetch.call_count

    def test_default_and_unknown_policies_never_spend(self):
        self.cfg.pop('adaptive_gate_failure_policy')
        self.assertEqual(self.poll(self.now), 0)
        self.assertEqual(m.read_state()['adaptive_gate_status'], 'gate_unavailable')
        self.cfg['adaptive_gate_failure_policy'] = 'unexpected'
        self.assertEqual(self.poll(self.now+300), 0)

    def test_cached_clear_stale_clear_and_unavailable_boundaries(self):
        self.cache(self.now-599)
        state = {}
        self.assertFalse(self.gate(state)[0]); self.assertEqual(state['adaptive_gate_health'], 'cached')
        self.assertFalse(self.gate(state, self.now+1)[0]); self.assertEqual(state['adaptive_gate_health'], 'stale_cache')
        self.assertFalse(self.gate(state, self.now+1201)[0])  # exactly30m old remains usable
        self.assertTrue(self.gate(state, self.now+1202)[0])
        self.assertEqual(state['adaptive_gate_status'], 'fallback')

    def test_fresh_forecast_clear_recovers_and_cached_positive_opens(self):
        state = {}
        self.gate(state)
        with patch.object(m, '_nws_json', side_effect=[
                {'properties': {'forecastGridData': 'https://api.weather.gov/gridpoints/TEST/1,1'}},
                {'properties': {'weather': {'values': [{'validTime': '1970-01-01T00:00:00Z/PT12H', 'value': []}]}}}]):
            self.assertFalse(self.gate(state)[0])
        self.assertEqual(state['adaptive_gate_health'], 'fresh')
        self.assertIsNone(state['adaptive_gate_outage'])
        self.cache(self.now-601, active=True)
        self.assertTrue(self.gate(state)[0])
        self.assertEqual(state['adaptive_gate_health'], 'stale_cache')
        self.assertEqual(state['adaptive_gate_status'], 'active')

    def test_positive_gate_grace_and_recovery_clear_persisted_episode(self):
        state = {}
        self.assertTrue(self.gate(state)[0])
        m.atomic_json_update(m.STATE_FILE, state)
        (self.directory/'nws-lightning-gate-area.json').write_text(json.dumps({'updated_at': self.now, 'active': True, 'events': ['Thunderstorm']}))
        self.assertTrue(self.gate(state)[0]); self.assertIsNone(state['adaptive_gate_outage'])
        m.atomic_json_update(m.STATE_FILE, state)
        self.assertIsNone(m.read_state()['adaptive_gate_outage'])
        self.assertTrue(self.gate(state, self.now+181)[0]); self.assertEqual(state['adaptive_gate_status'], 'grace')
        self.assertTrue(self.gate(state, self.now+301)[0]); self.assertEqual(state['adaptive_gate_status'], 'fallback')
        self.cache(self.now+302)
        self.assertFalse(self.gate(state, self.now+302)[0]); self.assertIsNone(state['adaptive_gate_outage'])

    def test_real_polling_interval_budget_restart_and_recovery(self):
        self.assertEqual(self.poll(self.now), 1)
        self.assertEqual(self.poll(self.now+1), 0)
        first = m.read_state()['adaptive_gate_outage']
        self.assertEqual(first['attempts'], 1)
        self.cfg['adaptive_fallback_minutes'] = 120  # unrelated save cannot renew allowance
        for index in range(1, 6):
            self.assertEqual(self.poll(self.now+300*index), 1)
        self.assertEqual(self.poll(self.now+1800), 0)
        state = m.read_state()
        self.assertEqual(state['adaptive_gate_outage']['deadline'], first['deadline'])
        self.assertEqual(state['adaptive_gate_outage']['attempts'], 6)
        status = m.read_json_object(m.STATUS_FILE)['xweather_groups']['area']
        self.assertEqual(status['xweather_gate_status'], 'fallback_exhausted')
        self.assertEqual(status['xweather_fallback_attempts'], 6)
        self.assertEqual(status['last_xweather_poll_status'], 'fallback_exhausted')
        self.cache(self.now+1801)
        self.assertEqual(self.poll(self.now+1801), 0)
        self.assertIsNone(m.read_state()['adaptive_gate_outage'])

    def test_real_quota_denial_consumes_no_attempt_and_no_query(self):
        m.atomic_json_update(m.STATUS_FILE, {'xweather_rate_remaining_period': 0})
        self.assertEqual(self.poll(self.now), 0)
        self.assertEqual(m.read_state()['adaptive_gate_outage']['attempts'], 0)
        self.assertEqual(m.read_json_object(m.STATUS_FILE)['last_xweather_poll_status'], 'quota_guard')

    def test_failed_queries_reserved_before_transport_and_clock_no_extension(self):
        with patch.dict(os.environ, {'XWEATHER_TEST_NOW': str(self.now), 'XWEATHER_TEST_EVENT': ''}), \
             patch.object(m, 'load_config', return_value=({}, self.cfg)), \
             patch.object(m, 'select_group', return_value=self.cfg):
            def failure(_):
                self.assertEqual(m.read_state()['adaptive_gate_outage']['attempts'], 1)
                raise RuntimeError('offline provider')
            with patch.object(m, 'fetch_payload', side_effect=failure):
                self.assertEqual(m.main(), 1)
        state = m.read_state()
        self.assertFalse(m.adaptive_gate_outage(state, self.now+1800, self.cfg)[0])
        self.assertFalse(m.adaptive_gate_outage(state, self.now-100, self.cfg)[0])

    def test_outage_never_sends_all_clear_or_rewrites_active_cluster(self):
        self.cfg['adaptive_gate_failure_policy'] = 'standby'
        m.atomic_json_update(m.STATE_FILE, {'active': True, 'notified': True, 'last_observed_at': 1,
                                          'empty_polls': 1, 'last_notification': 1})
        with patch.object(m, 'build_spoken_message', side_effect=AssertionError('outage tried to announce')):
            self.assertEqual(self.poll(self.now), 0)
        state = m.read_state()
        self.assertTrue(state['active']); self.assertTrue(state['notified'])
        self.assertEqual(state['last_observed_at'], 1)

    def test_bounded_transport_makes_one_http_attempt_without_hidden_retries(self):
        settings = dict(self.cfg, radius_miles=25, _bounded_gate_fallback=True)
        with patch.object(m.XWEATHER_OPENER, 'open', side_effect=RuntimeError('transport failure')) as opened, \
             patch.object(m.time, 'sleep', side_effect=AssertionError('hidden retry delay')):
            with self.assertRaisesRegex(RuntimeError, '1 HTTP attempt'):
                m.fetch_payload(settings)
        self.assertEqual(opened.call_count, 1)
        with patch.dict(os.environ, {'XWEATHER_TEST_NOW': str(self.now), 'XWEATHER_TEST_EVENT': ''}), \
             patch.object(m, 'load_config', return_value=({}, self.cfg)), \
             patch.object(m, 'select_group', return_value=self.cfg):
            def malformed(settings):
                self.assertTrue(settings['_bounded_gate_fallback'])
                m.LAST_RATE_LIMIT = {'cost_tokens': 50, 'remaining': 0, 'limit': 15000, 'reset_at': '2100-01-01T00:00:00Z'}
                return {'success': False}
            with patch.object(m, 'fetch_payload', side_effect=malformed):
                self.assertEqual(m.main(), 1)
        self.assertEqual(m.read_json_object(m.STATUS_FILE)['xweather_rate_remaining_period'], 0)
        self.assertEqual(m.read_json_object(m.QUOTA_STATE_FILE)['quota_bucket_tokens'], 450)

    def test_current_forecast_coverage_required_and_bad_data_cannot_renew_episode(self):
        covering = '1970-01-01T00:00:00Z/PT12H'
        invalid_properties = [
            {}, {'weather': {'values': []}},
            {'weather': {'values': [{'validTime': covering, 'value': None}]}},
            {'weather': {'values': [{'validTime': covering, 'value': [None]}]}},
            {'weather': {'values': [{'validTime': covering, 'value': [{}]}]}},
            {'weather': {'values': [{'validTime': '1970-01-01T00:00:00Z/PT1H', 'value': []}]}},
            {'weather': {'values': [{'validTime': '1970-01-02T00:00:00Z/PT1H', 'value': []}]}},
            {'weather': {'values': [{'validTime': 'invalid', 'value': []}]}},
        ]
        for value in (None, True, '0', float('nan'), float('inf'), -1, 101):
            invalid_properties.append({'probabilityOfThunder': {'values': [{'validTime': covering, 'value': value}]}})
        state = {}
        self.gate(state)
        original = dict(state['adaptive_gate_outage'])
        for properties in invalid_properties:
            with self.subTest(properties=properties):
                with self.assertRaises(RuntimeError):
                    m._forecast_indicates_thunder({'properties': properties}, self.now, 3600)
                with patch.object(m, '_nws_json', side_effect=[
                        {'properties': {'forecastGridData': 'https://api.weather.gov/gridpoints/TEST/1,1'}},
                        {'properties': properties}]):
                    self.assertTrue(self.gate(state)[0])
                self.assertEqual(state['adaptive_gate_outage'], original)
        for field, value in [('weather', []), ('probabilityOfThunder', 0)]:
            result = m._forecast_indicates_thunder({'properties': {field: {'values': [{'validTime': covering, 'value': value}]}}}, self.now, 3600)
            self.assertFalse(result[0])

    def test_area_change_replaces_old_cluster_and_equivalent_coordinates_keep_budget(self):
        old_cfg = dict(self.cfg, location='30,-97')
        m.atomic_json_update(m.STATE_FILE, {'configuration_identity': m.lightning_area_identity({}, old_cfg),
            'active': True, 'notified': True, 'last_nws_storm_active': self.now, 'last_query': self.now,
            'last_observed_at': self.now, 'cluster_started': 1, 'empty_polls': 100, 'last_notification': 1})
        with patch.object(m, 'build_spoken_message', side_effect=AssertionError('old cluster emitted clear')):
            self.assertEqual(self.poll(self.now), 1)
            self.assertFalse(m.read_state().get('active', False))
            self.assertFalse(m.read_state().get('notified', False))
            self.assertNotIn('cluster_started', m.read_state())
            self.assertEqual(self.poll(self.now+300), 1)
        episode = m.read_state()['adaptive_gate_outage']
        self.cfg['location'] = ' 40.0, -100.0000 '
        self.assertEqual(self.poll(self.now+600), 1)
        updated = m.read_state()['adaptive_gate_outage']
        self.assertEqual(updated['deadline'], episode['deadline'])
        self.assertEqual(updated['attempts'], 3)

    def test_clock_rollback_cannot_revive_expired_cache_and_reset_allowance(self):
        self.cache(self.now)
        state = {}
        self.assertTrue(self.gate(state, self.now+1801)[0])
        original = dict(state['adaptive_gate_outage'])
        self.assertTrue(self.gate(state, self.now+1000)[0])
        self.assertEqual(state['adaptive_gate_status'], 'fallback')
        self.assertEqual(state['adaptive_gate_outage'], original)
        self.assertFalse(self.gate(state, original['deadline'])[0])
        self.assertFalse(self.gate(state, self.now+1000)[0])
        self.assertEqual(state['adaptive_gate_outage']['deadline'], original['deadline'])

    def test_independent_areas_and_continuous_mode_unchanged(self):
        state_a, state_b = {}, {}
        self.gate(state_a)
        self.gate(state_b, self.now+600)
        self.assertNotEqual(state_a['adaptive_gate_outage']['deadline'], state_b['adaptive_gate_outage']['deadline'])
        self.cfg['adaptive_free_tier'] = '0'
        with patch.object(m, 'adaptive_storm_gate', side_effect=AssertionError('continuous used gate')), \
             patch.object(m, 'reserve_shared_quota', side_effect=AssertionError('continuous used adaptive quota')):
            self.assertEqual(self.poll(self.now), 1)

if __name__ == '__main__':
    unittest.main()
