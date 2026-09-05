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
        # Default argument paths are explicit in storage-only tests; dispatcher
        # calls use the patched wrapper below, never the production directory.
        self.real_lock = queue.state_lock
        self.lock_patch = mock.patch.object(queue, 'state_lock', side_effect=lambda *args: self.real_lock(self.directory))
        self.lock_patch.start()
        self.retry = mock.patch.object(queue, 'kick_external'); self.retry.start()
        self.enabled = mock.patch.object(queue, 'services_enabled', return_value=True); self.enabled.start()

    def tearDown(self):
        self.enabled.stop(); self.retry.stop(); self.lock_patch.stop(); self.patch.stop(); self.temp.cleanup()

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


if __name__ == '__main__': unittest.main()
