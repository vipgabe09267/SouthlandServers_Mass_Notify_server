#!/usr/bin/python3
"""Concurrent directory creation must retain canonical-path and ownership checks."""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "slsmassnotifyserver"


class RuntimeInitialization(unittest.TestCase):
    def run_case(self, kind, replacement):
        with tempfile.TemporaryDirectory(prefix="sls-initialize-race-") as temporary:
            base = Path(temporary)
            directory = base / "storage"
            namespace, source, invoke = {
                "sms": (
                    "SLS\\MassNotify\\Sms",
                    "api/sls-mass-notify/sms/Store.php",
                    "$store=new \\SLS\\MassNotify\\Sms\\Store($path);",
                ),
                "floorplan": (
                    "SLS\\MassNotify",
                    "EnterpriseFloorplanStore.php",
                    "$store=new \\SLS\\MassNotify\\EnterpriseFloorplanStore($path);",
                ),
                "announcement": (
                    "FreePBX\\modules",
                    "AnnouncementDelivery.php",
                    "class RaceAnnouncement {use \\FreePBX\\modules\\SlsAnnouncementDelivery;"
                    "const PLUGIN_DATA_DIR=" + json.dumps(str(base)) + ";}"
                    "$method=new ReflectionMethod(RaceAnnouncement::class,'announcementJobDirectory');"
                    "$path=$method->invoke(new RaceAnnouncement());",
                ),
            }[kind]
            if kind == "announcement":
                directory = base / "announcement-jobs"
            php = "<?php\nnamespace " + namespace + " {\n"
            php += """
function mkdir(string $path,int $mode=0777,bool $recursive=false,$context=null): bool {
    $GLOBALS['creation_attempts']++;
    // Another request finishes creation after the caller's initial existence check.
    if ($GLOBALS['replacement']==='directory') { \\mkdir($path,$mode); }
    elseif ($GLOBALS['replacement']==='symlink') {
        \\mkdir($path.'-other',$mode);\\symlink($path.'-other',$path);
    } else { file_put_contents($path,'preserve this non-directory'); }
    return false;
}
}
namespace {
"""
            php += "$GLOBALS['creation_attempts']=0;$GLOBALS['replacement']=" + json.dumps(replacement) + ";\n"
            php += "$path=" + json.dumps(str(directory)) + ";\n"
            php += "require " + json.dumps(str(MODULE / source)) + ";\n"
            php += "try {" + invoke + ";$ok=true;}catch(\\Throwable $error){$ok=false;}\n"
            php += "echo json_encode(['ok'=>$ok,'attempts'=>$GLOBALS['creation_attempts'],"
            php += "'linked'=>is_link($path),'contents'=>is_file($path)?file_get_contents($path):null]);\n}\n"
            script = base / "race.php"
            script.write_text(php)
            result = subprocess.run(
                ["php", str(script)], capture_output=True, text=True, timeout=10
            )
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertEqual(result.stderr, "", result.stderr)
            return json.loads(result.stdout)

    def test_simultaneously_created_valid_directory_is_accepted(self):
        for kind in ("sms", "floorplan", "announcement"):
            with self.subTest(kind=kind):
                result = self.run_case(kind, "directory")
                self.assertTrue(result["ok"], result)
                self.assertEqual(result["attempts"], 1)

    def test_linked_replacement_is_rejected(self):
        for kind in ("sms", "floorplan", "announcement"):
            with self.subTest(kind=kind):
                result = self.run_case(kind, "symlink")
                self.assertFalse(result["ok"], result)
                self.assertTrue(result["linked"])

    def test_non_directory_replacement_is_preserved_and_rejected(self):
        for kind in ("sms", "floorplan", "announcement"):
            with self.subTest(kind=kind):
                result = self.run_case(kind, "file")
                self.assertFalse(result["ok"], result)
                self.assertEqual(result["contents"], "preserve this non-directory")


if __name__ == "__main__":
    unittest.main()
