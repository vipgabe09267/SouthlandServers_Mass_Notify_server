#!/usr/bin/python3
"""Private storage fault injection; no PBX bootstrap, network or notifications."""
import errno
import fcntl
import importlib.util
import json
import os
import socket
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest
from unittest import mock

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_storage_maintenance.py'
SPEC = importlib.util.spec_from_file_location('audit_storage_fixture', HELPER)
storage = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(storage)


class AuditStorageTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='sls-audit-storage-')
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.audit = self.directory / 'control-api-audit.jsonl'
        self.fault = self.directory / 'control-api-audit-fault.json'
        self.record = {'created_at': '2026-09-20T23:45:00+00:00', 'ip': '192.0.2.10',
                       'method': 'POST', 'action': 'send_announcement', 'status': 200, 'ok': True}
        self.now = 1800000000
        self.old = b'{"created_at":"2020-01-01T00:00:00Z","action":"old"}\n'
        self.new = b'{"created_at":"2030-01-01T00:00:00Z","action":"new"}\n'

    def append(self):
        return storage.append_control_audit(self.record, self.directory)

    def fault_count(self):
        return json.loads(self.fault.read_text())['failed_records']

    def test_complete_short_writes_and_normal_append(self):
        real_write = storage.os.write
        with mock.patch.object(storage.os, 'write', side_effect=lambda fd, data: real_write(fd, data[:7])):
            self.assertTrue(self.append()['ok'])
        self.assertEqual(json.loads(self.audit.read_text()), self.record)
        self.assertEqual(self.audit.stat().st_mode & 0o777, 0o640)
        self.assertFalse(self.fault.exists())
        self.assertTrue(self.append()['ok'])
        self.assertEqual(len(self.audit.read_text().splitlines()), 2)

    def test_unfinished_tail_refuses_append_and_records_recovery_fault(self):
        original = self.new + b'{"unfinished":'
        self.audit.write_bytes(original)
        result = self.append()
        self.assertEqual(result, {'ok': False, 'error_code': 'audit_recovery_required', 'fault_recorded': True})
        self.assertEqual(self.audit.read_bytes(), original)
        self.assertEqual(self.fault_count(), 1)
        self.assertTrue(json.loads(self.fault.read_text())['active'])
        # A short tail read cannot falsely authorize an append either.
        with mock.patch.object(storage.os, 'pread', return_value=b''):
            self.assertFalse(self.append()['ok'])
        self.assertEqual(self.audit.read_bytes(), original)

    def test_credential_attribution_is_bounded_and_never_accepts_a_secret(self):
        for credential in ('legacy', 'api_' + 'a' * 24):
            self.record['credential_id'] = credential
            self.assertTrue(self.append()['ok'])
        rows = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertEqual([row['credential_id'] for row in rows], ['legacy', 'api_' + 'a' * 24])
        before = self.audit.read_bytes()
        for credential in ('Bearer fixture-secret', 'key_short', None, ['unexpected'], 'api_' + 'a' * 25):
            self.record['credential_id'] = credential
            self.assertFalse(self.append()['ok'])
            self.assertEqual(self.audit.read_bytes(), before)

    def test_optional_operator_attribution_is_bounded_and_single_line(self):
        self.record['actor'] = 'web:fixture-admin'
        self.assertTrue(self.append()['ok'])
        before = self.audit.read_bytes()
        for actor in ('', 'x' * 161, 'admin\nforged', 'admin\x00', None, ['admin']):
            self.record['actor'] = actor
            self.assertFalse(self.append()['ok'])
            self.assertEqual(self.audit.read_bytes(), before)

    def test_enospc_rolls_back_partial_tail_and_records_failure(self):
        self.audit.write_bytes(self.new)
        real_write = storage.os.write
        writes = []
        def no_space(fd, data):
            if os.readlink('/proc/self/fd/' + str(fd)) == str(self.audit):
                writes.append(True)
                if len(writes) == 1:
                    return real_write(fd, data[:7])
                raise OSError(errno.ENOSPC, 'fixture full disk')
            return real_write(fd, data)
        with mock.patch.object(storage.os, 'write', side_effect=no_space):
            result = self.append()
        self.assertEqual(result, {'ok': False, 'error_code': 'audit_storage_write_failed', 'fault_recorded': True})
        self.assertEqual(self.audit.read_bytes(), self.new)
        self.assertEqual(self.fault_count(), 1)
        self.assertTrue(self.append()['ok'])
        self.assertEqual(self.fault_count(), 1, 'A successful audit erased earlier missing-record evidence')

    def test_failed_sync_reports_failure_even_if_marker_cannot_be_persisted(self):
        self.audit.write_bytes(self.new)
        with mock.patch.object(storage.os, 'fsync', side_effect=OSError(errno.ENOSPC, 'fixture sync failure')):
            result = self.append()
        self.assertFalse(result['ok'])
        self.assertFalse(result['fault_recorded'])
        self.assertEqual(self.audit.read_bytes(), self.new)

    def test_lock_contention_is_bounded_and_visible(self):
        self.audit.write_bytes(self.new)
        with self.audit.open('r+b') as held:
            fcntl.flock(held, fcntl.LOCK_EX)
            started = time.monotonic()
            result = self.append()
            self.assertLess(time.monotonic() - started, 0.5)
        self.assertEqual(result['error_code'], 'audit_storage_busy')
        self.assertEqual(self.audit.read_bytes(), self.new)
        self.assertEqual(self.fault_count(), 1)

    def test_symlink_hardlink_fifo_and_parent_link_never_receive_content(self):
        victim = self.directory / 'victim'
        victim.write_bytes(self.new)
        self.audit.symlink_to(victim)
        self.assertFalse(self.append()['ok'])
        self.audit.unlink()
        os.link(victim, self.audit)
        self.assertFalse(self.append()['ok'])
        self.audit.unlink()
        os.mkfifo(self.audit)
        started = time.monotonic()
        self.assertFalse(self.append()['ok'])
        self.assertLess(time.monotonic() - started, 0.5)
        self.assertEqual(victim.read_bytes(), self.new)
        alias = self.directory / 'alias'
        alias.symlink_to(self.directory, target_is_directory=True)
        self.assertFalse(storage.append_control_audit(self.record, alias)['ok'])

    def test_open_time_substitution_is_rejected_before_writing(self):
        self.audit.write_bytes(self.new)
        victim = self.directory / 'victim'; victim.write_bytes(b'private')
        real_open = storage.os.open
        changed = []
        def replace_before_open(path, flags, *args, **kwargs):
            if path == 'control-api-audit.jsonl' and not changed:
                changed.append(True)
                self.audit.unlink(); self.audit.symlink_to(victim)
            return real_open(path, flags, *args, **kwargs)
        with mock.patch.object(storage.os, 'open', side_effect=replace_before_open):
            self.assertFalse(self.append()['ok'])
        self.assertEqual(victim.read_bytes(), b'private')

    def test_capacity_and_fault_marker_link_are_safe(self):
        self.audit.write_bytes(b'x' * storage.AUDIT_MAX_BYTES)
        victim = self.directory / 'victim'; victim.write_text('private')
        self.fault.symlink_to(victim)
        result = self.append()
        self.assertEqual(result['error_code'], 'audit_storage_at_capacity')
        self.assertFalse(result['fault_recorded'])
        self.assertEqual(victim.read_text(), 'private')
        self.assertEqual(self.audit.stat().st_size, storage.AUDIT_MAX_BYTES)

    def test_retention_preserves_inode_and_newest_ten_thousand(self):
        values = [dict(self.record, created_at='2030-01-01T00:00:00Z', ordinal=index) for index in range(10003)]
        self.audit.write_bytes(self.old + b'broken\n' + b''.join(json.dumps(value).encode() + b'\n' for value in values))
        inode = self.audit.stat().st_ino
        result = storage.prune_audit(self.audit, self.now)
        kept = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertEqual((len(kept), kept[0]['ordinal'], kept[-1]['ordinal']), (10000, 3, 10002))
        self.assertEqual(result['removed'], 5)
        self.assertEqual(self.audit.stat().st_ino, inode)
        self.assertEqual(list(self.directory.glob('.sls-retention-*')), [])

    def test_retention_enospc_restores_original(self):
        original = self.old + self.new
        self.audit.write_bytes(original)
        real_copy = storage.copy_event_stream
        calls = []
        def fail_first(source, target):
            calls.append(True)
            if len(calls) == 1:
                target.seek(0); target.write(b'partial'); target.flush()
                raise OSError(errno.ENOSPC, 'fixture disk full')
            return real_copy(source, target)
        with mock.patch.object(storage, 'copy_event_stream', side_effect=fail_first):
            with self.assertRaises(OSError): storage.prune_audit(self.audit, self.now)
        self.assertEqual(self.audit.read_bytes(), original)
        self.assertEqual(len(calls), 2)

    def test_failed_recovery_preserves_backup_and_blocks_further_append(self):
        original = self.old + self.new
        self.audit.write_bytes(original)
        def fail_copy(source, target):
            target.seek(0); target.write(b'partial'); target.flush()
            raise OSError(errno.ENOSPC, 'fixture persistent failure')
        with mock.patch.object(storage, 'copy_event_stream', side_effect=fail_copy):
            with self.assertRaises(OSError): storage.prune_audit(self.audit, self.now)
        backup = self.directory / '.sls-retention-control-api-audit.jsonl.backup'
        self.assertEqual(backup.read_bytes(), original)
        self.assertEqual(self.append()['error_code'], 'audit_recovery_required')
        with self.assertRaisesRegex(RuntimeError, 'recovery_required'):
            storage.prune_audit(self.audit, self.now)
        self.assertEqual(backup.read_bytes(), original)

    def test_oversized_audit_is_preserved_without_silent_prefix_loss(self):
        self.audit.write_bytes(b'x' * (storage.AUDIT_MAX_BYTES + 1))
        with self.assertRaisesRegex(RuntimeError, 'oversized'):
            storage.prune_audit(self.audit, self.now)
        self.assertEqual(self.audit.stat().st_size, storage.AUDIT_MAX_BYTES + 1)

    def queue(self, index):
        (self.directory / ('external-deliveries-%04d.json' % index)).write_text(json.dumps({
            'deliveries': {'event': {'email_pending': True, 'created_at': self.now - index}}}))

    def test_summary_includes_queues_beyond_sixteen_and_fault_history(self):
        for index in range(20): self.queue(index)
        self.audit.write_bytes(self.new)
        with self.audit.open('r+b') as held:
            fcntl.flock(held, fcntl.LOCK_EX)
            self.append()
        result = storage.storage_summary(self.directory, self.now)
        self.assertEqual(result['pending_external'], 20)
        self.assertEqual(result['queue_files_scanned'], 20)
        self.assertEqual(result['queue_files_seen'], 20)
        self.assertFalse(result['queue_scan_incomplete'])
        self.assertEqual(result['audit_failed_records'], 1)

    def test_success_clears_current_fault_but_keeps_failure_history(self):
        self.fault.write_text(json.dumps({'failed_records': 7, 'last_failure_at': 123,
                                         'error_code': 'audit_storage_busy'}))
        self.assertTrue(storage.storage_summary(self.directory, self.now)['audit_fault_active'])
        self.assertTrue(self.append()['ok'])
        result = storage.storage_summary(self.directory, self.now)
        self.assertEqual(result['audit_failed_records'], 7)
        self.assertEqual(result['audit_last_failure_at'], 123)
        self.assertFalse(result['audit_fault_active'])
        with self.audit.open('r+b') as held:
            fcntl.flock(held, fcntl.LOCK_EX)
            self.assertFalse(self.append()['ok'])
        result = storage.storage_summary(self.directory, self.now)
        self.assertTrue(result['audit_fault_active'])
        self.assertEqual(result['audit_failed_records'], 8)

    def test_real_unix_syslog_handoff_and_failure_recovery(self):
        path = str(self.directory / 'logger.sock')
        self.record['event_id'] = 'audit_' + 'a' * 32
        (self.directory / 'mass-notifications.config').write_text(json.dumps({'control_api': {'audit_syslog': '1'}}))
        (self.directory / 'mass-notifications.config').chmod(0o640)
        with socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM) as receiver:
            receiver.bind(path); receiver.settimeout(1)
            with mock.patch.object(storage, 'SYSLOG_SOCKET', path):
                self.assertTrue(storage.forward_control_audit(self.record, self.directory)['ok'])
            data = receiver.recv(4096)
            self.assertRegex(data.decode(), r'^<174>[A-Z][a-z]{2} [ 0-3][0-9] [0-9:]{8} sls-mass-notify\[[0-9]+\]: ')
            self.assertEqual(json.loads(data.split(b']: ', 1)[1]), self.record)
        with mock.patch.object(storage, 'SYSLOG_SOCKET', path):
            self.assertFalse(storage.forward_control_audit(self.record, self.directory)['ok'])
        state = storage.storage_summary(self.directory, self.now)
        self.assertTrue(state['audit_forwarding_active'])
        self.assertEqual(state['audit_forwarding_failed_records'], 1)
        os.unlink(path)
        with socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM) as receiver:
            receiver.bind(path)
            with mock.patch.object(storage, 'SYSLOG_SOCKET', path):
                self.assertTrue(storage.forward_control_audit(self.record, self.directory)['ok'])
        state = storage.storage_summary(self.directory, self.now)
        self.assertFalse(state['audit_forwarding_active'])
        self.assertEqual(state['audit_forwarding_failed_records'], 1)
        self.assertGreater(state['audit_forwarding_last_success_at'], 0)

    def test_full_syslog_socket_has_a_bounded_deadline(self):
        path = str(self.directory / 'full.sock')
        fillers = []
        try:
            with socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM) as receiver:
                receiver.bind(path)
                # A sender may hit its own buffer before the receiver queue is
                # full. Retain several senders until a fresh one cannot enqueue.
                for _ in range(32):
                    filler = socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM)
                    fillers.append(filler); filler.setblocking(False)
                    for count in range(10000):
                        try: filler.sendto(b'fixture', path)
                        except BlockingIOError: break
                    if count == 0: break
                else: self.fail('Could not fill private fixture socket')
                with mock.patch.object(storage, 'SYSLOG_SOCKET', path):
                    start = time.monotonic()
                    self.assertFalse(storage.forward_control_audit(self.record, self.directory)['ok'])
                    self.assertLess(time.monotonic() - start, 0.5)
        finally:
            for filler in fillers: filler.close()

    def test_syslog_rejects_extra_fields_and_unsafe_health_file(self):
        with mock.patch.object(storage.socket, 'socket') as transport:
            self.assertFalse(storage.forward_control_audit(dict(self.record, password='must-not-log'), self.directory)['ok'])
            transport.assert_not_called()
        victim = self.directory / 'private'; victim.write_text('untouched')
        state = self.directory / 'control-api-audit-forwarding.json'
        state.unlink(); state.symlink_to(victim)
        self.assertFalse(storage.record_audit_health(self.directory, 'audit_syslog_unavailable', forwarding=True))
        self.assertEqual(victim.read_text(), 'untouched')
        (self.directory / 'mass-notifications.config').write_text('{"control_api":{"audit_syslog":"1"}}')
        (self.directory / 'mass-notifications.config').chmod(0o640)
        self.assertTrue(storage.storage_summary(self.directory, self.now)['audit_forwarding_active'])
        (self.directory / 'mass-notifications.config').write_text('{"control_api":{"audit_syslog":"0"}}')
        self.assertFalse(storage.storage_summary(self.directory, self.now)['audit_forwarding_active'])

    def test_summary_explicitly_reports_file_budget_and_bad_queue(self):
        for index in range(5): self.queue(index)
        with mock.patch.object(storage, 'MAX_QUEUE_FILES', 3):
            result = storage.storage_summary(self.directory, self.now)
        self.assertTrue(result['queue_scan_incomplete'])
        self.assertEqual(result['queue_files_scanned'], 3)
        self.assertEqual(result['queue_files_seen'], 4)
        self.assertEqual(result['pending_external'], 3)
        (self.directory / 'external-deliveries-0000.json').write_text('broken')
        result = storage.storage_summary(self.directory, self.now)
        self.assertTrue(result['queue_scan_incomplete'])
        self.assertEqual(result['queue_errors'], 1)

    def test_php_audit_failure_preserves_already_decided_action_status(self):
        helper = self.directory / 'helper.py'
        (self.directory / 'sls_audio_state.py').write_bytes(HELPER.with_name('sls_audio_state.py').read_bytes())
        (self.directory / 'sls_config_crypto.py').write_bytes(HELPER.with_name('sls_config_crypto.py').read_bytes())
        helper.write_text(HELPER.read_text().replace("DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')",
                                                   'DATA = Path(' + repr(str(self.directory)) + ')'))
        source = (ROOT / 'slsmassnotifyserver/api/sls-mass-notify/index.php').read_text()
        function = source[source.index('function request_header_value('):source.index('function client_ip(')] + source[source.index('function audit_control_api('):source.index('function read_json_file(')]
        php = self.directory / 'fixture.php'
        php.write_text('<?php\ndeclare(strict_types=1);\n'
                       + 'define("CONTROL_API_AUDIT_HELPER", ' + json.dumps(str(helper)) + ');\n'
                       + function + '\nhttp_response_code(201);\n$_SERVER["REQUEST_METHOD"]="POST";\n'
                       + '$_SERVER["HTTP_AUTHORIZATION"]="Bearer inert-fixture-key"; audit_control_api("192.0.2.10", "send_announcement", 201, true);\n'
                       + 'echo json_encode(["status"=>http_response_code(), "accepted"=>true]);\n')
        normal = subprocess.run(['php', str(php)], capture_output=True, text=True, timeout=5, check=True)
        self.assertEqual(json.loads(normal.stdout), {'status': 201, 'accepted': True})
        self.assertTrue(self.audit.exists(), normal.stderr)
        self.assertEqual(json.loads(self.audit.read_text())['action'], 'send_announcement')
        self.assertRegex(json.loads(self.audit.read_text())['event_id'], r'^audit_[a-f0-9]{32}$')
        with self.audit.open('r+b') as held:
            fcntl.flock(held, fcntl.LOCK_EX)
            failed = subprocess.run(['php', str(php)], capture_output=True, text=True, timeout=5, check=True)
        self.assertEqual(json.loads(failed.stdout), {'status': 201, 'accepted': True})
        self.assertIn('audit_storage_busy', failed.stderr)
        self.assertNotIn('192.0.2.10', failed.stderr)
        self.assertEqual(self.fault_count(), 1)

    def test_php_forwarding_is_opt_in_and_independent_of_local_storage(self):
        socket_path = str(self.directory / 'logger.sock')
        helper = self.directory / 'helper.py'
        (self.directory / 'sls_audio_state.py').write_bytes(HELPER.with_name('sls_audio_state.py').read_bytes())
        (self.directory / 'sls_config_crypto.py').write_bytes(HELPER.with_name('sls_config_crypto.py').read_bytes())
        helper.write_text(HELPER.read_text().replace("DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')",
            'DATA = Path(' + repr(str(self.directory)) + ')').replace("SYSLOG_SOCKET = '/dev/log'", 'SYSLOG_SOCKET = ' + repr(socket_path)))
        source = (ROOT / 'slsmassnotifyserver/api/sls-mass-notify/index.php').read_text()
        function = source[source.index('function request_header_value('):source.index('function client_ip(')] + source[source.index('function audit_control_api('):source.index('function read_json_file(')]
        php = self.directory / 'forward.php'
        php.write_text('<?php define("CONTROL_API_AUDIT_HELPER", ' + json.dumps(str(helper)) + ');\n' + function
            + '\n$GLOBALS["control"]=["audit_syslog"=>$argv[1]]; $_SERVER["REQUEST_METHOD"]="GET"; http_response_code(200);'
            + '$_SERVER["HTTP_X_API_KEY"]="inert-fixture-key"; audit_control_api("192.0.2.4","get_status",200,true); echo http_response_code();')
        def call(enabled):
            value = subprocess.run(['php', str(php), enabled], capture_output=True, text=True, timeout=5, check=True)
            self.assertEqual(value.stdout, '200')
            return value
        with socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM) as receiver:
            receiver.bind(socket_path); receiver.settimeout(0.1)
            self.assertEqual(call('0').stderr, '')
            with self.assertRaises(socket.timeout): receiver.recv(4096)
            self.assertEqual(call('1').stderr, '')
            forwarded = json.loads(receiver.recv(4096).split(b']: ', 1)[1])
            rows = [json.loads(line) for line in self.audit.read_text().splitlines()]
            self.assertNotEqual(rows[0]['event_id'], rows[1]['event_id'])
            self.assertEqual(forwarded, rows[1])
            with self.audit.open('r+b') as held:
                fcntl.flock(held, fcntl.LOCK_EX)
                self.assertIn('audit_storage_busy', call('1').stderr)
            self.assertEqual(json.loads(receiver.recv(4096).split(b']: ', 1)[1])['action'], 'get_status')
        self.assertIn('audit_syslog_unavailable', call('1').stderr)
        self.assertEqual(len(self.audit.read_text().splitlines()), 3, 'A forwarding failure lost the independent local audit record')


if __name__ == '__main__':
    unittest.main()
