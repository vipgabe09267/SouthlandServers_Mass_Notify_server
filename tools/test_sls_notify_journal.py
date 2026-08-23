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
    def run_php_journal_harness(self, journal, statements):
        source = DESKTOP_API.read_text(encoding="utf-8")
        prefix = source.split("function announcement_display_expired", 1)[0]
        prefix, replacements = re.subn(
            r"const EVENTS_FILE = '[^']*';",
            "const EVENTS_FILE = " + json.dumps(str(journal)) + ";",
            prefix,
            count=1,
        )
        self.assertEqual(replacements, 1)
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
        return json.loads(result.stdout)

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
        self.assertIn("atomic_replace_journal($eventsFile, $normalized)", retained)
        self.assertNotIn("ftruncate", retained)

        with tempfile.TemporaryDirectory() as directory:
            journal = Path(directory) / "events.jsonl"
            journal.write_text(
                '{"kind":"announcement","id":"retained","created_at":"2026-08-22T12:00:00Z"}\n'
                "invalid journal line\n",
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


if __name__ == "__main__":
    unittest.main()
