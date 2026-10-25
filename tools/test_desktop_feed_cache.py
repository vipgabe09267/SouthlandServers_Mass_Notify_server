#!/usr/bin/python3
"""Real Python/PHP cache compatibility, tamper rejection and original recipients."""
from datetime import datetime, timezone
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "slsmassnotifyserver/bin/sls_mass_notify"))
import sls_desktop_feed_cache as cache


class FeedCache(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="sls-feed-cache-")
        self.root = Path(self.temporary.name)
        self.root.chmod(0o755)
        self.directory = self.root / "data"
        self.directory.mkdir(mode=0o750)
        self.account = pwd.getpwnam("asterisk")
        os.chown(self.directory, self.account.pw_uid, self.account.pw_gid)
        self.helper = self.root / "api/desktop-feed-cache.php"
        self.helper.parent.mkdir(mode=0o755)
        self.helper.parent.chmod(0o755)
        for name in ("desktop-feed-cache.php", "security.php"):
            target = self.helper.parent / name
            target.write_bytes(
                (ROOT / "slsmassnotifyserver/api/sls-mass-notify" / name).read_bytes()
            )
            target.chmod(0o644)
        self.path = self.directory / "events.jsonl"
        self.key = "a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s="
        stamp = datetime.now(timezone.utc).isoformat()
        self.event = {
            "id": "cache-event",
            "created_at": stamp,
            "kind": "announcement",
            "is_test": True,
            "message": "Private fixture",
            "desktop_all": False,
            "desktop_recipients": ["alice"],
            "desktop_client_ids": {"alice": "client-a", "bob": "client-b"},
        }
        self.lines = [json.dumps(self.event, separators=(",", ":"))]
        self.path.write_text(self.lines[0] + "\n")
        self.path.chmod(0o640)
        os.chown(self.path, self.account.pw_uid, self.account.pw_gid)
        self.assertTrue(cache.publish(self.path, self.lines, self.key))

    def tearDown(self):
        self.temporary.cleanup()

    def read(self, user="alice", client="client-a"):
        helper = self.helper
        code = 'require $argv[1];$p=$argv[2];echo json_encode(\\SLS\\MassNotify\\DesktopFeedCache::read($p,lstat($p),["desktop_auth_key"=>$argv[3]],["username"=>$argv[4],"client_id"=>$argv[5]],90,static fn($v)=>strtotime($v)),JSON_THROW_ON_ERROR);'
        result = subprocess.run(
            [
                "/usr/sbin/runuser",
                "-u",
                "asterisk",
                "--",
                "php",
                "-r",
                code,
                str(helper),
                str(self.path),
                self.key,
                user,
                client,
            ],
            capture_output=True,
            text=True,
            check=False,
            timeout=10,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def test_exact_original_recipient_and_enrollment(self):
        events = self.read()
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]["desktop_client_ids"], {"alice": "client-a"})
        self.assertNotIn("bob", json.dumps(events))
        self.assertEqual(self.read("bob", "client-b"), [])
        self.assertEqual(self.read("alice", "replacement-client"), [])

    def test_payload_tampering_and_generation_changes_fall_back(self):
        path = self.directory / cache.NAME
        body = path.read_bytes()
        path.write_bytes(body.replace(b"Private fixture", b"Changed fixture"))
        self.assertIsNone(self.read())
        self.assertTrue(cache.publish(self.path, self.lines, self.key))
        self.path.write_text(self.lines[0] + "\n\n")
        self.assertIsNone(self.read())

    def test_unsafe_cache_paths_and_permissions_are_ignored(self):
        path = self.directory / cache.NAME
        path.chmod(0o666)
        self.assertIsNone(self.read())
        path.chmod(0o640)
        backup = path.read_bytes()
        path.unlink()
        path.symlink_to(self.path)
        self.assertIsNone(self.read())
        self.assertEqual(self.path.read_text(), self.lines[0] + "\n")

    def test_repeated_sets_reduce_fleet_json_and_do_not_cache_permissions(self):
        bindings = {"desktop" + str(i): "cli_" + str(i) for i in range(1000)}
        event = dict(
            self.event,
            desktop_all=True,
            desktop_recipients=[],
            desktop_client_ids=bindings,
        )
        lines = [json.dumps(dict(event, id="event-" + str(i))) for i in range(100)]
        encoded = cache.body(lines)
        self.assertLess(len(encoded), sum(map(len, lines)) // 10)
        decoded = json.loads(encoded)
        self.assertEqual(len(decoded["sets"]), 1)
        self.assertEqual(len(decoded["events"]), 100)


if __name__ == "__main__":
    unittest.main()
