#!/usr/bin/python3
"""Pinned PHP security-floor observations, independent of application settings."""
import json
from pathlib import Path
import re


def evaluate(version, policy=None):
    result = {
        "ok": False,
        "code": "php_security_policy_unavailable",
        "minimum": "",
        "version": version,
    }
    if policy is None:
        try:
            path = Path(__file__).with_name("php-security-policy.json")
            if path.is_symlink() or path.stat().st_size > 8192:
                return result
            policy = json.loads(path.read_text())
        except (OSError, ValueError):
            return result
    if (
        not isinstance(policy, dict)
        or type(policy.get("schema")) is not int
        or policy["schema"] != 1
        or not isinstance(policy.get("branches"), dict)
        or len(policy["branches"]) > 16
    ):
        return result
    match = (
        re.fullmatch(
            r"(\d{1,2}\.\d{1,2})\.(\d{1,3})(?:[-+][A-Za-z0-9.+~_-]+)?", version
        )
        if isinstance(version, str) and len(version) <= 80
        else None
    )
    if match and re.search(r"(?:^|[-+~_.])(?:dev|alpha|beta|rc)[0-9]*", version, re.I):
        match = None
    if not match:
        return {**result, "code": "php_version_unrecognized"}
    minimum = policy["branches"].get(match[1])
    if not isinstance(minimum, str) or not re.fullmatch(
        re.escape(match[1]) + r"\.\d{1,3}", minimum
    ):
        return {**result, "code": "php_branch_unqualified"}
    ok = int(match[2]) >= int(minimum.rsplit(".", 1)[1])
    return {
        **result,
        "ok": ok,
        "code": "php_security_floor_met" if ok else "php_security_update_required",
        "minimum": minimum,
    }
