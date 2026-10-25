#!/usr/bin/python3
"""Exercise the standalone pre-execution verifier with a private ephemeral key."""
import base64
import hashlib
import io
import json
import os
from pathlib import Path
import shlex
import stat
import subprocess
import tarfile
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
BOOTSTRAP = ROOT / "tools/install_bootstrap.sh"


class VerifiedBootstrap(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="sls-bootstrap-")
        self.path = Path(self.temporary.name)
        self.version = "0.1.8-beta"
        self.marker = self.path / "executed"
        subprocess.run(
            [
                "openssl",
                "genpkey",
                "-algorithm",
                "ed25519",
                "-out",
                str(self.path / "private.pem"),
            ],
            check=True,
            capture_output=True,
        )
        subprocess.run(
            [
                "openssl",
                "pkey",
                "-in",
                str(self.path / "private.pem"),
                "-pubout",
                "-out",
                str(self.path / "publisher.pem"),
            ],
            check=True,
            capture_output=True,
        )
        der = subprocess.check_output(
            [
                "openssl",
                "pkey",
                "-in",
                str(self.path / "private.pem"),
                "-pubout",
                "-outform",
                "DER",
            ]
        )
        self.public_der = der
        self.installer = (
            b"#!/bin/bash\nprintf verified > " + str(self.marker).encode() + b"\n"
        )
        (self.path / "install_release.sh").write_bytes(self.installer)
        self.manifest = {
            "schema": 1,
            "version": self.version,
            "tag": "slsmassnotifyserver-" + self.version,
            "package": "slsmassnotifyserver-" + self.version + ".tgz",
            "package_sha256": "a" * 64,
            "installer_sha256": hashlib.sha256(self.installer).hexdigest(),
            "signing": {
                "key_id": hashlib.sha256(der).hexdigest(),
                "issued_at": int(time.time()) - 1,
                "expires_at": int(time.time()) + 300,
            },
        }

    def tearDown(self):
        self.temporary.cleanup()

    def sign(self, raw=None):
        (self.path / "release-manifest.json").write_text(
            json.dumps(self.manifest) if raw is None else raw
        )
        subprocess.run(
            [
                "openssl",
                "pkeyutl",
                "-sign",
                "-inkey",
                str(self.path / "private.pem"),
                "-rawin",
                "-in",
                str(self.path / "release-manifest.json"),
                "-out",
                str(self.path / "release-manifest.sig"),
            ],
            check=True,
            capture_output=True,
        )

    def execute(self):
        return subprocess.run(
            [
                "bash",
                "-c",
                'source "$1"; bootstrap_verify "$2" "$3" && /bin/bash "$2/install_release.sh"',
                "fixture",
                str(BOOTSTRAP),
                str(self.path),
                self.version,
            ],
            capture_output=True,
            text=True,
            timeout=10,
        )

    def prepare_correction(self):
        self.package = self.path / ("slsmassnotifyserver-" + self.version + ".tgz")
        with tarfile.open(self.package, "w:gz") as archive:
            body = b"<module><version>0.1.8-beta</version></module>\n"
            entry = tarfile.TarInfo("slsmassnotifyserver/module.xml")
            entry.size = len(body)
            archive.addfile(entry, io.BytesIO(body))
        self.manifest["package_sha256"] = hashlib.sha256(self.package.read_bytes()).hexdigest()
        self.policy = self.path / "publisher-policy"
        bootstrap = BOOTSTRAP.read_text()
        for name in ("sls_release_verify.py", "sls_release_trust.py"):
            original = (ROOT / "slsmassnotifyserver/bin/sls_mass_notify" / name).read_bytes()
            body = original
            if name == "sls_release_trust.py":
                needle = b"DIRECTORY = Path('/var/lib/sls-mass-notify-trust')"
                self.assertEqual(body.count(needle), 1)
                body = body.replace(needle, ("DIRECTORY = Path(" + repr(str(self.policy)) + ")").encode())
            original_hash = hashlib.sha256(original).hexdigest()
            self.assertIn(original_hash, bootstrap, name + " must be checksum-pinned")
            bootstrap = bootstrap.replace(original_hash, hashlib.sha256(body).hexdigest())
            (self.path / name).write_bytes(body)
        anchor = (ROOT / "slsmassnotifyserver/bin/sls_mass_notify/release-signing.pub").read_text().strip()
        bootstrap = bootstrap.replace(anchor, (self.path / "publisher.pem").read_text().strip())
        bootstrap = bootstrap.replace("/var/lib/sls-mass-notify-trust", str(self.policy))
        self.correction_bootstrap = self.path / "correction-bootstrap.sh"
        self.correction_bootstrap.write_text(bootstrap)
        (self.path / "release-signing.pub").write_bytes((self.path / "publisher.pem").read_bytes())
        self.sign()
        for name in (self.package.name, "install_release.sh", "release-manifest.json",
                     "release-manifest.sig", "sls_release_verify.py", "sls_release_trust.py",
                     "release-signing.pub"):
            (self.path / name).chmod(0o600)

    def execute_correction(self, main=False, environment=None):
        command = (
            'source "$1"; bootstrap_verify "$2" "$3" && '
            'bootstrap_verify_correction "$2" "$3" && /bin/bash "$2/install_release.sh"'
        )
        if main:
            command = '''source "$1"
fixture_assets="$2"
bootstrap_download() {
  local destination="$1" name
  for name in release-manifest.json release-manifest.sig install_release.sh \
      slsmassnotifyserver-0.1.8-beta.tgz sls_release_verify.py sls_release_trust.py; do
    /bin/cp -- "$fixture_assets/$name" "$destination/$name"
  done
}
bootstrap_main "$3" --correction
'''
        return subprocess.run(
            ["bash", "-c", command, "fixture", str(self.correction_bootstrap),
             str(self.path), self.version],
            capture_output=True, text=True, timeout=15, env=environment,
        )

    def write_policy(self, revoked=False):
        self.policy.mkdir(mode=0o700, exist_ok=True)
        key_id = self.manifest["signing"]["key_id"]
        entry = {"id": key_id, "public_key": base64.b64encode(self.public_der).decode(),
                 "not_before": 0, "expires_at": 0,
                 "revoked_at": int(time.time()) - 5 if revoked else 0}
        state = {"policy": {"schema": 1, "sequence": 1, "keys": [entry]},
                 "retired_ids": [key_id] if revoked else []}
        (self.policy / "publisher-trust.json").write_text(json.dumps(state))
        (self.policy / "release-trust.initialized").write_text(key_id + "\n")
        for path in self.policy.iterdir():
            path.chmod(0o600)

    def policy_snapshot(self):
        return {
            path.name: (path.read_bytes(), path.stat().st_ino,
                        stat.S_IMODE(path.stat().st_mode))
            for path in self.policy.iterdir()
        }

    def test_correction_authenticates_all_assets_before_installer_execution(self):
        self.prepare_correction()
        result = self.execute_correction()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.marker.read_text(), "verified")
        self.assertFalse(self.policy.exists(), "Verification unexpectedly initialized trust")

    def test_correction_main_supports_existing_active_policy_without_modifying_it(self):
        self.prepare_correction()
        self.write_policy()
        before = self.policy_snapshot()
        result = self.execute_correction(main=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.marker.read_text(), "verified")
        self.assertEqual(self.policy_snapshot(), before)

    def test_correction_main_executes_the_exact_verified_local_bundle(self):
        self.prepare_correction()
        lines = ["#!/bin/bash", "set -euo pipefail",
                 '[ -z "${SLS_MASS_NOTIFY_TGZ_URL:-}" ]',
                 '[ "${SLS_MASS_NOTIFY_SHA256:-}" = '
                 + shlex.quote(self.manifest["package_sha256"]) + ' ]']
        for variable, filename in (
            ("SLS_MASS_NOTIFY_TGZ", self.package.name),
            ("SLS_MASS_NOTIFY_MANIFEST", "release-manifest.json"),
            ("SLS_MASS_NOTIFY_MANIFEST_SIGNATURE", "release-manifest.sig"),
        ):
            lines.append('cmp -s -- "$' + variable + '" '
                         + shlex.quote(str(self.path / filename)))
        lines.append("printf verified > " + shlex.quote(str(self.marker)))
        self.installer = ("\n".join(lines) + "\n").encode()
        (self.path / "install_release.sh").write_bytes(self.installer)
        self.manifest["installer_sha256"] = hashlib.sha256(self.installer).hexdigest()
        self.sign()
        environment = os.environ.copy()
        environment["SLS_MASS_NOTIFY_TGZ_URL"] = "https://example.invalid/stale.tgz"
        for name in ("SLS_MASS_NOTIFY_TGZ", "SLS_MASS_NOTIFY_MANIFEST",
                     "SLS_MASS_NOTIFY_MANIFEST_SIGNATURE", "SLS_MASS_NOTIFY_SHA256"):
            environment[name] = "/stale-asset-must-not-be-used"
        result = self.execute_correction(main=True, environment=environment)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.marker.read_text(), "verified")

    def test_correction_rejects_other_versions_before_downloading(self):
        self.prepare_correction()
        result = subprocess.run(
            ["bash", "-c", '''source "$1"
fixture_marker="$2"
bootstrap_download() { : > "$fixture_marker"; }
bootstrap_main 0.1.7-beta --correction
''', "fixture", str(self.correction_bootstrap), str(self.marker)],
            capture_output=True, text=True, timeout=10,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("0.1.8-beta", result.stderr)
        self.assertFalse(self.marker.exists())

    def test_correction_rejects_each_unpinned_helper_before_executing_it(self):
        self.prepare_correction()
        helper_marker = self.path / "helper-executed"
        for name in ("sls_release_verify.py", "sls_release_trust.py"):
            with self.subTest(helper=name):
                path = self.path / name
                before = path.read_bytes()
                marker_code = ("from pathlib import Path\nPath(" + repr(str(helper_marker))
                               + ").write_text('untrusted helper executed')\n").encode()
                path.write_bytes(marker_code + before)
                try:
                    result = self.execute_correction()
                    self.assertNotEqual(result.returncode, 0)
                    self.assertFalse(helper_marker.exists())
                    self.assertFalse(self.marker.exists())
                finally:
                    path.write_bytes(before)

    def test_correction_rejects_tampered_package_manifest_signature_and_expiry(self):
        self.prepare_correction()
        for name in (self.package.name, "release-manifest.json", "release-manifest.sig"):
            with self.subTest(asset=name):
                path = self.path / name
                before = path.read_bytes()
                path.write_bytes(before + b"tampered")
                try:
                    self.assertNotEqual(self.execute_correction().returncode, 0)
                    self.assertFalse(self.marker.exists())
                finally:
                    path.write_bytes(before)
        self.manifest["signing"]["expires_at"] = int(time.time()) - 1
        self.sign()
        self.assertNotEqual(self.execute_correction().returncode, 0)
        self.assertFalse(self.marker.exists())

    def test_correction_respects_revocation_and_preserves_existing_policy(self):
        self.prepare_correction()
        self.write_policy(revoked=True)
        before = self.policy_snapshot()
        result = self.execute_correction(main=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.marker.exists())
        self.assertEqual(self.policy_snapshot(), before)

    def test_correction_does_not_fall_back_when_existing_policy_is_incomplete(self):
        self.prepare_correction()
        for missing in ("publisher-trust.json", "release-trust.initialized"):
            with self.subTest(missing=missing):
                self.write_policy()
                (self.policy / missing).unlink()
                before = self.policy_snapshot()
                self.assertNotEqual(self.execute_correction(main=True).returncode, 0)
                self.assertFalse(self.marker.exists())
                self.assertEqual(self.policy_snapshot(), before)

    def test_correction_rejects_unsafe_existing_policy_permissions(self):
        self.prepare_correction()
        self.write_policy()
        state = self.policy / "publisher-trust.json"
        state.chmod(0o644)
        before = self.policy_snapshot()
        self.assertNotEqual(self.execute_correction(main=True).returncode, 0)
        self.assertFalse(self.marker.exists())
        self.assertEqual(self.policy_snapshot(), before)

    def test_correct_signed_bytes_execute_only_after_verification(self):
        self.sign()
        result = self.execute()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.marker.read_text(), "verified")

    def test_tampered_installer_is_not_executed(self):
        self.sign()
        (self.path / "install_release.sh").write_bytes(
            self.installer + b"echo changed\n"
        )
        result = self.execute()
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.marker.exists())

    def test_identity_expiry_future_issued_and_ambiguous_fields_are_rejected(self):
        for field, value in [
            ("version", "0.1.7-beta"),
            ("package", "../package.tgz"),
            ("schema", True),
        ]:
            with self.subTest(field=field):
                prior = self.manifest[field]
                self.manifest[field] = value
                self.sign()
                result = self.execute()
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(self.marker.exists())
                self.manifest[field] = prior
        for field, value in [
            ("expires_at", int(time.time()) - 1),
            ("issued_at", int(time.time()) + 300),
            ("key_id", "0" * 64),
        ]:
            with self.subTest(field=field):
                prior = self.manifest["signing"][field]
                self.manifest["signing"][field] = value
                self.sign()
                self.assertNotEqual(self.execute().returncode, 0)
                self.assertFalse(self.marker.exists())
                self.manifest["signing"][field] = prior
        raw = json.dumps(self.manifest).replace(
            '"schema": 1', '"schema": 1, "schema": 1'
        )
        self.sign(raw)
        self.assertNotEqual(self.execute().returncode, 0)
        self.assertFalse(self.marker.exists())

    def test_signature_failure_never_executes_installer(self):
        self.sign()
        (self.path / "release-manifest.sig").write_bytes(bytes(64))
        self.assertNotEqual(self.execute().returncode, 0)
        self.assertFalse(self.marker.exists())

    def test_initialized_or_interrupted_policy_is_not_bypassed(self):
        for name in ("publisher-trust.json", "release-trust.initialized"):
            with self.subTest(name=name):
                trust = self.path / name
                trust.write_text("preserved")
                fixture = self.path / "fixture.sh"
                fixture.write_text(
                    BOOTSTRAP.read_text().replace(
                        "/var/lib/sls-mass-notify-trust", str(self.path)
                    )
                )
                command = 'source "$1"; fixture_marker="$3"; bootstrap_download(){ touch "$fixture_marker"; }; bootstrap_main 0.1.8-beta'
                result = subprocess.run(
                    [
                        "bash",
                        "-c",
                        command,
                        "fixture",
                        str(fixture),
                        "unused",
                        str(self.marker),
                    ],
                    capture_output=True,
                    text=True,
                    timeout=10,
                )
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("trust policy", result.stderr)
                self.assertFalse(self.marker.exists())
                trust.unlink()


if __name__ == "__main__":
    unittest.main()
