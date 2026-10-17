#!/usr/bin/env python3
"""Verify Arduino package indexes against the reviewed sensor SDK lock."""

import argparse
import json
import os
import pathlib
import re
import stat
import urllib.parse

MAX_INDEX_BYTES = 128 * 1024 * 1024
SHA256 = re.compile(r"[a-f0-9]{64}")


class LockError(RuntimeError):
    pass


def _object(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise LockError(f"Repeated JSON field: {key}")
        value[key] = item
    return value


def load_json(path):
    path = pathlib.Path(path)
    flags = os.O_RDONLY | os.O_CLOEXEC
    if hasattr(os, "O_NOFOLLOW"):
        flags |= os.O_NOFOLLOW
    descriptor = os.open(path, flags)
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
            raise LockError(f"Unsafe JSON input: {path}")
        if metadata.st_size < 2 or metadata.st_size > MAX_INDEX_BYTES:
            raise LockError(f"JSON input exceeds its size bound: {path}")
        chunks = []
        remaining = metadata.st_size
        while remaining:
            chunk = os.read(descriptor, min(remaining, 1024 * 1024))
            if not chunk:
                raise LockError(f"Short read from JSON input: {path}")
            chunks.append(chunk)
            remaining -= len(chunk)
        if os.read(descriptor, 1):
            raise LockError(f"JSON input changed while reading: {path}")
    finally:
        os.close(descriptor)
    try:
        return json.loads(b"".join(chunks), object_pairs_hook=_object)
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise LockError(f"Invalid JSON input: {path}") from error


def exact(value, keys, label):
    if not isinstance(value, dict) or set(value) != set(keys):
        raise LockError(f"{label} has an unsupported schema")
    return value


def text(value, pattern, label):
    if not isinstance(value, str) or re.fullmatch(pattern, value) is None:
        raise LockError(f"Invalid {label}")
    return value


def artifact(value, label):
    exact(value, {"archive", "url", "sha256", "size"}, label)
    archive = text(value["archive"], r"[A-Za-z0-9][A-Za-z0-9._+-]{0,199}", label + " archive")
    digest = text(value["sha256"], r"[a-f0-9]{64}", label + " SHA-256")
    size = value["size"]
    if not isinstance(size, int) or isinstance(size, bool) or size < 1 or size > 2 * 1024**3:
        raise LockError(f"Invalid {label} size")
    url = value["url"]
    if not isinstance(url, str) or len(url) > 2048:
        raise LockError(f"Invalid {label} URL")
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password or parsed.fragment:
        raise LockError(f"Invalid {label} URL")
    return {"archive": archive, "url": url, "sha256": digest, "size": size}


def indexed_artifact(value, label):
    if not isinstance(value, dict):
        raise LockError(f"Missing {label}")
    checksum = value.get("checksum")
    if not isinstance(checksum, str) or not checksum.startswith("SHA-256:"):
        raise LockError(f"Invalid {label} checksum")
    candidate = {
        "archive": value.get("archiveFileName"),
        "url": value.get("url"),
        "sha256": checksum[8:],
        "size": int(value.get("size")) if isinstance(value.get("size"), (str, int)) and str(value.get("size")).isdigit() else None,
    }
    return artifact(candidate, label)


def packages(indexes, name):
    for index in indexes:
        rows = index.get("packages") if isinstance(index, dict) else None
        if not isinstance(rows, list):
            raise LockError("Package index has no package list")
        for package in rows:
            if isinstance(package, dict) and package.get("name") == name:
                yield package


def verify(lock_path, package_paths, library_path):
    lock = exact(load_json(lock_path), {"schema", "reviewed_at", "hosts", "platforms", "tools", "libraries"}, "Sensor SDK lock")
    if lock["schema"] != 1 or not isinstance(lock["reviewed_at"], str) or re.fullmatch(r"20[0-9]{2}-[0-9]{2}-[0-9]{2}", lock["reviewed_at"]) is None:
        raise LockError("Sensor SDK lock version or review date is invalid")
    hosts = lock["hosts"]
    if not isinstance(hosts, list) or hosts != sorted(set(hosts)) or any(not isinstance(host, str) or re.fullmatch(r"x86_64-(?:pc-)?linux-gnu", host) is None for host in hosts):
        raise LockError("Sensor SDK host list is invalid")
    indexes = [load_json(path) for path in package_paths]

    platform_rows = lock["platforms"]
    if not isinstance(platform_rows, list) or not platform_rows:
        raise LockError("Sensor SDK platform lock is empty")
    dependency_ids = set()
    platform_ids = set()
    for row in platform_rows:
        exact(row, {"id", "packager", "architecture", "version", "artifact", "dependencies"}, "Platform lock")
        packager = text(row["packager"], r"[A-Za-z0-9_.-]{1,64}", "platform packager")
        architecture = text(row["architecture"], r"[A-Za-z0-9_.-]{1,64}", "platform architecture")
        version = text(row["version"], r"[A-Za-z0-9_.+-]{1,64}", "platform version")
        identity = f"{packager}:{architecture}@{version}"
        if row["id"] != identity or identity in platform_ids:
            raise LockError("Platform identity is invalid or repeated")
        platform_ids.add(identity)
        expected_artifact = artifact(row["artifact"], identity)
        dependencies = row["dependencies"]
        if not isinstance(dependencies, list) or dependencies != sorted(set(dependencies)) or any(not isinstance(item, str) or re.fullmatch(r"[A-Za-z0-9_.-]{1,64}:[A-Za-z0-9_.+-]{1,128}@[A-Za-z0-9_.+-]{1,64}", item) is None for item in dependencies):
            raise LockError(f"Invalid dependency set for {identity}")
        dependency_ids.update(dependencies)
        matches = []
        for package in packages(indexes, packager):
            platforms = package.get("platforms")
            if not isinstance(platforms, list):
                raise LockError(f"Invalid platform list for {packager}")
            matches.extend(item for item in platforms if isinstance(item, dict) and item.get("architecture") == architecture and item.get("version") == version)
        if len(matches) != 1:
            raise LockError(f"Locked platform is absent or ambiguous: {identity}")
        item = matches[0]
        if indexed_artifact(item, identity) != expected_artifact:
            raise LockError(f"Platform artifact changed: {identity}")
        actual_dependencies = item.get("toolsDependencies")
        if not isinstance(actual_dependencies, list):
            raise LockError(f"Platform dependency list is invalid: {identity}")
        actual = []
        for dependency in actual_dependencies:
            if not isinstance(dependency, dict) or not all(isinstance(dependency.get(key), str) for key in ("packager", "name", "version")):
                raise LockError(f"Platform dependency is invalid: {identity}")
            actual.append(f"{dependency['packager']}:{dependency['name']}@{dependency['version']}")
        if sorted(actual) != dependencies:
            raise LockError(f"Platform dependencies changed: {identity}")

    tool_rows = lock["tools"]
    if not isinstance(tool_rows, list):
        raise LockError("Sensor SDK tool lock is invalid")
    tool_ids = set()
    for row in tool_rows:
        exact(row, {"id", "packager", "name", "version", "host", "artifact"}, "Tool lock")
        packager = text(row["packager"], r"[A-Za-z0-9_.-]{1,64}", "tool packager")
        name = text(row["name"], r"[A-Za-z0-9_.+-]{1,128}", "tool name")
        version = text(row["version"], r"[A-Za-z0-9_.+-]{1,64}", "tool version")
        identity = f"{packager}:{name}@{version}"
        if row["id"] != identity or identity in tool_ids or row["host"] not in hosts:
            raise LockError("Tool identity or host is invalid")
        tool_ids.add(identity)
        expected_artifact = artifact(row["artifact"], identity)
        matches = []
        for package in packages(indexes, packager):
            tools = package.get("tools")
            if not isinstance(tools, list):
                raise LockError(f"Invalid tool list for {packager}")
            for tool in tools:
                if isinstance(tool, dict) and tool.get("name") == name and tool.get("version") == version:
                    systems = tool.get("systems")
                    if not isinstance(systems, list):
                        raise LockError(f"Invalid tool systems for {identity}")
                    matches.extend(item for item in systems if isinstance(item, dict) and item.get("host") == row["host"])
        if len(matches) != 1 or indexed_artifact(matches[0], identity) != expected_artifact:
            raise LockError(f"Tool artifact changed or disappeared: {identity}")
    if tool_ids != dependency_ids:
        raise LockError("Tool lock does not exactly cover platform dependencies")

    library_index = load_json(library_path)
    available = library_index.get("libraries") if isinstance(library_index, dict) else None
    if not isinstance(available, list):
        raise LockError("Library index has no library list")
    library_rows = lock["libraries"]
    if not isinstance(library_rows, list) or not library_rows:
        raise LockError("Sensor SDK library lock is empty")
    library_ids = set()
    for row in library_rows:
        exact(row, {"id", "name", "version", "artifact"}, "Library lock")
        name = text(row["name"], r"[A-Za-z0-9_. +()-]{1,128}", "library name")
        version = text(row["version"], r"[A-Za-z0-9_.+-]{1,64}", "library version")
        identity = f"{name}@{version}"
        if row["id"] != identity or identity in library_ids:
            raise LockError("Library identity is invalid or repeated")
        library_ids.add(identity)
        expected_artifact = artifact(row["artifact"], identity)
        matches = [item for item in available if isinstance(item, dict) and item.get("name") == name and item.get("version") == version]
        if len(matches) != 1 or indexed_artifact(matches[0], identity) != expected_artifact:
            raise LockError(f"Library artifact changed or disappeared: {identity}")
    return {"platforms": len(platform_ids), "tools": len(tool_ids), "libraries": len(library_ids), "reviewed_at": lock["reviewed_at"]}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--lock", required=True)
    parser.add_argument("--package-index", action="append", required=True)
    parser.add_argument("--library-index", required=True)
    args = parser.parse_args()
    result = verify(args.lock, args.package_index, args.library_index)
    print(json.dumps({"ok": True, **result}, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except (LockError, OSError, ValueError) as error:
        raise SystemExit(str(error))
