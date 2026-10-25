#!/usr/bin/python3
"""Optional real offline package reconciliation and CPU synthesis in a disposable venv."""
import array
import hashlib
import json
import math
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import wave

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT/'slsmassnotifyserver/bin/sls_mass_notify'


class PinnedRuntime(unittest.TestCase):
    def test_verified_offline_install_and_native_worker(self):
        wheelhouse=os.environ.get('SLS_PINNED_WHEEL_FIXTURE','')
        model=os.environ.get('SLS_PINNED_VOICE_FIXTURE','')
        if not wheelhouse or not model:
            self.skipTest('Supply the private reviewed wheels and voice for offline runtime qualification.')
        if os.environ.get('SLS_TEST_NAMESPACE')!='entered' or os.environ.get('SLS_TEST_PARENT_NET_NS') in (None,'',os.readlink('/proc/self/ns/net')):
            self.fail('Use the isolated fixture runner; no package installation was started.')
        catalog=json.loads((RUNTIME/'piper-artifacts.json').read_text())
        known={row['filename']:row['sha256'] for package in catalog['packages'].values() for row in package['wheels']}
        wheels=list(Path(wheelhouse).glob('*.whl'))
        self.assertEqual(len(wheels),10)
        for wheel in wheels:
            self.assertEqual(hashlib.sha256(wheel.read_bytes()).hexdigest(),known.get(wheel.name))
        with tempfile.TemporaryDirectory(prefix='sls-pinned-runtime-') as temporary:
            folder=Path(temporary);python=folder/'venv/bin/python'
            environment={'PATH':'/usr/bin:/bin','LANG':'C.UTF-8'}
            def run(args,**options):
                result=subprocess.run(args,capture_output=True,text=True,env=environment,timeout=180,**options)
                self.assertEqual(result.returncode,0,result.stdout[-2000:]+result.stderr[-2000:])
            run(['/usr/bin/python3','-I','-m','venv',str(folder/'venv')])
            for lock in ['piper-packaging.lock','piper-requirements.lock']:
                run([str(python),'-I','-m','pip','--isolated','--disable-pip-version-check','install','--no-index',
                    '--no-cache-dir','--require-hashes','--only-binary=:all:','--find-links',wheelhouse,'-r',str(RUNTIME/lock)])
            run([str(python),'-I','-m','pip','check'])
            run([str(python),'-I',str(RUNTIME/'sls_piper_dependencies.py'),'--quiet'])
            descriptor=os.open(folder/'speech.wav',os.O_CREAT|os.O_EXCL|os.O_RDWR,0o600)
            try:
                run([str(python),'-I',str(RUNTIME/'sls_piper_worker.py'),'--model',model,'--output-file',f'/proc/self/fd/{descriptor}'],
                    input='This is an offline notification test. Please enter your paging PIN.\n',pass_fds=(descriptor,))
            finally:
                os.close(descriptor)
            with wave.open(str(folder/'speech.wav'),'rb') as audio:
                self.assertEqual((audio.getnchannels(),audio.getsampwidth()),(1,2))
                frames=audio.getnframes();rate=audio.getframerate();samples=array.array('h',audio.readframes(frames))
            self.assertIn(rate,(16000,22050))
            self.assertTrue(0.5<frames/rate<30)
            self.assertGreater(math.sqrt(sum(value*value for value in samples)/max(1,len(samples))),25)
            self.assertLess(sum(abs(value)>=32760 for value in samples)/max(1,len(samples)),0.05)
            files=[path for path in (folder/'venv').rglob('*') if path.is_file() or path.is_symlink()]
            print('SLS_PINNED_RUNTIME '+json.dumps({'allocated_bytes':sum(path.lstat().st_blocks*512 for path in files),
                'files':len(files),'wheel_bytes':sum(path.stat().st_size for path in wheels),'speech_seconds':round(frames/rate,3)}),flush=True)


if __name__=='__main__':unittest.main()
