#!/usr/bin/python3
"""Private provider, clock and polling admission regressions; no network."""
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode = True
RUNTIME = Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'
sys.path.insert(0, str(RUNTIME))
spec = importlib.util.spec_from_file_location('lightning_boundary_fixture', RUNTIME / 'sls_mass_notify_xweather_poll.py')
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class LightningBoundaryTests(unittest.TestCase):
    def settings(self):
        return {'location': '30,-97', 'radius_miles': 5, 'client_id': 'private-fixture',
                'client_secret': 'inert-fixture', '_bounded_gate_fallback': True}

    def response(self, raw):
        response = mock.Mock(status=200, headers={})
        response.read.return_value = raw
        context = mock.MagicMock()
        context.__enter__.return_value = response
        return context

    def test_provider_duplicates_are_rejected(self):
        raw = b'{"success":false,"success":true,"response":[]}'
        with mock.patch.object(worker.XWEATHER_OPENER, 'open', return_value=self.response(raw)):
            with self.assertRaises(RuntimeError):
                worker.fetch_payload(self.settings())

    def test_provider_size_bound_is_enforced_before_parsing(self):
        raw = b'{"success":true,"response":[]}'
        raw += b' ' * (10 * 1024 * 1024 + 1 - len(raw))
        with mock.patch.object(worker.XWEATHER_OPENER, 'open', return_value=self.response(raw)):
            with self.assertRaises(RuntimeError):
                worker.fetch_payload(self.settings())

    def test_clock_rollback_cannot_mint_quota_refill(self):
        status = {'xweather_rate_limit_period': 15000, 'xweather_rate_remaining_period': 14000,
                  'xweather_rate_reset_period': 'private-period', 'xweather_last_query_cost_tokens': 10}
        state = {'quota_reset_marker': 'private-period', 'quota_bucket_tokens': 100,
                 'quota_bucket_updated': 1000}
        with mock.patch.object(worker, 'read_json_object', return_value=status):
            allowed, cost, _ = worker.quota_governor(state, 900)
            self.assertTrue(allowed)
            self.assertEqual(cost, 10)
            self.assertEqual(state['quota_bucket_updated'], 1000)
            worker.quota_governor(state, 1000)
        self.assertEqual(state['quota_bucket_tokens'], 80)

    def test_invalid_quiet_hours_withhold_delivery(self):
        for start, end in (('invalid', '06:00'), ('25:00', '06:00'), ('21:00', '06:99')):
            with self.subTest(start=start, end=end):
                self.assertTrue(worker.quiet_hours_active({'quiet_hours_enabled': '1',
                                                          'quiet_hours_start': start, 'quiet_hours_end': end}))

    def test_forecast_cache_cannot_cross_its_known_transition(self):
        area = {'location': '30,-97', 'radius_miles': 5, 'strike_type': 'cloud_to_ground'}
        cache = {'coverage_schema': 2, 'configuration_identity': worker.lightning_area_identity({}, area),
                 'checked_at': 1000, 'expires_at': 1200, 'next_transition_at': 1100, 'active': True}
        with mock.patch.object(worker, 'read_json_object', return_value=cache), \
             mock.patch.object(worker, '_nws_json', side_effect=RuntimeError('private unavailable forecast')), \
             mock.patch.object(worker, 'log'):
            active, _, available = worker.forecast_storm_gate({}, area, 1150, 30)
        self.assertFalse(active)
        self.assertFalse(available)

    def test_polling_lock_rejects_symlinks_before_work(self):
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            victim = root / 'victim'
            victim.write_bytes(b'private evidence')
            lock = root / 'poll.lock'
            lock.symlink_to(victim)
            with mock.patch.object(worker, 'WORKER_LOCK_FILE', lock), \
                 mock.patch.object(worker, 'run_configured_group_cycle', return_value=0) as run:
                with self.assertRaises((RuntimeError, OSError)):
                    worker.cli([])
                run.assert_not_called()
            self.assertEqual(victim.read_bytes(), b'private evidence')


if __name__ == '__main__':
    unittest.main()
