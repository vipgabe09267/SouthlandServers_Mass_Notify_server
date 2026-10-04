#!/usr/bin/env python3
"""Desktop API regressions using private fixture data, without FreePBX bootstrap."""

import json
import re
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
API = ROOT / "slsmassnotifyserver/api/sipnotify/index.php"


class DesktopApiDeliveryTests(unittest.TestCase):
    def setUp(self):
        self.fixture = tempfile.TemporaryDirectory(prefix="sls-desktop-api-")
        self.directory = Path(self.fixture.name)
        source = API.read_text(encoding="utf-8")
        source = source.replace("dirname(__DIR__) . '/sls-mass-notify/security.php'", json.dumps(str(API.parent.parent / 'sls-mass-notify/security.php')))
        source = source.replace("dirname(__DIR__) . '/sls-mass-notify/config-crypto.php'", json.dumps(str(API.parent.parent / 'sls-mass-notify/config-crypto.php')))
        self.prefix = source.split("\n\n$endpoint =", 1)[0]
        paths = {
            "EVENTS_FILE": self.directory / "events.jsonl",
            "DESKTOP_AUTH_RATE_FILE": self.directory / "rate.json",
            "DESKTOP_ACK_DIRECTORY": self.directory / "acks",
            "DESKTOP_LAST_SEEN_FILE": self.directory / "seen.json",
            "SETTINGS_FILE": self.directory / "settings.json",
        }
        for name, path in paths.items():
            self.prefix, count = re.subn(
                rf"const {name} = '[^']*';",
                f"const {name} = {json.dumps(str(path))};",
                self.prefix,
                count=1,
            )
            self.assertEqual(count, 1)
        self.endpoint = source.split("\n\n$endpoint =", 1)[1]

    def tearDown(self):
        self.fixture.cleanup()

    def php(self, statements, endpoint=False):
        harness = self.directory / "harness.php"
        contents = self.prefix + "\n" + statements
        if endpoint:
            contents += "\n$endpoint =" + self.endpoint
        harness.write_text(contents, encoding="utf-8")
        result = subprocess.run(
            ["php", str(harness)], capture_output=True, text=True, timeout=30, check=False
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(result.stderr, "", result.stderr)
        return json.loads(result.stdout)

    def authenticated_endpoint_setup(self, endpoint="desktop", method="GET"):
        return """
            $key = random_bytes(32);
            $nonce = random_bytes(12);
            $cipher = openssl_encrypt('test-password', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            file_put_contents(SETTINGS_FILE, json_encode(['desktop_auth_key' => base64_encode($key), 'desktop_clients' => [
                ['client_id' => 'device-a', 'username' => 'alice', 'password_enc' => 'v1:' . base64_encode($nonce . $tag . $cipher), 'enabled' => '1'],
            ]]));
            chmod(SETTINGS_FILE, 0640);
            $_SERVER = ['REMOTE_ADDR' => '192.0.2.1', 'HTTPS' => 'on', 'PHP_AUTH_USER' => 'alice', 'PHP_AUTH_PW' => 'test-password'];
        """ + "\n$_SERVER['REQUEST_URI'] = " + json.dumps("/api/sipnotify/" + endpoint) + ";\n" + "$_SERVER['REQUEST_METHOD'] = " + json.dumps(method) + ";\n"

    def test_failed_known_username_cannot_exhaust_another_sources_budget(self):
        result = self.php("""
            for ($i = 0; $i < 150; $i++) {
                desktop_auth_attempt_allowed('192.0.2.1', 'alice', false, 1800000000);
            }
            echo json_encode([
                'failed' => desktop_auth_attempt_allowed('192.0.2.1', 'alice', false, 1800000000),
                'valid_same_ip' => desktop_auth_attempt_allowed('192.0.2.1', 'alice', true, 1800000000),
                'valid_other_ip' => desktop_auth_attempt_allowed('192.0.2.2', 'alice', true, 1800000000),
                'exhausted_source_precheck' => desktop_auth_failure_budget_available('192.0.2.1', 'alice', 1800000000),
                'other_source_precheck' => desktop_auth_failure_budget_available('192.0.2.2', 'alice', 1800000000),
            ]);
        """)
        self.assertEqual(result, {"failed": False, "valid_same_ip": True, "valid_other_ip": True, "exhausted_source_precheck": False, "other_source_precheck": True})

    def test_shared_nat_clients_have_independent_authenticated_budgets(self):
        result = self.php("""
            $allowed = true;
            for ($client = 0; $client < 1000; $client++) {
                for ($request = 0; $request < 2; $request++) {
                    $allowed = desktop_auth_attempt_allowed('192.0.2.1', 'client-' . $client, true, 1800000000) && $allowed;
                }
            }
            for ($i = 0; $i < 118; $i++) {
                desktop_auth_attempt_allowed('192.0.2.1', 'client-0', true, 1800000000);
            }
            echo json_encode([
                'shared_nat_allowed' => $allowed,
                'overactive_allowed' => desktop_auth_attempt_allowed('192.0.2.1', 'client-0', true, 1800000000),
                'another_client_allowed' => desktop_auth_attempt_allowed('192.0.2.1', 'client-999', true, 1800000000),
                'next_minute' => desktop_auth_attempt_allowed('192.0.2.1', 'client-0', true, 1800000060),
            ]);
        """)
        self.assertEqual(result, {"shared_nat_allowed": True, "overactive_allowed": False, "another_client_allowed": True, "next_minute": True})

    def test_authentication_decrypts_only_the_matching_enabled_username(self):
        self.prefix = self.prefix.replace(
            "function decrypt_desktop_password(", "function fixture_decrypt_desktop_password(", 1
        )
        result = self.php("""
            function decrypt_desktop_password(string $encoded, array $settings): string {
                $GLOBALS['decrypt_count'] = ($GLOBALS['decrypt_count'] ?? 0) + 1;
                return fixture_decrypt_desktop_password($encoded, $settings);
            }
            $key = random_bytes(32); $nonce = random_bytes(12);
            $cipher = openssl_encrypt('test-password', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            $settings = ['desktop_auth_key' => base64_encode($key), 'desktop_clients' => []];
            for ($i = 0; $i < 1000; $i++) {
                $settings['desktop_clients'][] = ['username' => 'client-' . $i, 'enabled' => '1',
                    'password_enc' => 'v1:' . base64_encode($nonce . $tag . $cipher)];
            }
            $client = authorized_desktop_client($settings, 'client-999', 'test-password');
            $unknown = authorized_desktop_client($settings, 'unknown', 'test-password');
            $settings['desktop_clients'][999]['enabled'] = '0';
            $disabled = authorized_desktop_client($settings, 'client-999', 'test-password');
            echo json_encode(['username' => $client['username'], 'unknown' => $unknown,
                'disabled' => $disabled, 'decrypt_count' => $GLOBALS['decrypt_count']]);
        """)
        self.assertEqual(result, {"username": "client-999", "unknown": [], "disabled": [], "decrypt_count": 1})

    def test_limiter_storage_capacity_tracks_configured_and_existing_fleet(self):
        result = self.php("""
            $capacities = [desktop_authenticated_capacity([]),
                desktop_authenticated_capacity(['desktop_client_limit' => 1000]),
                desktop_authenticated_capacity(['desktop_clients' => array_fill(0, 50, [])]),
                desktop_authenticated_capacity(['desktop_client_limit' => 100000])];
            $allowed = true;
            for ($i = 0; $i < 25; $i++) {
                $allowed = desktop_auth_attempt_allowed('192.0.2.1', 'client-' . $i, true, 1800000000, false, 25) && $allowed;
            }
            echo json_encode(['capacities' => $capacities, 'allowed' => $allowed,
                'over_capacity' => desktop_auth_attempt_allowed('192.0.2.1', 'new-client', true, 1800000000, false, 25),
                'enrolled_preserved' => desktop_auth_attempt_allowed('192.0.2.1', 'client-0', true, 1800000000, false, 25)]);
        """)
        self.assertEqual(result, {"capacities": [25, 1000, 50, 1000], "allowed": True, "over_capacity": False, "enrolled_preserved": True})

    def test_poll_and_ack_burst_fit_one_authenticated_client_budget(self):
        result = self.php("""
            $allowed = true;
            // Twelve five-second polls, 100 receipts, and two reconnects.
            for ($i = 0; $i < 114; $i++) {
                $allowed = desktop_auth_attempt_allowed('192.0.2.1', 'alice', true, 1800000000) && $allowed;
            }
            echo json_encode(['allowed' => $allowed]);
        """)
        self.assertTrue(result["allowed"])

    def test_date_no_cache_and_retry_after_use_real_utc_bucket_boundaries(self):
        result = self.php("""
            echo json_encode(['headers' => desktop_response_headers(1800000000),
                'retry' => [desktop_rate_retry_after(1800000000), desktop_rate_retry_after(1800000020), desktop_rate_retry_after(1800000059)]]);
        """)
        self.assertEqual(result["headers"], ["Date: Fri, 15 Jan 2027 08:00:00 GMT", "Cache-Control: private, no-store, max-age=0, must-revalidate", "Vary: Authorization"])
        self.assertEqual(result["retry"], [60, 40, 1])

    def test_full_failure_table_does_not_evict_budgets_or_block_authenticated_namespace(self):
        result = self.php("""
            $data = [];
            for ($i = 0; $i < 2048; $i++) {
                $data['failed:' . hash('sha256', (string)$i)] = ['bucket' => gmdate('YmdHi', 1800000000), 'count' => 121];
            }
            file_put_contents(DESKTOP_AUTH_RATE_FILE, json_encode($data));
            $failed = desktop_auth_attempt_allowed('192.0.2.99', 'new-name', false, 1800000000);
            $valid = true;
            for ($i = 0; $i < 1000; $i++) {
                $valid = desktop_auth_attempt_allowed('192.0.2.99', 'real-client-' . $i, true, 1800000000) && $valid;
            }
            $saved = json_decode(file_get_contents(DESKTOP_AUTH_RATE_FILE), true);
            echo json_encode(['failed' => $failed, 'valid' => $valid, 'count' => count($saved), 'original_preserved' => isset($saved[array_key_first($data)]), 'within_size_bound' => filesize(DESKTOP_AUTH_RATE_FILE) <= 524288]);
        """)
        self.assertEqual(result, {"failed": False, "valid": True, "count": 3048, "original_preserved": True, "within_size_bound": True})

    def test_cursor_polling_pages_backlog_without_skipping_expired_records(self):
        result = self.php("""
            $events = [];
            for ($i = 0; $i < 8; $i++) {
                $events[] = ['id' => 'event-' . $i, 'kind' => 'alert', 'desktop_recipients' => ['alice'], 'expires' => $i % 2 ? '2027-01-16T09:00:00Z' : '2000-01-01T00:00:00Z'];
            }
            $first = desktop_poll_events_after_cursor($events, 'alice', '@sls:empty', 2, 1800000000);
            $second = desktop_poll_events_after_cursor($events, 'alice', $first['last_event_id'], 2, 1800000000);
            $third = desktop_poll_events_after_cursor($events, 'alice', $second['last_event_id'], 2, 1800000000);
            echo json_encode([$first, $second, $third]);
        """)
        self.assertEqual([event["id"] for event in result[0]["events"]], ["event-1", "event-3"])
        self.assertEqual(result[0]["last_event_id"], "event-3")
        self.assertTrue(result[0]["has_more"])
        self.assertEqual([event["id"] for event in result[1]["events"]], ["event-5", "event-7"])
        self.assertFalse(result[1]["has_more"])
        self.assertEqual(result[2]["events"], [])

    def test_cursor_polling_preserves_all_250_burst_events_and_snapshot_reports_truncation(self):
        result = self.php("""
            $events = [];
            for ($i = 0; $i < 250; $i++) {
                $events[] = ['id' => 'event-' . $i, 'kind' => 'announcement',
                    'created_at' => '2027-01-15T08:00:00+00:00', 'desktop_recipients' => ['alice'],
                    'display_timeout_seconds' => 300, 'display_expires_at' => '2027-01-15T08:05:00+00:00'];
                $events[] = ['id' => 'private-' . $i, 'kind' => 'announcement', 'desktop_recipients' => ['bob']];
            }
            $snapshot = desktop_poll_snapshot($events, 'alice', 100, 1800000000);
            $cursor = '@sls:empty'; $received = []; $counts = [];
            do {
                $page = desktop_poll_events_after_cursor($events, 'alice', $cursor, 100, 1800000000);
                $counts[] = count($page['events']);
                $received = array_merge($received, $page['events']);
                $cursor = $page['last_event_id'];
            } while ($page['has_more']);
            $repeat = desktop_poll_snapshot($events, 'alice', 100, 1800000001);
            echo json_encode(['snapshot' => $snapshot, 'counts' => $counts,
                'ids' => array_column($received, 'id'), 'stable_payloads' => $snapshot['events'] === $repeat['events']]);
        """)
        self.assertEqual(result["counts"], [100, 100, 50])
        self.assertEqual(result["ids"], [f"event-{index}" for index in range(250)])
        self.assertTrue(result["stable_payloads"])
        self.assertTrue(result["snapshot"]["window_truncated"])
        self.assertFalse(result["snapshot"]["has_more"])
        self.assertEqual(result["snapshot"]["eligible_count"], 250)
        self.assertEqual(result["snapshot"]["events"][0]["id"], "event-150")

    def test_delivery_expiry_does_not_delete_audit_record(self):
        result = self.php("""
            $event = ['id' => 'expired', 'kind' => 'announcement', 'created_at' => gmdate('c', time() - 60),
                'desktop_recipients' => ['alice'], 'display_timeout_seconds' => 30,
                'display_expires_at' => gmdate('c', time() - 30)];
            file_put_contents(EVENTS_FILE, json_encode($event) . "\n");
            $journal = retained_events([]);
            $snapshot = desktop_poll_snapshot($journal, 'alice', 100);
            $saved = json_decode(trim(file_get_contents(EVENTS_FILE)), true);
            echo json_encode(['delivered' => $snapshot['events'], 'retained' => count($journal),
                'unchanged' => $saved === $event]);
        """)
        self.assertEqual(result, {"delivered": [], "retained": 1, "unchanged": True})

    def test_stream_capacity_preserves_bound_and_advertises_poll_fallback(self):
        result = self.php("""
            $slots = [];
            for ($i = 0; $i < 32; $i++) {
                $slots[] = desktop_stream_slot('client-' . $i);
            }
            desktop_stream_slot('client-33');
        """)
        self.assertEqual(result["error"], "stream_capacity_reached")
        self.assertEqual(result["fallback"]["transport"], "json_poll")
        self.assertEqual(result["fallback"]["cursor_parameter"], "last_event_id")

    def test_stream_slots_skip_special_or_linked_files_without_changing_them(self):
        result = self.php("""
            $directory = dirname(EVENTS_FILE) . '/stream-slots';
            mkdir($directory, 0750);
            $target = dirname(EVENTS_FILE) . '/unrelated';
            file_put_contents($target, 'preserve'); chmod($target, 0600);
            $client = $directory . '/client-' . hash('sha256', 'alice');
            link($target, $client . '-0.lock');
            symlink($target, $directory . '/global-0.lock');
            posix_mkfifo($directory . '/global-1.lock', 0600);
            $slots = desktop_stream_slot('alice');
            $held = count($slots); desktop_stream_release_slots($slots);
            clearstatcache(true, $target);
            echo json_encode(['held' => $held, 'released' => count($slots),
                'data' => file_get_contents($target), 'mode' => fileperms($target) & 0777,
                'special_preserved' => filetype($directory . '/global-1.lock') === 'fifo']);
        """)
        self.assertEqual(result, {"held": 2, "released": 0, "data": "preserve",
                                  "mode": 0o600, "special_preserved": True})

    def test_legacy_json_endpoint_filters_expiry_without_reordering_history(self):
        result = self.php(self.authenticated_endpoint_setup() + """
            $events = [
                ['id' => 'old', 'kind' => 'alert', 'expires' => '2000-01-01T00:00:00Z', 'desktop_all' => true],
                ['id' => 'first', 'kind' => 'announcement', 'desktop_all' => true],
                ['id' => 'second', 'kind' => 'announcement', 'desktop_recipients' => ['alice']],
                ['id' => 'private', 'kind' => 'announcement', 'desktop_recipients' => ['bob']],
            ];
            file_put_contents(EVENTS_FILE, implode("\n", array_map('json_encode', $events)) . "\n");
            $_GET = ['limit' => 1];
        """, endpoint=True)
        self.assertTrue(result["ok"])
        self.assertEqual([item["id"] for item in result["events"]], ["second"])
        self.assertEqual(result["latest"]["id"], "second")
        self.assertEqual(result["last_event_id"], "second")
        self.assertTrue(result["window_truncated"])
        self.assertEqual(result["eligible_count"], 2)
        self.assertEqual(result["limit"], 1)
        self.assertEqual(result["cursor_parameter"], "last_event_id")

    def test_resumable_json_endpoint_advances_expired_cursor_and_preserves_backlog(self):
        result = self.php(self.authenticated_endpoint_setup() + """
            $events = [
                ['id' => 'old', 'kind' => 'alert', 'expires' => '2000-01-01T00:00:00Z', 'desktop_all' => true],
                ['id' => 'first', 'kind' => 'announcement', 'desktop_all' => true],
                ['id' => 'second', 'kind' => 'announcement', 'desktop_recipients' => ['alice']],
            ];
            file_put_contents(EVENTS_FILE, implode("\n", array_map('json_encode', $events)) . "\n");
            $_GET = ['limit' => 1, 'last_event_id' => 'old'];
        """, endpoint=True)
        self.assertEqual([item["id"] for item in result["events"]], ["first"])
        self.assertEqual(result["last_event_id"], "first")
        self.assertTrue(result["has_more"])

    def test_endpoint_valid_credentials_survive_failed_attempt_exhaustion(self):
        result = self.php(self.authenticated_endpoint_setup() + """
            for ($i = 0; $i < 150; $i++) {
                desktop_auth_attempt_allowed('192.0.2.2', 'alice', false);
            }
            file_put_contents(EVENTS_FILE, '');
        """, endpoint=True)
        self.assertTrue(result["ok"])
        self.assertEqual(result["client"]["client_id"], "device-a")

    def test_endpoint_blocks_exhausted_source_even_if_next_guess_is_correct(self):
        result = self.php(self.authenticated_endpoint_setup() + """
            for ($i = 0; $i < 20; $i++) {
                desktop_auth_attempt_allowed('192.0.2.1', 'alice', false);
            }
            file_put_contents(EVENTS_FILE, '');
        """, endpoint=True)
        self.assertEqual(result["error"], "rate_limited")
        self.assertIn(result["retry_after_seconds"], range(1, 61))

    def test_ack_is_durable_per_event_client_and_idempotent(self):
        result = self.php("""
            $client = ['client_id' => 'device-a', 'username' => 'alice'];
            $other = ['client_id' => 'device-b', 'username' => 'bob'];
            $first = ['id' => 'event-a', 'kind' => 'announcement', 'created_at' => '2027-01-15T08:00:00Z'];
            $second = ['id' => 'event-b', 'kind' => 'alert'];
            $ok = record_desktop_acknowledgement([], $client, $first, 1800000000)
                && record_desktop_acknowledgement([], $client, $second, 1800000001)
                && record_desktop_acknowledgement([], $client, $first, 1800000100)
                && record_desktop_acknowledgement([], $other, $first, 1800000101);
            $stores = [];
            foreach (glob(DESKTOP_ACK_DIRECTORY . '/*.json') as $path) {
                $data = json_decode(file_get_contents($path), true);
                $stores[$data['username']] = $data;
            }
            echo json_encode(['ok' => $ok, 'stores' => $stores]);
        """)
        self.assertTrue(result["ok"])
        alice = result["stores"]["alice"]["acknowledgements"]
        bob = result["stores"]["bob"]["acknowledgements"]
        self.assertEqual(len(alice), 2)
        self.assertEqual(len(bob), 1)
        original = next(item for item in alice.values() if item["event_id"] == "event-a")
        self.assertEqual(original["ack_at"], "2027-01-15T08:00:00+00:00")
        self.assertEqual(original["receipt_type"], "client_acknowledgement")

    def test_ack_corrupt_history_is_preserved_and_failure_is_reported(self):
        result = self.php("""
            mkdir(DESKTOP_ACK_DIRECTORY, 0750);
            $path = DESKTOP_ACK_DIRECTORY . '/' . hash('sha256', 'id:device-a') . '.json';
            file_put_contents($path, '{corrupt');
            $ok = record_desktop_acknowledgement([], ['client_id' => 'device-a', 'username' => 'alice'], ['id' => 'event-a']);
            echo json_encode(['ok' => $ok, 'raw' => file_get_contents($path)]);
        """)
        self.assertEqual(result, {"ok": False, "raw": "{corrupt"})

    def test_ack_rejects_linked_storage_without_modifying_target(self):
        result = self.php("""
            mkdir(DESKTOP_ACK_DIRECTORY, 0750);
            $path = DESKTOP_ACK_DIRECTORY . '/' . hash('sha256', 'id:device-a') . '.json';
            $target = dirname(DESKTOP_ACK_DIRECTORY) . '/unrelated.json';
            file_put_contents($target, '{"preserve":true}');
            symlink($target, $path);
            $linked = record_desktop_acknowledgement([], ['client_id' => 'device-a', 'username' => 'alice'], ['id' => 'event-a']);
            unlink($path);
            link($target, $path);
            $hardlinked = record_desktop_acknowledgement([], ['client_id' => 'device-a', 'username' => 'alice'], ['id' => 'event-a']);
            echo json_encode(['linked' => $linked, 'hardlinked' => $hardlinked, 'raw' => file_get_contents($target)]);
        """)
        self.assertEqual(result, {"linked": False, "hardlinked": False, "raw": '{"preserve":true}'})

    def test_ack_retention_and_capacity_are_bounded(self):
        result = self.php("""
            mkdir(DESKTOP_ACK_DIRECTORY, 0750);
            $path = DESKTOP_ACK_DIRECTORY . '/' . hash('sha256', 'id:device-a') . '.json';
            $acks = ['old' => ['event_id' => 'old', 'ack_at' => gmdate('c', 1800000000 - 86401)]];
            for ($i = 0; $i < 1000; $i++) {
                $acks[hash('sha256', 'event-' . $i)] = ['event_id' => 'event-' . $i, 'ack_at' => gmdate('c', 1800000000)];
            }
            file_put_contents($path, json_encode(['schema' => 1, 'acknowledgements' => $acks]));
            $ok = record_desktop_acknowledgement(['log_retention_days' => 1], ['client_id' => 'device-a', 'username' => 'alice'], ['id' => 'new'], 1800000001);
            $data = json_decode(file_get_contents($path), true)['acknowledgements'];
            echo json_encode(['ok' => $ok, 'count' => count($data), 'old' => isset($data['old']), 'first' => isset($data[hash('sha256', 'event-0')]), 'new' => isset($data[hash('sha256', 'new')])]);
        """)
        self.assertEqual(result, {"ok": True, "count": 1000, "old": False, "first": False, "new": True})

    def test_ack_endpoint_rejects_another_clients_event(self):
        # CLI cannot populate php://input directly; a substituted input reader
        # still exercises authorization, routing, persistence, and response code.
        self.endpoint = self.endpoint.replace(
            "file_get_contents('php://input', false, null, 0, 4097)",
            "json_encode(['event_id' => 'private-event'])",
        )
        result = self.php(self.authenticated_endpoint_setup("desktop/ack", "POST") + """
            file_put_contents(EVENTS_FILE, json_encode(['id' => 'private-event', 'kind' => 'announcement', 'desktop_recipients' => ['bob'], 'created_at' => gmdate('c')]) . "\n");
        """, endpoint=True)
        self.assertEqual(result["error"], "unknown_or_unauthorized_event")
        self.assertFalse((self.directory / "acks").exists())

    def test_ack_endpoint_persists_routed_event_before_success(self):
        self.endpoint = self.endpoint.replace(
            "file_get_contents('php://input', false, null, 0, 4097)",
            "json_encode(['event_id' => 'routed-event'])",
        )
        result = self.php(self.authenticated_endpoint_setup("desktop/ack", "POST") + """
            file_put_contents(EVENTS_FILE, json_encode(['id' => 'routed-event', 'kind' => 'announcement', 'desktop_recipients' => ['alice'], 'created_at' => gmdate('c')]) . "\n");
        """, endpoint=True)
        self.assertTrue(result["ok"])
        stored = list((self.directory / "acks").glob("*.json"))
        self.assertEqual(len(stored), 1)
        acks = json.loads(stored[0].read_text())["acknowledgements"]
        self.assertEqual([item["event_id"] for item in acks.values()], ["routed-event"])


if __name__ == "__main__":
    unittest.main()
