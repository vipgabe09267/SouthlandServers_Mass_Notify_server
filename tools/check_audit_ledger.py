#!/usr/bin/python3
"""Check recorded review completion against exact source bytes; never mark reviews."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import stat


def verify(ledger, root):
    if not isinstance(ledger, dict) or not isinstance(
        ledger.get("files"), (list, dict)
    ):
        raise ValueError("The review ledger must contain a file inventory.")
    root = Path(root).resolve(strict=True)
    entries = ledger["files"]
    if isinstance(entries, dict):
        entries = [dict(entry, path=name) for name, entry in entries.items()]
    if not entries or len(entries) > 20000:
        raise ValueError("The review ledger file count is invalid.")
    seen = set()
    problems = []
    for entry in entries:
        name = entry.get("path") if isinstance(entry, dict) else None
        if (
            not isinstance(name, str)
            or not name
            or Path(name).is_absolute()
            or name != Path(name).as_posix()
            or ".." in Path(name).parts
            or name in seen
        ):
            raise ValueError("The review ledger has an unsafe or duplicate path.")
        seen.add(name)
        path = root / name
        try:
            info = path.lstat()
            if (
                not stat.S_ISREG(info.st_mode)
                or info.st_nlink != 1
                or info.st_size > 8 * 1024 * 1024
                or path.resolve() != path
            ):
                raise ValueError("unsafe source file")
            descriptor = os.open(
                path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
            )
            with os.fdopen(descriptor, "rb") as source:
                opened = os.fstat(source.fileno())
                if not stat.S_ISREG(opened.st_mode) or (
                    opened.st_dev,
                    opened.st_ino,
                ) != (info.st_dev, info.st_ino):
                    raise ValueError("source changed before opening")
                raw = source.read(8 * 1024 * 1024 + 1)
                after = os.fstat(source.fileno())
            current = path.lstat()
            identity = lambda meta: (
                meta.st_dev,
                meta.st_ino,
                meta.st_mode,
                meta.st_nlink,
                meta.st_size,
                meta.st_mtime_ns,
                meta.st_ctime_ns,
            )
            if (
                not stat.S_ISREG(opened.st_mode)
                or len(raw) != info.st_size
                or identity(info) != identity(opened)
                or identity(opened) != identity(after)
                or identity(after) != identity(current)
                or path.resolve() != path
            ):
                raise ValueError("source changed while checking")
            digest = hashlib.sha256(raw).hexdigest()
        except (OSError, ValueError):
            problems.append({"path": name, "reason": "source_missing_or_unsafe"})
            continue
        for number, key in ((1, "pass_one"), (2, "pass_two")):
            legacy = entry.get("manual_pass_" + str(number))
            if isinstance(legacy, dict):
                complete = legacy.get("status") == "complete"
                reviewed = legacy.get("sha256")
            else:
                complete = entry.get(key) == "reviewed"
                reviewed = entry.get(key + "_sha256")
            if not complete or reviewed != digest:
                problems.append(
                    {
                        "path": name,
                        "pass": number,
                        "reason": (
                            "incomplete_review" if not complete else "source_changed"
                        ),
                    }
                )
    return {
        "ok": not problems,
        "files": len(seen),
        "problems": problems,
        "scope": "Only the declared inventory; this does not prove that every project file was included or that reviewed code is defect-free.",
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("ledger", type=Path)
    parser.add_argument("--root", required=True, type=Path)
    args = parser.parse_args()
    if args.ledger.stat().st_size > 16 * 1024 * 1024:
        parser.error("The review ledger exceeds 16 MiB.")
    with args.ledger.open("rb") as source:
        raw = source.read(16 * 1024 * 1024 + 1)
    if len(raw) > 16 * 1024 * 1024:
        parser.error("The review ledger exceeds 16 MiB.")

    def unique_pairs(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("The review ledger contains duplicate JSON fields.")
            result[key] = value
        return result

    result = verify(json.loads(raw, object_pairs_hook=unique_pairs), args.root)
    print(json.dumps(result, indent=2))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError) as error:
        raise SystemExit(str(error))
