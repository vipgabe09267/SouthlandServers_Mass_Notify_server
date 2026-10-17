#!/usr/bin/python3
"""Download commit/cleanup tests with a local inert curl replacement."""
import hashlib
import os
from pathlib import Path
import pwd
import re
import shutil
import subprocess
import tempfile
import unittest

if os.environ.get('SLS_TEST_NAMESPACE') != 'entered' or any(
    os.environ.get('SLS_TEST_PARENT_' + label + '_NS') in
    (None, '', os.readlink('/proc/self/ns/' + kind))
    for label, kind in [('MOUNT', 'mnt'), ('NET', 'net'), ('PID', 'pid')]
):
    raise SystemExit('Use the private fixture runner; no installed voices were changed.')

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT/'slsmassnotifyserver/bin/sls_mass_notify_install_piper_voices.sh').read_text()
PREFIX = SOURCE[:SOURCE.index('download_file() {')]
FUNCTION = re.search(r'^download_file\(\) \{.*?(?=^[a-z_]+\(\) \{|\Z)', SOURCE, re.M|re.S).group(0)
DIRECTORY_FUNCTION = re.search(r'^ensure_piper_install_directories\(\) \{.*?(?=^[a-z_]+\(\) \{|\Z)', SOURCE, re.M|re.S).group(0)
CURRENT_FUNCTION = re.search(r'^voice_file_is_current\(\) \{.*?(?=^[a-z_]+\(\) \{|\Z)', SOURCE, re.M|re.S).group(0)
COMPATIBILITY_FUNCTION = re.search(r'^install_piper_compatibility_path\(\) \{.*?(?=^[a-z_]+\(\) \{|\Z)', SOURCE, re.M|re.S).group(0)

PROMOTION = SOURCE.split('SLS_PIPER_VOICE_SOURCE="$tmp"',1)[1].split("<<'PY' || {\n",1)[1].split('\nPY\n',1)[0]
VOICE_REPAIR = SOURCE.split('secure_piper_voice_tree() {',1)[1].split("<<'PY'\n",1)[1].split('\nPY\n',1)[0]


