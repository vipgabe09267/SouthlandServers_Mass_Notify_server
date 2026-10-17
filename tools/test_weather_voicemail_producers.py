#!/usr/bin/env python3
"""Exercise Weather admission requests and Lightning dispatch without sending alerts."""
import ast
import base64
from contextlib import ExitStack, redirect_stdout
from datetime import datetime, timedelta, timezone
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest import mock
import wave


if os.environ.get('SLS_TEST_NAMESPACE') != 'entered' or any(
    os.environ.get('SLS_TEST_PARENT_' + label + '_NS') in
    (None, '', os.readlink('/proc/self/ns/' + kind))
    for label, kind in [('MOUNT', 'mnt'), ('NET', 'net'), ('PID', 'pid')]
):
    raise SystemExit('Use tools/run_isolated_tests.sh; no production Weather or Asterisk resources were accessed.')


ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'
NWS_SOURCE = (ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_nws_poll.sh').read_text()
LIGHTNING_SOURCE = (RUNTIME / 'sls_mass_notify_xweather_poll.py').read_text()
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('voicemail_lightning_producer', RUNTIME / 'sls_mass_notify_xweather_poll.py')
lightning = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lightning)
import sls_phone_admission as admission
import sls_notification_destinations as destinations


def nws_python(marker, ending):
    start = NWS_SOURCE.index(marker)
    return NWS_SOURCE[start + len(marker):NWS_SOURCE.index(ending, start)]


NWS_REQUEST = compile(nws_python('      /usr/bin/python3 -I - "$CONFIG_JSON_FILE" 3<<<"$ALERT_B64" <<\'PY\'\n', '\nPY\n'),
                      'NWS admission request heredoc', 'exec')
NWS_RESULT = compile(nws_python("  if ! admission_lines=\"$(printf '%s' \"$admission_json\" | /usr/bin/python3 -c '\n", "\n')\"; then"),
                     'NWS admission result parser', 'exec')


