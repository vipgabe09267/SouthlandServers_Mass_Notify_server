#!/usr/bin/python3
"""No network, calls, mail, or production state: weather outbox failure fixtures."""
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import time
import unittest
from contextlib import contextmanager
from unittest import mock
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'
if not ROOT.exists():
    ROOT = Path('/usr/local/bin/sls_mass_notify')
sys.path.insert(0, str(ROOT))
CANDIDATE = Path(os.environ.get('SLS_WEATHER_QUEUE_CANDIDATE', str(ROOT / 'sls_weather_queue.py')))
spec = importlib.util.spec_from_file_location('weather_queue_fixture', CANDIDATE)
queue = importlib.util.module_from_spec(spec); spec.loader.exec_module(queue)


def iso(value):
    return datetime.fromtimestamp(value, timezone.utc).isoformat()


def feature(identifier='alert1', event='Heat Advisory', issued=None):
    now = time.time()
    return {'id': identifier, 'type': 'Feature', 'properties': {'event': event, 'status': 'Actual',
        'messageType': 'Alert', 'sent': iso(issued or now), 'onset': iso(issued or now), 'expires': iso(now + 3600)}}


class QueueTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-weather-fixture-')
        self.directory = Path(self.temp.name)
        self.patch = mock.patch.object(queue, 'DATA', self.directory); self.patch.start()
        self.config_env = mock.patch.dict(os.environ, {'CONFIG_FILE':str(self.directory/'mass-notifications.config')})
        self.config_env.start()
        (self.directory/'mass-notifications.config').write_text('{"enabled":"0"}')
        (self.directory/'mass-notifications.config').chmod(0o640)
        # Default argument paths are explicit in storage-only tests; dispatcher
        # calls use the patched wrapper below, never the production directory.
        self.real_lock = queue.state_lock
        self.lock_patch = mock.patch.object(queue, 'state_lock', side_effect=lambda *args, **kwargs: self.real_lock(self.directory, **kwargs))
        self.lock_patch.start()
        self.retry = mock.patch.object(queue, 'kick_external'); self.retry.start()
        self.enabled = mock.patch.object(queue, 'services_enabled', return_value=True); self.enabled.start()
        def inline_delivery(job_id, job):
            try:
                state, detail = queue.dispatch_nws(job_id, job)
            except Exception as error:
                state, detail = 'uncertain', 'Fixture interruption: ' + type(error).__name__
            queue.finish_delivery(job_id, job['claim_token'], state, detail)
            return mock.Mock(pid=-1, poll=lambda: 0, wait=lambda **kwargs: 0)
        self.launch = mock.patch.object(queue, 'start_delivery', side_effect=inline_delivery)
        self.launch.start()


    def tearDown(self):
        self.launch.stop(); self.enabled.stop(); self.retry.stop(); self.lock_patch.stop(); self.patch.stop(); self.config_env.stop(); self.temp.cleanup()

    def test_disabled_installer_probe_does_not_leave_background_children(self):
        with mock.patch.object(queue, 'services_enabled', return_value=False), \
             mock.patch.object(queue.subprocess, 'Popen') as spawn, \
             mock.patch.object(queue.subprocess, 'run') as run:
            self.assertEqual(queue.cycle(), 0)
            spawn.assert_not_called(); run.assert_not_called()

    def add(self, identifier, group='north', event='Heat Advisory', issued=None):
        item = feature(identifier, event, issued)
        with queue.state_lock() as data:
            data['snapshots'].setdefault(group, {'zone': 'TXC491', 'observed_at': time.time(), 'active': {}})['active'][queue.alert_key(item)] = item
            job_id = queue.enqueue_locked(data, 'nws', group, queue.alert_key(item), {'zone': 'TXC491', 'feature': item}, time.time(), queue.priority(item))
        return job_id, item

    def test_time_update_uses_original_chain_but_new_alert_does_not(self):
        first = feature()
        update = feature('alert2'); update['properties'].update(messageType='Update', references=[{'identifier': 'alert1', 'sent': iso(time.time()-100)}])
        self.assertEqual(queue.alert_key(first), queue.alert_key(update))
        update['properties']['messageType'] = 'Alert'
        self.assertNotEqual(queue.alert_key(first), queue.alert_key(update))

    def test_bad_schema_is_not_an_empty_observation(self):
        for payload in ({'features': []}, {'type': 'FeatureCollection', 'features': [None]},
                        {'type': 'FeatureCollection', 'features': [{'properties': {'status': 'Actual'}}]}):
            with self.assertRaises(ValueError): queue.validate_collection(payload)

    def test_expired_alert_is_not_actionable(self):
        item = feature(); item['properties']['expires'] = iso(time.time()-1)
        self.assertFalse(queue.actionable(item, time.time()))

    def test_fetch_allows_system_dns_failover_with_bounded_deadlines(self):
        result = mock.Mock(returncode=0, stdout=b'{"type":"FeatureCollection","features":[]}', stderr=b'')
        with mock.patch.object(queue.subprocess, 'run', return_value=result) as run:
            self.assertEqual(queue.fetch_zone('TXC491'), [])
        command = run.call_args.args[0]
        self.assertGreater(int(command[command.index('--connect-timeout')+1]), 5)
        self.assertEqual(command[command.index('--max-time')+1], '20')
        self.assertEqual(run.call_args.kwargs['timeout'], 50)
        self.assertNotIn('-k', command)
        self.assertNotIn('--insecure', command)
        self.assertNotIn('--resolve', command)

    def test_fetch_failures_are_useful_without_leaking_responses(self):
        for code, stderr, expected in [(6, b'SECRET', 'DNS'),
            (28, b'curl: (28) Resolving timed out SECRET', 'DNS'),
            (60, b'SECRET', 'TLS'), (22, b'curl: (22) The requested URL returned error: 503 SECRET', 'HTTP 503'),
            (28, b'Connection timed out SECRET', 'timed out')]:
            with mock.patch.object(queue.subprocess, 'run', return_value=mock.Mock(returncode=code, stderr=stderr, stdout=b'SECRET')):
                with self.assertRaises(queue.WeatherPollError) as error: queue.fetch_zone('TXC491')
                self.assertIn(expected, str(error.exception)); self.assertNotIn('SECRET', str(error.exception))

    def test_poll_failure_then_recovery_resets_only_api_fault(self):
        row = ['north','North','TXC491','1000','','','0','21:00','06:00','','']
        with mock.patch.object(queue, 'groups', return_value={'north': row}), \
             mock.patch.object(queue, 'fetch_zone', side_effect=queue.WeatherPollError('Weather.gov DNS resolution failed.')):
            for _ in range(3): queue.observe_nws()
        status = json.loads((self.directory / 'status.json').read_text())
        self.assertEqual(status['last_poll_status'], 'fault')
        self.assertIn('DNS', status['last_poll_message'])
        self.assertEqual(status['last_poll_fail_count'], 3)
        with mock.patch.object(queue, 'groups', return_value={'north': row}), \
             mock.patch.object(queue, 'fetch_zone', return_value=[]):
            queue.observe_nws()
        status = json.loads((self.directory / 'status.json').read_text())
        self.assertEqual(status['last_poll_status'], 'ok')
        self.assertEqual(status['last_poll_fail_count'], 0)
        self.assertNotIn('api', status['nws_groups']['north'].get('faults', {}))

    def test_symlink_queue_rejected(self):
        target = self.directory / 'outside'; target.write_text('{}')
        (self.directory / 'weather-delivery.json').symlink_to(target)
        with self.assertRaises(OSError):
            with queue.state_lock(): pass
        self.assertEqual(target.read_text(), '{}')

    def test_multizone_chronological_merge(self):
        now = time.time()
        self.add('late', 'north', issued=now-1)
        self.add('early', 'south', issued=now-100)
        self.add('middle', 'north', issued=now-50)
        order = []
        with mock.patch.object(queue, 'dispatch_nws', side_effect=lambda key, job: (order.append(job['payload']['feature']['id']) or ('complete','fixture'))):
            queue.dispatch()
        self.assertEqual(order, ['early', 'middle', 'late'])

    def test_fresh_cancellation_does_not_dispatch(self):
        job_id, item = self.add('cancelled')
        with queue.state_lock() as data: data['snapshots']['north']['active'] = {}
        with mock.patch.object(queue, 'dispatch_nws') as deliver: queue.dispatch(); deliver.assert_not_called()
        with queue.state_lock() as data: self.assertEqual(data['jobs'][job_id]['state'], 'cancelled')

    def test_stale_observation_waits_without_marking_delivered(self):
        job_id, item = self.add('stale')
        with queue.state_lock() as data: data['snapshots']['north']['observed_at'] = time.time()-181
        with mock.patch.object(queue, 'dispatch_nws') as deliver: queue.dispatch(); deliver.assert_not_called()
        with queue.state_lock() as data: self.assertEqual(data['jobs'][job_id]['state'], 'queued')

    def test_interrupted_job_is_not_replayed(self):
        job_id, item = self.add('interrupted')
        with queue.state_lock() as data: data['jobs'][job_id]['state'] = 'running'
        with mock.patch.object(queue, 'dispatch_nws') as deliver: queue.dispatch(); deliver.assert_not_called()
        with queue.state_lock() as data: self.assertEqual(data['jobs'][job_id]['state'], 'uncertain')

    def test_queued_update_replaced_but_complete_not_replayed(self):
        job_id, item = self.add('update')
        changed = dict(item, changed='later text')
        with queue.state_lock() as data:
            queue.enqueue_locked(data, 'nws', 'north', queue.alert_key(item), {'feature': changed}, time.time(), [0,0])
            self.assertEqual(data['jobs'][job_id]['payload']['feature']['changed'], 'later text')
            data['jobs'][job_id]['state'] = 'complete'
            queue.enqueue_locked(data, 'nws', 'north', queue.alert_key(item), {}, time.time(), [0,0])
            self.assertEqual(data['jobs'][job_id]['state'], 'complete')

    def test_fresh_update_stops_old_running_revision_and_refreshes_queued_payload(self):
        running, original = self.add('alert1', 'north', issued=time.time()-60)
        queued, _ = self.add('alert1', 'south', issued=time.time()-60)
        rows = {group: [group, group.title(), 'TXC491', '1000,1001', '', '', '0', '21:00', '06:00', '', '']
                for group in ('north', 'south')}
        with queue.state_lock() as data:
            data['jobs'][running]['state'] = 'running'
            for job_id in (running, queued):
                data['jobs'][job_id]['payload']['routing_snapshot'] = {'phones': ['1000']}
        with mock.patch.object(queue, 'groups', return_value=rows):
            self.assertTrue(queue.check_job(running))
        updated = feature('alert2', issued=time.time()-10)
        updated['properties'].update(messageType='Update', references=[{
            'identifier': original['id'], 'sent': original['properties']['sent']}])
        self.assertEqual(queue.alert_key(original), queue.alert_key(updated))
        with mock.patch.object(queue, 'groups', return_value=rows), \
             mock.patch.object(queue, 'fetch_zone', return_value=[updated]):
            self.assertEqual(queue.observe_nws(), 2)
            self.assertFalse(queue.check_job(running), 'A new revision authorized superseded running text')
        with queue.state_lock() as data:
            self.assertEqual(data['jobs'][running]['state'], 'running')
            self.assertEqual(data['jobs'][running]['payload']['feature']['id'], 'alert1')
            self.assertEqual(data['jobs'][queued]['state'], 'queued')
            self.assertEqual(data['jobs'][queued]['payload']['feature']['id'], 'alert2')
            self.assertEqual(data['jobs'][queued]['payload']['routing_snapshot'], {'phones': ['1000']})
            self.assertTrue(queue.valid_nws(data['jobs'][queued], data, time.time()))
            data['jobs'][queued]['state'] = 'running'
        with mock.patch.object(queue, 'groups', return_value=rows):
            self.assertTrue(queue.check_job(queued))

    def test_running_revision_requires_full_identity_matching_sent_and_original_expiry(self):
        job_id, original = self.add('https://api.weather.gov/alerts/alert1')
        row = ['north', 'North', 'TXC491', '1000', '', '', '0', '21:00', '06:00', '', '']
        for label, mutation, expected in [
            ('same revision', lambda item: None, True),
            ('equivalent timestamp', lambda item: item['properties'].update(
                sent=original['properties']['sent'].replace('+00:00', 'Z')), True),
            ('different full identity', lambda item: item.update(id='https://other.example/alerts/alert1'), False),
            ('changed sent', lambda item: item['properties'].update(sent=iso(time.time()+1)), False),
            ('missing sent', lambda item: item['properties'].pop('sent'), False),
            ('malformed sent', lambda item: item['properties'].update(sent='invalid'), False),
        ]:
            with self.subTest(label=label):
                current = json.loads(json.dumps(original)); mutation(current)
                with queue.state_lock() as data:
                    data['jobs'][job_id]['state'] = 'running'
                    data['snapshots']['north']['active'][queue.alert_key(original)] = current
                with mock.patch.object(queue, 'groups', return_value={'north': row}):
                    self.assertEqual(queue.check_job(job_id), expected)
        with queue.state_lock() as data:
            data['snapshots']['north']['active'][queue.alert_key(original)] = original
            data['jobs'][job_id]['payload']['feature']['properties']['expires'] = iso(time.time()-1)
        with mock.patch.object(queue, 'groups', return_value={'north': row}):
            self.assertFalse(queue.check_job(job_id), 'A later current expiry revived an expired running payload')

    def test_running_revision_rechecks_current_configured_zone(self):
        job_id, _ = self.add('alert1')
        with queue.state_lock() as data:
            data['jobs'][job_id]['state'] = 'running'
        changed = ['north', 'North', 'TXC453', '1000', '', '', '0', '21:00', '06:00', '', '']
        with mock.patch.object(queue, 'groups', return_value={'north': changed}):
            self.assertFalse(queue.check_job(job_id))

    def test_terminal_history_does_not_fill_active_queue(self):
        now = time.time()
        with queue.state_lock() as data:
            for index in range(queue.MAX_JOBS):
                data['jobs'][f'complete-{index}'] = {'service': 'nws', 'group_id': 'north',
                    'key': f'old-{index}', 'state': 'complete', 'created_at': now,
                    'updated_at': now, 'payload': {'unused_message': 'x' * 1000}}
        job_id, _ = self.add('new-active')
        with queue.state_lock() as data:
            self.assertEqual(data['jobs'][job_id]['state'], 'queued')
            self.assertEqual(len(data['jobs']), queue.MAX_JOBS + 1)
            self.assertNotIn('payload', data['jobs']['complete-0'])
            self.assertIn('payload', data['jobs'][job_id])
        self.assertLess((self.directory / 'weather-delivery.json').stat().st_size, 512 * 1024)

    def test_active_queue_limit_preserves_history_and_pending_requests(self):
        with mock.patch.object(queue, 'MAX_JOBS', 2):
            first, _ = self.add('first')
            second, _ = self.add('second')
            before = (self.directory / 'weather-delivery.json').read_bytes()
            with self.assertRaisesRegex(queue.WeatherQueueCapacityError, '2 queued or running'):
                self.add('rejected')
            self.assertEqual((self.directory / 'weather-delivery.json').read_bytes(), before)
            with queue.state_lock() as data:
                key = data['jobs'][first]['key']
                queue.enqueue_locked(data, 'nws', 'north', key,
                    {'zone': 'TXC491', 'feature': feature('newer-observation')}, time.time(), [0, 0])
                self.assertEqual(data['jobs'][first]['payload']['feature']['id'], 'newer-observation')
                self.assertEqual(data['jobs'][second]['state'], 'queued')

    def test_capacity_failure_commits_fresh_cancellation_and_observation(self):
        removed, _ = self.add('withdrawn', 'north')
        existing, southern = self.add('still-active', 'south')
        with queue.state_lock() as data:
            data['jobs'][existing]['payload']['zone'] = 'TXC453'
            data['snapshots']['south']['zone'] = 'TXC453'
        rows = {'north': ['north','North','TXC491','1000','','','0','21:00','06:00','',''],
                'south': ['south','South','TXC453','1000','','','0','21:00','06:00','','']}
        new = feature('new-northern-alert')
        with mock.patch.object(queue, 'MAX_JOBS', 1), \
             mock.patch.object(queue, 'groups', return_value=rows), \
             mock.patch.object(queue, 'fetch_zone', side_effect=lambda zone: [new] if zone == 'TXC491' else [southern]), \
             mock.patch.object(queue, 'reconcile_status'), mock.patch.object(queue, 'mutate_status') as status, \
             mock.patch.object(queue, 'write_gate'):
            with self.assertRaisesRegex(queue.WeatherQueueCapacityError, 'active limit is 1'):
                queue.observe_nws()
        with queue.state_lock() as data:
            self.assertEqual(data['jobs'][removed]['state'], 'cancelled')
            self.assertEqual(data['jobs'][existing]['state'], 'queued')
            self.assertIn(queue.alert_key(new), data['snapshots']['north']['active'])
            self.assertNotIn(data['jobs'][removed]['key'], data['snapshots']['north']['active'])
            self.assertEqual(len(data['jobs']), 2)
        faults = [call.args[-1]['fault'] for call in status.call_args_list if 'fault' in call.args[-1]]
        self.assertTrue(any(fault['stage'] == 'queue' and 'active limit' in fault['message'] for fault in faults))
        delivered = []
        with mock.patch.object(queue, 'dispatch_nws', side_effect=lambda key, job: (delivered.append(key) or ('complete', 'fixture'))):
            queue.dispatch()
        self.assertEqual(delivered, [existing], 'Withdrawn alert dispatched after capacity failure')

    def test_oversized_observation_invalidates_old_snapshot_without_discarding_backlog(self):
        retained, item = self.add('still-active')
        removed, _ = self.add('withdrawn')
        with queue.state_lock() as data:
            data['jobs'][retained]['payload']['routing_snapshot'] = {'phones': ['1000']}
            original = json.loads(json.dumps(data['jobs'][retained]['payload']))
        maximum = (self.directory / 'weather-delivery.json').stat().st_size + 100
        changed = json.loads(json.dumps(item))
        changed['properties']['description'] = 'x' * maximum
        row = ['north','North','TXC491','1000,1001','','','0','21:00','06:00','','']
        with mock.patch.object(queue, 'MAX_BYTES', maximum), \
             mock.patch.object(queue, 'groups', return_value={'north': row}), \
             mock.patch.object(queue, 'fetch_zone', return_value=[changed]), \
             mock.patch.object(queue, 'reconcile_status'), mock.patch.object(queue, 'mutate_status') as status, \
             mock.patch.object(queue, 'write_gate'):
            with self.assertRaisesRegex(queue.WeatherQueueCapacityError, 'previous observation was invalidated'):
                queue.observe_nws()
        with queue.state_lock() as data:
            self.assertNotIn('north', data['snapshots'])
            self.assertEqual(data['jobs'][retained]['state'], 'queued')
            self.assertEqual(data['jobs'][retained]['payload'], original)
            self.assertEqual(data['jobs'][removed]['state'], 'cancelled')
            self.assertFalse(queue.valid_nws(data['jobs'][retained], data, time.time()))
        self.assertTrue(any(call.args[-1].get('fault', {}).get('stage') == 'queue' for call in status.call_args_list))
        with mock.patch.object(queue, 'dispatch_nws') as deliver:
            queue.dispatch(); deliver.assert_not_called()

    def test_cancelled_and_expired_bodies_compact_without_losing_audience_or_replaying_expired(self):
        cancelled, _ = self.add('cancelled-history')
        expired, _ = self.add('expired-history')
        with queue.state_lock() as data:
            for job_id, state in [(cancelled, 'cancelled'), (expired, 'expired')]:
                data['jobs'][job_id]['state'] = state
                data['jobs'][job_id]['payload']['feature']['properties']['description'] = 'x' * 10000
                data['jobs'][job_id]['payload']['routing_snapshot'] = {'phones': ['1000']}
        with queue.state_lock() as data:
            self.assertEqual(data['jobs'][cancelled]['payload'], {'routing_snapshot': {'phones': ['1000']}})
            self.assertNotIn('payload', data['jobs'][expired])
            self.assertIn('deduplicate_until', data['jobs'][expired])
            queue.enqueue_locked(data, 'nws', 'north', data['jobs'][cancelled]['key'],
                {'feature': feature('cancelled-history'), 'routing_snapshot': {'phones': ['1000', '1001']}}, time.time(), [0, 0])
            self.assertEqual(data['jobs'][cancelled]['payload']['routing_snapshot'], {'phones': ['1000']})
            queue.enqueue_locked(data, 'nws', 'north', data['jobs'][expired]['key'],
                {'feature': feature('expired-history')}, time.time(), [0, 0])
            self.assertEqual(data['jobs'][expired]['state'], 'expired')

    def test_compaction_keeps_long_active_alert_replay_guard(self):
        now = time.time()
        job_id, item = self.add('long-running-alert')
        item['properties']['expires'] = iso(now + 86400)
        with queue.state_lock() as data:
            data['jobs'][job_id].update(state='complete', updated_at=now - 8 * 86400)
            data['snapshots']['north']['active'][queue.alert_key(item)] = item
        with queue.state_lock() as data:
            self.assertNotIn('payload', data['jobs'][job_id])
            queue.enqueue_locked(data, 'nws', 'north', queue.alert_key(item),
                {'zone': 'TXC491', 'feature': item}, now, [0, 0])
            self.assertEqual(data['jobs'][job_id]['state'], 'complete')
            data['snapshots']['north']['active'] = {}
            queue.compact_terminal_history(data, now + 86401)
            self.assertNotIn(job_id, data['jobs'])

    def test_oversized_state_failure_is_specific_and_preserves_disk(self):
        self.add('existing')
        before = (self.directory / 'weather-delivery.json').read_bytes()
        with mock.patch.object(queue, 'MAX_BYTES', len(before) + 100):
            with self.assertRaisesRegex(queue.WeatherQueueCapacityError, 'state update was not saved'):
                with queue.state_lock() as data:
                    data['snapshots']['large'] = {'value': 'x' * len(before)}
        self.assertEqual((self.directory / 'weather-delivery.json').read_bytes(), before)

    def test_polling_does_not_take_delivery_worker_lock(self):
        row = ['north','North','TXC491','1000','','','0','21:00','06:00','','']
        with queue.singleton('weather-dispatch-worker.lock') as locked:
            self.assertTrue(locked)
            with mock.patch.object(queue, 'groups', return_value={'north':row}), \
                 mock.patch.object(queue, 'fetch_zone', return_value=[feature()]), \
                 mock.patch.object(queue, 'reconcile_status'), mock.patch.object(queue, 'mutate_status'), \
                 mock.patch.object(queue, 'write_gate'):
                self.assertEqual(queue.observe_nws(),1)
        with queue.state_lock() as data: self.assertEqual(len(data['jobs']),1)

    def test_no_slow_external_retry_in_dispatch_loop(self):
        self.add('fixture')
        with mock.patch.object(queue,'dispatch_nws',return_value=('complete','fixture')), \
             mock.patch.object(queue,'retry_pending_external',side_effect=AssertionError('No network in local worker')):
            self.assertEqual(queue.dispatch(),0)

    def test_original_audience_is_frozen_across_queued_update(self):
        payload = {'feature': feature(), 'routing_snapshot': {'phones': ['1000']}}
        with queue.state_lock() as data:
            job_id = queue.enqueue_locked(data, 'nws', 'north', 'chain', payload, time.time(), [0, 0])
            queue.enqueue_locked(data, 'nws', 'north', 'chain',
                {'feature': feature('update'), 'routing_snapshot': {'phones': ['1000', '1001']}}, time.time(), [0, 0])
            self.assertEqual(data['jobs'][job_id]['payload']['routing_snapshot'], {'phones': ['1000']})
            self.assertEqual(data['jobs'][job_id]['payload']['feature']['id'], 'update')

    def test_dispatch_does_not_add_new_weather_recipients(self):
        current = ['north', 'North', 'TXC491', '1000,1001', 'original,new',
                   'original@example.com new@example.com', '0', '21:00', '06:00', '', 'generic:first,generic:new']
        job = {'group_id': 'north', 'payload': {'zone': 'TXC491', 'feature': feature(),
            'routing_snapshot': {'phones': ['1000'], 'desktops': ['original'],
                                 'emails': ['original@example.com'], 'webhooks': ['generic:first']}}}
        with mock.patch.object(queue, 'groups', return_value={'north': current}), \
             mock.patch.object(queue.subprocess, 'run', return_value=mock.Mock(returncode=0)) as run:
            self.assertEqual(queue.dispatch_nws('fixture', job)[0], 'complete')
            environment = run.call_args.kwargs['env']
            self.assertEqual(environment['NWS_RECIPIENTS_OVERRIDE'], '1000')
            self.assertEqual(environment['NWS_DESKTOP_CLIENTS_OVERRIDE'], 'original')
            self.assertEqual(environment['NWS_EMAIL_RECIPIENTS_OVERRIDE'], 'original@example.com')
            self.assertEqual(environment['NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE'], 'generic:first')
            self.assertEqual(environment['NWS_AUDIO_DELIVERY_LOCK'], str(self.directory/'nws-audio-delivery-north.lock'))
            self.assertLessEqual(int(environment['SLS_WORKER_DEADLINE_EPOCH'])-time.time(),queue.DELIVERY_SECONDS)

    def test_external_uncertainty_is_an_active_health_fault(self):
        row = ['north', 'North', 'TXC491', '1000', '', '', '0', '21:00', '06:00', '', '']
        (self.directory / 'external-deliveries-north.json').write_text('{}')
        outcome = mock.Mock(returncode=1, stdout=json.dumps({'results': [], 'pending': 0, 'uncertain': 1}))
        with mock.patch.object(queue, 'groups', return_value={'north': row}), \
             mock.patch.object(queue.subprocess, 'run', return_value=outcome):
            queue.retry_pending_external()
        status = json.loads((self.directory / 'status.json').read_text())
        self.assertIn('unconfirmed', status['nws_groups']['north']['faults']['external']['message'])
        self.assertEqual(status['last_fault_stage'], 'external')
        queue.mutate_status(self.directory / 'status.json', 'north', 'North', 'TXC491', {
            'clear_faults': True, 'preserve_fault_stages': ['external'], 'reset_api': True,
            'patch': {'last_delivery_status': 'queued', 'last_delivery_message': 'Later local delivery succeeded.'}})
        status = json.loads((self.directory / 'status.json').read_text())
        self.assertIn('external', status['nws_groups']['north']['faults'])
        self.assertEqual(status['last_fault_stage'], 'external')


    def process_launcher(self, behaviors):
        """Actual child processes, with the only delivery adapter replaced."""
        runner = self.directory / 'delivery-fixture.py'
        runner.write_text('''import importlib.util, json, os, signal, subprocess, sys, time
from pathlib import Path
sys.path.insert(0, sys.argv[1])
spec=importlib.util.spec_from_file_location('fixture_queue', sys.argv[2])
q=importlib.util.module_from_spec(spec); spec.loader.exec_module(q)
q.DELIVERY_STOP_GRACE=0.1
trace=Path(os.environ['DATA_DIR'])/'trace.jsonl'
behaviors=json.loads(os.environ['FIXTURE_BEHAVIORS'])
def mark(event, name):
    with trace.open('a') as out: out.write(json.dumps({'event':event,'name':name,'time':time.monotonic(),'pid':os.getpid()})+'\\n')
def inert(job_id, job):
    name=job['payload']['feature']['id']; behavior=behaviors.get(name,{})
    mark('start',name)
    if behavior.get('grandchild'):
        child_code="import os,signal,time; from pathlib import Path; signal.signal(signal.SIGTERM,signal.SIG_IGN); Path(os.environ['DATA_DIR']+'/grandchild.pid').write_text(str(os.getpid())); time.sleep(30)"
        subprocess.Popen(['/usr/bin/timeout','30',sys.executable,'-c',child_code])
    try:
        time.sleep(behavior.get('sleep',0.03))
    except Exception:
        mark('swallowed',name)
        time.sleep(30)
    if behavior.get('crash'):
        os._exit(9)
    mark('end',name)
    return 'complete','Inert fixture finished'
q.dispatch_nws=inert
raise SystemExit(q.deliver_claim(sys.argv[3],sys.argv[4]))
''')
        def launch(job_id, job):
            return queue.subprocess.Popen([sys.executable, str(runner), str(ROOT), str(CANDIDATE), job_id, job['claim_token']],
                stdin=queue.subprocess.DEVNULL, stdout=queue.subprocess.DEVNULL, stderr=queue.subprocess.DEVNULL,
                start_new_session=True, close_fds=True, env=dict(os.environ,
                    DATA_DIR=str(self.directory), FIXTURE_BEHAVIORS=json.dumps(behaviors)))
        return launch

    def recipients(self, job_id, phones):
        with queue.state_lock() as data:
            data['jobs'][job_id]['payload']['routing_snapshot'] = {'phones': phones, 'desktops': [], 'emails': [], 'webhooks': []}

    def traces(self):
        return [json.loads(line) for line in (self.directory / 'trace.jsonl').read_text().splitlines()]

    def test_two_processes_free_an_independent_area_without_reordering_overlap(self):
        now = time.time()
        slow, _ = self.add('slow', 'north', issued=now-30)
        overlap, _ = self.add('overlap', 'south', issued=now-20)
        independent, _ = self.add('independent', 'west', issued=now-10)
        self.recipients(slow, ['1000']); self.recipients(overlap, ['1000']); self.recipients(independent, ['1001'])
        launch = self.process_launcher({'slow': {'sleep': 0.7}, 'independent': {'sleep': 0.05}})
        with mock.patch.object(queue, 'start_delivery', side_effect=launch), \
             mock.patch.object(queue, 'DISPATCH_SECONDS', 8), mock.patch.object(queue, 'DELIVERY_SECONDS', 5):
            self.assertEqual(queue.dispatch(), 0)
        trace = self.traces()
        event_time = lambda kind, name: next(row['time'] for row in trace if row['event'] == kind and row['name'] == name)
        self.assertLess(event_time('start','independent'), event_time('end','slow'))
        self.assertLess(event_time('end','slow'), event_time('start','overlap'))
        self.assertEqual(len([row for row in trace if row['event']=='start']),3)
        active = maximum = 0
        for row in sorted(trace,key=lambda row:row['time']):
            active += 1 if row['event']=='start' else -1
            maximum=max(maximum,active)
        self.assertEqual(maximum,2)
        with queue.state_lock() as data:
            self.assertTrue(all(data['jobs'][job_id]['state']=='complete' for job_id in (slow,overlap,independent)))
            self.assertEqual(data['dispatch_metrics']['running'],0)
            self.assertEqual(data['dispatch_metrics']['max_workers'],2)

    def test_overlap_canonicalization_and_blocked_predecessor(self):
        now=time.time()
        first,_=self.add('first','north',issued=now-30)
        second,_=self.add('second','south',issued=now-20)
        third,_=self.add('third','west',issued=now-10)
        now=time.time()
        with queue.state_lock() as data:
            data['jobs'][first]['payload']['routing_snapshot']={'desktops':['ALICE'],'emails':['Ops@Example.com'],'webhooks':['generic:shared']}
            data['jobs'][second]['payload']['routing_snapshot']={'desktops':['alice','bob']}
            data['jobs'][third]['payload']['routing_snapshot']={'desktops':['BOB']}
            self.assertEqual(queue.choose_deliveries(data,now,2),[first])
            data['jobs'][first]['state']='running'
            self.assertEqual(queue.choose_deliveries(data,now,1),[])
            for snapshot in ({'emails':['ops@example.com']},{'webhooks':['generic:shared']}):
                other={'service':'nws','group_id':'other','payload':{'routing_snapshot':snapshot}}
                self.assertTrue(queue.audiences_overlap(queue.audience_keys(data['jobs'][first]),queue.audience_keys(other)))

    def test_webhook_alias_urls_conflict_without_exposing_secret_urls(self):
        secret='https://EXAMPLE.com:443/hooks/private-token?key=secret'
        (self.directory/'mass-notifications.config').write_text(json.dumps({'generic_webhooks':[
            {'id':'one','url':secret,'enabled':'1'},
            {'id':'two','url':'https://example.com/hooks/private-token?key=secret','enabled':'1'}]}))
        aliases=queue.webhook_audience_aliases()
        self.assertEqual(aliases['generic:one'],aliases['generic:two'])
        self.assertNotIn('secret',json.dumps(aliases))
        first={'service':'nws','group_id':'north','payload':{'routing_snapshot':{'webhooks':['generic:one']}}}
        second={'service':'lightning','group_id':'south','payload':{'routing_snapshot':{'webhooks':['generic:two']}}}
        self.assertTrue(queue.audiences_overlap(queue.audience_keys(first,aliases),queue.audience_keys(second,aliases)))
        first['webhook_aliases']=[aliases['generic:one']]
        changed={'generic:one':'a'*64,'generic:two':aliases['generic:two']}
        self.assertTrue(queue.audiences_overlap(queue.audience_keys(first,changed),queue.audience_keys(second,changed)))

    def test_delivery_deadline_kills_timeout_grandchild_and_suppresses_replay(self):
        job_id,_=self.add('stalled','north')
        self.recipients(job_id,['1000'])
        launch=self.process_launcher({'stalled':{'sleep':30,'grandchild':True}})
        with mock.patch.object(queue,'start_delivery',side_effect=launch), \
             mock.patch.object(queue,'DELIVERY_SECONDS',0.4), mock.patch.object(queue,'DISPATCH_SECONDS',5), \
             mock.patch.object(queue,'DELIVERY_STOP_GRACE',0.15):
            started=time.monotonic(); queue.dispatch()
            self.assertLess(time.monotonic()-started,3)
        pid=int((self.directory/'grandchild.pid').read_text())
        identity=queue.process_identity(pid)
        self.assertTrue(identity is None or identity['state']=='Z','Timeout grandchild survived the delivery deadline')
        with queue.state_lock() as data:
            self.assertEqual(data['jobs'][job_id]['state'],'uncertain')
            self.assertTrue(data['jobs'][job_id]['deadline_missed'])
            self.assertEqual(data['dispatch_metrics']['deadline_misses'],1)
        with mock.patch.object(queue,'start_delivery') as spawn:
            queue.dispatch(); spawn.assert_not_called()

    def test_replacement_coordinator_adopts_running_claim_without_replay(self):
        old,_=self.add('adopted','north',issued=time.time()-10)
        later,_=self.add('later','south')
        self.recipients(old,['1000']); self.recipients(later,['1000'])
        launch=self.process_launcher({'adopted':{'sleep':0.5}})
        with queue.state_lock() as data:
            job=queue.claim_delivery(data,old,time.time(),time.time()+5)
        process=launch(old,job)
        try:
            identity=queue.process_identity(process.pid)
            with queue.state_lock() as data:
                data['jobs'][old].update(worker_pid=process.pid,worker_start_ticks=identity['start_ticks'])
            with mock.patch.object(queue,'start_delivery',side_effect=launch) as spawn, \
                 mock.patch.object(queue,'DISPATCH_SECONDS',8):
                queue.dispatch()
                self.assertEqual(spawn.call_count,1)
                self.assertEqual(spawn.call_args.args[0],later)
            process.wait(timeout=2)
            trace=self.traces()
            self.assertEqual([row['name'] for row in trace if row['event']=='start'],['adopted','later'])
            self.assertLess(next(row['time'] for row in trace if row['name']=='adopted' and row['event']=='end'),
                            next(row['time'] for row in trace if row['name']=='later' and row['event']=='start'))
        finally:
            if process.poll() is None:
                queue.stop_delivery_session(process.pid,identity['start_ticks']); process.wait(timeout=2)

    def test_duplicate_launcher_cannot_consume_a_claim_twice(self):
        job_id,_=self.add('single-claim','north')
        self.recipients(job_id,['1000'])
        launch=self.process_launcher({'single-claim':{'sleep':0.2}})
        with queue.state_lock() as data:
            job=queue.claim_delivery(data,job_id,time.time(),time.time()+3)
        processes=[launch(job_id,job),launch(job_id,job)]
        try:
            outcomes=[process.wait(timeout=3) for process in processes]
            self.assertEqual(sorted(outcomes),[0,1])
            self.assertEqual(len([row for row in self.traces() if row['event']=='start']),1)
        finally:
            for process in processes:
                if process.poll() is None:
                    identity=queue.process_identity(process.pid)
                    queue.stop_delivery_session(process.pid,identity['start_ticks']); process.wait(timeout=2)

    def test_identity_commit_failure_retains_live_claim_and_overlap_reservation(self):
        first,_=self.add('identity-write-failed','north',issued=time.time()-10)
        later,_=self.add('later','south')
        self.recipients(first,['1000']); self.recipients(later,['1000'])
        launch=self.process_launcher({'identity-write-failed':{'sleep':0.5}})
        failure={'armed':False,'seen':False}
        processes=[]
        @contextmanager
        def interrupted_lock(*args,**kwargs):
            if failure['armed'] and kwargs.get('write',True):
                failure.update(armed=False,seen=True)
                raise RuntimeError('simulated parent identity commit failure')
            with self.real_lock(self.directory,**kwargs) as data:
                yield data
        def launch_registered(job_id,job):
            process=launch(job_id,job); processes.append(process)
            if job_id==first:
                deadline=time.monotonic()+2
                while time.monotonic()<deadline:
                    if (self.directory/'trace.jsonl').exists() and any(row['event']=='start' for row in self.traces()):
                        break
                    time.sleep(0.01)
                else:
                    self.fail('Fixture child did not enter its durable claim')
                failure['armed']=True
            return process
        try:
            with mock.patch.object(queue,'state_lock',side_effect=interrupted_lock), \
                 mock.patch.object(queue,'start_delivery',side_effect=launch_registered) as spawn, \
                 mock.patch.object(queue,'DISPATCH_SECONDS',8), mock.patch.object(queue,'DELIVERY_SECONDS',5):
                self.assertEqual(queue.dispatch(),0)
                self.assertEqual(spawn.call_count,2)
            self.assertTrue(failure['seen'])
            trace=self.traces()
            self.assertEqual([row['name'] for row in trace if row['event']=='start'],['identity-write-failed','later'])
            self.assertLess(next(row['time'] for row in trace if row['event']=='end' and row['name']=='identity-write-failed'),
                            next(row['time'] for row in trace if row['event']=='start' and row['name']=='later'))
            with queue.state_lock() as data:
                self.assertEqual(data['jobs'][first]['state'],'complete')
                self.assertEqual(data['jobs'][later]['state'],'complete')
        finally:
            for process in processes:
                if process.poll() is None:
                    identity=queue.process_identity(process.pid)
                    queue.stop_delivery_session(process.pid,identity['start_ticks']); process.wait(timeout=2)

    def test_crashed_predecessor_does_not_strand_queued_overlap(self):
        first,_=self.add('crashed','north',issued=time.time()-10)
        later,_=self.add('later','south')
        self.recipients(first,['1000']); self.recipients(later,['1000'])
        launch=self.process_launcher({'crashed':{'crash':True}})
        with mock.patch.object(queue,'start_delivery',side_effect=launch) as spawn, \
             mock.patch.object(queue,'DISPATCH_SECONDS',8), mock.patch.object(queue,'DELIVERY_SECONDS',5):
            self.assertEqual(queue.dispatch(),0)
            self.assertEqual(spawn.call_count,2)
        self.assertEqual([row['name'] for row in self.traces() if row['event']=='start'],['crashed','later'])
        with queue.state_lock() as data:
            self.assertEqual(data['jobs'][first]['state'],'uncertain')
            self.assertEqual(data['jobs'][later]['state'],'complete')

    def test_worker_own_deadline_survives_missing_coordinator(self):
        job_id,_=self.add('orphaned','north')
        self.recipients(job_id,['1000'])
        launch=self.process_launcher({'orphaned':{'sleep':30,'grandchild':True}})
        with queue.state_lock() as data:
            job=queue.claim_delivery(data,job_id,time.time(),time.time()+0.4)
        process=launch(job_id,job)
        try:
            self.assertEqual(process.wait(timeout=3),1)
            self.assertNotIn('swallowed',[row['event'] for row in self.traces()])
            pid=int((self.directory/'grandchild.pid').read_text())
            identity=queue.process_identity(pid)
            self.assertTrue(identity is None or identity['state']=='Z')
            with queue.state_lock() as data:
                self.assertEqual(data['jobs'][job_id]['state'],'uncertain')
                self.assertTrue(data['jobs'][job_id]['deadline_missed'])
        finally:
            if process.poll() is None:
                identity=queue.process_identity(process.pid)
                queue.stop_delivery_session(process.pid,identity['start_ticks']); process.wait(timeout=2)

    def test_queue_expiry_and_claim_age_are_diagnostic(self):
        expired,_=self.add('expired'); ready,_=self.add('ready','south')
        now=time.time()
        with queue.state_lock() as data:
            data['jobs'][expired]['created_at']=now-queue.QUEUE_MAX_AGE-1
            data['jobs'][ready]['created_at']=now-125
            chosen=queue.choose_deliveries(data,now,2)
            self.assertEqual(chosen,[ready])
            claim=queue.claim_delivery(data,ready,now,now+200)
            self.assertEqual(claim['queue_age_seconds'],125)
            self.assertEqual(claim['deadline_at'],now+200)
            queue.update_dispatch_metrics(data,now)
            self.assertTrue(data['jobs'][expired]['deadline_missed'])
            self.assertEqual(data['dispatch_metrics']['deadline_misses'],1)
            self.assertEqual(data['dispatch_metrics']['running'],1)


if __name__ == '__main__': unittest.main()
