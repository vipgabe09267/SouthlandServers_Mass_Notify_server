#!/usr/bin/env python3
"""Focused cross-zone NWS delivery claim and turn-order regressions."""

from __future__ import annotations

import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest import mock


sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_nws_delivery_claims.py"
NWS_POLLER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify_nws_poll.sh"
WEATHER_WRAPPER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify_weather_poll.sh"
SPEC = importlib.util.spec_from_file_location("sls_nws_delivery_claims_test", HELPER)
claims = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(claims)


class CrossZoneClaimTests(unittest.TestCase):
    def test_state_byte_limit_can_hold_documented_claim_capacity(self):
        record = {
            "chain": "a" * 64,
            "destination": "b" * 64,
            "kind": "desktop",
            "group": "c" * 64,
            "group_rank": 4,
            "claimed_at": 1_800_000_000,
            "status": "reserved",
            "owner": "d" * 64,
            "lease_until": 1_800_001_800,
        }
        state = {
            "version": 1,
            "claims": {
                "e" * 64 + ":" + f"{index:064x}": record
                for index in range(claims.MAX_CLAIMS)
            },
            "cycles": {},
        }
        encoded = (json.dumps(state, separators=(",", ":"), sort_keys=True) + "\n").encode("utf-8")
        self.assertLessEqual(len(encoded), claims.MAX_STATE_BYTES)

    def test_worker_integration_waits_claims_and_treats_duplicate_only_as_noop(self):
        poller = NWS_POLLER.read_text(encoding="utf-8")
        wrapper = WEATHER_WRAPPER.read_text(encoding="utf-8")
        wait_marker = "if ! wait_for_nws_dispatch_turn; then"
        loop_marker = "# Parse alerts\nprintf '%s\\n' \"$PARSED_ALERTS\""
        self.assertIn(wait_marker, poller)
        self.assertIn(loop_marker, poller)
        self.assertLess(poller.index(wait_marker), poller.index(loop_marker))
        self.assertIn('claim_cross_zone_destinations "$ALERT_KEY" "$QUIET_SUPPRESS_PAGING"', poller)
        self.assertIn('"reservation_id": "group_" + hashlib.sha256(', poller)
        self.assertIn('finalize_cross_zone_destinations "$ALERT_KEY" commit phone desktop', poller)
        self.assertIn('finalize_cross_zone_destinations "$ALERT_KEY" commit email discord generic', poller)
        self.assertIn('finalize_cross_zone_destinations "$ALERT_KEY" release phone desktop', poller)
        self.assertIn('cancel_local_dispatch_intent "$ALERT_KEY"', poller)
        self.assertIn("the alert remains eligible for retry", poller)
        self.assertIn('"expected_count": int(os.environ["NWS_CLAIM_EXPECTED_COUNT"])', poller)
        self.assertIn("skipped_cross_zone_duplicate", poller)
        self.assertIn("print rounded + 7", poller)
        self.assertIn('"timeout_seconds": 3300', poller)
        self.assertIn("CORE_WORKER_TIMEOUT_SECONDS=5400", wrapper)
        self.assertGreater(claims.RESERVATION_LEASE_SECONDS, 3300)
        self.assertIn('exec 7>"$DATA_DIR/weather-poll-cycle.lock"', wrapper)
        self.assertIn(
            '[ "$LOCAL_AUDIO_QUEUE_FAILED" = "0" ] && [ "$LOCAL_VISUAL_REQUESTED" = "1" ]',
            poller,
        )
        self.assertIn("complete_nws_dispatch_turn || ALERT_LOOP_STATUS=1", poller)
        self.assertIn('> /dev/null 2>> "$LOG"', poller)
        self.assertIn('> /dev/null 2>> "$LOG"', wrapper)

    def test_shell_claim_adapter_builds_and_consumes_real_helper_protocol(self):
        poller = NWS_POLLER.read_text(encoding="utf-8")
        function_prefix = poller.split('\nexec 9>"$LOCK_FILE"', 1)[0]
        with tempfile.TemporaryDirectory(prefix="sls-nws-shell-adapter-") as directory:
            root = Path(directory)
            state = root / "claims.json"
            harness = root / "claim-adapter.sh"
            harness.write_text(
                function_prefix
                + "\ndelivery_targets() { :; }\n"
                + f"NWS_CROSS_ZONE_CLAIM_HELPER={str(HELPER)!r}\n"
                + f"NWS_CROSS_ZONE_CLAIM_STATE={str(state)!r}\n"
                + f"LOG={str(root / 'adapter.log')!r}\n"
                + "NWS_DISPATCH_CYCLE_ID=cycle_20260902_adapter\n"
                + "NWS_DISPATCH_GROUP_RANK=0\n"
                + "NWS_ZONE_GROUP_ID_OVERRIDE=north\n"
                + "NWS_ALERTS_DRY_RUN=0\nFORCE_REPLAY=0\nEVENT='Heat Advisory'\n"
                + "NWS_ALERT_RECIPIENTS=(1000)\nNWS_DESKTOP_RECIPIENTS=(gabe)\n"
                + "NWS_ZONE_EMAIL_RECIPIENTS=()\nNWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE=\n"
                + "claim_cross_zone_destinations 'Heat Advisory|adapter' 0\n"
                + "status=$?\n"
                + "printf 'status=%s phone=%s desktop=%s\\n' \"$status\" \"${NWS_ALERT_RECIPIENTS[*]}\" \"${NWS_DESKTOP_RECIPIENTS[*]}\"\n"
                + "finalize_cross_zone_destinations 'Heat Advisory|adapter' commit phone desktop\n",
                encoding="utf-8",
            )
            harness.chmod(0o755)
            result = subprocess.run(
                [str(harness)],
                env={**os.environ, "PYTHONDONTWRITEBYTECODE": "1"},
                capture_output=True,
                text=True,
                timeout=10,
                check=False,
            )
            self.assertEqual(result.returncode, 0, (result.stdout, result.stderr))
            self.assertIn("status=0 phone=1000 desktop=gabe", result.stdout)

    def test_overlapping_zone_targets_are_claimed_once_without_exposing_values(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-claims-") as directory:
            state = Path(directory) / "claims.json"
            first = claims.claim_destinations(
                state,
                "Heat Advisory|nws-alert-one",
                "phone",
                ["1000", "1001"],
                "north",
                0,
                now=1_800_000_000,
            )
            second = claims.claim_destinations(
                state,
                "Heat Advisory|nws-alert-one",
                "phone",
                ["1000", "1002"],
                "south",
                1,
                now=1_800_000_001,
            )
            self.assertEqual(first, {"claimed": ["1000", "1001"], "duplicates": [], "reserved": []})
            self.assertEqual(second, {"claimed": ["1002"], "duplicates": ["1000"], "reserved": []})

            email = claims.claim_destinations(
                state,
                "Heat Advisory|nws-alert-one",
                "email",
                ["Weather.Team@Example.com"],
                "north",
                0,
                now=1_800_000_002,
            )
            duplicate_email = claims.claim_destinations(
                state,
                "Heat Advisory|nws-alert-one",
                "email",
                ["weather.team@example.com"],
                "south",
                1,
                now=1_800_000_003,
            )
            self.assertEqual(email["claimed"], ["Weather.Team@Example.com"])
            self.assertEqual(duplicate_email["duplicates"], ["weather.team@example.com"])
            raw = state.read_text(encoding="utf-8")
            for secret_or_identity in (
                "Heat Advisory",
                "nws-alert-one",
                "1000",
                "1001",
                "1002",
                "Weather.Team",
                "weather.team",
                "north",
                "south",
            ):
                self.assertNotIn(secret_or_identity, raw)
            self.assertEqual(state.stat().st_mode & 0o777, 0o640)
            self.assertEqual(Path(str(state) + ".lock").stat().st_mode & 0o777, 0o640)

    def test_reservations_retry_by_owner_and_commit_or_release_without_losing_alert(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-reservation-") as directory:
            state = Path(directory) / "claims.json"
            first = claims.claim_destinations(
                state,
                "Warning|reservation-chain",
                "phone",
                ["1000"],
                "north",
                0,
                now=1_800_000_000,
                reservation_id="group_north_1234",
            )
            same_owner = claims.claim_destinations(
                state,
                "Warning|reservation-chain",
                "phone",
                ["1000"],
                "north",
                0,
                now=1_800_000_001,
                reservation_id="group_north_1234",
            )
            blocked = claims.claim_destinations(
                state,
                "Warning|reservation-chain",
                "phone",
                ["1000"],
                "south",
                1,
                now=1_800_000_002,
                reservation_id="group_south_1234",
            )
            self.assertEqual(first["claimed"], ["1000"])
            self.assertEqual(same_owner["claimed"], ["1000"])
            self.assertEqual(blocked["reserved"], ["1000"])
            self.assertEqual(blocked["duplicates"], [])

            released = claims.finalize_reservation(
                state,
                "Warning|reservation-chain",
                "group_north_1234",
                ["phone"],
                "release",
                1,
                now=1_800_000_003,
            )
            self.assertEqual(released["changed"], 1)
            takeover = claims.claim_destinations(
                state,
                "Warning|reservation-chain",
                "phone",
                ["1000"],
                "south",
                1,
                now=1_800_000_004,
                reservation_id="group_south_1234",
            )
            self.assertEqual(takeover["claimed"], ["1000"])
            committed = claims.finalize_reservation(
                state,
                "Warning|reservation-chain",
                "group_south_1234",
                ["phone"],
                "commit",
                1,
                now=1_800_000_005,
            )
            self.assertEqual(committed["changed"], 1)
            final_duplicate = claims.claim_destinations(
                state,
                "Warning|reservation-chain",
                "phone",
                ["1000"],
                "north",
                0,
                now=1_800_000_006,
                reservation_id="group_north_1234",
            )
            self.assertEqual(final_duplicate["duplicates"], ["1000"])
            self.assertEqual(final_duplicate["reserved"], [])

    def test_finalization_count_mismatch_fails_without_mutating_reservations(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-finalize-count-") as directory:
            state = Path(directory) / "claims.json"
            claims.claim_destinations(
                state,
                "Warning|count-chain",
                "phone",
                ["1000"],
                now=1_800_000_000,
                reservation_id="group_north_1234",
            )
            state.unlink()
            with self.assertRaisesRegex(claims.CoordinationError, "count changed"):
                claims.finalize_reservation(
                    state,
                    "Warning|count-chain",
                    "group_north_1234",
                    ["phone"],
                    "commit",
                    1,
                    now=1_800_000_001,
                )
            self.assertFalse(state.exists())

    def test_expired_reservation_can_be_reclaimed_after_worker_crash(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-reservation-expiry-") as directory:
            state = Path(directory) / "claims.json"
            claims.claim_destinations(
                state,
                "Warning|crashed-worker",
                "desktop",
                ["gabe"],
                now=100,
                reservation_id="group_crashed_1234",
            )
            takeover = claims.claim_destinations(
                state,
                "Warning|crashed-worker",
                "desktop",
                ["gabe"],
                now=100 + claims.RESERVATION_LEASE_SECONDS + 1,
                reservation_id="group_recovery_1234",
            )
            self.assertEqual(takeover["claimed"], ["gabe"])
            self.assertEqual(takeover["reserved"], [])

    def test_claim_kinds_and_alert_chains_are_independent(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-claim-kind-") as directory:
            state = Path(directory) / "claims.json"
            phone = claims.claim_destinations(state, "chain-one", "phone", ["1000"], now=100)
            desktop = claims.claim_destinations(state, "chain-one", "desktop", ["1000"], now=101)
            next_alert = claims.claim_destinations(state, "chain-two", "phone", ["1000"], now=102)
            discord = claims.claim_destinations(state, "chain-one", "discord", ["operations"], now=103)
            generic = claims.claim_destinations(state, "chain-one", "generic", ["operations"], now=104)
            self.assertEqual(phone["claimed"], ["1000"])
            self.assertEqual(desktop["claimed"], ["1000"])
            self.assertEqual(next_alert["claimed"], ["1000"])
            self.assertEqual(discord["claimed"], ["operations"])
            self.assertEqual(generic["claimed"], ["operations"])

    def test_quiet_zone_does_not_claim_a_suppressed_phone(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-claim-quiet-") as directory:
            state = Path(directory) / "claims.json"
            # A quiet zone claims only its still-enabled external destination.
            external = claims.claim_destinations(
                state, "warning|shared", "discord", ["operations"], "quiet-zone", 0, now=200
            )
            # A second zone whose local quiet policy permits paging can still
            # claim the shared phone because the first zone never claimed it.
            phone = claims.claim_destinations(
                state, "warning|shared", "phone", ["1000"], "audible-zone", 1, now=201
            )
            self.assertEqual(external["claimed"], ["operations"])
            self.assertEqual(phone["claimed"], ["1000"])

    def test_atomic_multi_kind_claim_keeps_unique_targets_in_configured_zone_order(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-claim-many-") as directory:
            state = Path(directory) / "claims.json"
            claims.begin_cycle(state, "cycle_20260902_claimmany", 2)
            first = claims.claim_destination_sets(
                state,
                "Severe Thunderstorm Warning|shared-chain",
                {
                    "phone": ["1000", "1001"],
                    "desktop": ["gabe", "operations"],
                    "email": ["alerts@example.com"],
                    "discord": ["weather"],
                    "generic": ["automation"],
                },
                "first-zone",
                0,
                now=400,
            )
            second = claims.claim_destination_sets(
                state,
                "Severe Thunderstorm Warning|shared-chain",
                {
                    "phone": ["1001", "1002"],
                    "desktop": ["operations", "remote"],
                    "email": ["ALERTS@example.com", "south@example.com"],
                    "discord": ["weather", "south"],
                    "generic": ["automation", "south"],
                },
                "second-zone",
                1,
                now=401,
            )
            self.assertEqual(first["claimed"]["phone"], ["1000", "1001"])
            self.assertEqual(second["claimed"]["phone"], ["1002"])
            self.assertEqual(second["claimed"]["desktop"], ["remote"])
            self.assertEqual(second["claimed"]["email"], ["south@example.com"])
            self.assertEqual(second["claimed"]["discord"], ["south"])
            self.assertEqual(second["claimed"]["generic"], ["south"])
            self.assertEqual(second["duplicates"]["phone"], ["1001"])
            self.assertEqual(second["duplicates"]["desktop"], ["operations"])
            self.assertEqual(second["duplicates"]["email"], ["ALERTS@example.com"])

            # Capacity is checked for the complete transaction before any new
            # destination is recorded; partial multi-channel claims cannot leak.
            before = state.read_bytes()
            with mock.patch.object(claims, "MAX_CLAIMS", len(json.loads(before)["claims"])):
                with self.assertRaisesRegex(claims.CoordinationError, "capacity"):
                    claims.claim_destination_sets(
                        state,
                        "another-chain",
                        {"phone": ["1003"], "desktop": ["new-client"]},
                        now=402,
                    )
            self.assertEqual(state.read_bytes(), before)

    def test_concurrent_claim_has_exactly_one_winner(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-claim-race-") as directory:
            state = Path(directory) / "claims.json"
            barrier = threading.Barrier(3)
            results = []
            failures = []

            def worker(group, rank):
                try:
                    barrier.wait(timeout=2)
                    results.append(claims.claim_destinations(
                        state, "warning|race", "phone", ["1000"], group, rank, now=300
                    ))
                except BaseException as exc:  # surfaced by the parent assertion
                    failures.append(exc)

            threads = [
                threading.Thread(target=worker, args=("north", 0)),
                threading.Thread(target=worker, args=("south", 1)),
            ]
            for thread in threads:
                thread.start()
            barrier.wait(timeout=2)
            for thread in threads:
                thread.join(timeout=5)
            self.assertFalse(failures)
            self.assertEqual(sum(result["claimed"] == ["1000"] for result in results), 1)
            self.assertEqual(sum(result["duplicates"] == ["1000"] for result in results), 1)

    def test_cycle_turns_wait_for_every_lower_configured_rank(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-turns-") as directory:
            state = Path(directory) / "claims.json"
            claims.begin_cycle(state, "cycle_20260902_abcdef", 3, now=int(time.time()))
            released = threading.Event()
            failure = []

            def wait_for_last():
                try:
                    claims.wait_turn(state, "cycle_20260902_abcdef", 2, timeout_seconds=3)
                    released.set()
                except BaseException as exc:
                    failure.append(exc)

            thread = threading.Thread(target=wait_for_last)
            thread.start()
            time.sleep(0.1)
            self.assertFalse(released.is_set())
            claims.complete_turn(state, "cycle_20260902_abcdef", 0)
            time.sleep(0.1)
            self.assertFalse(released.is_set())
            claims.complete_turn(state, "cycle_20260902_abcdef", 1)
            thread.join(timeout=3)
            self.assertFalse(failure)
            self.assertTrue(released.is_set())

    def test_malformed_cycle_state_fails_cleanly_without_value_error(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-cycle-schema-") as directory:
            state = Path(directory) / "claims.json"
            cycle_id = "cycle_20260902_badrecord"
            claims.begin_cycle(state, cycle_id, 2, now=int(time.time()))
            state.write_text(json.dumps({
                "version": 1,
                "claims": {},
                "cycles": {
                    claims._digest("cycle", cycle_id): {
                        "started_at": int(time.time()),
                        "group_count": "two",
                        "completed": [],
                    }
                },
            }), encoding="utf-8")
            with self.assertRaisesRegex(claims.CoordinationError, "unavailable"):
                claims.wait_turn(state, cycle_id, 1, timeout_seconds=1)

    def test_unsafe_state_or_parent_write_permissions_are_rejected(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-claim-metadata-") as directory:
            root = Path(directory)
            state = root / "claims.json"
            claims.claim_destinations(state, "chain", "phone", ["1000"])
            state.chmod(0o660)
            with self.assertRaisesRegex(claims.CoordinationError, "unsafe"):
                claims.claim_destinations(state, "next-chain", "phone", ["1001"])

        with tempfile.TemporaryDirectory(prefix="sls-nws-parent-metadata-") as directory:
            root = Path(directory)
            root.chmod(0o770)
            with self.assertRaisesRegex(claims.CoordinationError, "directory"):
                claims.claim_destinations(root / "claims.json", "chain", "phone", ["1000"])

    def test_expired_claims_are_pruned_but_capacity_never_evicts_live_claims(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-claim-retention-") as directory:
            state = Path(directory) / "claims.json"
            claims.claim_destinations(state, "old-chain", "phone", ["1000"], now=1)
            later = 1 + claims.CLAIM_RETENTION_SECONDS + 1
            claims.claim_destinations(state, "new-chain", "phone", ["1001"], now=later)
            decoded = json.loads(state.read_text(encoding="utf-8"))
            self.assertEqual(len(decoded["claims"]), 1)
            with mock.patch.object(claims, "MAX_CLAIMS", 1):
                with self.assertRaisesRegex(claims.CoordinationError, "capacity"):
                    claims.claim_destinations(state, "another-chain", "phone", ["1002"], now=later + 1)
            decoded_after = json.loads(state.read_text(encoding="utf-8"))
            self.assertEqual(decoded_after, decoded)

    def test_state_and_lock_symlinks_are_rejected_without_touching_target(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-claim-symlink-") as directory:
            root = Path(directory)
            sentinel = root / "sentinel"
            sentinel.write_text("must-not-change", encoding="utf-8")
            state = root / "claims.json"
            state.symlink_to(sentinel)
            with self.assertRaises((claims.CoordinationError, OSError)):
                claims.claim_destinations(state, "chain", "phone", ["1000"])
            self.assertEqual(sentinel.read_text(encoding="utf-8"), "must-not-change")

            state.unlink()
            lock = Path(str(state) + ".lock")
            lock.unlink()
            lock.symlink_to(sentinel)
            with self.assertRaises((claims.CoordinationError, OSError)):
                claims.begin_cycle(state, "cycle_20260902_deadbeef", 1)
            self.assertEqual(sentinel.read_text(encoding="utf-8"), "must-not-change")

    def test_state_and_lock_hardlinks_are_rejected_without_touching_target(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-claim-hardlink-") as directory:
            root = Path(directory)
            sentinel = root / "sentinel"
            sentinel.write_text("must-not-change", encoding="utf-8")
            state = root / "claims.json"
            state.hardlink_to(sentinel)
            with self.assertRaises(claims.CoordinationError):
                claims.claim_destinations(state, "chain", "phone", ["1000"])
            self.assertEqual(sentinel.read_text(encoding="utf-8"), "must-not-change")

            state.unlink()
            lock = Path(str(state) + ".lock")
            lock.unlink()
            lock.hardlink_to(sentinel)
            with self.assertRaises(claims.CoordinationError):
                claims.begin_cycle(state, "cycle_20260902_hardlink", 1)
            self.assertEqual(sentinel.read_text(encoding="utf-8"), "must-not-change")

    def test_invalid_destinations_and_requests_fail_closed(self):
        with tempfile.TemporaryDirectory(prefix="sls-nws-claim-invalid-") as directory:
            state = Path(directory) / "claims.json"
            invalid = (
                ("phone", ["1000;System(reboot)"]),
                ("desktop", ["../client"]),
                ("email", ["not-an-email"]),
                ("discord", ["id/secret"]),
                ("unsupported", ["value"]),
            )
            for kind, destinations in invalid:
                with self.assertRaises(claims.CoordinationError):
                    claims.claim_destinations(state, "chain", kind, destinations)
            self.assertFalse(state.exists())


if __name__ == "__main__":
    unittest.main()