class WeatherVoicemailProducers(unittest.TestCase):
    def setUp(self):
        self.scope = 'nws_fixture'
        self.feature = {'id': 'https://api.weather.gov/alerts/fixture-weather', 'properties': {
            'expires': (datetime.now(timezone.utc) + timedelta(hours=1)).isoformat(),
            'event': 'Tornado Warning', 'severity': 'Extreme', 'messageType': 'Alert'}}
        self.environ = {
            'SLS_VM_RECIPIENTS': '1000,1001', 'SLS_VM_SOUND': 'SLS_Mass_Notifications_Plugin/tts/fixture',
            'SLS_VM_DURATION': '14', 'SLS_VM_ENABLED': '1', 'SLS_VM_SCOPE': self.scope,
            'SLS_VM_FEATURE': base64.b64encode(json.dumps(self.feature).encode()).decode(),
            'SLS_VM_CHAIN': 'Tornado Warning|fixture-original', 'SLS_VM_EVENT': 'Tornado Warning',
            'SLS_VM_SEVERITY': 'Extreme', 'SLS_VM_EVENT_ID': 'fixture-weather', 'SLS_VM_ZONE': 'TXC453',
        }
        self.context = {'event': 'Lightning Alert', 'severity': 'Severe', 'event_id': 'xweather-fixture-event',
            'source_validity': destinations.weather_source_validity('xweather', 'lightning_fixture',
                observed_at=int(datetime.now(timezone.utc).timestamp()), event_kind='entry',
                cluster_started=1, configuration_identity='a' * 64)}

    def request(self, changes=None, config=None):
        environment = {**self.environ, **(changes or {})}
        reader = mock.Mock(return_value=config) if config is not None else mock.Mock(
            side_effect=AssertionError('This admission request must not reopen the protected config.'))
        crypto = types.ModuleType('sls_config_crypto')
        crypto.read_config = reader
        output = io.StringIO()
        original_fdopen = os.fdopen
        def feature_input(descriptor, *args, **kwargs):
            if descriptor == 3:
                return io.BytesIO(environment['SLS_VM_FEATURE'].encode() + b'\n')
            return original_fdopen(descriptor, *args, **kwargs)
        original_path = list(sys.path)
        try:
            with mock.patch.dict(os.environ, environment), \
                 mock.patch.object(os, 'fdopen', side_effect=feature_input), \
                 mock.patch.dict(sys.modules, {'sls_config_crypto': crypto,
                                               'sls_notification_destinations': destinations}), \
                 mock.patch.object(sys, 'argv', ['fixture', '/unreadable/protected.config']), redirect_stdout(output):
                exec(NWS_REQUEST, {'__name__': 'fixture_nws_request'})
        finally:
            sys.path[:] = original_path
        return json.loads(output.getvalue()), reader

    def test_nws_disabled_keeps_ordinary_audio_with_ambiguous_or_invalid_legacy_scope(self):
        for scope in ('', 'unrecognized-group', 'invalid/scope'):
            with self.subTest(scope=scope):
                request, reader = self.request({'SLS_VM_ENABLED': '0', 'SLS_VM_SCOPE': scope,
                    'SLS_VM_FEATURE': 'not-base64', 'SLS_VM_ZONE': 'not-a-weather-zone'})
                reader.assert_not_called()
                self.assertEqual(request['internal'], ['1000', '1001'])
                self.assertEqual(request['sound'], self.environ['SLS_VM_SOUND'])
                self.assertEqual(request['duration'], 14)
                self.assertEqual(request['service'], 'nws')
                self.assertIs(request['voicemail'], False)
                self.assertEqual(request['voicemail_scope'], '')
                self.assertIsNone(request['voicemail_context'])
                self.assertEqual(request['correlation'], '')

    def test_nws_preserves_provider_identity_expiry_and_stable_correlation(self):
        request, reader = self.request()
        reader.assert_not_called()
        context = request['voicemail_context']
        self.assertIs(request['voicemail'], True)
        self.assertEqual(context['event'], 'Tornado Warning')
        self.assertEqual(context['severity'], 'Extreme')
        validity = context['source_validity']
        self.assertEqual(validity['provider'], 'nws')
        self.assertEqual(validity['group_id'], self.scope)
        self.assertEqual(validity['provider_event_id'], self.feature['id'])
        self.assertEqual(validity['chain_key'], self.environ['SLS_VM_CHAIN'])
        self.assertEqual(validity['zone'], 'TXC453')
        self.assertEqual(validity['expires_at'], int(datetime.fromisoformat(self.feature['properties']['expires']).timestamp()))
        expected = 'vm-' + hashlib.sha256((self.scope + '|fixture-weather').encode()).hexdigest()
        self.assertEqual(request['correlation'], expected)
        self.assertEqual(self.request()[0]['correlation'], expected)
        self.assertNotEqual(self.request({'SLS_VM_EVENT_ID': 'next-weather'})[0]['correlation'], expected)

    def test_nws_large_feature_stdin_preserves_voicemail_validity(self):
        self.feature['geometry'] = {'type': 'LineString', 'coordinates': [[41, -90]] * 10000}
        payload = base64.b64encode(json.dumps(self.feature).encode()).decode()
        self.assertGreater(len(payload), 131072)
        request, reader = self.request({'SLS_VM_FEATURE': payload})
        reader.assert_not_called()
        self.assertEqual(request['voicemail_context']['source_validity']['provider_event_id'], self.feature['id'])
        self.assertEqual(request['voicemail_scope'], self.scope)

    def test_nws_legacy_zone_uses_same_scope_as_current_source_policy(self):
        config = {'enabled': '1', 'nws_zones': [{'name': '  Fixture   Area  ', 'zone': 'TXC453'}]}
        request, reader = self.request({'SLS_VM_SCOPE': ''}, config)
        reader.assert_called_once_with('/unreadable/protected.config')
        validity = request['voicemail_context']['source_validity']
        expected = 'nws_' + hashlib.sha256(b'fixture area|TXC453').hexdigest()[:12]
        self.assertEqual(request['voicemail_scope'], expected)
        self.assertIsNotNone(destinations._source_group(config, validity))

    def test_nws_enabled_rejects_invalid_source_and_ambiguous_scope(self):
        with self.assertRaises(ValueError):
            self.request({'SLS_VM_FEATURE': 'not-base64'})
        with self.assertRaises(ValueError):
            self.request({'SLS_VM_SCOPE': ''}, {'nws_zones': [
                {'id': 'area_one', 'zone': 'TXC453'}, {'id': 'area_two', 'zone': 'TXC453'}]})

    def test_nws_result_preserves_snapshot_failure_and_offline_admission(self):
        for targets, fallback, error, expected in [
                (['1000'], ['1001'], None, ['2', '0', '1000']),
                (['1000'], ['1000'], 'phone_voicemail_audio_unavailable', ['1', '1', '1000']),
                ([], ['1001'], None, ['1', '0'])]:
            with self.subTest(targets=targets, error=error):
                output = io.StringIO()
                result = {'ok': True, 'token': '1' * 32, 'targets': targets,
                          'voicemail_targets': fallback, 'voicemail_failure_code': error}
                with mock.patch.object(sys, 'stdin', io.StringIO(json.dumps(result))), redirect_stdout(output):
                    exec(NWS_RESULT, {'__name__': 'fixture_nws_result'})
                self.assertEqual(output.getvalue().splitlines(), ['1' * 32] + expected)

    def lightning_fixture(self, stack, result):
        directory = Path(stack.enter_context(tempfile.TemporaryDirectory(
            prefix='sls-weather-voicemail-')))
        sounds = directory / 'sounds'
        audio = sounds / self.environ['SLS_VM_SOUND']
        audio.parent.mkdir(parents=True)
        with wave.open(str(audio) + '.wav', 'wb') as handle:
            handle.setparams((1, 2, 8000, 0, 'NONE', ''))
            handle.writeframes(b'\x20\x00' * 8000)
        outgoing = directory / 'outgoing'
        outgoing.mkdir()
        spool_tmp = directory / 'tmp'
        spool_tmp.mkdir()
        real_mkstemp = tempfile.mkstemp
        def isolated_mkstemp(*args, **kwargs):
            if kwargs.get('dir') == '/var/spool/asterisk/tmp':
                kwargs['dir'] = spool_tmp
            return real_mkstemp(*args, **kwargs)
        stack.enter_context(mock.patch.multiple(lightning,
            ASTERISK_SOUNDS_DIR=sounds, SPOOL_DIR=outgoing, SPOOL_DONE_DIR=directory / 'outgoing_done',
            CURRENT_GROUP_ID='lightning_fixture'))
        stack.enter_context(mock.patch.object(lightning.tempfile, 'mkstemp', side_effect=isolated_mkstemp))
        # Only external contact admission, queue scheduling and delivery are doubled.
        admit = stack.enter_context(mock.patch.object(admission, 'admit_internal', return_value={
            'token': '2' * 32, 'targets': [], 'voicemail_targets': [], 'voicemail_failure_code': None, **result}))
        queue = stack.enter_context(mock.patch.object(lightning, 'wait_for_slot'))
        visual = stack.enter_context(mock.patch.object(lightning, 'send_visual'))
        completion = stack.enter_context(mock.patch.object(lightning, 'wait_for_archived_calls'))
        return outgoing, admit, queue, visual, completion

    def test_lightning_offline_voicemail_admission_does_not_invent_a_phone_call(self):
        with ExitStack() as stack:
            outgoing, admit, queue, visual, _ = self.lightning_fixture(stack, {'voicemail_targets': ['1000']})
            queued, errors = lightning.submit_local_channels(['1000'], ['fixture-desktop'], 'Fixture alert',
                self.environ['SLS_VM_SOUND'], voicemail_context=self.context)
            self.assertEqual((queued, errors), (0, []))
            self.assertEqual(list(outgoing.iterdir()), [])
            self.assertEqual(visual.call_args.kwargs['phone_delay_seconds'], 0)
            self.assertIs(admit.call_args.kwargs['voicemail'], True)
            self.assertEqual(admit.call_args.kwargs['voicemail_scope'], 'lightning_fixture')
            self.assertEqual(admit.call_args.kwargs['voicemail_context']['source_validity'], self.context['source_validity'])
            self.assertEqual(admit.call_args.kwargs['correlation'], 'vm-' + hashlib.sha256(
                b'lightning_fixture|xweather-fixture-event').hexdigest())
            queue.assert_called_once()

    def test_lightning_mixed_online_offline_counts_calls_and_pending_mailboxes_separately(self):
        with ExitStack() as stack:
            outgoing, _, _, visual, _ = self.lightning_fixture(stack,
                {'targets': ['1000'], 'voicemail_targets': ['1000', '1001']})
            queued, errors = lightning.submit_local_channels(['1000', '1001'], [], 'Fixture alert',
                self.environ['SLS_VM_SOUND'], voicemail_context=self.context)
            self.assertEqual((queued, errors), (1, []))
            files = list(outgoing.iterdir())
            self.assertEqual(len(files), 1)
            self.assertIn('Channel: Local/1000@sls-alert-audio/n', files[0].read_text())
            self.assertNotIn('Local/1001@', files[0].read_text())
            self.assertEqual(visual.call_args.kwargs['phone_delay_seconds'], 2)

    def test_lightning_snapshot_failure_preserves_phone_submission_and_visual_delay(self):
        with ExitStack() as stack:
            outgoing, _, _, visual, _ = self.lightning_fixture(stack, {'targets': ['1000'],
                'voicemail_targets': ['1000'], 'voicemail_failure_code': 'phone_voicemail_audio_unavailable'})
            queued, errors = lightning.submit_local_channels(['1000'], [], 'Fixture alert',
                self.environ['SLS_VM_SOUND'], voicemail_context=self.context)
            self.assertEqual(queued, 1)
            self.assertEqual(len(list(outgoing.iterdir())), 1)
            self.assertEqual(visual.call_args.kwargs['phone_delay_seconds'], 2)
            self.assertEqual(len(errors), 1)
            self.assertIn('Voicemail audio could not be retained safely', errors[0])
            self.assertIn('queued phone audio is unaffected', errors[0])

    def test_lightning_disabled_queue_retains_original_three_item_result(self):
        with ExitStack() as stack:
            outgoing, admit, _, _, _ = self.lightning_fixture(stack, {'targets': ['1000']})
            result = lightning.queue_audio(['1000'], self.environ['SLS_VM_SOUND'])
            self.assertIsInstance(result, tuple)
            self.assertEqual(result, (1, [], 3))
            self.assertIs(admit.call_args.kwargs['voicemail'], False)
            self.assertIsNone(admit.call_args.kwargs['voicemail_context'])
            self.assertEqual(admit.call_args.kwargs['correlation'], '')
            self.assertEqual(len(list(outgoing.iterdir())), 1)

    def test_lightning_manual_test_never_requests_voicemail_and_waits_for_archived_call(self):
        with ExitStack() as stack:
            outgoing, admit, _, visual, completion = self.lightning_fixture(stack, {'targets': ['1000']})
            stack.enter_context(mock.patch.dict(os.environ, {'SLS_TEST_DELIVERY_ID': 'fixture-test-id'}))
            queued, errors = lightning.submit_local_channels(['1000'], [], 'Fixture test',
                self.environ['SLS_VM_SOUND'], is_test=True, voicemail_context=self.context)
            self.assertEqual((queued, errors), (1, []))
            self.assertIs(admit.call_args.kwargs['voicemail'], False)
            self.assertEqual(admit.call_args.kwargs['correlation'], 'fixture-test-id')
            files = list(outgoing.iterdir())
            self.assertEqual(len(files), 1)
            self.assertIn('Archive: yes', files[0].read_text())
            self.assertEqual(visual.call_args.kwargs['phone_delay_seconds'], 2)
            self.assertEqual(completion.call_args.kwargs['timeout'], 48)
            self.assertEqual(completion.call_args.args[0][0][1], '1000')

    def test_lightning_producer_context_does_not_validate_voicemail_when_disabled_or_test(self):
        expressions = [keyword.value for node in ast.walk(ast.parse(LIGHTNING_SOURCE))
            if isinstance(node, ast.Call) and isinstance(node.func, ast.Name)
            and node.func.id == 'submit_local_channels'
            for keyword in node.keywords if keyword.arg == 'voicemail_context']
        self.assertEqual(len(expressions), 2, 'Both immediate and queued alert producers must be exercised.')
        for expression in expressions:
            code = compile(ast.Expression(expression), 'Lightning producer voicemail context', 'eval')
            for enabled, is_test in [('0', False), (False, False), ('1', True)]:
                # The queued producer cannot receive a manual-test record.
                if is_test and 'is_test' not in {node.id for node in ast.walk(expression) if isinstance(node, ast.Name)}:
                    continue
                with self.subTest(enabled=enabled, is_test=is_test):
                    context_factory = mock.Mock(side_effect=AssertionError('Disabled voicemail must not validate its source.'))
                    self.assertIsNone(eval(code, {'config': {'xweather': {'voicemail_enabled': enabled}},
                        'is_test': is_test, 'weather_source_validity': context_factory}))
                    context_factory.assert_not_called()


if __name__ == '__main__':
    unittest.main()
