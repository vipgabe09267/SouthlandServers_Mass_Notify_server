#!/usr/bin/env python3
"""Cross-language configuration encryption and isolated key lifecycle tests."""

import base64
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
RUNTIME = ROOT / "slsmassnotifyserver/bin/sls_mass_notify"
spec = importlib.util.spec_from_file_location("sls_config_crypto_fixture", RUNTIME / "sls_config_crypto.py")
crypto = importlib.util.module_from_spec(spec)
spec.loader.exec_module(crypto)
PHP_FILE = ROOT / "slsmassnotifyserver/api/sls-mass-notify/config-crypto.php"
PHP = r'''
require $argv[1];
use FreePBX\modules\SlsConfigCrypto as C;
$input=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
try {
 switch($input['op']) {
  case 'encode': $result=C::encode($input['settings'],$input['keyring']); break;
  case 'decode': $result=C::decode($input['raw'],$input['keyring'],$input['legacy']??true); break;
  case 'read': $result=C::readFile($input['path'],$input['keyring'],$input['legacy']??true); break;
  case 'backup': $result=C::backupKeyring($input['raw'],$input['keyring']); break;
  case 'restore': $result=C::decodeBackup($input['raw'],$input['backup']); break;
  default: throw new RuntimeException('Invalid fixture operation.');
 }
 echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
} catch(Throwable $error) { echo json_encode(['ok'=>false,'message'=>$error->getMessage()],JSON_THROW_ON_ERROR); }
'''


class ConfigCryptoTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="sls-crypto-")
        self.root = Path(self.temporary.name)
        self.data = self.root / "data"
        self.data.mkdir(mode=0o700)
        self.keyring = self.root / "keys/config-keys.json"
        self.now = 1791000000
        self.ring = crypto.initialize_keyring(self.keyring, os.getgid(), self.now)
        self.settings = {"enabled": "0", "message": "Évacuation · 避難", "nested": {"password": "fixture-private-credential", "null": None},
                         "recipients": ["1000", "desktop-fixture"], "value": 0.125}
        self.sealed = crypto.encode_config(self.settings, self.keyring)

    def tearDown(self):
        self.temporary.cleanup()

    def php(self, operation, **arguments):
        arguments["op"] = operation
        arguments.setdefault("keyring", str(self.keyring))
        if isinstance(arguments.get("raw"), bytes):
            arguments["raw"] = arguments["raw"].decode("ascii")
        result = subprocess.run(["php", "-r", PHP, str(PHP_FILE)], input=json.dumps(arguments), text=True,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=5, check=True)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def private_file(self, name, contents):
        path = self.data / name
        path.write_bytes(contents)
        path.chmod(0o640)
        return path

    def test_cross_language_authenticated_roundtrips(self):
        self.assertEqual(self.php("decode", raw=self.sealed)["result"], self.settings)
        generated = self.php("encode", settings=self.settings)["result"]
        self.assertEqual(crypto.decode_config(generated, self.keyring), self.settings)
        self.assertNotIn("fixture-private-credential", generated)
        self.assertNotEqual(generated.encode("ascii"), self.sealed)

    def test_duplicate_json_keys_rejected_in_both_runtimes(self):
        envelope = self.sealed.decode("ascii")
        duplicate_envelope = '{"key_id":"ignored-duplicate",' + envelope[1:]
        duplicate_legacy = '{"enabled":"0","nested":{"name":1,"na\\u006de":2}}'
        for raw in (duplicate_envelope, duplicate_legacy):
            with self.subTest(raw_type="encrypted" if "ciphertext" in raw else "legacy"):
                with self.assertRaises(crypto.ConfigCryptoError):
                    crypto.decode_config(raw, self.keyring)
                self.assertFalse(self.php("decode", raw=raw)["ok"])

        # A valid tag must not cause different settings to be interpreted by PHP.
        plaintext = b'{"enabled":"0","enabled":"1"}'
        nonce = os.urandom(12)
        key_id = self.ring["active"]
        key = base64.b64decode(self.ring["keys"][key_id]["key"])
        sealed = crypto.AESGCM(key).encrypt(nonce, plaintext, (crypto.FORMAT + "\n" + key_id).encode("ascii"))
        raw = json.dumps({"format": crypto.FORMAT, "key_id": key_id,
                          "nonce": base64.b64encode(nonce).decode("ascii"),
                          "ciphertext": base64.b64encode(sealed).decode("ascii")})
        with self.assertRaises(crypto.ConfigCryptoError):
            crypto.decode_config(raw, self.keyring)
        self.assertFalse(self.php("decode", raw=raw)["ok"])

        ring_raw = self.keyring.read_text(encoding="ascii")
        self.keyring.write_text('{"active":"ignored-duplicate",' + ring_raw[1:], encoding="ascii")
        with self.assertRaises(crypto.ConfigCryptoError):
            crypto.read_keyring(self.keyring)
        self.assertFalse(self.php("encode", settings=self.settings)["ok"])

    def test_distinct_escaped_json_keys_and_string_values_remain_compatible(self):
        raw = r'{"enabled":"0","nested":{"quote\\\"":1,"slash\\\\":2,"0":3,"00":4},"message":"{\\\"fake\\\":1,\\\"fake\\\":2}"}'
        expected = json.loads(raw)
        self.assertEqual(crypto.decode_config(raw, self.keyring), expected)
        self.assertEqual(self.php("decode", raw=raw)["result"], expected)

    def test_random_nonces_and_no_cleartext(self):
        values = {crypto.encode_config(self.settings, self.keyring) for _ in range(100)}
        self.assertEqual(len(values), 100)
        self.assertFalse(any(b"fixture-private-credential" in value for value in values))

    def test_every_authenticated_field_tamper_rejected(self):
        for field in ("format", "key_id", "nonce", "ciphertext"):
            with self.subTest(field=field):
                envelope = json.loads(self.sealed)
                if field in ("nonce", "ciphertext"):
                    value = bytearray(base64.b64decode(envelope[field]))
                    value[0] ^= 1
                    envelope[field] = base64.b64encode(value).decode("ascii")
                else:
                    envelope[field] = "f" * 32 if field == "key_id" else "broken-format"
                raw = json.dumps(envelope)
                with self.assertRaises(crypto.ConfigCryptoError):
                    crypto.decode_config(raw, self.keyring)
                self.assertFalse(self.php("decode", raw=raw)["ok"])

    def test_truncation_extra_fields_noncanonical_base64_rejected(self):
        envelopes = []
        for field, value in (("nonce", "AA=="), ("ciphertext", "AA=="), ("key_id", 123), ("extra", "unexpected")):
            envelope = json.loads(self.sealed)
            envelope[field] = value
            envelopes.append(envelope)
        envelope = json.loads(self.sealed)
        envelope["nonce"] += "\n"
        envelopes.append(envelope)
        for envelope in envelopes:
            with self.assertRaises(crypto.ConfigCryptoError):
                crypto.decode_config(json.dumps(envelope), self.keyring)
            self.assertFalse(self.php("decode", raw=json.dumps(envelope))["ok"])

    def test_legacy_read_explicit_and_never_cipher_fallback(self):
        raw = json.dumps(self.settings)
        self.assertEqual(crypto.decode_config(raw, self.root / "absent"), self.settings)
        self.assertTrue(self.php("decode", raw=raw)["ok"])
        with self.assertRaises(crypto.ConfigCryptoError):
            crypto.decode_config(raw, self.keyring, allow_legacy=False)
        self.assertFalse(self.php("decode", raw=raw, legacy=False)["ok"])
        for raw in ('{"format":"unsupported"}', '{"ciphertext":"broken"}', '{"format":null}', '{"nonce":null}', '[]', '{}', '{broken', 'NaN'):
            with self.assertRaises(crypto.ConfigCryptoError):
                crypto.decode_config(raw, self.keyring)
            self.assertFalse(self.php("decode", raw=raw)["ok"])

    def test_missing_and_wrong_key_fail_closed(self):
        with self.assertRaises(crypto.ConfigCryptoError):
            crypto.decode_config(self.sealed, self.root / "absent")
        wrong = json.loads(self.keyring.read_text())
        wrong["keys"][wrong["active"]]["key"] = base64.b64encode(os.urandom(32)).decode("ascii")
        self.keyring.write_text(json.dumps(wrong))
        with self.assertRaises(crypto.ConfigCryptoError):
            crypto.decode_config(self.sealed, self.keyring)
        self.assertFalse(self.php("decode", raw=self.sealed)["ok"])

    def test_private_file_permissions_and_shared_inodes(self):
        path = self.private_file("mass-notifications.config", self.sealed)
        self.assertEqual(crypto.read_config(path, self.keyring), self.settings)
        self.assertTrue(self.php("read", path=str(path))["ok"])
        for mode in (0o644, 0o660, 0o666):
            path.chmod(mode)
            with self.assertRaises(crypto.ConfigCryptoError):
                crypto.read_config(path, self.keyring)
            self.assertFalse(self.php("read", path=str(path))["ok"])
        path.chmod(0o640)
        os.link(path, self.data / "second-link")
        with self.assertRaises(crypto.ConfigCryptoError):
            crypto.read_config(path, self.keyring)
        self.assertFalse(self.php("read", path=str(path))["ok"])

    def test_symlink_parents_files_and_fifo_never_block(self):
        path = self.private_file("actual.config", self.sealed)
        link = self.data / "symlink.config"
        link.symlink_to(path)
        parent_link = self.root / "linked-data"
        parent_link.symlink_to(self.data, target_is_directory=True)
        fifo = self.data / "fifo.config"
        os.mkfifo(fifo, 0o600)
        for candidate in (link, parent_link / path.name, fifo):
            with self.assertRaises(crypto.ConfigCryptoError):
                crypto.read_config(candidate, self.keyring)
            self.assertFalse(self.php("read", path=str(candidate))["ok"])

    def test_unsafe_keyring_modes_and_directory(self):
        for path, mode in ((self.keyring, 0o644), (self.keyring.parent, 0o770)):
            previous = path.stat().st_mode & 0o777
            path.chmod(mode)
            with self.assertRaises(crypto.ConfigCryptoError):
                crypto.read_keyring(self.keyring)
            self.assertFalse(self.php("decode", raw=self.sealed)["ok"])
            path.chmod(previous)

    def test_keyring_rejects_runtime_writable_ancestor(self):
        ancestor = self.root / "runtime-controlled"
        ancestor.mkdir(mode=0o770)
        ancestor.chmod(0o770)
        key_folder = ancestor / "keys"
        key_folder.mkdir(mode=0o750)
        path = key_folder / "config-keys.json"
        path.write_bytes(self.keyring.read_bytes())
        path.chmod(0o640)
        with self.assertRaises(crypto.ConfigCryptoError):
            crypto.read_keyring(path)
        self.assertFalse(self.php("decode", raw=self.sealed, keyring=str(path))["ok"])

    def test_keyring_invalid_types_and_unknown_fields(self):
        for mutation in (lambda value: value.update(active="missing"), lambda value: value.update(extra="unexpected"),
                         lambda value: value["keys"][value["active"]].update(created_at=True),
                         lambda value: value["keys"][value["active"]].update(key="AA==")):
            ring = json.loads(self.keyring.read_text())
            mutation(ring)
            with self.assertRaises(crypto.ConfigCryptoError):
                crypto.validate_keyring(ring)

    def test_configuration_size_bounds(self):
        with self.assertRaises(crypto.ConfigCryptoError):
            crypto.encode_config({"large": "x" * crypto.MAX_PLAIN_BYTES}, self.keyring)
        self.assertFalse(self.php("encode", settings={"large": "x" * crypto.MAX_PLAIN_BYTES})["ok"])
        with self.assertRaises(crypto.ConfigCryptoError):
            crypto.decode_config(b" " * (crypto.MAX_FILE_BYTES + 1), self.keyring)

    def test_yearly_rotation_retains_old_backups_and_preserves_settings(self):
        active = self.private_file("mass-notifications.config", self.sealed)
        pending = self.private_file("mass-notifications.pending.config", json.dumps({"draft": True}).encode())
        backups = self.data / "config-backups"
        backups.mkdir(mode=0o700)
        backup = backups / "mass-notifications-20261003-1234.config"
        backup.write_bytes(json.dumps(self.settings).encode())
        backup.chmod(0o640)
        before = crypto.maintain(self.data, self.keyring, os.getuid(), os.getgid(), rotate=True,
                                now=self.now + crypto.ROTATE_SECONDS - 1)
        self.assertFalse(before["rotated"])
        after = crypto.maintain(self.data, self.keyring, os.getuid(), os.getgid(), rotate=True,
                               now=self.now + crypto.ROTATE_SECONDS)
        self.assertTrue(after["rotated"])
        self.assertEqual(after["encrypted_files"], 3)
        ring = crypto.read_keyring(self.keyring)
        self.assertEqual(len(ring["keys"]), 2)
        self.assertNotEqual(ring["active"], self.ring["active"])
        self.assertEqual(crypto.decode_config(self.sealed, self.keyring), self.settings)
        self.assertEqual(crypto.read_config(active, self.keyring), self.settings)
        self.assertEqual(crypto.read_config(pending, self.keyring), {"draft": True})
        self.assertEqual(crypto.read_config(backup, self.keyring), self.settings)
        self.assertFalse(crypto.maintain(self.data, self.keyring, os.getuid(), os.getgid(), rotate=True,
                                        now=self.now + crypto.ROTATE_SECONDS + 1)["rotated"])

    def test_outside_legacy_json_is_encrypted_only_for_the_canonical_data_directory(self):
        outside = self.data.parent / "slsmassnotifyserver-settings.json"
        outside_pending = self.data.parent / "slsmassnotifyserver-settings.pending.json"
        for path in (outside, outside_pending):
            path.write_text(json.dumps(self.settings), encoding="utf-8")
            path.chmod(0o640)
        original = outside.read_bytes()
        result = crypto.maintain(self.data, self.keyring, os.getuid(), os.getgid(), now=self.now)
        self.assertEqual(result["encrypted_files"], 0)
        self.assertEqual(outside.read_bytes(), original)
        with patch.object(crypto, "DEFAULT_DATA", self.data):
            result = crypto.maintain(self.data, self.keyring, os.getuid(), os.getgid(), now=self.now)
        self.assertEqual(result["encrypted_files"], 2)
        for path in (outside, outside_pending):
            self.assertEqual(crypto.read_config(path, self.keyring), self.settings)
            self.assertNotIn(b"fixture-private-credential", path.read_bytes())
        with patch.object(crypto, "DEFAULT_DATA", self.data):
            self.assertEqual(crypto.maintain(self.data, self.keyring, os.getuid(), os.getgid(), now=self.now)["encrypted_files"], 0)

    def test_backup_directory_scan_stops_at_its_entry_budget(self):
        active = self.private_file("mass-notifications.config", self.sealed)
        backups = self.data / "config-backups"
        backups.mkdir(mode=0o700)
        for index in range(1001):
            (backups / f"unrelated-{index}").touch()
        with patch.object(crypto.os, "listdir", side_effect=AssertionError("Unbounded directory listing used")):
            with self.assertRaisesRegex(crypto.ConfigCryptoError, "file-count limit"):
                crypto.maintain(self.data, self.keyring, os.getuid(), os.getgid(), now=self.now)
        self.assertEqual(active.read_bytes(), self.sealed)

    def test_partial_migration_remains_readable_and_retries(self):
        active = self.private_file("mass-notifications.config", self.sealed)
        pending = self.private_file("mass-notifications.pending.config", self.sealed)
        original = crypto._atomic_write
        def injected(path, raw, uid, gid):
            if Path(path) == pending:
                raise OSError("fixture disk failure")
            return original(path, raw, uid, gid)
        with patch.object(crypto, "_atomic_write", injected), self.assertRaises(OSError):
            crypto.maintain(self.data, self.keyring, os.getuid(), os.getgid(), rotate=True, now=self.now + crypto.ROTATE_SECONDS)
        self.assertEqual(crypto.read_config(active, self.keyring), self.settings)
        self.assertEqual(crypto.read_config(pending, self.keyring), self.settings)
        retry = crypto.maintain(self.data, self.keyring, os.getuid(), os.getgid(), rotate=True, now=self.now + crypto.ROTATE_SECONDS)
        self.assertFalse(retry["rotated"])
        self.assertEqual(retry["encrypted_files"], 1)

    def test_daily_marker_bounds_work_and_clock_rollback(self):
        self.private_file("mass-notifications.config", self.sealed)
        first = crypto.daily(self.data, self.keyring, os.getuid(), os.getgid(), now=self.now)
        self.assertTrue(first["ok"])
        with patch.object(crypto, "maintain", side_effect=AssertionError("daily cache failed")):
            self.assertTrue(crypto.daily(self.data, self.keyring, os.getuid(), os.getgid(), now=self.now + 60)["skipped"])
        with self.assertRaises(crypto.ConfigCryptoError):
            crypto.daily(self.data, self.keyring, os.getuid(), os.getgid(), now=self.now - 1)

    def test_configuration_activity_lock_prevents_migration(self):
        path = self.private_file("mass-notifications.config", self.sealed)
        lock_path = self.private_file("announcement-activity.lock", b"")
        with lock_path.open("rb") as handle:
            fcntl.flock(handle, fcntl.LOCK_SH)
            with self.assertRaises(BlockingIOError):
                crypto.maintain(self.data, self.keyring, os.getuid(), os.getgid(), rotate=True, now=self.now + crypto.ROTATE_SECONDS)
        self.assertEqual(path.read_bytes(), self.sealed)

    def test_portable_native_backup_only_includes_required_key(self):
        crypto.maintain(self.data, self.keyring, os.getuid(), os.getgid(), rotate=True, now=self.now + crypto.ROTATE_SECONDS)
        current = crypto.encode_config(self.settings, self.keyring)
        backup = self.php("backup", raw=current)["result"]
        self.assertEqual(len(json.loads(backup)["keys"]), 1)
        self.keyring.unlink()
        self.assertEqual(self.php("restore", raw=current, backup=backup)["result"], self.settings)
        self.assertFalse(self.php("restore", raw=self.sealed, backup=backup)["ok"])
        self.assertFalse(self.php("restore", raw=current, backup="{}")["ok"])

    def test_runtime_php_matches_canonical_source(self):
        self.assertEqual(PHP_FILE.read_bytes(), (RUNTIME / "sls_config_crypto.php").read_bytes())


if __name__ == "__main__":
    unittest.main(verbosity=2)
