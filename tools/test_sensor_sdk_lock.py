#!/usr/bin/env python3
import copy
import importlib.util
import json
import pathlib
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("sensor_sdk_lock", ROOT / "tools/verify_sensor_sdk_lock.py")
LOCKER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(LOCKER)


def indexed(artifact):
    return {
        "archiveFileName": artifact["archive"],
        "url": artifact["url"],
        "checksum": "SHA-256:" + artifact["sha256"],
        "size": str(artifact["size"]),
    }


class SensorSdkLockTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="sls-sensor-lock-")
        self.root = pathlib.Path(self.temporary.name)
        self.reset_fixtures()

    def reset_fixtures(self):
        self.lock = json.loads((ROOT / "tools/sensor-sdk-lock.json").read_text())
        packages = {}
        for platform in self.lock["platforms"]:
            package = packages.setdefault(platform["packager"], {"name": platform["packager"], "platforms": [], "tools": []})
            dependencies = []
            for identity in platform["dependencies"]:
                packager, item = identity.split(":", 1)
                name, version = item.rsplit("@", 1)
                dependencies.append({"packager": packager, "name": name, "version": version})
            package["platforms"].append({
                "architecture": platform["architecture"], "version": platform["version"],
                "toolsDependencies": dependencies, **indexed(platform["artifact"]),
            })
        for tool in self.lock["tools"]:
            package = packages.setdefault(tool["packager"], {"name": tool["packager"], "platforms": [], "tools": []})
            package["tools"].append({"name": tool["name"], "version": tool["version"],
                "systems": [{"host": tool["host"], **indexed(tool["artifact"])}]})
        self.packages = {"packages": list(packages.values())}
        self.libraries = {"libraries": [{"name": row["name"], "version": row["version"], **indexed(row["artifact"])} for row in self.lock["libraries"]]}
        self.write()

    def tearDown(self):
        self.temporary.cleanup()

    def write(self):
        self.lock_path = self.root / "lock.json"
        self.package_path = self.root / "packages.json"
        self.library_path = self.root / "libraries.json"
        self.lock_path.write_text(json.dumps(self.lock))
        self.package_path.write_text(json.dumps(self.packages))
        self.library_path.write_text(json.dumps(self.libraries))

    def verify(self):
        return LOCKER.verify(self.lock_path, [self.package_path], self.library_path)

    def rejected(self, fragment):
        with self.assertRaises(LOCKER.LockError) as caught:
            self.verify()
        self.assertIn(fragment, str(caught.exception))

    def test_exact_lock_and_duplicate_platform_is_rejected(self):
        self.assertEqual(self.verify(), {"platforms": 2, "tools": 25, "libraries": 3, "reviewed_at": "2026-10-15"})
        duplicate = copy.deepcopy(self.packages["packages"][0])
        duplicate["tools"] = []
        self.packages["packages"].append(duplicate)
        self.write()
        self.rejected("absent or ambiguous")

    def test_platform_artifact_or_dependency_change_is_rejected(self):
        self.packages["packages"][0]["platforms"][0]["checksum"] = "SHA-256:" + "0" * 64
        self.write(); self.rejected("Platform artifact changed")
        self.reset_fixtures()
        self.packages["packages"][0]["platforms"][0]["toolsDependencies"].append({"packager": "bad", "name": "extra", "version": "1"})
        self.write(); self.rejected("dependencies changed")

    def test_tool_lock_coverage_and_checksum_are_rejected(self):
        self.lock["tools"].pop()
        self.write(); self.rejected("exactly cover")
        self.reset_fixtures()
        first = self.packages["packages"][0]["tools"][0]["systems"][0]
        first["checksum"] = "SHA-256:" + "1" * 64
        self.write(); self.rejected("Tool artifact changed")

    def test_library_checksum_and_unsafe_lock_url_are_rejected(self):
        self.libraries["libraries"][0]["checksum"] = "SHA-256:" + "2" * 64
        self.write(); self.rejected("Library artifact changed")
        self.reset_fixtures()
        self.lock["libraries"][0]["artifact"]["url"] = "http://downloads.example.invalid/library.zip"
        self.write(); self.rejected("Invalid WiFiNINA@1.9.1 URL")

    def test_repeated_json_fields_and_symlinks_are_rejected(self):
        self.lock_path.write_text('{"schema":1,"schema":1}')
        with self.assertRaises(LOCKER.LockError):
            LOCKER.load_json(self.lock_path)
        self.lock_path.unlink()
        self.lock_path.symlink_to(ROOT / "tools/sensor-sdk-lock.json")
        with self.assertRaises(OSError):
            LOCKER.load_json(self.lock_path)

    def test_oversized_input_is_rejected_before_parsing(self):
        with self.package_path.open("wb") as stream:
            stream.truncate(LOCKER.MAX_INDEX_BYTES + 1)
        with self.assertRaisesRegex(LOCKER.LockError, "size bound"):
            LOCKER.load_json(self.package_path)


if __name__ == "__main__":
    unittest.main()
