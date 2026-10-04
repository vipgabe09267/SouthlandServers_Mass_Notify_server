#!/usr/bin/env python3
"""Measure generated fixture WAVs; never invoke PBX or notification channels."""
import base64
import importlib.util
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile
from unittest import mock
import wave

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / "slsmassnotifyserver/bin/sls_mass_notify"
sys.path.insert(0, str(RUNTIME))
spec = importlib.util.spec_from_file_location("lightning_speech_fixture", RUNTIME / "sls_mass_notify_xweather_poll.py")
lightning = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lightning)


def function(source, name):
    return name + "() {" + source.split(name + "() {", 1)[1].split("\n}", 1)[0] + "\n}\n"


with tempfile.TemporaryDirectory(prefix="sls-weather-speech-") as temporary:
    directory = Path(temporary)
    piper = directory / "piper"
    piper.write_text('''#!/usr/bin/python3
import os, sys, wave
from pathlib import Path
text = sys.stdin.read()
Path(os.environ["SLS_SPEECH_CAPTURE"]).write_text(text)
path = sys.argv[sys.argv.index("--output-file") + 1]
with wave.open(path, "wb") as output:
    output.setnchannels(1); output.setsampwidth(2); output.setframerate(8000)
    output.writeframes(b"\\0\\0" * int(float(os.environ["SLS_FIXTURE_DURATION"]) * 8000))
''')
    piper.chmod(0o755)
    voice = directory / "voice.onnx"
    voice.touch()
    capture = directory / "speech.txt"
    feature = {"id": "fixture", "properties": {"event": "Special Weather Statement",
        "areaDesc": "Area One; Area Two; Area Three",
        "instruction": " ".join(["Keep all instructions."] * 45) + " FINAL INSTRUCTION MUST REMAIN."}}
    payload = base64.b64encode(json.dumps(feature).encode()).decode()
    expected = "Weather alert. Special Weather Statement for Area One; Area Two; Area Three. " + feature["properties"]["instruction"]
    environment = {**os.environ, "SLS_SPEECH_CAPTURE": str(capture), "SLS_FIXTURE_DURATION": "2"}

    for filename, entry in (("sls_mass_notify_nws_poll.sh", "generate_tts_audio"),
                            ("sls_mass_notify_test.sh", "generate_test_tts_audio")):
        source = (RUNTIME.parent / filename).read_text()
        definitions = function(source, entry)
        if entry == "generate_tts_audio":
            definitions = function(source, "build_tts_text") + definitions
        # Permission changes are irrelevant to this media-only fixture.
        definitions += "chown() { :; }\n"
        variables = {"PIPER_BIN": piper, "PIPER_NWS_VOICE": voice,
            "SLS_TTS_DIR": directory / filename, "LOG": directory / "fixture.log",
            "PIPER_NWS_VOLUME": "1.0"}
        definitions += "\n".join(key + "=" + shlex.quote(str(value)) for key, value in variables.items()) + "\n"
        for maximum, expected_code in ((1, 1), (2, 0)):
            arguments = " " + shlex.quote(payload) + " 'Special Weather Statement' fixture" if entry == "generate_tts_audio" else ""
            result = subprocess.run(["bash", "-c", definitions + f"PIPER_MAX_SECONDS={maximum}\n" + entry + arguments],
                env=environment, capture_output=True, text=True, timeout=15)
            assert result.returncode == expected_code, result.stderr
            if maximum == 1:
                assert "2.000000 seconds" in result.stderr and "maximum is 1 seconds" in result.stderr
                assert result.stdout.startswith("SLS_TTS_ERROR:")
                assert not list(Path(variables["SLS_TTS_DIR"]).glob("*.wav"))
            else:
                output = Path(variables["SLS_TTS_DIR"]) / (result.stdout.strip() + ".wav")
                with wave.open(str(output)) as generated:
                    assert generated.getnframes() / generated.getframerate() == 2
            if entry == "generate_tts_audio":
                assert capture.read_text().strip() == expected

    with mock.patch.object(lightning, "PIPER_BIN", piper), \
         mock.patch.object(lightning, "TTS_DIR", directory / "lightning"), \
         mock.patch.object(lightning, "safe_tone", return_value=None), \
         mock.patch.dict(os.environ, environment):
        config = {"nws_piper_voice": str(voice), "tts_max_seconds": 1}
        try:
            lightning.generate_audio(config, {}, expected, "overlimit")
        except RuntimeError as error:
            assert "2.00 seconds" in str(error) and "maximum is 1 seconds" in str(error)
        else:
            raise AssertionError("Over-limit Lightning speech was accepted")
        assert not list((directory / "lightning").glob("*.wav"))
        config["tts_max_seconds"] = 2
        lightning.generate_audio(config, {}, expected, "boundary")
        with wave.open(str(directory / "lightning/xweather_tts_boundary.wav")) as generated:
            assert generated.getnframes() / generated.getframerate() == 2
        assert capture.read_text().strip() == expected

print("Weather/Lightning complete speech, measured rejection, and duration boundary checks passed.")
