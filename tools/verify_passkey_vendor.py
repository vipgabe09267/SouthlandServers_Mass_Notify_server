#!/usr/bin/python3
"""Verify the exact locked passkey dependency tree without executing it."""
from pathlib import Path
import hashlib
import json
import re
import stat

ROOT = Path(__file__).resolve().parents[1] / "slsmassnotifyserver/passkey-libs"


def verify(root=ROOT):
    baseline = json.loads((root / "SLS_VENDOR_BASELINE.json").read_bytes())
    if (
        set(baseline) != {"schema", "reviewed_at", "composer_lock_sha256", "files"}
        or baseline["schema"] != 1
        or not isinstance(baseline["files"], dict)
        or not 1 <= len(baseline["files"]) <= 5000
    ):
        raise ValueError("Passkey vendor baseline is invalid")
    if (
        hashlib.sha256((root / "composer.lock").read_bytes()).hexdigest()
        != baseline["composer_lock_sha256"]
    ):
        raise ValueError("Passkey Composer lock differs from its reviewed baseline")
    actual = {}
    for path in (root / "vendor").rglob("*"):
        info = path.lstat()
        if stat.S_ISDIR(info.st_mode):
            continue
        if (
            not stat.S_ISREG(info.st_mode)
            or info.st_nlink != 1
            or info.st_size > 8 * 1024 * 1024
        ):
            raise ValueError(
                "Unsafe passkey vendor file: " + str(path.relative_to(root))
            )
        name = path.relative_to(root).as_posix()
        actual[name] = hashlib.sha256(path.read_bytes()).hexdigest()
        if len(actual) > 5000:
            raise ValueError("Passkey dependency file count exceeds its bound")
    if actual != baseline["files"]:
        raise ValueError("Passkey vendor files differ from the reviewed baseline")
    guard = (root / "vendor/symfony/polyfill-uuid/bootstrap80.php").read_text()
    if not re.search(
        r"if \(\\PHP_VERSION_ID >= 80500\) \{\s*require __DIR__\.'/bootstrap85\.php';",
        guard,
    ):
        raise ValueError("The upstream PHP 8.5 compatibility branch is no longer gated")
    lock = json.loads((root / "composer.lock").read_bytes())
    installed = json.loads((root / "vendor/composer/installed.json").read_bytes())
    packages = {row["name"]: row for row in lock["packages"]}
    deployed = {row["name"]: row for row in installed["packages"]}
    if packages.keys() != deployed.keys():
        raise ValueError("Passkey installed package inventory differs from the lock")
    for name, row in packages.items():
        if (
            deployed[name]["version"] != row["version"]
            or deployed[name].get("source", {}).get("reference")
            != row["source"]["reference"]
            or not re.fullmatch(r"[a-f0-9]{40}", row["source"]["reference"])
        ):
            raise ValueError("Passkey package revision mismatch: " + name)
    return (
        lock,
        hashlib.sha256((root / "SLS_VENDOR_BASELINE.json").read_bytes()).hexdigest(),
    )


if __name__ == "__main__":
    locked, digest = verify()
    print(
        f'Passkey dependency baseline verified: {len(locked["packages"])} packages; SHA-256 {digest}.'
    )