class VoiceDownload(unittest.TestCase):
    def test_compatibility_scan_rejects_excess_entries_before_mutation(self):
        if os.environ.get('SLS_TEST_NAMESPACE') != 'entered': self.fail('Run through the isolation wrapper')
        result=subprocess.run(['/bin/bash','-c',DIRECTORY_FUNCTION+'\nensure_piper_install_directories'],capture_output=True,text=True,timeout=5)
        self.assertEqual(result.returncode,0,result.stderr)
        piper=Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper')
        if (piper/'venv').exists(): shutil.rmtree(piper/'venv')
        junk=[]
        for index in range(128):
            path=piper/('excess-%03d' % index);path.write_text('preserved');junk.append(path)
        script='set -euo pipefail\nPIPER_ARTIFACTS_CHANGED=0\n'+COMPATIBILITY_FUNCTION+'\ninstall_piper_compatibility_path\n'
        result=subprocess.run(['/bin/bash','-c',script],capture_output=True,text=True,timeout=5)
        self.assertNotEqual(result.returncode,0)
        self.assertFalse((piper/'venv').exists())
        self.assertTrue(all(path.read_text()=='preserved' for path in junk))
        for path in junk:path.unlink()

    def test_compatibility_ownership_repair_is_reported(self):
        if os.environ.get('SLS_TEST_NAMESPACE') != 'entered': self.fail('Run through the isolation wrapper')
        result=subprocess.run(['/bin/bash','-c',DIRECTORY_FUNCTION+'\nensure_piper_install_directories'],capture_output=True,text=True,timeout=5)
        self.assertEqual(result.returncode,0,result.stderr)
        piper=Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper')
        binary=piper/'venv/bin';binary.mkdir(parents=True,exist_ok=True)
        link=binary/'piper';link.unlink(missing_ok=True);link.symlink_to('/usr/local/bin/piper')
        account=pwd.getpwnam('asterisk');os.chown(link,account.pw_uid,account.pw_gid,follow_symlinks=False)
        script='set -euo pipefail\nPIPER_ARTIFACTS_CHANGED=0\n'+COMPATIBILITY_FUNCTION+'\ninstall_piper_compatibility_path\necho "$PIPER_ARTIFACTS_CHANGED"\n'
        result=subprocess.run(['/bin/bash','-c',script],capture_output=True,text=True,timeout=5)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(result.stdout.strip(),'1')
        self.assertEqual((link.lstat().st_uid,link.lstat().st_gid),(0,0))
        shutil.rmtree(piper/'venv')

    def test_existing_voice_validation_is_bounded_and_rejects_aliases(self):
        if os.environ.get('SLS_TEST_NAMESPACE') != 'entered': self.fail('Run through the isolation wrapper')
        voices=Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices')
        voices.mkdir(parents=True,exist_ok=True)
        target=voices/'en_US-lessac-low.onnx.json'
        body=b'v'*4882
        digest=hashlib.sha256(body).hexdigest()
        script=PREFIX+CURRENT_FUNCTION+'\nvoice_file_is_current "$1" "$2" 4882\n'
        target.unlink(missing_ok=True)
        result=subprocess.run(['/bin/bash','-c',script,'fixture',str(target),digest],capture_output=True,text=True,timeout=5)
        self.assertNotEqual(result.returncode,0)
        self.assertNotIn('Traceback',result.stderr)
        target.write_bytes(body);target.chmod(0o644)
        result=subprocess.run(['/bin/bash','-c',script,'fixture',str(target),digest],capture_output=True,text=True,timeout=5)
        self.assertEqual(result.returncode,0,result.stderr)
        victim=voices/'voice-victim';victim.write_bytes(body);victim.chmod(0o644)
        target.unlink();target.symlink_to(victim)
        result=subprocess.run(['/bin/bash','-c',script,'fixture',str(target),digest],capture_output=True,text=True,timeout=5)
        self.assertNotEqual(result.returncode,0)
        target.unlink();os.link(victim,target)
        result=subprocess.run(['/bin/bash','-c',script,'fixture',str(target),digest],capture_output=True,text=True,timeout=5)
        self.assertNotEqual(result.returncode,0)
        target.unlink();target.write_bytes(body+b'x');target.chmod(0o644)
        result=subprocess.run(['/bin/bash','-c',script,'fixture',str(target),digest],capture_output=True,text=True,timeout=5)
        self.assertNotEqual(result.returncode,0)
        target.unlink();victim.unlink()

    def test_install_directory_creation_is_anchored_and_rejects_a_symlinked_data_path(self):
        if os.environ.get('SLS_TEST_NAMESPACE') != 'entered': self.fail('Run through the isolation wrapper')
        result = subprocess.run(['/bin/bash', '-c', DIRECTORY_FUNCTION + '\nensure_piper_install_directories'],
                                capture_output=True, text=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr)
        runtime = Path('/usr/local/bin/sls_mass_notify/piper')
        data_piper = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper')
        self.assertTrue(runtime.is_dir() and not runtime.is_symlink())
        self.assertTrue((data_piper / 'voices').is_dir())
        held = data_piper.with_name('piper-held')
        data_piper.rename(held)
        victim = Path('/tmp/sls-piper-directory-victim')
        victim.mkdir()
        sentinel = victim / 'preserved'
        sentinel.write_text('preserved')
        data_piper.symlink_to(victim, target_is_directory=True)
        try:
            result = subprocess.run(['/bin/bash', '-c', DIRECTORY_FUNCTION + '\nensure_piper_install_directories'],
                                    capture_output=True, text=True, timeout=5)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(sentinel.read_text(), 'preserved')
            self.assertFalse((victim / 'voices').exists())
        finally:
            data_piper.unlink(missing_ok=True)
            held.rename(data_piper)

    def test_promotion_rechecks_the_actual_staging_bytes_and_preserves_prior_voice(self):
        if os.environ.get('SLS_TEST_NAMESPACE') != 'entered': self.fail('Run through the isolation wrapper')
        with tempfile.TemporaryDirectory(prefix='sls-voice-promotion-') as directory:
            root=Path(directory)
            source=root/'staged'
            body=b'fixture pinned model bytes'
            source.write_bytes(body);source.chmod(0o600)
            target=Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices/en_US-lessac-low.onnx')
            target.parent.mkdir(parents=True,exist_ok=True)
            target.write_bytes(b'prior voice')
            environment={**os.environ,'SLS_PIPER_VOICE_SOURCE':str(source),'SLS_PIPER_VOICE_TARGET':str(target),
                         'SLS_PIPER_VOICE_SHA256':hashlib.sha256(body).hexdigest(),'SLS_PIPER_VOICE_BYTES':str(len(body))}
            result=subprocess.run(['/usr/bin/python3','-I','-c',PROMOTION],env=environment,capture_output=True,text=True,timeout=5)
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual(target.read_bytes(),body)
            account=pwd.getpwnam('asterisk')
            self.assertEqual(target.stat().st_uid,account.pw_uid)
            self.assertEqual(target.stat().st_mode&0o777,0o644)
            # A download changing between verification and promotion must leave
            # the current voice intact; this test never invokes Piper or curl.
            source.write_bytes(b'x'*len(body))
            result=subprocess.run(['/usr/bin/python3','-I','-c',PROMOTION],env=environment,capture_output=True,text=True,timeout=5)
            self.assertNotEqual(result.returncode,0)
            self.assertEqual(target.read_bytes(),body)
            self.assertEqual(list(target.parent.glob('.voice.*')),[])

    def test_regular_voice_permission_repair_is_bounded_and_repeatable(self):
        if os.environ.get('SLS_TEST_NAMESPACE') != 'entered': self.fail('Run through the isolation wrapper')
        voices=Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices')
        voices.mkdir(parents=True,exist_ok=True)
        target=voices/'en_US-lessac-low.onnx'
        target.write_bytes(b'private fixture voice');target.chmod(0o600)
        before=target.read_bytes();inode=target.stat().st_ino
        environment={**os.environ,'PIPER_VOICE_PERMISSION_ROOT':str(voices)}
        for unused in range(2):
            result=subprocess.run(['/usr/bin/python3','-I','-c',VOICE_REPAIR],env=environment,capture_output=True,text=True,timeout=5)
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual(target.read_bytes(),before)
            self.assertEqual(target.stat().st_ino,inode)
            self.assertEqual(target.stat().st_mode&0o777,0o644)

    def test_offline_voice_cache_is_hash_checked_and_never_falls_back_to_network(self):
        if os.environ.get('SLS_TEST_NAMESPACE') != 'entered': self.fail('Run through the isolation wrapper')
        with tempfile.TemporaryDirectory() as name:
            root=Path(name);cache=root/'cache';cache.mkdir(mode=0o700)
            filename='en_US-lessac-low.onnx.json'
            body=b'{"fixture":"'+b'x'*(4882-14)+b'"}'
            source=cache/filename;source.write_bytes(body);source.chmod(0o600)
            voices=Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices');voices.mkdir(parents=True,exist_ok=True)
            target=voices/filename;target.write_bytes(b'preserved')
            script=PREFIX+'\nPIPER_VOICE_CACHE="$1"\ncurl() { printf network >"$3"; return 99; }\n'+FUNCTION+'\ndownload_file https://example.invalid "$2" "$4"\n'
            digest=hashlib.sha256(body).hexdigest()
            for mode,success in (('valid',True),('corrupt',False),('writable',False),('missing',False)):
                target.write_bytes(b'preserved')
                if mode=='corrupt':source.write_bytes(b'x'*4882)
                elif mode=='writable':source.chmod(0o666)
                elif mode=='missing':source.unlink()
                result=subprocess.run(['bash','-c',script,'fixture',str(cache),str(target),str(root/'network'),digest],env={**os.environ,'TMPDIR':str(root)},capture_output=True,text=True,timeout=10)
                self.assertEqual(result.returncode==0,success,result.stderr)
                self.assertFalse((root/'network').exists())
                self.assertEqual(target.read_bytes(),body if success else b'preserved')

    def test_verified_commit_size_failure_and_interruption_leave_no_temporary_file(self):
        if os.environ.get('SLS_TEST_NAMESPACE') != 'entered': self.fail('Run through the isolation wrapper')
        with tempfile.TemporaryDirectory() as name:
            root = Path(name); tools = root/'bin'; tools.mkdir()
            filename = 'en_US-lessac-low.onnx.json'
            body = b'{"fixture":"' + b'x'*(4882-14) + b'"}'
            self.assertEqual(len(body),4882)
            source = root/'body'; source.write_bytes(body)
            curl = tools/'curl'
            curl.write_text('''#!/usr/bin/python3
import os, pathlib, signal, sys
args=sys.argv[1:]
assert args[args.index('--max-filesize')+1]=='4882'
assert args[args.index('--proto')+1]=='=https' and args[args.index('--proto-redir')+1]=='=https'
data=pathlib.Path(os.environ['FIXTURE_BODY']).read_bytes()
if os.environ['FIXTURE_CASE']=='oversize': data+=b'x'
assert args[args.index('-o')+1]=='-'
if os.environ['FIXTURE_CASE']=='flood':
 try:
  for _ in range(10000): sys.stdout.buffer.write(data);sys.stdout.buffer.flush()
 except BrokenPipeError: os._exit(23)
else: sys.stdout.buffer.write(data);sys.stdout.buffer.flush()
if os.environ['FIXTURE_CASE'].startswith('interrupt_'):
 signum=getattr(signal,'SIG'+os.environ['FIXTURE_CASE'].split('_',1)[1])
 # Interrupt the owning shell, not curl's timeout parent. Terminate this
 # foreground child by the same signal so Bash also observes an INT outcome.
 os.kill(int(os.environ['FIXTURE_OWNER_PID']),signum)
 signal.signal(signum,signal.SIG_DFL);os.kill(os.getpid(),signum)
'''); curl.chmod(0o755)
            target = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices')/filename
            target.parent.mkdir(parents=True,exist_ok=True)
            previous = target.read_bytes() if target.exists() else None
            self.addCleanup(target.write_bytes,previous) if previous is not None else self.addCleanup(target.unlink, missing_ok=True)
            target.write_bytes(b'preserved voice')
            for case, success in [('oversize',False),('flood',False),('interrupt_TERM',False),('interrupt_INT',False),('interrupt_HUP',False),('valid',True)]:
                fixture_function=FUNCTION.replace('/usr/bin/curl',str(curl))
                script = PREFIX+fixture_function+'\nLOG_FILE="$TMPDIR/log"\nexport FIXTURE_OWNER_PID="$BASHPID"\ndownload_file "$1" "$2" "$3"\n'
                result = subprocess.run(['bash','-c',script,'fixture','https://fixture.invalid/voice',str(target),hashlib.sha256(body).hexdigest()],
                    env={**os.environ,'FIXTURE_TOOLS':str(tools),'TMPDIR':name,'FIXTURE_BODY':str(source),'FIXTURE_CASE':case},
                    capture_output=True,text=True,timeout=10)
                self.assertEqual(result.returncode==0,success,case+': '+result.stderr)
                if case.startswith('interrupt_'):
                    self.assertEqual(result.returncode,{'TERM':143,'INT':130,'HUP':129}[case.split('_',1)[1]],case+': wrong signal outcome')
                self.assertEqual(target.read_bytes(), body if success else b'preserved voice')
                self.assertEqual(list(root.glob('sls-piper-voice.*')),[])

    def test_stream_writer_enforces_bytes_before_any_checksum_or_commit(self):
        with tempfile.TemporaryDirectory() as name:
            root=Path(name);target=root/'download';target.touch(mode=0o600)
            script=PREFIX+'\nbounded_voice_stream "$1" 4882\n'
            result=subprocess.run(['bash','-c',script,'fixture',str(target)],input=b'x'*65536,capture_output=True,timeout=10)
            self.assertNotEqual(result.returncode,0)
            self.assertLessEqual(target.stat().st_size,4882)
            self.assertIn(b'exceeds its byte limit',result.stderr)
            sentinel=root/'sentinel';sentinel.write_bytes(b'preserved');target.unlink();target.symlink_to(sentinel)
            result=subprocess.run(['bash','-c',script,'fixture',str(target)],input=b'x',capture_output=True,timeout=10)
            self.assertNotEqual(result.returncode,0)
            self.assertEqual(sentinel.read_bytes(),b'preserved')

    def test_private_file_validation_and_fresh_retry(self):
        if os.environ.get('SLS_TEST_NAMESPACE') != 'entered': self.fail('Run through the isolation wrapper')
        helper = '/usr/local/bin/sls_mass_notify/sls_bounded_stream.py'
        def write(target, body=b'replacement'):
            return subprocess.run(['/usr/bin/python3','-I',helper,str(target),'16'],
                input=body,capture_output=True,timeout=10)
        with tempfile.TemporaryDirectory() as name:
            root=Path(name);target=root/'download';target.touch(mode=0o600)
            target.write_bytes(b'preserved')
            linked=root/'linked';os.link(target,linked)
            self.assertNotEqual(write(target).returncode,0)
            self.assertEqual(linked.read_bytes(),b'preserved');linked.unlink()
            target.chmod(0o644)
            self.assertNotEqual(write(target).returncode,0)
            self.assertEqual(target.read_bytes(),b'preserved');target.chmod(0o600)
            os.chown(target,pwd.getpwnam('asterisk').pw_uid,-1)
            self.assertNotEqual(write(target).returncode,0)
            self.assertEqual(target.read_bytes(),b'preserved');os.chown(target,0,-1)
            unsafe=root/'unsafe';unsafe.mkdir();unsafe.chmod(0o777)
            self.assertNotEqual(write(unsafe/'download').returncode,0)
            self.assertFalse((unsafe/'download').exists())
            alias=root/'alias';alias.symlink_to(root,target_is_directory=True)
            self.assertNotEqual(write(alias/'download').returncode,0)
            self.assertEqual(target.read_bytes(),b'preserved')
            fifo=root/'fifo';os.mkfifo(fifo,0o600)
            self.assertNotEqual(write(fifo).returncode,0)
            self.assertNotEqual(write(target,b'x'*65536).returncode,0)
            self.assertLessEqual(target.stat().st_size,16)
            self.assertEqual(write(target,b'complete').returncode,0)
            self.assertEqual(target.read_bytes(),b'complete')
            self.assertEqual(write(target,b'x'*16).returncode,0)
            self.assertEqual(target.read_bytes(),b'x'*16)


if __name__ == '__main__': unittest.main()
