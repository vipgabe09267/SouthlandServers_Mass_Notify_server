#!/usr/bin/env python3
"""Run real Weather dispatch control flow with isolated, failing channel doubles."""
import importlib.util
import subprocess
import tempfile
import time
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin'
spec = importlib.util.spec_from_file_location('lightning_isolation', ROOT / 'sls_mass_notify/sls_mass_notify_xweather_poll.py')
lightning = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lightning)


class IsolationTests(unittest.TestCase):
    def queued_lightning(self, *, observation=None, area_changes=None, audio_error=False):
        area = {'id': 'fixture', '_service_enabled': '1', 'enabled': '1', 'radius_miles': 10,
                'location': 'this area', 'recipients': ['1000'], 'desktop_clients': ['fixture-desktop'],
                'query_interval_minutes': 5, 'all_clear': 'send'}
        area.update(area_changes or {})
        record = {'group_id': 'fixture', 'configuration_identity': 'identity', 'event_kind': 'entry',
                  'cluster_started': 1, 'message': 'Fixture warning', 'subject': 'Fixture',
                  'event_name': 'Lightning', 'severity': 'Severe', 'state_label': 'active',
                  'alert_id': 'fixture-alert', 'correlation_key': 'fixture-key', 'observed_at': time.time()}
        order = []
        def audio(*args):
            order.append('audio')
            if audio_error:
                raise RuntimeError('fixture synthesis failure')
            return 'fixture-sound'
        with mock.patch.multiple(lightning,
             load_config=mock.Mock(return_value=({'desktop_clients': [{'username': 'fixture-desktop', 'enabled': '1'}]}, {})),
             select_group=mock.Mock(return_value=area), configure_group_runtime=mock.Mock(),
             lightning_area_identity=mock.Mock(return_value='identity'), quiet_hours_active=mock.Mock(return_value=False),
             read_state=mock.Mock(return_value=observation or {'last_observed_at': time.time(), 'active': True, 'cluster_started': 1}),
             send_visual=mock.Mock(side_effect=lambda *args: order.append('desktop')),
             generate_audio=mock.Mock(side_effect=audio),
             submit_local_channels=mock.Mock(side_effect=lambda *args: (order.append('phone') or 1, [])),
             queue_external_delivery=mock.Mock(side_effect=lambda *args: order.append('external-queue')),
             append_event=mock.Mock(), record_xweather_outcome=mock.Mock(),
             atomic_json_update=mock.Mock(side_effect=AssertionError('Dispatcher must not rewrite observer state'))):
            result = lightning.deliver_queued_event(record)
        return result, order

    def test_queued_lightning_desktop_precedes_audio_and_external_work(self):
        result, order = self.queued_lightning()
        self.assertEqual(result[0], 'complete')
        self.assertEqual(order, ['desktop', 'audio', 'phone', 'external-queue'])

    def test_queued_lightning_preparation_failure_preserves_other_channels(self):
        result, order = self.queued_lightning(audio_error=True)
        self.assertEqual(result[0], 'failed')
        self.assertEqual(order, ['desktop', 'audio', 'phone', 'external-queue'])

    def test_queued_lightning_rejects_stale_changed_and_disabled_targets(self):
        for observation, area in [({'last_observed_at': 1}, {}),
                                  ({'last_observed_at': time.time(), 'active': False}, {}),
                                  ({'last_observed_at': time.time(), 'active': True, 'cluster_started': 2}, {}),
                                  (None, {'enabled': '0'})]:
            with self.subTest(observation=observation, area=area):
                result, order = self.queued_lightning(observation=observation, area_changes=area)
                self.assertEqual(result[0], 'cancelled')
                self.assertEqual(order, [])

    def test_lightning_audio_failure_still_submits_visual(self):
        with mock.patch.object(lightning, 'queue_audio', side_effect=RuntimeError('spool unavailable')), \
             mock.patch.object(lightning, 'send_visual') as visual:
            queued, errors = lightning.submit_local_channels(['1000'], ['fixture-desktop'], 'test', 'fixture-sound')
            self.assertEqual(queued, 0)
            self.assertIn('spool unavailable', errors[0])
            visual.assert_called_once()

    def test_lightning_preparation_failure_still_submits_visual(self):
        with mock.patch.object(lightning, 'queue_audio') as audio, mock.patch.object(lightning, 'send_visual') as visual:
            _, errors = lightning.submit_local_channels(['1000'], ['fixture-desktop'], 'test', '', preparation_error='TTS failed')
            audio.assert_not_called()
            visual.assert_called_once()
            self.assertEqual(errors, ['TTS failed'])

    def test_lightning_visual_failure_does_not_skip_audio_result(self):
        with mock.patch.object(lightning, 'queue_audio', return_value=(1, [('fake', '1000')], 5)), \
             mock.patch.object(lightning, 'send_visual', side_effect=RuntimeError('visual failed')), \
             mock.patch.object(lightning, 'wait_for_archived_calls') as completion:
            _, errors = lightning.submit_local_channels(['1000'], [], 'test', 'fixture-sound', True)
            completion.assert_called_once()
            self.assertIn('visual failed', errors[0])

    def test_nws_failures_preserve_intent_and_attempt_visual(self):
        source = (ROOT / 'sls_mass_notify_nws_poll.sh').read_text()
        start = source.index('    if [ "$LOCAL_INTENT_COMMITTED" = "1" ] && [ "$LOCAL_RECOVERY" = "0" ]; then')
        end = source.index('\n  if [ "$AUX_DELIVERY_OK"', start)
        # The trailing fi closes the surrounding state-machine branch.
        block = source[start:end].rsplit('\n  fi', 1)[0]
        for preparation in ('0', '1'):
            with self.subTest(preparation=preparation), tempfile.TemporaryDirectory() as directory:
                script = '''
LOCAL_INTENT_COMMITTED=1 LOCAL_RECOVERY=0 LOCAL_PHONE_REQUESTED=1 LOCAL_VISUAL_REQUESTED=1
LOCAL_SUBMISSION_OK=1 LOCAL_AUDIO_QUEUE_FAILED=0 LOCAL_INTENT_NEW=1
NWS_AUDIO_LOCK_FD= NWS_LAST_PAGE_HOLD_SECONDS=0
EVENT=fixture ALERT_ID=fixture ALERT_KEY=fixture ALERT_B64=fixture AUDIO_SEQUENCE=fixture
LOG=/dev/null
acquire_audio_delivery_slot() { return 0; }
queue_audio_to_recipients() { echo AUDIO; return 1; }
finalize_cross_zone_destinations() { echo CLAIM "$@"; }
cancel_local_dispatch_intent() { echo UNSAFE_CANCEL; }
trigger_visual_alert() { echo VISUAL; }
report_fault() { :; }
'''
                result = subprocess.run(['bash'], input=script + '\nLOCAL_PREP_OK=' + preparation + '\n' + block,
                                        text=True, capture_output=True, cwd=directory, timeout=5)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn('VISUAL', result.stdout)
                self.assertIn('commit phone desktop', result.stdout)
                self.assertNotIn('UNSAFE_CANCEL', result.stdout)
                self.assertEqual('AUDIO\n' in result.stdout, preparation == '1')


if __name__ == '__main__':
    unittest.main()
