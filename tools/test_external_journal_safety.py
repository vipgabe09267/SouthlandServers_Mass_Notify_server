#!/usr/bin/env python3
"""Exercise actual external-delivery file readers and leases in private storage."""
import fcntl
import importlib.util
import json
import os
from pathlib import Path
from unittest import mock
import subprocess
import tempfile
import unittest
import sys

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'


class ExternalJournalSafety(unittest.TestCase):
    def load_runtime(self):
        sys.dont_write_bytecode = True
        spec = importlib.util.spec_from_file_location('external_protocol_fixture', RUNTIME / 'sls_notification_destinations.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_authorization_reply_requires_one_explicit_boolean(self):
        module = self.load_runtime()
        for raw in (b'{"allowed":1}', b'{"allowed":false,"allowed":true}'):
            with self.subTest(raw=raw), mock.patch.object(module.subprocess, 'run', return_value=mock.Mock(returncode=0, stdout=raw)):
                self.assertFalse(module._current_authorization({}))
        with mock.patch.object(module.subprocess, 'run', return_value=mock.Mock(returncode=0, stdout=b'{"allowed":true}')):
            self.assertTrue(module._current_authorization({}))

    def test_invalid_retry_versions_and_counters_remain_unchanged(self):
        module = self.load_runtime()
        with tempfile.TemporaryDirectory() as name:
            path = Path(name) / 'external.json'
            module.queue_external_delivery(path, {}, 'retained', 'Alert', 'Body', source='nws')
            for header in ({'version': True, 'attempt_sequence': 0}, {'version': 2, 'attempt_sequence': 0},
                           {'version': 1, 'attempt_sequence': True}, {'version': 1, 'attempt_sequence': 1.5}):
                with self.subTest(header=header):
                    path.write_text(json.dumps(dict(header, deliveries={})))
                    before = path.read_bytes()
                    inode = path.stat().st_ino
                    with self.assertRaises(module.RetryStateError):
                        module.external_delivery_recorded(path, 'nws', 'retained')
                    self.assertEqual(path.read_bytes(), before)
                    self.assertEqual(path.stat().st_ino, inode)

    def test_boolean_completion_does_not_prune_a_retained_delivery(self):
        module = self.load_runtime()
        with tempfile.TemporaryDirectory() as name:
            path = Path(name) / 'external.json'
            key = module.queue_external_delivery(path, {}, 'retained', 'Alert', 'Body', source='nws')
            state = json.loads(path.read_bytes())
            state['deliveries'][key]['completed_at'] = True
            path.write_text(json.dumps(state))
            before = path.read_bytes()
            with self.assertRaises(module.RetryStateError):
                module.queue_external_delivery(path, {}, 'retained', 'New alert', 'New body', source='nws')
            self.assertEqual(path.read_bytes(), before)

    def test_malformed_retry_record_shapes_fail_closed(self):
        module = self.load_runtime()
        mutations = (
            lambda record: record.update(payload=[]),
            lambda record: record.update(webhook_pending='generic:receiver'),
            lambda record: record.update(email_pending=1),
            lambda record: record.update(routing_snapshot=[]),
            lambda record: record.update(destination_receipts=[]),
            lambda record: (record.update(channels_pending=True), record.pop('channels', None)),
        )
        for mutate in mutations:
            with self.subTest(mutation=repr(mutate)), tempfile.TemporaryDirectory() as name:
                path = Path(name) / 'external.json'
                key = module.queue_external_delivery(path, {}, 'retained', 'Alert', 'Body', source='nws')
                state = json.loads(path.read_bytes())
                mutate(state['deliveries'][key])
                path.write_text(json.dumps(state))
                corrupt = path.read_bytes()
                with self.assertRaisesRegex(module.RetryStateError, 'retry_state_corrupt'):
                    module.external_delivery_recorded(path, 'nws', 'retained')
                self.assertEqual(path.read_bytes(), corrupt)

    def test_all_supported_receipts_can_be_refreshed_at_capacity(self):
        module = self.load_runtime()
        record = {}
        results = [{'type': kind, 'id': kind + str(index), 'status': 'accepted'}
                   for kind in ('discord', 'generic') for index in range(module.MAX_DESTINATIONS)]
        results += [{'type': 'email', 'id': 'email', 'status': 'accepted'},
                    {'type': 'channels', 'id': 'sms_external_voice', 'status': 'queued'}]
        for result in results:
            module._record_external_receipt(record, result)
        for result in results:
            module._record_external_receipt(record, result)
        self.assertEqual(len(record['destination_receipts']), 22)
        with self.assertRaises(module.RetryStateError):
            module._record_external_receipt(record, {'type': 'generic', 'id': 'overflow', 'status': 'accepted'})

    def test_collaboration_fields_fail_closed_without_aborting_other_rows(self):
        module = self.load_runtime()
        config = {'generic_webhooks': [
            {'id': 'slack', 'name': 'Slack', 'url': 'https://hooks.example/one',
             'enabled': '1', 'payload_format': 'slack'},
            {'id': 'native', 'name': 'Native', 'url': 'https://hooks.example/two',
             'enabled': '1', 'payload_format': 'native'},
        ]}
        calls = []
        results = module.dispatch_webhook_destinations(
            config, 'Alert', 'Body', fields=[['valid', 'value'], ['missing-value']],
            source='nws', live=True, enforce_wall_clock=False,
            transport=lambda *args, **kwargs: calls.append(args[0]) or (204, ''),
        )
        self.assertEqual(results[0]['id'], 'slack')
        self.assertEqual((results[0]['status'], results[0]['error'], results[0]['retryable']),
                         ('failed', 'invalid_webhook_fields', False))
        self.assertEqual(results[1]['status'], 'accepted')
        self.assertEqual(calls, ['https://hooks.example/two'])

    def test_only_definite_temporary_failures_remain_retryable(self):
        module = self.load_runtime()
        row = {'kind': 'generic', 'id': 'receiver', 'name': 'Receiver', 'url': 'https://hooks.example/one'}
        def deliver(status):
            calls = []
            result = module._deliver(
                row, {}, 'event', lambda *_args, **_kwargs: calls.append(status) or (status, '0'),
                None, lambda _delay: None, module.time.monotonic() + 5, module.time.monotonic,
            )
            return result, calls
        permanent, permanent_calls = deliver(401)
        throttled, throttled_calls = deliver(429)
        redirected, redirected_calls = deliver(302)
        self.assertFalse(permanent['retryable'])
        self.assertEqual(permanent_calls, [401])
        self.assertTrue(throttled['retryable'])
        self.assertEqual(throttled_calls, [429, 429])
        self.assertFalse(redirected['retryable'])
        self.assertEqual(redirected_calls, [302])

    def test_untyped_retry_hint_cannot_replay_a_permanent_failure(self):
        module = self.load_runtime()
        config = {'generic_webhooks': [
            {'id': 'receiver', 'name': 'Receiver', 'url': 'https://hooks.example/one', 'enabled': '1'},
        ]}
        calls = []
        def malformed_dispatcher(*_args, **_kwargs):
            calls.append(1)
            return [{'type': 'generic', 'id': 'receiver', 'name': 'Receiver', 'status': 'failed',
                     'attempts': 1, 'http_status': 401, 'error': 'http_failure', 'retryable': 'true'}]
        with tempfile.TemporaryDirectory() as name:
            path = Path(name) / 'external.json'
            module.queue_external_delivery(path, config, 'permanent', 'Alert', 'Body', source='nws')
            options = {'live': True, 'webhook_dispatcher': malformed_dispatcher,
                       'validity_checker': lambda *_args: ('eligible', '', None)}
            first = module.retry_external_deliveries(path, config, 'nws', **options)
            second = module.retry_external_deliveries(path, config, 'nws', **options)
            self.assertEqual(calls, [1])
            self.assertEqual((first['pending'], second['pending']), (0, 0))

    def test_invalid_source_identity_numbers_use_the_declared_error(self):
        module = self.load_runtime()
        for observed, cluster in ((True, 1), ('invalid', 1), (1000, True), (1000, '1')):
            with self.subTest(observed=observed, cluster=cluster), self.assertRaises(module.RetryStateError):
                module.weather_source_validity(
                    'xweather', 'area', observed_at=observed, event_kind='entry',
                    cluster_started=cluster, configuration_identity='a' * 64,
                )

    def test_cli_rejects_out_of_range_schedule_without_a_traceback(self):
        module = self.load_runtime()
        environment = {
            'SLS_NOTIFICATION_LIVE': '1',
            'SLS_DESTINATION_SOURCE': 'dashboard',
            'SLS_DESTINATION_IDS': 'receiver',
            'SLS_DESTINATION_LATEST_START': '999999999999',
        }
        with mock.patch.object(module._config_crypto, 'read_config', return_value={}), \
                mock.patch.object(module.sys, 'argv', ['worker', '--announcement']), \
                mock.patch.dict(module.os.environ, environment, clear=True), \
                mock.patch('sys.stdout') as output:
            self.assertEqual(module.main(), 1)
        rendered = ''.join(call.args[0] for call in output.write.call_args_list if call.args)
        self.assertIn('invalid_schedule_deadline', rendered)

    def test_interrupted_webhook_receipt_is_persisted(self):
        module = self.load_runtime()
        config = {'generic_webhooks': [
            {'id': 'receiver', 'name': 'Receiver', 'url': 'https://hooks.example/one', 'enabled': '1'},
        ]}
        with tempfile.TemporaryDirectory() as name:
            path = Path(name) / 'external.json'
            key = module.queue_external_delivery(path, config, 'interrupted', 'Alert', 'Body', source='nws')
            with module._locked_retry_state(path) as locked:
                state = module._load_retry_state(locked)
                record = state['deliveries'][key]
                record['webhook_inflight'] = ['generic:receiver']
                module._write_retry_state(locked, state)
            result = module.retry_external_deliveries(
                path, config, 'nws', live=True,
                validity_checker=lambda *_args: ('eligible', '', None),
            )
            saved = module._load_retry_state(path)['deliveries'][key]
            self.assertEqual(result['uncertain'], 1)
            self.assertEqual(saved['destination_receipts']['generic:receiver']['status'], 'uncertain')
            self.assertEqual(saved['terminal_status'], 'uncertain')

    def test_uncertain_weather_channel_is_never_replayed(self):
        module = self.load_runtime()
        channels = {'voice_recipient_ids': ['voice_' + 'a' * 24], 'sms_recipient_ids': [], 'fingerprints': {}}
        reply = '{"success":false,"status":"uncertain","message":"submission_unconfirmed"}'
        with tempfile.TemporaryDirectory() as name:
            path = Path(name) / 'external.json'
            key = module.queue_external_delivery(
                path, {}, 'uncertain-channel', 'Alert', 'Body', source='nws', channel_snapshot=channels,
            )
            process = mock.Mock(returncode=1, stdout=reply)
            with mock.patch.object(module.subprocess, 'run', return_value=process) as run:
                first = module.retry_external_deliveries(
                    path, {}, 'nws', live=True,
                    validity_checker=lambda *_args: ('eligible', '', None),
                )
                second = module.retry_external_deliveries(
                    path, {}, 'nws', live=True,
                    validity_checker=lambda *_args: ('eligible', '', None),
                )
            saved = module._load_retry_state(path)['deliveries'][key]
            self.assertEqual(run.call_count, 1)
            self.assertEqual((first['pending'], first['uncertain']), (0, 1))
            self.assertEqual((second['pending'], second['uncertain']), (0, 1))
            self.assertFalse(saved['channels_pending'])
            self.assertEqual(saved['channels_uncertain']['status'], 'uncertain')
            self.assertEqual(saved['terminal_status'], 'uncertain')
            self.assertEqual(run.call_args.kwargs['env'],
                             {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8'})

    def test_malformed_channel_reply_does_not_block_independent_email(self):
        module = self.load_runtime()
        channels = {'voice_recipient_ids': ['voice_' + 'a' * 24], 'sms_recipient_ids': [], 'fingerprints': {}}
        for raw in ('[]', '{"success":true,"status":[]}', '{"success":true,"status":"unexpected"}'):
            with self.subTest(raw=raw), tempfile.TemporaryDirectory() as name:
                path = Path(name) / 'external.json'
                key = module.queue_external_delivery(path, {}, 'retained', 'Alert', 'Body', source='nws',
                                                     email_recipients='fixture@example.invalid', channel_snapshot=channels)
                calls = []
                def email_sender(*args, **kwargs):
                    calls.append(1)
                    return True
                with mock.patch.object(module.subprocess, 'run', return_value=mock.Mock(returncode=0, stdout=raw)):
                    module.retry_external_deliveries(path, {}, 'nws', live=True, email_sender=email_sender,
                                                     validity_checker=lambda *args: ('eligible', '', None))
                self.assertEqual(calls, [1])
                saved = module._load_retry_state(path)['deliveries'][key]
                self.assertTrue(saved['channels_pending'])
                self.assertFalse(saved['email_pending'])

    def test_directory_change_cannot_retarget_a_leased_write(self):
        spec=importlib.util.spec_from_file_location('external_parent_boundary',RUNTIME/'sls_notification_destinations.py')
        m=importlib.util.module_from_spec(spec);sys.dont_write_bytecode=True;spec.loader.exec_module(m)
        with tempfile.TemporaryDirectory() as name:
            root=Path(name);folder=root/'data';folder.mkdir(mode=0o700);state=folder/'external.json'
            m.queue_external_delivery(state,{},'original','Alert','Body',source='nws')
            original=state.read_bytes()
            with m._locked_retry_state(state):
                old=root/'preserved';folder.rename(old);folder.mkdir(mode=0o700)
                with self.assertRaisesRegex(m.RetryStateError,'directory_changed'):
                    m._write_retry_state(state,{'version':1,'attempt_sequence':0,'deliveries':{}})
            self.assertEqual((old/'external.json').read_bytes(),original)
            self.assertEqual(list(folder.iterdir()),[])

    def test_failed_write_does_not_unlink_a_swapped_temporary_name(self):
        module = self.load_runtime()
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            state_path = root / 'external.json'
            module.queue_external_delivery(state_path, {}, 'original', 'Alert', 'Body', source='nws')
            replacement = []
            real_rename = os.rename

            def swap_then_fail(source, _destination, *, src_dir_fd=None, dst_dir_fd=None):
                preserved = source + '.preserved'
                real_rename(source, preserved, src_dir_fd=src_dir_fd, dst_dir_fd=src_dir_fd)
                descriptor = os.open(source, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640, dir_fd=src_dir_fd)
                try:
                    os.write(descriptor, b'replacement must remain')
                finally:
                    os.close(descriptor)
                replacement.append(source)
                raise OSError('simulated commit failure')

            with module._locked_retry_state(state_path) as locked, mock.patch.object(module.os, 'replace', side_effect=swap_then_fail):
                state = module._load_retry_state(locked)
                with self.assertRaisesRegex(module.RetryStateError, 'retry_state_write_failed'):
                    module._write_retry_state(locked, state)
            self.assertEqual(len(replacement), 1)
            self.assertEqual((root / replacement[0]).read_bytes(), b'replacement must remain')

    def test_legacy_and_interrupted_marker_adoption_preserves_data_and_inodes(self):
        spec=importlib.util.spec_from_file_location('external_marker_boundary',RUNTIME/'sls_notification_destinations.py')
        m=importlib.util.module_from_spec(spec);sys.dont_write_bytecode=True;spec.loader.exec_module(m)
        for marker in (b'',b'SLS'):
            with self.subTest(marker=marker),tempfile.TemporaryDirectory() as name:
                state=Path(name)/'external.json';state.write_text('{"version":1,"attempt_sequence":9,"deliveries":{}}');state.chmod(0o640)
                lock=Path(str(state)+'.lock');lock.write_bytes(marker);lock.chmod(0o640)
                original=state.read_bytes();data_inode=state.stat().st_ino;lock_inode=lock.stat().st_ino
                self.assertFalse(m.external_delivery_recorded(state,'nws','private'))
                self.assertEqual(state.read_bytes(),original);self.assertEqual(state.stat().st_ino,data_inode)
                self.assertEqual(lock.stat().st_ino,lock_inode);self.assertEqual(lock.read_bytes(),m.RETRY_MARKER)
        with tempfile.TemporaryDirectory() as name:
            state=Path(name)/'external.json';lock=Path(str(state)+'.lock')
            with mock.patch.object(m.os,'replace',side_effect=OSError('private commit fault')):
                with self.assertRaises(m.RetryStateError):m.queue_external_delivery(state,{},'private','Alert','Body',source='nws')
            self.assertFalse(state.exists());self.assertEqual(lock.read_bytes(),b'')
            inode=lock.stat().st_ino;m.queue_external_delivery(state,{},'private','Alert','Body',source='nws')
            self.assertEqual(lock.stat().st_ino,inode);self.assertEqual(lock.read_bytes(),m.RETRY_MARKER)
    def test_missing_established_history_cannot_be_reinitialized(self):
        spec=importlib.util.spec_from_file_location('external_history_boundary',RUNTIME/'sls_notification_destinations.py')
        m=importlib.util.module_from_spec(spec);sys.dont_write_bytecode=True;spec.loader.exec_module(m)
        with tempfile.TemporaryDirectory() as name:
            state=Path(name)/'external.json'
            m.queue_external_delivery(state,{},'already-recorded','Alert','Body',source='nws')
            lock=Path(str(state)+'.lock');before=lock.stat();state.unlink()
            with self.assertRaises((RuntimeError,ValueError)):
                m.queue_external_delivery(state,{},'already-recorded','Alert','Body',source='nws')
            self.assertFalse(state.exists())
            self.assertEqual(lock.stat().st_ino,before.st_ino)

    def test_explicit_nonretryable_failure_is_not_submitted_again(self):
        spec=importlib.util.spec_from_file_location('external_retry_boundary',RUNTIME/'sls_notification_destinations.py')
        m=importlib.util.module_from_spec(spec);sys.dont_write_bytecode=True;spec.loader.exec_module(m)
        with tempfile.TemporaryDirectory() as name:
            state=Path(name)/'external.json'
            config={'generic_webhooks':[{'id':'receiver','name':'Receiver','url':'https://receiver.example/private','enabled':'1'}]}
            key=m.queue_external_delivery(state,config,'private-rejection','Alert','Body',source='nws')
            calls=[]
            def rejected(*args,**kwargs):
                calls.append(1)
                return [{'type':'generic','id':'receiver','name':'Receiver','status':'failed','attempts':0,
                         'error':'payload_too_large','retryable':False}]
            options={'live':True,'validity_checker':lambda *args:('eligible','',None),'webhook_dispatcher':rejected}
            result=m.retry_external_deliveries(state,config,'nws',**options)
            self.assertEqual(result['pending'],0)
            self.assertEqual(m.external_delivery_status(state,'nws','private-rejection'),'failed')
            m.retry_external_deliveries(state,config,'nws',**options)
            self.assertEqual(calls,[1])
            self.assertEqual(m._load_retry_state(state)['deliveries'][key]['destination_receipts']['generic:receiver']['status'],'failed')
    def test_unsafe_files_and_contention_are_rejected_without_modification(self):
        for fault in ('state_fifo', 'observation_fifo', 'held_lock', 'linked_lock', 'duplicate_state', 'duplicate_observation'):
            with self.subTest(fault=fault), tempfile.TemporaryDirectory() as name:
                root = Path(name)
                path = root / 'external.json'
                victim = root / 'victim'
                victim.write_bytes(b'preserved')
                victim.chmod(0o600)
                held = None
                if fault in ('state_fifo', 'observation_fifo'):
                    os.mkfifo(path, 0o640)
                elif fault == 'held_lock':
                    held = os.open(str(path) + '.lock', os.O_CREAT | os.O_RDWR, 0o640)
                    fcntl.flock(held, fcntl.LOCK_EX)
                elif fault == 'linked_lock':
                    os.link(victim, str(path) + '.lock')
                else:
                    path.write_text('{"version":1,"attempt_sequence":0,"deliveries":{"prior":{}},"deliveries":{}}')
                    path.chmod(0o640)
                before = victim.stat()
                code = '''import json,sys
sys.dont_write_bytecode=True
sys.path.insert(0,sys.argv[1])
import sls_notification_destinations as m
from pathlib import Path
p=Path(sys.argv[2]);fault=sys.argv[3]
try:
 if fault.endswith('observation') or fault=='observation_fifo': m._read_weather_observation(p)
 elif fault in ('held_lock','linked_lock'):
  with m._locked_retry_state(p): pass
 else: m._load_retry_state(p)
except (RuntimeError,ValueError,OSError): print(json.dumps({'rejected':True}))
else: print(json.dumps({'rejected':False}))
'''
                try:
                    result = subprocess.run(['python3', '-I', '-c', code, str(RUNTIME), str(path), fault],
                                            capture_output=True, text=True, timeout=3)
                    self.assertEqual(result.returncode, 0, result.stderr)
                    self.assertEqual(json.loads(result.stdout), {'rejected': True})
                    after = victim.stat()
                    self.assertEqual((before.st_ino, before.st_mode, before.st_uid),
                                     (after.st_ino, after.st_mode, after.st_uid))
                    self.assertEqual(victim.read_bytes(), b'preserved')
                finally:
                    if held is not None: os.close(held)


if __name__ == '__main__':
    unittest.main()
