#!/usr/bin/python3
"""Run each registered suite in a fresh PBX-isolated namespace."""
from __future__ import annotations

import argparse
import os
from pathlib import Path
import random
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]


def suites(root: Path, selected: list[str], seed: int | None) -> list[Path]:
    names = selected or re.findall(
        r'^(?:python3|php|bash|node) "\$\{ROOT_DIR\}/tools/(test_[^"/]+)"$',
        (root / "tools/build_tgz.sh").read_text(),
        re.M,
    )
    paths = []
    for name in names:
        path = Path(name) if selected else Path("tools") / name
        if (
            path.parent != Path("tools")
            or not re.fullmatch(r"test_[A-Za-z0-9_]+\.(?:py|php|sh|js)", path.name)
            or not (root / path).is_file()
            or (root / path).is_symlink()
        ):
            raise ValueError("Unknown fixture path: " + str(path))
        if path in paths:
            raise ValueError("Duplicate fixture path: " + str(path))
        paths.append(path)
    if not paths:
        raise ValueError("No fixture suites were discovered.")
    if seed is not None:
        random.Random(seed).shuffle(paths)
    return paths


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--shuffle-seed", type=int)
    parser.add_argument("--repeat", type=int, choices=(1, 2, 3), default=1)
    parser.add_argument("tests", nargs="*")
    args = parser.parse_args()
    tests = suites(ROOT, args.tests, args.shuffle_seed)
    failures = []
    environment = {
        key: value
        for key, value in os.environ.items()
        if not key.startswith("SLS_TEST_PARENT_") and key != "SLS_TEST_NAMESPACE"
    }
    for iteration in range(args.repeat):
        for path in tests:
            print(
                "\nFRESH BASELINE " + str(path) + f" (pass {iteration + 1})", flush=True
            )
            try:
                result = subprocess.run(
                    [
                        "bash",
                        str(ROOT / "tools/run_isolated_tests.sh"),
                        "--single",
                        str(path),
                    ],
                    cwd=ROOT,
                    env=environment,
                    timeout=300,
                    check=False,
                )
                if result.returncode:
                    failures.append(str(path))
            except subprocess.TimeoutExpired:
                failures.append(str(path) + " (300-second namespace limit)")
    total = len(tests) * args.repeat
    print(
        f"\n{total-len(failures)}/{total} independent fixture suites passed.",
        flush=True,
    )
    if failures:
        print("Failed: " + ", ".join(failures), flush=True)
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
