#!/usr/bin/python3
"""Private native-backup evidence and non-replay restoration fault cases."""
import base64
import fcntl
import hashlib
import importlib.util
import json
import os
import re
import struct
import zlib
from pathlib import Path
import sys
import sqlite3
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('operational_backup_fixture', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_operational_backup.py')
backup = importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(backup)


class OperationalBackupTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-operational-backup-'); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name); self.data = self.root / 'data'; self.data.mkdir(mode=0o700)
        self.target = self.root / 'target'; self.target.mkdir(mode=0o700)
        self.file = self.root / 'snapshot.jsonl'
        self.records = {
            '.automations-required': b'SLS_AUTOMATION_STATE_V1\n',
            'automations/worker-state.json': b'{"schema":1,"events":{"accepted":"never replay"}}',
            'automations/announcement-send.lock': b'',
            'operator-login-failures.json': b'{"schema":1,"last_time":1,"ips":{}}',
            'seen-alerts-zone_fixture.txt': b'known-weather-alert\n',
            'seen-alerts-zone_fixture.txt.lock': b'SLS_NWS_LEGACY_HISTORY_V1\n',
            'nws-cross-zone-delivery-claims.json': b'{"uncertain":"do not repeat"}',
            'nws-cross-zone-delivery-claims.json.lock': b'SLS_NWS_COORDINATION_V1\n',
            'weather-delivery.json': b'{"jobs":{"fixture":{"state":"queued"}}}',
            'weather-delivery.lock': b'SLS_WEATHER_OUTBOX_V1\n',
            'phone-admission.json': b'{"outbound_budget":{"used":12},"batches":{}}',
            'phone-admission.lock': b'',
            '.phone-admission-required': b'SLS_PHONE_ADMISSION_V1\n',
            'control-idempotency/announcement_claims.json': b'{"uncertain":"never repeat"}',
            'control-idempotency/announcement_claims.required': b'SLS_ENTERPRISE_STATE_V1\n',
            'enterprise-state/browser_subscriptions.json': b'{"endpoint":"revocable"}',
            'enterprise-state/browser_subscriptions.required': b'SLS_ENTERPRISE_STATE_V1\n',
            'enterprise-state/browser_push_claims.json': b'{"receipt":"provider accepted only"}',
            'enterprise-state/browser_push_claims.required': b'SLS_ENTERPRISE_STATE_V1\n',
            'enterprise-operations/.archive-required': b'SLS_ENTERPRISE_STATE_V1\n',
            'enterprise-operations/archive/approvals.json': b'{"review":"audit history"}',
            'enterprise-operations/archive/approvals.required': b'SLS_ENTERPRISE_STATE_V1\n',
            'enterprise-operations/archive/floorplan_retirements.json': b'{"retirement":"audit history"}',
            'enterprise-operations/archive/floorplan_retirements.required': b'SLS_ENTERPRISE_STATE_V1\n',
            'enterprise-integrations/.archive-required': b'SLS_ENTERPRISE_STATE_V1\n',
            'enterprise-integrations/archive/operations.json': b'{"sent":"never replay"}',
            'enterprise-integrations/archive/operations.required': b'SLS_ENTERPRISE_STATE_V1\n',
            'enterprise-integrations/archive/operations_ab.json': b'{"ab":"sharded full evidence"}',
            'enterprise-integrations/archive/operations_ab.required': b'SLS_ENTERPRISE_STATE_V1\n',
            'enterprise-integrations/archive/responses_cd.json': b'{"cd":"sharded response evidence"}',
            'enterprise-integrations/archive/responses_cd.required': b'SLS_ENTERPRISE_STATE_V1\n',
            'enterprise-integrations/archive/operation_tombstones_ef.json': b'{"ef":"compact replay evidence"}',
            'enterprise-integrations/archive/operation_tombstones_ef.required': b'SLS_ENTERPRISE_STATE_V1\n',
            'enterprise-integrations/archive/response_tombstones_01.json': b'{"01":"compact response evidence"}',
            'enterprise-integrations/archive/response_tombstones_01.required': b'SLS_ENTERPRISE_STATE_V1\n',
            'xweather-lightning-state-fixture.json': b'{"active":true,"notified":true}',
            'sipnotify/sipnotify_events.jsonl': b'{"id":"stable-event","created_at":"2026-09-26T00:00:00Z"}\n',
            'sipnotify/acknowledgements/' + 'a' * 64 + '.json': b'{"acknowledgements":{"event":true}}',
            'announcement-jobs/job_' + 'b' * 32 + '.json': b'{"state":"running","delivery":{"event_id":"stable-event"}}',
            'incidents/inc_' + 'c' * 32 + '.json': b'{"state":"open","responses":{"person":"needs_assistance"}}',
        }
        for name, body in self.records.items():
            path = self.data / name; path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(body); path.chmod(0o600)
        (self.data / 'mass-notifications.config').write_bytes(b'never include configuration here')

    def snapshot(self):
        report = backup.snapshot(self.data, self.file)
        self.assertEqual(report['files'], len(self.records))
        return self.file.read_bytes()

    def test_unfinished_initialization_is_not_silently_omitted(self):
        for name, body in (
            ('enterprise-state/login.initializing', b'SLS_ENTERPRISE_LEDGER_INIT_V1\nlogin\n'),
            ('.incident-replay-initializing.json', backup.line({'schema': 1, 'ids': ['inc_' + 'a' * 32]})),
        ):
            with self.subTest(name=name):
                intent = self.data / name
                intent.write_bytes(body)
                intent.chmod(0o600)
                with self.assertRaisesRegex(backup.RecoveryError, 'unfinished first initialization'):
                    backup.snapshot(self.data, self.file)
                self.assertFalse(self.file.exists())
                self.assertEqual(intent.read_bytes(), body)
                intent.unlink()

    def test_missing_established_replay_guard_is_not_replaced_during_restore(self):
        original={'inc_'+'a'*32};incoming={'inc_'+'b'*32}
        backup.retain_replay_guard(self.target,original)
        marker=self.target/'.incident-replay-required';guard=self.target/'.incident-replay-blocked.json'
        before=marker.read_bytes();guard.unlink()
        with self.assertRaises(backup.RecoveryError):backup.retain_replay_guard(self.target,incoming)
        self.assertEqual(marker.read_bytes(),before);self.assertFalse(guard.exists())

    def test_replay_guard_rejects_duplicate_ids_fields_and_boolean_schema(self):
        for raw in ('{"schema":1,"ids":["inc_'+'a'*32+'"],"ids":[]}',
                    '{"schema":true,"ids":[]}', '{"schema":1,"ids":[],"unused":1e309}'):
            with self.subTest(raw=raw):
                with self.assertRaises(backup.RecoveryError):backup.inspect_replay_guard(raw)

    def test_replay_initialization_resumes_after_each_first_commit_boundary(self):
        incoming={'inc_'+'a'*32};real_write=backup.write_new;real_replace=backup.os.replace
        for boundary in ('marker','guard','legacy_marker'):
            with self.subTest(boundary=boundary):
                target=self.root/('restore-'+boundary);target.mkdir(mode=0o700)
                legacy={'inc_'+'b'*32} if boundary=='legacy_marker' else set()
                if legacy:(target/'.incident-replay-blocked.json').write_bytes(backup.line({'schema':1,'ids':sorted(legacy)}))
                def fail_write(path,body):
                    if Path(path).name=='.incident-replay-required':raise OSError('Private marker failure')
                    return real_write(path,body)
                def fail_replace(*args,**kwargs):raise OSError('Private guard commit failure')
                with mock.patch.object(backup,'write_new',side_effect=fail_write if boundary!='guard' else real_write),mock.patch.object(backup.os,'replace',side_effect=fail_replace if boundary=='guard' else real_replace):
                    with self.assertRaises(OSError):backup.retain_replay_guard(target,incoming)
                self.assertTrue((target/'.incident-replay-initializing.json').exists())
                backup.retain_replay_guard(target,incoming)
                self.assertEqual(backup.inspect_replay_guard((target/'.incident-replay-blocked.json').read_bytes()),incoming|legacy)
                self.assertFalse((target/'.incident-replay-initializing.json').exists())

    def test_aggregate_size_is_checked_before_any_output_is_created(self):
        for index in range(5):
            path=self.data/('xweather-lightning-state-'+str(index)+'.json')
            with path.open('wb') as stream:stream.truncate(12*1024*1024)
            path.chmod(0o600)
        with self.assertRaisesRegex(backup.RecoveryError,'including encoding'):
            backup.snapshot(self.data,self.file)
        self.assertFalse(self.file.exists())

    def test_snapshot_omits_only_expired_transient_enterprise_rows(self):
        transient = {
            'enterprise-state/login.json': {'expired': {'expires_at': 4999}, 'active': {'expires_at': 5001}},
            'enterprise-state/replay.json': {'expired': 5000, 'active': 5001},
            'enterprise-state/subscriber_tokens.json': {
                'expired': {'created_at': 1, 'expires_at': 2},
                'rate_window': {'created_at': 4500, 'expires_at': 4600},
                'active': {'created_at': 4900, 'expires_at': 5100},
            },
            'enterprise-state/browser_test_events.json': {'expired': {'expires_at': 5000}, 'active': {'expires_at': 5001}},
            'control-idempotency/announcement_claims.json': {'expired': {'expires_at': 4999}, 'active': {'expires_at': 5001}},
        }
        source = {}
        for name, rows in transient.items():
            path = self.data / name; path.parent.mkdir(parents=True, exist_ok=True)
            raw = json.dumps(rows, separators=(',', ':')).encode(); path.write_bytes(raw); path.chmod(0o600); source[name] = raw
        with mock.patch.object(backup.time, 'time', return_value=5000): backup.snapshot(self.data, self.file)
        captured = {row['path']: base64.b64decode(row['base64']) for row in map(json.loads, self.file.read_bytes().splitlines()[1:-1])}
        self.assertEqual(json.loads(captured['enterprise-state/login.json']), {'active': {'expires_at': 5001}})
        self.assertEqual(json.loads(captured['enterprise-state/replay.json']), {'active': 5001})
        self.assertEqual(set(json.loads(captured['enterprise-state/subscriber_tokens.json'])), {'rate_window', 'active'})
        self.assertEqual(json.loads(captured['enterprise-state/browser_test_events.json']), {'active': {'expires_at': 5001}})
        self.assertEqual(json.loads(captured['control-idempotency/announcement_claims.json']), {'active': {'expires_at': 5001}})
        self.assertEqual({name: (self.data / name).read_bytes() for name in source}, source)

    def test_exact_evidence_and_restore_does_not_activate_any_source_file(self):
        body = self.snapshot(); report = backup.inspect(body)
        decoded = {row['path']: base64.b64decode(row['base64']) for row in map(json.loads, body.splitlines()[1:-1])}
        self.assertEqual(decoded, self.records)
        self.assertEqual(report['policy'], 'evidence_only_no_replay')
        self.assertNotIn(b'never include configuration', body)
        live = self.target / 'weather-delivery.json'; live.write_bytes(b'current history must survive')
        result = backup.archive(self.file, self.target, hashlib.sha256(body).hexdigest())
        self.assertEqual(live.read_bytes(), b'current history must survive')
        self.assertFalse(list((self.target / 'incidents').glob('inc_*.json'))); self.assertFalse((self.target / 'sipnotify').exists())
        guard=backup.inspect_replay_guard((self.target/'.incident-replay-blocked.json').read_bytes())
        self.assertEqual(guard,{'inc_'+'c'*32})
        archive = self.target / 'recovery-archives' / result['archive_name']
        self.assertEqual(archive.read_bytes(), body)
        self.assertEqual(archive.stat().st_mode & 0o777, 0o600)
        self.assertEqual(archive.parent.stat().st_mode & 0o777, 0o700)
        self.assertEqual(backup.archive(self.file, self.target), result)
        for name, original in self.records.items(): self.assertEqual((self.data / name).read_bytes(), original)

    def test_both_audit_channels_and_recovery_evidence_remain_passive_on_restore(self):
        # Historical audit bytes, including malformed/incomplete records, must
        # survive as evidence rather than being parsed, repaired or replayed.
        audit = {
            'control-api-audit.jsonl': b'{"action":"unauthorized","credential_id":"legacy"}\nmalformed\n{"unfinished":',
            'security-audit.jsonl': b'{"action":"operator_sign_in_completed","actor":"operator:fixture"}\r\n',
            'control-api-audit-fault.json': b'{"active":false,"failed_records":12}',
            'security-audit-fault.json': b'{"active":true,"failed_records":3}',
            'control-api-audit-forwarding.json': b'{"active":false,"last_success_at":42}',
            'security-audit-forwarding.json': b'{"active":true,"error_code":"audit_syslog_unavailable"}',
            '.sls-retention-control-api-audit.jsonl.backup': b'original API audit bytes\n',
            '.sls-retention-control-api-audit.jsonl.kept': b'pending API retention bytes\n',
            '.sls-retention-security-audit.jsonl.backup': b'original security audit bytes\n',
            '.sls-retention-security-audit.jsonl.kept': b'pending security retention bytes\n',
            '.audit-separation-required': b'SLS_AUDIT_SEPARATION_V1\n',
        }
        snapshots={name:b'original raw migration evidence\n' for name in ('audit-separation-original.jsonl','audit-separation-security-before.jsonl','audit-separation-control-after.jsonl','audit-separation-security-after.jsonl')}
        audit.update(snapshots)
        audit['audit-separation.json']=json.dumps({'schema':1,'state':'complete','predicate':'positive-human-audit-v1','created_at':42,'completed_at':43,'moved_records':4,'retained_records':75,'snapshots':{name:{'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()} for name,raw in snapshots.items()}}).encode()
        excluded = ['security-audit-extra.jsonl', 'control-api-audit.jsonl.backup',
                    'audit-separation.json.tmp', 'audit-separation.lock',
                    'control-api-audit-fault.lock', 'security-audit-forwarding.lock']
        for name, raw in audit.items():
            path = self.data / name; path.write_bytes(raw); path.chmod(0o600)
        for name in excluded:
            path = self.data / name; path.write_bytes(b'not an approved evidence path'); path.chmod(0o600)
        source_before = {name: (self.data / name).read_bytes() for name in audit}
        report = backup.snapshot(self.data, self.file)
        self.assertEqual(report['files'], len(self.records) + len(audit))
        body = self.file.read_bytes()
        captured = {row['path']: base64.b64decode(row['base64'])
                    for row in map(json.loads, body.splitlines()[1:-1])}
        self.assertEqual(captured, dict(self.records, **audit))
        self.assertFalse(set(excluded) & set(captured))
        self.assertEqual(backup.inspect(body)['files'], len(captured))

        live = {name: b'current live evidence: ' + name.encode() for name in audit}
        for name, raw in live.items():
            path = self.target / name; path.write_bytes(raw); path.chmod(0o600)
        restored = backup.archive(self.file, self.target, hashlib.sha256(body).hexdigest())
        self.assertEqual({name: (self.target / name).read_bytes() for name in live}, live)
        self.assertEqual((self.target / 'recovery-archives' / restored['archive_name']).read_bytes(), body)
        self.assertEqual(backup.archive(self.file, self.target), restored)
        self.assertEqual({name: (self.data / name).read_bytes() for name in audit}, source_before)

        empty = self.root / 'empty-target'; empty.mkdir(mode=0o700)
        passive = backup.archive(self.file, empty)
        self.assertEqual((empty / 'recovery-archives' / passive['archive_name']).read_bytes(), body)
        self.assertTrue(all(not (empty / name).exists() for name in audit),
                        'Restore activated historical audit or separation state.')

    def test_audit_capture_refuses_busy_unsafe_or_oversized_evidence_without_partial_snapshot(self):
        for name in ('control-api-audit.jsonl', 'security-audit.jsonl',
                     '.audit-separation-required', 'audit-separation.json'):
            path = self.data / name; path.write_bytes(b'original private evidence\n'); path.chmod(0o600)
            with self.subTest(name=name), path.open('r+b') as held:
                fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
                with self.assertRaises((BlockingIOError,backup.RecoveryError)): backup.snapshot(self.data, self.file)
            self.assertFalse(self.file.exists())
            path.unlink()

        victim = self.root / 'unrelated'; victim.write_bytes(b'unrelated private bytes'); victim.chmod(0o600)
        path = self.data / 'security-audit.jsonl'
        for kind in ('symlink', 'hardlink', 'fifo'):
            if kind == 'symlink': path.symlink_to(victim)
            elif kind == 'hardlink': os.link(victim, path)
            else: os.mkfifo(path, 0o600)
            with self.subTest(kind=kind), self.assertRaises((OSError, backup.RecoveryError)):
                backup.snapshot(self.data, self.file)
            self.assertFalse(self.file.exists()); path.unlink()
        with path.open('wb') as handle: handle.truncate(backup.MAX_FILE + 1)
        path.chmod(0o600)
        with self.assertRaises(backup.RecoveryError): backup.snapshot(self.data, self.file)
        self.assertFalse(self.file.exists())
        self.assertEqual(victim.read_bytes(), b'unrelated private bytes')

    def test_incident_archive_capture_uses_permanent_locks_and_preserves_evidence(self):
        folder=self.data/'incidents/archive';folder.mkdir(mode=0o700)
        marker=b'SLS_INCIDENT_ARCHIVE_V1\n'
        for path,body in ((self.data/'incidents/.archive-required',marker),(folder/'initialized',marker),
                          (self.data/'incidents/admission.lock',b''),(folder/'storage.lock',b''),(folder/'reports.sqlite',b'private archived reports')):
            path.write_bytes(body);path.chmod(0o600)
        body=backup.snapshot(self.data,self.file)
        self.assertEqual(body['files'],len(self.records)+3)
        captured={row['path']:base64.b64decode(row['base64']) for row in map(json.loads,self.file.read_bytes().splitlines()[1:-1])}
        self.assertEqual(captured['incidents/archive/reports.sqlite'],b'private archived reports')
        self.assertNotIn('incidents/archive/storage.lock',captured)
        self.file.unlink()
        for path in (self.data/'incidents/admission.lock',folder/'storage.lock'):
            with path.open('r+b') as held:
                fcntl.flock(held,fcntl.LOCK_EX|fcntl.LOCK_NB)
                with self.assertRaises(BlockingIOError):backup.snapshot(self.data,self.file)
            self.assertFalse(self.file.exists())
        journal=folder/'reports.sqlite-journal';journal.write_bytes(b'pending SQLite recovery');journal.chmod(0o600)
        with self.assertRaises(backup.RecoveryError):backup.snapshot(self.data,self.file)

        journal.unlink();(folder/'initialized').write_bytes(b'bad marker')
        with self.assertRaises(backup.RecoveryError):backup.snapshot(self.data,self.file)
        self.assertFalse(self.file.exists())
        (folder/'initialized').write_bytes(marker);(self.data/'incidents/.archive-required').unlink()
        with self.assertRaises(backup.RecoveryError):backup.snapshot(self.data,self.file)

    def test_restore_merges_archived_and_active_replay_ids_without_loading_workflows(self):
        folder=self.data/'incidents/archive';folder.mkdir(mode=0o700)
        marker=b'SLS_INCIDENT_ARCHIVE_V1\n'
        for path,body in ((self.data/'incidents/.archive-required',marker),(folder/'initialized',marker),
                          (self.data/'incidents/admission.lock',b''),(folder/'storage.lock',b'')):
            path.write_bytes(body);path.chmod(0o600)
        path=folder/'reports.sqlite';db=sqlite3.connect(path)
        db.execute('CREATE TABLE reports(id TEXT PRIMARY KEY)');db.execute('PRAGMA user_version=1')
        db.execute('INSERT INTO reports VALUES (?)',('inc_'+'d'*32,));db.commit();db.close();path.chmod(0o600)
        backup.snapshot(self.data,self.file);result=backup.archive(self.file,self.target)
        ids=backup.inspect_replay_guard((self.target/'.incident-replay-blocked.json').read_bytes())
        self.assertEqual(ids,{'inc_'+'c'*32,'inc_'+'d'*32})
        self.assertFalse(list((self.target/'incidents').glob('inc_*.json')))
        self.assertFalse((self.target/'incidents/archive').exists())
        self.assertEqual(backup.archive(self.file,self.target),result)
        with (self.target/'incidents/admission.lock').open('r+b') as held:
            fcntl.flock(held,fcntl.LOCK_EX|fcntl.LOCK_NB)
            with self.assertRaises(BlockingIOError):backup.archive(self.file,self.target)
        raw=(self.target/'.incident-replay-blocked.json').read_bytes()
        with mock.patch.object(backup.os,'replace',side_effect=OSError('fixture rename')):
            with self.assertRaises(OSError):backup.retain_replay_guard(self.target,{'inc_'+'e'*32})
        self.assertEqual((self.target/'.incident-replay-blocked.json').read_bytes(),raw)
        self.assertFalse([p for p in self.target.iterdir() if re.fullmatch(r'\.incident-replay-[a-f0-9]{32}',p.name)])

    def test_corrupt_incomplete_duplicate_and_unapproved_path_archives_fail(self):
        original = self.snapshot(); rows = list(map(json.loads, original.splitlines()))
        for mutation in ('path', 'bytes', 'hash', 'base64', 'count', 'duplicate', 'truncated', 'time'):
            changed = json.loads(json.dumps(rows))
            if mutation == 'path': changed[1]['path'] = '../mass-notifications.config'
            elif mutation == 'bytes': changed[1]['bytes'] += 1
            elif mutation == 'hash': changed[1]['sha256'] = '0' * 64
            elif mutation == 'base64': changed[1]['base64'] += '\n'
            elif mutation == 'count': changed[-1]['files'] += 1
            elif mutation == 'duplicate': changed[2] = changed[1]
            elif mutation == 'truncated': changed.pop()
            elif mutation == 'time': changed[1]['captured_at'] = 0
            with self.subTest(mutation=mutation), self.assertRaises(backup.RecoveryError):
                backup.inspect(b''.join(map(backup.line, changed)))
        with self.assertRaises(backup.RecoveryError): backup.archive(self.file, self.target, '0' * 64)
        self.assertFalse((self.target / 'recovery-archives').exists())

    def test_symlink_hardlink_fifo_and_linked_parents_fail_without_output(self):
        path = self.data / 'weather-delivery.json'; path.unlink()
        victim = self.root / 'unrelated'; victim.write_bytes(b'unrelated secret')
        for kind in ('symlink', 'hardlink', 'fifo'):
            if kind == 'symlink': path.symlink_to(victim)
            elif kind == 'hardlink': os.link(victim, path)
            else: os.mkfifo(path)
            with self.subTest(kind=kind), self.assertRaises((OSError, backup.RecoveryError)): backup.snapshot(self.data, self.file)
            self.assertFalse(self.file.exists()); path.unlink()
        alias = self.root / 'alias'; alias.symlink_to(self.data, target_is_directory=True)
        with self.assertRaises(OSError): backup.snapshot(alias, self.file)
        self.assertEqual(victim.read_bytes(), b'unrelated secret')

    def test_busy_or_replaced_input_refuses_partial_snapshot(self):
        path = self.data / 'weather-delivery.json'
        with path.open('r+b') as held:
            fcntl.flock(held, fcntl.LOCK_EX)
            with self.assertRaises((BlockingIOError,backup.RecoveryError)): backup.snapshot(self.data, self.file)
        self.assertFalse(self.file.exists())
        real_stat = backup.os.stat
        def replace(name, *args, **kwargs):
            if name == path.name:
                path.unlink(); path.write_bytes(b'same path but different inode')
            return real_stat(name, *args, **kwargs)
        with mock.patch.object(backup.os, 'stat', side_effect=replace), self.assertRaises(backup.RecoveryError):
            backup.read(path, backup.MAX_FILE)

    def test_inventory_and_output_limits_are_failures_not_silent_omissions(self):
        with mock.patch.object(backup, 'MAX_FILES', 2), self.assertRaises(backup.RecoveryError): self.snapshot()
        with mock.patch.object(backup, 'MAX_ARCHIVE', 600), self.assertRaises(backup.RecoveryError): self.snapshot()
        with mock.patch.object(backup, 'MAX_FILE', 3), self.assertRaises(backup.RecoveryError): self.snapshot()
        self.assertFalse(self.file.exists())

    def test_snapshot_output_cannot_replace_existing_file_and_failed_sync_removes_new_file(self):
        self.file.write_bytes(b'previous backup')
        with self.assertRaises(FileExistsError): self.snapshot()
        self.assertEqual(self.file.read_bytes(), b'previous backup'); self.file.unlink()
        with mock.patch.object(backup.os, 'fsync', side_effect=OSError('fixture sync failure')), self.assertRaises(OSError): self.snapshot()
        self.assertFalse(self.file.exists())

    def test_snapshot_preserves_write_error_when_output_disappears_before_cleanup(self):
        real_sync = backup.os.fsync
        first = True

        def fail_after_removal(descriptor):
            nonlocal first
            if first:
                first = False
                self.file.unlink()
                raise OSError('fixture write sync failure')
            return real_sync(descriptor)

        with mock.patch.object(backup.os, 'fsync', side_effect=fail_after_removal), self.assertRaisesRegex(
                OSError, 'fixture write sync failure'):
            self.snapshot()
        self.assertFalse(self.file.exists())

    def test_write_new_does_not_unlink_a_replacement_after_sync_failure(self):
        destination = self.root / 'new-evidence'
        real_sync = backup.os.fsync
        first = True

        def replace_then_fail(descriptor):
            nonlocal first
            if first:
                first = False
                destination.unlink()
                destination.write_bytes(b'unrelated replacement')
                destination.chmod(0o600)
                raise OSError('fixture evidence sync failure')
            return real_sync(descriptor)

        with mock.patch.object(backup.os, 'fsync', side_effect=replace_then_fail), self.assertRaisesRegex(
                OSError, 'fixture evidence sync failure'):
            backup.write_new(destination, b'new evidence')
        self.assertEqual(destination.read_bytes(), b'unrelated replacement')

    def test_archive_capacity_and_contention_preserve_previous_evidence(self):
        first = self.snapshot(); backup.archive(self.file, self.target)
        with (self.target / 'recovery-archives/.archive.lock').open('r+b') as held:
            fcntl.flock(held, fcntl.LOCK_EX)
            with self.assertRaises(BlockingIOError): backup.archive(self.file, self.target)
        self.file.unlink(); (self.data / 'weather-delivery.json').write_bytes(b'changed delivery history')
        backup.snapshot(self.data, self.file)
        with mock.patch.object(backup, 'MAX_ARCHIVES', 1), self.assertRaises(backup.RecoveryError): backup.archive(self.file, self.target)
        archives = list((self.target / 'recovery-archives').glob('*.jsonl'))
        self.assertEqual(len(archives), 1); self.assertEqual(archives[0].read_bytes(), first)

    def test_enterprise_backup_archives_authority_and_restores_only_passive_images(self):
        image = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=')
        image_name = 'img_' + hashlib.sha256(image).hexdigest()
        evidence = {'.enterprise-cluster-required.json': json.dumps({'schema':1,'cluster_id':'fixture-cluster','node_id':'fixture-node','witness_epoch':'f'*64}).encode(),
                    '.enterprise-operations-required': b'SLS_ENTERPRISE_OPERATIONS_V1\n',
                    '.enterprise-integrations-required': b'SLS_ENTERPRISE_INTEGRATIONS_V1\n',
                    'enterprise-cluster/worker-state.json': b'{"effects":"never replay"}',
                    'enterprise-operations/worker-state.json': b'{"approvals":"never reactivate"}',
                    'enterprise-integrations/worker-state.json': b'{"operations":"never repeat"}',
                    'enterprise-floorplans/' + image_name: image}
        for name, content in evidence.items():
            path = self.data / name; path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            path.write_bytes(content); path.chmod(0o600)
        backup.snapshot(self.data, self.file)
        captured = {row['path'] for row in map(json.loads, self.file.read_bytes().splitlines()[1:-1])}
        self.assertTrue(set(evidence) <= captured)
        result = backup.archive(self.file, self.target)
        self.assertEqual(result['private_images_restored'], 1)
        self.assertEqual((self.target / 'enterprise-floorplans' / image_name).read_bytes(), image)
        self.assertEqual(result['enterprise_fences_retained'], 4)
        self.assertEqual((self.target/'.automations-required').read_bytes(),b'SLS_AUTOMATION_STATE_V1\n')
        self.assertFalse((self.target/'automations/worker-state.json').exists())
        for name, content in evidence.items():
            if name.startswith('.'):
                self.assertEqual((self.target / name).read_bytes(), content)
            elif not name.startswith('enterprise-floorplans/'):
                self.assertFalse((self.target / name).exists(), name)
        self.assertEqual(backup.archive(self.file, self.target), result)
        for name in ('.enterprise-operations-required', '.enterprise-integrations-required', '.enterprise-cluster-required.json'):
            (self.target / name).write_bytes(b'conflicting evidence')
            with self.assertRaises(backup.RecoveryError): backup.archive(self.file, self.target)
            (self.target / name).write_bytes(evidence[name])
        lock = self.target / 'enterprise-floorplans/.storage.lock'
        with lock.open('r+b') as held:
            fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaises(BlockingIOError):
                backup.archive(self.file, self.target)
        lock.write_bytes(b'unexpected')
        with self.assertRaises(backup.RecoveryError): backup.archive(self.file, self.target)
        lock.write_bytes(b''); lock.chmod(0o644)
        with self.assertRaises(backup.RecoveryError): backup.archive(self.file, self.target)
        lock.chmod(0o600)

        real_scandir = backup.os.scandir
        removed = False
        def remove_named_lock(descriptor):
            nonlocal removed
            if isinstance(descriptor, int) and not removed:
                opened_path = os.readlink('/proc/self/fd/' + str(descriptor))
                if opened_path.endswith('/enterprise-floorplans'):
                    os.unlink('.storage.lock', dir_fd=descriptor)
                    removed = True
            return real_scandir(descriptor)
        with mock.patch.object(backup.os, 'scandir', side_effect=remove_named_lock), self.assertRaisesRegex(
                backup.RecoveryError, 'lock disappeared'):
            backup.archive(self.file, self.target)
        self.assertTrue(removed)


    def test_private_image_parser_rejects_bombs_corruption_truncation_and_trailing_data(self):
        valid = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=')
        self.assertEqual(backup.private_image_info(valid), {'type':'png','width':1,'height':1})
        bad_crc = bytearray(valid); idat = bad_crc.index(b'IDAT'); bad_crc[idat + 4] ^= 1
        huge = bytearray(valid); huge[16:20] = struct.pack('>I', 100000)
        huge[29:33] = struct.pack('>I', zlib.crc32(huge[12:29]) & 0xffffffff)
        def chunk(kind, payload):
            return struct.pack('>I',len(payload))+kind+payload+struct.pack('>I',zlib.crc32(kind+payload)&0xffffffff)
        bomb_header = struct.pack('>IIBBBBB',1,1,8,6,0,0,0)
        bomb = b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',bomb_header)+chunk(b'IDAT',zlib.compress(b'\x00'*(1024*1024)))+chunk(b'IEND',b'')
        short = b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',bomb_header)+chunk(b'IDAT',zlib.compress(b'\x00'))+chunk(b'IEND',b'')
        for body in (valid[:-1], valid+b'trailing', bytes(bad_crc), bytes(huge), bomb, short):
            with self.assertRaises(ValueError): backup.private_image_info(body)



if __name__ == '__main__': unittest.main()
