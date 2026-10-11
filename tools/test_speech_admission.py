#!/usr/bin/python3
"""Private lock/quota/process tests; no installed Piper, PBX or notifications."""
import json
import fcntl
import os
from pathlib import Path
import subprocess
import signal
import time
import sys
import tempfile
import unittest
from contextlib import redirect_stderr
import io

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_speech_admission as speech


class SpeechTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-speech-admission-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'sounds/tts').mkdir(parents=True)
        self.web = self.root / 'web'; self.web.mkdir()
        self.config = {'speech_queue_wait_seconds': 0}

    def admission(self, config=None):
        value = speech.Admission(config or self.config, 30, self.root, self.web)
        self.addCleanup(value.close)
        return value

    def test_shared_limit_queue_and_immediate_reuse(self):
        first = self.admission().acquire()
        second = self.admission()
        with self.assertRaisesRegex(speech.SpeechAdmissionError, 'waited too long'): second.acquire()
        first.close()
        # Conversion reservations remain, but must not block the CPU slot.
        third = self.admission().acquire()
        self.assertIsNotNone(third.active)

    def test_configured_parallel_limit_and_bounded_queue(self):
        config = {'speech_queue_wait_seconds': 0, 'speech_synthesis_limit': 2, 'speech_queue_limit': 2}
        self.admission(config).acquire(); self.admission(config).acquire()
        third = self.admission(config)
        with self.assertRaisesRegex(speech.SpeechAdmissionError, 'waited too long'): third.acquire()
        third.close()
        waiting=[]
        for index in range(2):
            path=self.root/('speech-wait-%02d.lock' % index);file=path.open('a+');path.chmod(0o640)
            self.addCleanup(file.close);fcntl.flock(file,fcntl.LOCK_EX|fcntl.LOCK_NB);waiting.append(file)
        with self.assertRaisesRegex(speech.SpeechAdmissionError, 'queue is full'): self.admission(config).acquire()

    def test_current_media_and_reserved_work_share_the_budget(self):
        path = self.root / 'sounds/tts/announcement_tts_fixture.wav'
        with path.open('wb') as handle: handle.truncate(64 * speech.MIB)
        with self.assertRaisesRegex(speech.SpeechAdmissionError, 'cache budget'):
            self.admission({'speech_queue_wait_seconds': 0, 'generated_media_cache_mib': 64}).acquire()
        self.assertTrue(path.exists(), 'Quota enforcement deleted referenced/new media')

    def test_non_speech_reserves_without_using_synthesis_slot(self):
        first = self.admission().acquire()
        media = self.admission(); media.reserve_media(speech.MIB)
        self.assertIsNone(media.active)
        records = [row for file in self.root.glob('speech-slot-*.json') for row in json.loads(file.read_text() or '[]')]
        self.assertEqual(sum(row['bytes'] for row in records), first.bytes + speech.MIB)

    def test_unsafe_lock_and_invalid_config_rejected(self):
        os.symlink(self.root/'outside', self.root/'speech-admission.lock')
        with self.assertRaises(OSError): self.admission().acquire()
        for value in ('1', True, 0, 5):
            with self.assertRaises(speech.SpeechAdmissionError): speech.Admission({'speech_synthesis_limit': value}, 30, self.root, self.web)

    def test_child_timeout_stops_processing_and_returns_explicit_code(self):
        marker = self.root/'should-not-exist'
        command = [sys.executable, '-c', 'import time,pathlib;time.sleep(3);pathlib.Path('+repr(str(marker))+').write_text("bad")']
        self.assertEqual(speech.launch(command, .1), 124)
        self.assertFalse(marker.exists())

    def test_cli_cannot_execute_arbitrary_programs(self):
        result = subprocess.run([sys.executable, '-I', speech.__file__, '--max-seconds', '30', '--', '/bin/true'], capture_output=True)
        self.assertNotEqual(result.returncode, 0)

    def synthesis_command(self, duration=1, rate=16000, force_success=False, noisy=False):
        model = self.root/'voice.onnx'
        model.with_suffix('.onnx.json').write_text(json.dumps({'audio': {'sample_rate': rate}}))
        script = self.root/'producer.py'
        script.write_text('''import argparse, json, sys, wave
p=argparse.ArgumentParser();p.add_argument('--model');p.add_argument('--output-file');a=p.parse_args()
rate=json.load(open(a.model+'.json'))['audio']['sample_rate']
try:
 with wave.open(a.output_file,'wb') as output:
  output.setnchannels(1);output.setsampwidth(2);output.setframerate(rate)
  for _ in range(int(DURATION*10)):output.writeframes(b'\\0\\0'*int(rate/10))
except OSError:
 if not FORCE_SUCCESS:raise
if NOISY:sys.stderr.write('diagnostic\\n'*20000)
'''.replace('DURATION',repr(duration)).replace('FORCE_SUCCESS',repr(force_success)).replace('NOISY',repr(noisy)))
        return [sys.executable, str(script), '--model', str(model), '--output-file', str(self.root/'speech.wav')]

    def test_exact_duration_and_catalog_rates_fit_reserved_workspace(self):
        for rate in (16000,22050,48000):
            command=self.synthesis_command(rate=rate)
            self.assertEqual(speech.synthesize(command, 1, 3),0)
            path=self.root/'speech.wav'
            self.assertEqual(path.stat().st_size, rate*2+44)
            self.assertLess(path.stat().st_size, self.admission().bytes)
            path.unlink()

    def test_overlong_duration_below_byte_guard_is_rejected(self):
        diagnostic=io.StringIO()
        with redirect_stderr(diagnostic):
            self.assertEqual(speech.synthesize(self.synthesis_command(1.2),1,3),65)
        self.assertIn('configured maximum of 1 seconds',diagnostic.getvalue())
        self.assertFalse((self.root/'speech.wav').exists())

    def test_kernel_cap_rejects_partial_success_and_leaves_parent_writes_unlimited(self):
        command=self.synthesis_command(20,force_success=True,noisy=True)
        command,descriptor,output,bound=speech.speech_output(command,1)
        large_log=self.root/'existing.log'
        with large_log.open('wb') as log:log.truncate(2*speech.MIB)
        try:
            with large_log.open('a') as log, redirect_stderr(log):
                self.assertEqual(speech.launch(command,3,output_fd=descriptor,max_bytes=bound),65)
            self.assertEqual(os.fstat(descriptor).st_size,bound)
            self.assertEqual(large_log.stat().st_size,2*speech.MIB+speech.MAX_DIAGNOSTIC_BYTES)
            admission=self.admission().acquire();admission.close()
            self.assertTrue((self.root/'speech-slot-00.json').exists(),'Child file limit blocked parent admission state')
        finally:
            os.close(descriptor)

    def test_full_synthesis_discards_bound_reached_output_and_unsafe_targets(self):
        with redirect_stderr(io.StringIO()):
            self.assertEqual(speech.synthesize(self.synthesis_command(20,force_success=True),1,3),65)
        self.assertFalse((self.root/'speech.wav').exists())
        command=self.synthesis_command()
        path=self.root/'speech.wav';path.write_bytes(b'previous audio')
        with self.assertRaisesRegex(speech.SpeechAdmissionError,'empty private'):
            speech.speech_output(command,1)
        self.assertEqual(path.read_bytes(),b'previous audio')
        path.unlink();os.symlink(self.root/'outside',path)
        with self.assertRaises(OSError):speech.speech_output(command,1)
        path.unlink()
        metadata=self.root/'voice.onnx.json';metadata.write_text('{"audio":{"sample_rate":96000}}')
        with self.assertRaisesRegex(speech.SpeechAdmissionError,'unsupported sample rate'):
            speech.speech_output(command,1)
        for value in ('[]','{"audio":[]}','{broken'):
            metadata.write_text(value)
            with self.assertRaisesRegex(speech.SpeechAdmissionError,'voice metadata is invalid'):
                speech.speech_output(command,1)

    def test_killed_supervisor_cannot_leave_synthesis_running(self):
        child_pid = self.root/'child.pid'
        marker = self.root/'late-output'
        child_code = 'import os,time,pathlib;pathlib.Path('+repr(str(child_pid))+').write_text(str(os.getpid()));time.sleep(30);pathlib.Path('+repr(str(marker))+').touch()'
        supervisor_code = 'import sys;sys.path.insert(0,'+repr(str(Path(speech.__file__).parent))+');import sls_speech_admission as s;s.launch('+repr([sys.executable,'-c',child_code])+',60)'
        supervisor = subprocess.Popen([sys.executable,'-c',supervisor_code])
        try:
            deadline = time.monotonic()+3
            while not child_pid.exists() and time.monotonic()<deadline:time.sleep(.02)
            self.assertTrue(child_pid.exists())
            pid = int(child_pid.read_text())
            supervisor.kill(); supervisor.wait(timeout=2)
            deadline = time.monotonic()+3
            while time.monotonic()<deadline:
                try: state = Path('/proc/'+str(pid)+'/stat').read_text().split()[2]
                except FileNotFoundError: break
                if state == 'Z': break
                time.sleep(.02)
            else: self.fail('Speech child survived its killed supervisor')
            self.assertFalse(marker.exists())
        finally:
            if supervisor.poll() is None:supervisor.kill();supervisor.wait(timeout=2)


if __name__ == '__main__': unittest.main()
