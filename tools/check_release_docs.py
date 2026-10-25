#!/usr/bin/python3
"""Check candidate/published documentation state without consulting live PBX state."""
import hashlib
import json
from pathlib import Path
import re
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]


def release_state(record, version):
    if (
        not isinstance(record, dict)
        or set(record)
        != {
            "schema",
            "published_version",
            "candidate_version",
            "candidate_status",
            "purpose",
        }
        or type(record["schema"]) is not int
        or record["schema"] != 1
        or not isinstance(record["purpose"], str)
        or not record["purpose"].strip()
    ):
        raise ValueError("Release documentation record has an invalid schema.")
    if record["candidate_version"] != version:
        raise ValueError("Candidate documentation state differs from module.xml.")
    for value in [version, record["published_version"]]:
        if not isinstance(value, str) or not re.fullmatch(
            r"[0-9]+\.[0-9]+\.[0-9]+-beta", value
        ):
            raise ValueError("Release documentation version is invalid.")
    current = tuple(map(int, version[:-5].split(".")))
    published = tuple(map(int, record["published_version"][:-5].split(".")))
    status = record["candidate_status"]
    if status == "published" and version == record["published_version"]:
        return status
    if status == "unpublished" and current > published:
        return status
    raise ValueError("Published documentation must match the release; unpublished candidates must follow it.")


def check(root=ROOT):
    record = json.loads((root / "docs/release-status.json").read_text())
    version = ET.parse(root / "slsmassnotifyserver/module.xml").findtext("version")
    status = release_state(record, version)
    for name in ["CONTROL_API.md", "QUALIFICATION_MATRIX.md"]:
        intro = (root / "docs" / name).read_text()[:500]
        if (version not in intro or re.search(r"\b" + status + r"\b", intro) is None
                or (status == "published" and re.search(r"\bunpublished\b", intro))):
            raise ValueError("Current release state is missing from " + name)
    for name in [
        "release-status.json",
        "QUALIFICATION_MATRIX.md",
        "openapi.json",
        "schemas/notification.schema.json",
    ]:
        if (root / "docs" / name).read_bytes() != (
            root / "slsmassnotifyserver/docs" / name
        ).read_bytes():
            raise ValueError("Packaged documentation differs: " + name)
    readme = (root / "README.md").read_text()
    digest = hashlib.sha256(
        (root / "tools/install_bootstrap.sh").read_bytes()
    ).hexdigest()
    if (
        digest + "  sls-bootstrap.sh" not in readme
        or "sls-bootstrap.sh " + version not in readme
    ):
        raise ValueError("The README bootstrap checksum/version is stale.")
    security = (root / "SECURITY.md").read_text()
    if "currently `" + record["published_version"] + "`" not in security:
        raise ValueError("The security policy published version is stale.")
    print(
        "Module version, release state, bootstrap checksum and packaged descriptions agree."
    )


if __name__ == "__main__":
    try:
        check()
    except (OSError, ValueError, ET.ParseError) as error:
        raise SystemExit(str(error))
