#!/usr/bin/python3
"""Pinned optional voice metadata and optional real offline CPU synthesis."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import wave

ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/piper-optional-voices.json"
spec = importlib.util.spec_from_file_location(
    "regional_capacity",
    ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_resource_capacity.py",
)
capacity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(capacity)


class RegionalVoicePacks(unittest.TestCase):
    def test_exact_pins_licenses_and_optional_only_storage(self):
        catalog = json.loads(CATALOG.read_text())
        self.assertEqual(catalog["schema"], 1)
        self.assertEqual(
            {voice["id"] for voice in catalog["voices"]},
            {"en_GB-cori-high", "es_MX-ald-medium"},
        )
        for voice in catalog["voices"]:
            self.assertIn(voice["license"], ("LicenseRef-Public-Domain", "Unlicense"))
            self.assertEqual(
                {name: meta["size"] for name, meta in voice["files"].items()},
                capacity.REGIONAL_VOICE_BYTES[voice["id"]],
            )
            for name, meta in voice["files"].items():
                self.assertRegex(meta["sha256"], r"^[a-f0-9]{64}$")
                self.assertIn("/e21c7de8d4eab79b902f0d61e662b3f21664b8d2/", meta["url"])
                self.assertTrue(meta["url"].endswith("/" + name))
        default = capacity.storage_requirements()
        selected = capacity.storage_requirements(
            optional_packs=tuple(capacity.REGIONAL_VOICE_BYTES)
        )
        self.assertGreater(
            selected["fresh_reference_voice_bytes"],
            default["fresh_reference_voice_bytes"],
        )
        self.assertEqual(default["optional_voice_packs"], [])
        with self.assertRaises(ValueError):
            capacity.storage_requirements(optional_packs=("unreviewed-model",))

    def test_real_admission_supervisor_to_native_worker(self):
        models=os.environ.get('SLS_REGIONAL_VOICE_FIXTURE','');python=os.environ.get('SLS_PIPER_PYTHON','')
        if not models or not python:self.skipTest('Supply the private pinned models and Piper Python for supervisor qualification.')
        runtime=ROOT/'slsmassnotifyserver/bin/sls_mass_notify'
        sys.path.insert(0,str(runtime))
        import sls_speech_admission as speech
        from unittest import mock
        with tempfile.TemporaryDirectory(prefix='sls-native-supervisor-') as temporary:
            folder=Path(temporary);text=folder/'input.txt';text.write_text('This is an offline supervision test.\n')
            output=folder/'speech.wav'
            with mock.patch.object(speech,'PIPER',str(Path(python).with_name('piper'))):
                result=speech.synthesize([speech.PIPER,'--model',str(Path(models)/'en_GB-cori-high.onnx'),'--input-file',str(text),'--output-file',str(output)],10,35)
            self.assertEqual(result,0)
            with wave.open(str(output)) as audio:
                self.assertGreater(audio.getnframes(),100)
                self.assertLessEqual(audio.getnframes()/audio.getframerate(),10)
            self.assertEqual(output.stat().st_mode&0o777,0o600)

    def test_real_qualified_models_only_when_private_artifacts_are_supplied(self):
        models = os.environ.get("SLS_REGIONAL_VOICE_FIXTURE", "")
        python = os.environ.get("SLS_PIPER_PYTHON", "")
        if not models or not python:
            self.skipTest(
                "Supply private pinned voice artifacts and Piper Python for offline synthesis qualification."
            )
        self.assertEqual(os.environ.get("SLS_TEST_NAMESPACE"), "entered")
        model_root = Path(models)
        worker = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_piper_worker.py"
        results = []
        for voice in json.loads(CATALOG.read_text())["voices"]:
            for name, meta in voice["files"].items():
                path = model_root / name
                self.assertEqual(path.stat().st_size, meta["size"])
                self.assertEqual(
                    hashlib.sha256(path.read_bytes()).hexdigest(), meta["sha256"]
                )
            with tempfile.TemporaryDirectory(
                prefix="sls-regional-speech-"
            ) as temporary:
                output = Path(temporary) / "speech.wav"
                descriptor = os.open(output, os.O_CREAT | os.O_EXCL | os.O_RDWR, 0o600)
                try:
                    phrase = (
                        "This is an offline speech preview."
                        if voice["locale"] == "en_GB"
                        else "Esta es una prueba de voz sin conexión."
                    )
                    subprocess.run(
                        [
                            python,
                            "-I",
                            str(worker),
                            "--model",
                            str(model_root / (voice["id"] + ".onnx")),
                            "--output-file",
                            "/proc/self/fd/" + str(descriptor),
                        ],
                        input=phrase + "\n",
                        text=True,
                        pass_fds=(descriptor,),
                        stdout=subprocess.DEVNULL,
                        stderr=subprocess.PIPE,
                        check=True,
                        timeout=40,
                    )
                finally:
                    os.close(descriptor)
                with wave.open(str(output)) as audio:
                    seconds = audio.getnframes() / audio.getframerate()
                    self.assertGreater(seconds, 0.2)
                    self.assertLess(seconds, 30)
                    self.assertEqual(audio.getnchannels(), 1)
                    self.assertEqual(audio.getsampwidth(), 2)
                    self.assertTrue(any(audio.readframes(audio.getnframes())))
                    results.append(
                        {
                            "voice": voice["id"],
                            "seconds": round(seconds, 3),
                            "sample_rate": audio.getframerate(),
                            "played": False,
                        }
                    )
        print(
            "SLS_REGIONAL_VOICE_RESULTS " + json.dumps(results, separators=(",", ":"))
        )


if __name__ == "__main__":
    unittest.main()
