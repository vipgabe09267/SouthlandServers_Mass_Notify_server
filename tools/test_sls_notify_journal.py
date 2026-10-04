#!/usr/bin/env python3
"""Focused regressions for crash-safe desktop journal persistence."""

import configparser
import importlib.util
import json
import multiprocessing
import re
import subprocess
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]
SENDER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py"
DESKTOP_API = ROOT / "slsmassnotifyserver/api/sipnotify/index.php"
SPEC = importlib.util.spec_from_file_location("sls_notify_journal", SENDER)
SENDER_MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(SENDER_MODULE)


def journal_config(path):
    config = configparser.ConfigParser(interpolation=None)
    config.read_dict({"api": {"events_file": str(path)}})
    return config


def concurrent_append(journal_path, process_index, iterations, barrier):
    config = journal_config(Path(journal_path))
    barrier.wait()
    for iteration in range(iterations):
        SENDER_MODULE.append_sipnotify_event(
            config,
            {
                "kind": "announcement",
                "id": f"event-{process_index:02d}-{iteration:02d}",
            },
        )


class JournalPersistenceTests(unittest.TestCase):
    def test_scheduled_deadline_after_fsync_preserves_existing_journal(self):
        with tempfile.TemporaryDirectory() as directory:
            journal = Path(directory) / 'events.jsonl'
            config = journal_config(journal)
            SENDER_MODULE.append_sipnotify_event(config, {'kind':'announcement', 'id':'original'})
            original = journal.read_bytes()
            clock = [1000.0]
            sync = SENDER_MODULE.os.fsync
            def delayed_sync(descriptor):
                sync(descriptor)
                clock[0] = 1002.0
            with mock.patch.object(SENDER_MODULE.time, 'time', lambda: clock[0]):
                guard = SENDER_MODULE.scheduled_start_guard(1001)
                with mock.patch.object(SENDER_MODULE.os, 'fsync', delayed_sync):
                    with self.assertRaises(SENDER_MODULE.ScheduledStartExpired):
                        SENDER_MODULE.append_sipnotify_event(config, {'kind':'announcement', 'id':'late'}, submission_guard=guard)
            self.assertEqual(journal.read_bytes(), original)
            self.assertEqual(list(journal.parent.glob('.events.jsonl.tmp.*')), [])

    def test_scheduled_publication_guard_survives_clock_rollback(self):
        with mock.patch.object(SENDER_MODULE.time, 'time', return_value=1000), mock.patch.object(SENDER_MODULE.time, 'monotonic', return_value=10):
            guard = SENDER_MODULE.scheduled_start_guard(1001)
        with mock.patch.object(SENDER_MODULE.time, 'time', return_value=900), mock.patch.object(SENDER_MODULE.time, 'monotonic', return_value=12):
            with self.assertRaises(SENDER_MODULE.ScheduledStartExpired):
                guard()

    def run_php_journal_harness(self, journal, statements, raw=False):
        source = DESKTOP_API.read_text(encoding="utf-8")
        prefix = source.split("function announcement_display_expired", 1)[0]
        include = "require_once dirname(__DIR__) . '/sls-mass-notify/security.php';"
        self.assertIn(include, prefix)
        prefix = prefix.replace(include, "require_once " + json.dumps(str(DESKTOP_API.parent.parent / "sls-mass-notify/security.php")) + ";", 1)
        crypto_include = "require_once dirname(__DIR__) . '/sls-mass-notify/config-crypto.php';"
        self.assertIn(crypto_include, prefix)
        prefix = prefix.replace(crypto_include, "require_once " + json.dumps(str(DESKTOP_API.parent.parent / "sls-mass-notify/config-crypto.php")) + ";", 1)
        prefix, replacements = re.subn(
            r"const EVENTS_FILE = '[^']*';",
            "const EVENTS_FILE = " + json.dumps(str(journal)) + ";",
            prefix,
            count=1,
        )
        self.assertEqual(replacements, 1)
        prefix = prefix.replace("http_response_code($status);", "$payload['http_status'] = $status; http_response_code($status);", 1)
        harness = journal.parent / "journal_harness.php"
        harness.write_text(prefix + "\n" + statements + "\n", encoding="utf-8")
        result = subprocess.run(
            ["php", str(harness)],
            text=True,
            capture_output=True,
            timeout=15,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(result.stderr, "", result.stderr)
        return result.stdout if raw else json.loads(result.stdout)

    def test_replace_failure_preserves_existing_journal_and_cleans_temporary_file(self):
        with tempfile.TemporaryDirectory() as directory:
            journal = Path(directory) / "events.jsonl"
            original = b'{"kind":"announcement","id":"original"}\n'
            journal.write_bytes(original)

            with mock.patch.object(
                SENDER_MODULE.os,
                "replace",
                side_effect=OSError("simulated atomic replacement failure"),
            ):
                with self.assertRaisesRegex(OSError, "simulated atomic replacement failure"):
                    SENDER_MODULE.append_sipnotify_event(
                        journal_config(journal),
                        {"kind": "announcement", "id": "new"},
                    )

            self.assertEqual(journal.read_bytes(), original)
            self.assertEqual(list(journal.parent.glob(".events.jsonl.tmp.*")), [])

    def test_atomic_replacement_never_exposes_a_partially_written_target(self):
        with tempfile.TemporaryDirectory() as directory:
            journal = Path(directory) / "events.jsonl"
            original = b'{"kind":"announcement","id":"original"}\n'
            journal.write_bytes(original)
            real_replace = SENDER_MODULE.os.replace

            def inspected_replace(source, destination):
                self.assertEqual(journal.read_bytes(), original)
                replacement = Path(source).read_bytes()
                self.assertTrue(replacement.endswith(b"\n"))
                self.assertEqual(len(replacement.splitlines()), 2)
                for line in replacement.splitlines():
                    json.loads(line)
                real_replace(source, destination)

            with mock.patch.object(SENDER_MODULE.os, "replace", side_effect=inspected_replace):
                SENDER_MODULE.append_sipnotify_event(
                    journal_config(journal),
                    {"kind": "announcement", "id": "new"},
                )

            records = [json.loads(line) for line in journal.read_text(encoding="utf-8").splitlines()]
            self.assertEqual([record["id"] for record in records], ["original", "new"])
            self.assertEqual(journal.stat().st_mode & 0o777, 0o640)

    def test_concurrent_atomic_replacements_do_not_lose_events(self):
        with tempfile.TemporaryDirectory() as directory:
            journal = Path(directory) / "events.jsonl"
            process_count = 8
            iterations = 4
            context = multiprocessing.get_context("fork")
            barrier = context.Barrier(process_count)
            processes = [
                context.Process(
                    target=concurrent_append,
                    args=(str(journal), process_index, iterations, barrier),
                )
                for process_index in range(process_count)
            ]
            for process in processes:
                process.start()
            for process in processes:
                process.join(15)
                self.assertEqual(process.exitcode, 0)

            records = [json.loads(line) for line in journal.read_text(encoding="utf-8").splitlines()]
            self.assertEqual(len(records), process_count * iterations)
            self.assertEqual(
                {record["id"] for record in records},
                {
                    f"event-{process_index:02d}-{iteration:02d}"
                    for process_index in range(process_count)
                    for iteration in range(iterations)
                },
            )

    def test_announcement_ids_remain_unique_at_the_same_instant(self):
        fixed = datetime(2026, 8, 22, 12, 34, 56, 123456, tzinfo=timezone.utc)
        with mock.patch.object(
            SENDER_MODULE.secrets,
            "token_hex",
            side_effect=["a" * 32, "b" * 32],
        ):
            first = SENDER_MODULE.new_announcement_id(fixed)
            second = SENDER_MODULE.new_announcement_id(fixed)

        self.assertEqual(first, "announcement-20260822123456123456-" + "a" * 32)
        self.assertEqual(second, "announcement-20260822123456123456-" + "b" * 32)
        self.assertNotEqual(first, second)

    def test_push_announcement_uses_collision_resistant_id_without_sending(self):
        with mock.patch.object(
            SENDER_MODULE,
            "new_announcement_id",
            return_value="announcement-deterministic-id",
        ), mock.patch.object(SENDER_MODULE, "append_sipnotify_event") as append_event:
            SENDER_MODULE.push_announcement(
                configparser.ConfigParser(interpolation=None),
                "Test announcement",
                [],
                api_only=True,
                desktop_targets=["desktop_one"],
            )

        self.assertEqual(append_event.call_args.args[1]["id"], "announcement-deterministic-id")

    def test_api_only_announcement_requires_an_explicit_desktop_route(self):
        with self.assertRaisesRegex(RuntimeError, "at least one desktop target"):
            SENDER_MODULE.push_announcement(
                configparser.ConfigParser(interpolation=None),
                "Untargeted announcement",
                [],
                api_only=True,
            )

    def test_api_only_lightning_style_alert_does_not_require_desktop_presence(self):
        config = configparser.ConfigParser(interpolation=None)
        alert = {
            "id": "lightning-offline-desktop",
            "properties": {"event": "Lightning Alert", "severity": "Severe"},
        }
        with mock.patch.object(SENDER_MODULE, "build_xml", return_value="<xml />"), mock.patch.object(
            SENDER_MODULE, "append_sipnotify_event"
        ) as append_event, mock.patch.object(SENDER_MODULE, "AmiClient") as ami_client:
            SENDER_MODULE.push_alert(
                config,
                alert,
                api_only=True,
                desktop_targets=["sleeping_desktop"],
            )

        ami_client.assert_not_called()
        append_event.assert_called_once()
        self.assertEqual(
            append_event.call_args.args[1]["desktop_recipients"],
            ["sleeping_desktop"],
        )

    def test_targeted_announcement_is_published_once_before_phone_discovery(self):
        config = configparser.ConfigParser(interpolation=None)
        config.read_dict({
            "ami": {"host": "127.0.0.1", "port": "5038", "username": "test", "password": "test"}
        })
        ami = mock.MagicMock()
        with mock.patch.object(SENDER_MODULE, "AmiClient", return_value=ami), mock.patch.object(
            SENDER_MODULE, "get_registered_endpoint_info", return_value={}
        ), mock.patch.object(SENDER_MODULE, "send_notify_batch", return_value=0), mock.patch.object(
            SENDER_MODULE, "append_sipnotify_event"
        ) as append_event:
            SENDER_MODULE.push_announcement(
                config,
                "Desktop and phone announcement",
                [],
                desktop_targets=["desktop_one"],
            )

        self.assertEqual(append_event.call_count, 1)
        self.assertEqual(append_event.call_args.args[1]["desktop_recipients"], ["desktop_one"])

    def test_targeted_live_alert_is_published_once_before_phone_discovery(self):
        config = configparser.ConfigParser(interpolation=None)
        config.read_dict({
            "ami": {"host": "127.0.0.1", "port": "5038", "username": "test", "password": "test"}
        })
        alert = {
            "id": "alert-desktop-once",
            "properties": {"event": "Severe Thunderstorm Warning", "severity": "Severe"},
        }
        ami = mock.MagicMock()
        with mock.patch.object(SENDER_MODULE, "build_xml", return_value="<xml />"), mock.patch.object(
            SENDER_MODULE, "AmiClient", return_value=ami
        ), mock.patch.object(SENDER_MODULE, "get_registered_endpoint_info", return_value={}), mock.patch.object(
            SENDER_MODULE, "send_notify_batch", return_value=0
        ), mock.patch.object(SENDER_MODULE, "append_sipnotify_event") as append_event:
            SENDER_MODULE.push_alert(
                config,
                alert,
                retries=False,
                desktop_targets=["desktop_one"],
            )

        self.assertEqual(append_event.call_count, 1)
        self.assertEqual(append_event.call_args.args[1]["desktop_recipients"], ["desktop_one"])

    def test_failed_phone_only_announcement_does_not_publish_a_desktop_record(self):
        config = configparser.ConfigParser(interpolation=None)
        config.read_dict({
            "ami": {"host": "127.0.0.1", "port": "5038", "username": "test", "password": "test"}
        })
        ami = mock.MagicMock()
        with mock.patch.object(SENDER_MODULE, "AmiClient", return_value=ami), mock.patch.object(
            SENDER_MODULE, "get_registered_endpoint_info", return_value={}
        ), mock.patch.object(SENDER_MODULE, "append_sipnotify_event") as append_event:
            with self.assertRaisesRegex(RuntimeError, "registered/reachable"):
                SENDER_MODULE.push_announcement(
                    config,
                    "Phone-only announcement",
                    ["1000"],
                )

        append_event.assert_not_called()

    def test_php_retention_uses_the_shared_lock_and_atomic_replacement(self):
        source = DESKTOP_API.read_text(encoding="utf-8")
        retained = source.split("function retained_events", 1)[1].split(
            "function announcement_display_expired", 1
        )[0]
        self.assertIn("$eventsFile . '.lock'", retained)
        self.assertIn("atomic_replace_journal($eventsFile, $normalized, null, $metadata)", retained)
        self.assertNotIn("ftruncate", retained)

        with tempfile.TemporaryDirectory() as directory:
            journal = Path(directory) / "events.jsonl"
            journal.write_text(
                '{"kind":"announcement","id":"retained","created_at":"2026-08-22T12:00:00Z"}\n'
                '{"id":"expired","created_at":"2000-01-01T00:00:00Z"}\n',
                encoding="utf-8",
            )
            original_inode = journal.stat().st_ino
            result = self.run_php_journal_harness(
                journal,
                "$events = retained_events(['log_retention_days' => 365]);\n"
                "echo json_encode(['events' => $events, 'raw' => file_get_contents(EVENTS_FILE)]);",
            )

            self.assertEqual([event["id"] for event in result["events"]], ["retained"])
            self.assertEqual(
                result["raw"],
                '{"kind":"announcement","id":"retained","created_at":"2026-08-22T12:00:00Z"}\n',
            )
            self.assertNotEqual(journal.stat().st_ino, original_inode)
            lock_file = Path(str(journal) + ".lock")
            self.assertTrue(lock_file.is_file())
            self.assertEqual(lock_file.stat().st_mode & 0o777, 0o640)
            self.assertEqual(journal.stat().st_mode & 0o777, 0o640)
            self.assertEqual(list(journal.parent.glob(".sipnotify_events.*")), [])

    def test_php_atomic_replace_failure_preserves_existing_journal(self):
        with tempfile.TemporaryDirectory() as directory:
            journal = Path(directory) / "events.jsonl"
            original = '{"kind":"announcement","id":"original"}\n'
            journal.write_text(original, encoding="utf-8")
            result = self.run_php_journal_harness(
                journal,
                "$ok = atomic_replace_journal(EVENTS_FILE, \"replacement\\n\", "
                "static function (string $source, string $destination): bool { return false; });\n"
                "echo json_encode(['ok' => $ok, 'raw' => file_get_contents(EVENTS_FILE)]);",
            )

            self.assertFalse(result["ok"])
            self.assertEqual(result["raw"], original)
            self.assertEqual(list(journal.parent.glob(".sipnotify_events.*")), [])


    def test_corrupt_or_oversized_prefix_is_preserved_by_reader_and_writer(self):
        for label, payload, expected in [
            ('corrupt', b'not-json\n', 'journal_corrupt'),
            ('array', b'[]\n', 'journal_corrupt'),
            ('line', b'{"value":"' + b'x' * (256 * 1024) + b'"}\n', 'journal_line_too_large'),
            ('scan', b'\n' * 100001, 'journal_scan_limit'),
        ]:
            with self.subTest(label=label), tempfile.TemporaryDirectory() as directory:
                journal = Path(directory) / 'events.jsonl'
                original = payload + b'{"id":"last-valid"}\n'
                journal.write_bytes(original)
                before = journal.stat().st_ino
                with self.assertRaises(OSError):
                    SENDER_MODULE.append_sipnotify_event(journal_config(journal), {'id': 'new'})
                result = self.run_php_journal_harness(journal, 'retained_events([]);')
                self.assertEqual(result['error'], expected)
                self.assertEqual(result['http_status'], 503)
                self.assertTrue(result['retryable'])
                self.assertEqual(journal.read_bytes(), original)
                self.assertEqual(journal.stat().st_ino, before)

    def test_file_byte_bound_rejects_sparse_oversized_journal_without_rewrite(self):
        with tempfile.TemporaryDirectory() as directory:
            journal = Path(directory) / 'events.jsonl'
            with journal.open('wb') as handle:
                handle.write(b'{"id":"preserve"}\n')
                handle.truncate(64 * 1024 * 1024 + 1)
            before = journal.stat()
            with self.assertRaisesRegex(OSError, 'byte limit'):
                SENDER_MODULE.append_sipnotify_event(journal_config(journal), {'id': 'new'})
            result = self.run_php_journal_harness(journal, 'retained_events([]);')
            self.assertEqual(result['error'], 'journal_too_large')
            self.assertEqual(journal.stat().st_size, before.st_size)
            self.assertEqual(journal.stat().st_ino, before.st_ino)

    def test_legacy_record_count_is_bounded_to_newest_thousand_after_full_validation(self):
        with tempfile.TemporaryDirectory() as directory:
            journal = Path(directory) / 'events.jsonl'
            original = b''.join(json.dumps({'id': f'event-{i}'}).encode() + b'\n' for i in range(1200))
            journal.write_bytes(original)
            result = self.run_php_journal_harness(journal, "echo json_encode(array_column(retained_events([]), 'id'));")
            self.assertEqual(result, [f'event-{i}' for i in range(200, 1200)])
            journal.write_bytes(original)
            SENDER_MODULE.append_sipnotify_event(journal_config(journal), {'id': 'new'})
            records = [json.loads(line) for line in journal.read_text().splitlines()]
            self.assertEqual([row['id'] for row in records], [f'event-{i}' for i in range(201, 1200)] + ['new'])
            journal.write_bytes(b'corrupt-prefix\n' + original)
            with self.assertRaisesRegex(OSError, 'corrupt'):
                SENDER_MODULE.append_sipnotify_event(journal_config(journal), {'id': 'new'})
            self.assertEqual(self.run_php_journal_harness(journal, 'retained_events([]);')['error'], 'journal_corrupt')
            self.assertTrue(journal.read_bytes().startswith(b'corrupt-prefix\n'))

    def test_lock_contention_is_bounded_and_preserves_journal(self):
        import fcntl
        import time
        for lock_target in ('events.jsonl', 'events.jsonl.lock'):
            with self.subTest(lock_target=lock_target), tempfile.TemporaryDirectory() as directory:
                journal = Path(directory) / 'events.jsonl'
                original = b'{"id":"unchanged"}\n'
                journal.write_bytes(original)
                with (journal.parent / lock_target).open('a+b') as lock:
                    fcntl.flock(lock, fcntl.LOCK_EX)
                    started = time.monotonic()
                    with self.assertRaisesRegex(OSError, 'busy'):
                        SENDER_MODULE.append_sipnotify_event(journal_config(journal), {'id': 'new'})
                    self.assertLess(time.monotonic() - started, 1.5)
                    started = time.monotonic()
                    result = self.run_php_journal_harness(journal, 'retained_events([]);')
                    self.assertLess(time.monotonic() - started, 1.5)
                    self.assertEqual(result['error'], 'journal_locked')
                    self.assertEqual(result['http_status'], 503)
                self.assertEqual(journal.read_bytes(), original)

    def test_fifo_symlink_and_hardlink_are_rejected_for_journal_and_lock(self):
        import os
        for target_name in ('events.jsonl', 'events.jsonl.lock'):
            for kind in ('fifo', 'symlink', 'hardlink'):
                with self.subTest(target=target_name, kind=kind), tempfile.TemporaryDirectory() as directory:
                    journal = Path(directory) / 'events.jsonl'
                    target = journal.parent / target_name
                    evidence = journal.parent / 'evidence.jsonl'
                    evidence.write_bytes(b'{"id":"untouched"}\n')
                    if kind == 'fifo':
                        os.mkfifo(target)
                    elif kind == 'symlink':
                        target.symlink_to(evidence)
                    else:
                        os.link(evidence, target)
                    with self.assertRaises(OSError):
                        SENDER_MODULE.append_sipnotify_event(journal_config(journal), {'id': 'new'})
                    result = self.run_php_journal_harness(journal, 'retained_events([]);')
                    self.assertEqual(result['http_status'], 503)
                    self.assertEqual(evidence.read_bytes(), b'{"id":"untouched"}\n')
                    self.assertEqual(self.run_php_journal_harness(target,
                        '$ok=atomic_replace_journal(EVENTS_FILE, "replacement"); echo json_encode($ok);'), False)

    def test_thousand_recipient_burst_and_republication_keep_identity_and_expiry(self):
        with tempfile.TemporaryDirectory() as directory:
            journal = Path(directory) / 'events.jsonl'
            recipients = [f'desktop-{i:04d}' for i in range(1000)]
            records = [{'id': f'burst-{i}', 'kind': 'announcement', 'desktop_recipients': recipients,
                        'created_at': '2026-09-20T10:00:00Z', 'display_expires_at': '2026-09-20T10:05:00Z',
                        'display_timeout_seconds': 300} for i in range(250)]
            journal.write_text(''.join(json.dumps(row) + '\n' for row in records))
            SENDER_MODULE.append_sipnotify_event(journal_config(journal), {
                **records[-1], 'created_at': '2026-09-21T10:00:00Z',
                'display_expires_at': '2026-09-21T10:05:00Z', 'display_timeout_seconds': 600})
            result = self.run_php_journal_harness(journal,
                "$events=retained_events(['log_retention_days'=>365]); echo json_encode(['ids'=>array_column($events,'id'),'last'=>end($events)]);")
            self.assertEqual(result['ids'], [f'burst-{i}' for i in range(250)])
            self.assertEqual(result['last']['desktop_recipients'], recipients)
            self.assertEqual(result['last']['created_at'], records[-1]['created_at'])
            self.assertEqual(result['last']['display_expires_at'], records[-1]['display_expires_at'])
            self.assertEqual(result['last']['display_timeout_seconds'], 300)

    def test_post_handshake_failure_is_an_sse_reconnect_frame(self):
        with tempfile.TemporaryDirectory() as directory:
            journal = Path(directory) / 'events.jsonl'
            journal.write_bytes(b'corrupt-evidence\n')
            output = self.run_php_journal_harness(journal,
                'echo "event: authenticated\\ndata: {}\\n\\n"; flush(); retained_events([]);', raw=True)
            self.assertTrue(output.startswith('event: authenticated\ndata: {}\n\n'))
            self.assertIn('event: reconnect\ndata: {"ok":false,"error":"journal_corrupt","retryable":true}\n\n', output)
            self.assertNotIn('\n{\n', output)
            self.assertEqual(journal.read_bytes(), b'corrupt-evidence\n')


if __name__ == "__main__":
    unittest.main()
