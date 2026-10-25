#!/usr/bin/python3
import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location(
    "review_gate", Path(__file__).with_name("check_audit_ledger.py")
)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class ReviewGateTests(unittest.TestCase):
    def test_both_passes_bind_exact_bytes_and_do_not_rewrite_evidence(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / "fixture.php").write_bytes(b"<?php echo 'fixture';\n")
            digest = hashlib.sha256((root / "fixture.php").read_bytes()).hexdigest()
            entry = {
                "path": "fixture.php",
                "manual_pass_1": {"status": "complete", "sha256": digest},
                "manual_pass_2": {"status": "pending", "sha256": digest},
            }
            self.assertFalse(gate.verify({"files": [entry]}, root)["ok"])
            self.assertEqual(entry["manual_pass_2"]["status"], "pending")
            entry["manual_pass_2"]["status"] = "complete"
            self.assertTrue(gate.verify({"files": [entry]}, root)["ok"])
            (root / "fixture.php").write_bytes(b"<?php echo 'changed';\n")
            result = gate.verify({"files": [entry]}, root)
            self.assertFalse(result["ok"])
            self.assertEqual(
                {row["reason"] for row in result["problems"]}, {"source_changed"}
            )

    def test_current_ledger_requires_per_pass_hashes_and_safe_paths(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            path = root / "fixture.php"
            path.write_bytes(b"fixture")
            digest = hashlib.sha256(path.read_bytes()).hexdigest()
            entry = {"pass_one": "reviewed", "pass_two": "reviewed", "sha256": digest}
            self.assertFalse(gate.verify({"files": {"fixture.php": entry}}, root)["ok"])
            entry.update(pass_one_sha256=digest, pass_two_sha256=digest)
            self.assertTrue(gate.verify({"files": {"fixture.php": entry}}, root)["ok"])
            (root / "alias.php").symlink_to(path)
            self.assertFalse(gate.verify({"files": {"alias.php": entry}}, root)["ok"])
            for name in ("../fixture.php", str(path), "./fixture.php", "nested//fixture.php"):
                with self.assertRaises(ValueError):
                    gate.verify({"files": {name: entry}}, root)
            with self.assertRaises(ValueError):
                gate.verify({"files": {"fixture.php": entry, "./fixture.php": entry}}, root)


if __name__ == "__main__":
    unittest.main()
