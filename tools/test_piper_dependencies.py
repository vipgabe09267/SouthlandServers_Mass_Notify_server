#!/usr/bin/env python3
"""Exact dependency reconciliation using metadata and installer command fixtures."""
import importlib.metadata
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'
HELPER = RUNTIME / 'sls_piper_dependencies.py'
REQUIREMENTS = RUNTIME / 'piper-requirements.txt'
SPEC = importlib.util.spec_from_file_location('piper_dependency_fixture', HELPER)
DEPENDENCIES = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DEPENDENCIES)
PINS = DEPENDENCIES.load_pins(REQUIREMENTS)
VOICE_INSTALLER = (ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_install_piper_voices.sh').read_text()
ENSURE_RUNTIME = 'reset_managed_piper_venv() {' + VOICE_INSTALLER.split('reset_managed_piper_venv() {', 1)[1].split('\ninstall_piper_wrapper() {', 1)[0]
RESET_RUNTIME = ENSURE_RUNTIME.split('\npiper_venv_usable() {', 1)[0]
VALIDATE_RUNTIME = 'validate_piper_runtime_tree() {' + VOICE_INSTALLER.split('validate_piper_runtime_tree() {', 1)[1].split('\nsecure_piper_runtime_tree() {', 1)[0]
ENSURE_RUNTIME = VALIDATE_RUNTIME + '\n' + ENSURE_RUNTIME


class PiperDependencyTests(unittest.TestCase):
    def test_exact_versions_and_equivalent_release_spelling(self):
        installed = dict(PINS)
        installed['pip'] = PINS['pip'] + '.0'
        self.assertEqual(DEPENDENCIES.mismatches(PINS, installed.__getitem__), [])

    def test_healthy_older_and_newer_packages_both_require_reconciliation(self):
        for name in PINS:
            for wrong in ('0.0.1', '999.0.0'):
                with self.subTest(name=name, wrong=wrong):
                    installed = dict(PINS)
                    installed[name] = wrong
                    self.assertEqual(DEPENDENCIES.mismatches(PINS, installed.__getitem__),
                                     [{'package': name, 'required': PINS[name], 'installed': wrong}])

    def test_missing_package_is_reported(self):
        def installed(name):
            if name == 'piper-tts':
                raise importlib.metadata.PackageNotFoundError(name)
            return PINS[name]
        self.assertEqual(DEPENDENCIES.mismatches(PINS, installed)[0]['installed'], None)

    def test_unpinned_and_duplicate_requirements_are_rejected(self):
        with tempfile.TemporaryDirectory(prefix='sls-piper-lock-') as name:
            path = Path(name) / 'requirements.txt'
            for suffix in ('\nnumpy>=1\n', '\n-e https://example.test/package\n', '\nPIPER_tts==1.4.2\n'):
                path.write_text(REQUIREMENTS.read_text() + suffix)
                with self.assertRaises(ValueError):
                    DEPENDENCIES.load_pins(path)

    def test_packaging_bootstrap_uses_same_manifest(self):
        result = subprocess.run(['python3', str(HELPER), '--requirements', str(REQUIREMENTS), '--packaging-pins'],
                                capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.splitlines(), [name + '==' + PINS[name] for name in ('pip', 'setuptools', 'wheel')])

    def runtime_fixture(self, mode):
        with tempfile.TemporaryDirectory(prefix='sls-piper-runtime-', dir='/root' if os.geteuid() == 0 else None) as name:
            fixture = Path(name)
            binary = fixture / 'venv/bin'
            binary.mkdir(parents=True)
            for filename in ('piper', 'pip'):
                path = binary / filename
                path.write_text('#!/bin/bash\nexit 0\n')
                path.chmod(0o755)
            python = binary / 'python'
            python.write_text(r'''#!/bin/bash
set -euo pipefail
[[ "$1" == '-I' ]] || exit 98
shift
runtime="$(dirname "$0")/.."
if [[ "$MODE" == broken-python && -e "$runtime/old-runtime-marker" ]]; then exit 126; fi
if [[ "$1" == '-c' ]]; then
  [[ "$MODE" != invalid-prefix || ! -e "$runtime/old-runtime-marker" ]]
  exit $?
fi
if [[ "$*" == '-m pip --version' ]]; then
  [[ "$MODE" != broken-pip || ! -e "$runtime/old-runtime-marker" ]]
  exit $?
fi
if [[ "$*" == '-m piper -h' || "$*" == '-m pip check' ]]; then exit 0; fi
if [[ "$1" == "$CHECKER" ]]; then
  [[ "$MODE" == exact || -e "$runtime/reconciled" ]] && [[ "$MODE" != postcheck-failure ]]
  exit $?
fi
if [[ "$1 $2 $3 $4" == '-m pip --isolated install' ]]; then
  printf '%s\n' "$*" >>"$FIXTURE/installs"
  [[ "$MODE" != install-failure ]] || exit 22
  if [[ " $* " == *" -r "* ]]; then touch "$runtime/reconciled"; fi
  exit 0
fi
printf 'Unexpected fixture interpreter invocation\n' >&2
exit 99
''')
            python.chmod(0o755)
            (fixture / 'interpreter-template').write_bytes(python.read_bytes())
            if mode == 'missing-python':
                python.unlink()
            (fixture / 'venv/old-runtime-marker').write_text('broken runtime')
            (fixture / 'voices').mkdir()
            (fixture / 'voices/model.onnx').write_text('preserve voice model')
            (fixture / 'mass-notifications.config').write_text('preserve protected config')
            # Exercise the real exchange/rollback helper with a private package
            # builder. Full namespace/wheel qualification is tested separately.
            replacement = fixture / 'replacement.py'
            replacement.write_text('''import importlib.util, os, shutil, subprocess, sys
from pathlib import Path
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('replacement', %r)
helper = importlib.util.module_from_spec(spec); spec.loader.exec_module(helper)
root = Path(os.environ['FIXTURE'])
def builder(stage, lock):
    binary = stage / 'venv/bin'; binary.mkdir()
    shutil.copyfile(root / 'interpreter-template', binary / 'python')
    (binary / 'python').chmod(0o755)
    (binary / 'piper').write_text('#!/bin/bash\\nexit 0\\n'); (binary / 'piper').chmod(0o755)
    for filename in ('piper-packaging.lock', 'piper-requirements.lock'):
        helper.checked([str(binary / 'python'), '-I', '-m', 'pip', '--isolated', 'install',
                        '--no-cache-dir', '--only-binary=:all:', '--require-hashes',
                        '-r', str(Path(%r) / filename)])
    helper.checked([str(binary / 'python'), '-I', os.environ['CHECKER']])
def validator(root):
    helper.checked([str(root / 'venv/bin/python'), '-I', os.environ['CHECKER']])
helper.replace_environment(root, builder, validator, workers_paused=True)
''' % (str(RUNTIME / 'sls_piper_environment.py'), str(RUNTIME)))
            script = ENSURE_RUNTIME.replace('"/usr/local/bin/sls_mass_notify/piper"', repr(str(fixture)))
            script = script.replace('/usr/local/bin/sls_mass_notify/sls_piper_environment.py', str(replacement)) + r'''
PIPER_DIR="$1"
PIPER_BIN="$1/venv/bin/piper"
PIPER_PY="$1/venv/bin/python"
PIPER_DEPENDENCY_CHECK="$2"
PIPER_REQUIREMENTS="$3"
PIPER_ARTIFACTS_CHANGED=0
LOG_FILE="$1/log"
log() { printf '%s\n' "$*" >>"$LOG_FILE"; }
ensure_piper_runtime
'''
            result = subprocess.run(['bash', '-c', script, '_', str(fixture), str(HELPER), str(REQUIREMENTS)],
                                    env={**os.environ, 'FIXTURE': str(fixture), 'MODE': mode, 'CHECKER': str(HELPER)},
                                    capture_output=True, text=True, timeout=15)
            installs = (fixture / 'installs').read_text().splitlines() if (fixture / 'installs').exists() else []
            self.assertEqual((fixture / 'voices/model.onnx').read_text(), 'preserve voice model')
            self.assertEqual((fixture / 'mass-notifications.config').read_text(), 'preserve protected config')
            rebuilt = not (fixture / 'venv/old-runtime-marker').exists()
            if result.returncode:
                self.assertEqual((fixture / 'venv/old-runtime-marker').read_text(), 'broken runtime')
            return result, installs, rebuilt

    @unittest.skipUnless(os.geteuid() == 0,'Root ownership boundary requires root fixture')
    def test_untrusted_venv_descendants_are_rejected_before_interpreter_execution(self):
        import pwd
        account = pwd.getpwnam('asterisk')
        for kind in ('foreign-owner','writable','hardlink','fifo','external-link'):
            with self.subTest(kind=kind), tempfile.TemporaryDirectory(prefix='sls-piper-admission-',dir='/root') as directory:
                base=Path(directory); (base/'venv/bin').mkdir(parents=True)
                interpreter=base/'venv/bin/python'
                interpreter.write_text('#!/bin/bash\ntouch "'+str(base/'EXECUTED')+'"\nexit 0\n'); interpreter.chmod(0o755)
                entry=base/'venv/unsafe.py'
                entry.write_text('never execute'); entry.chmod(0o644)
                victim=base/'outside'; victim.write_text('preserved'); victim.chmod(0o600)
                if kind=='foreign-owner': os.chown(entry,account.pw_uid,account.pw_gid)
                elif kind=='writable': entry.chmod(0o666)
                elif kind=='hardlink': entry.unlink(); os.link(victim,entry)
                elif kind=='fifo': entry.unlink(); os.mkfifo(entry)
                else: interpreter.unlink(); interpreter.symlink_to(victim)
                script=ENSURE_RUNTIME+'\nPIPER_DIR="$1"; PIPER_PY="$1/venv/bin/python"; PIPER_BIN="$1/venv/bin/piper"; log() { :; }; ensure_piper_runtime'
                result=subprocess.run(['/bin/bash','-c',script,'_',str(base)],capture_output=True,text=True,timeout=5)
                self.assertNotEqual(result.returncode,0,result.stderr)
                self.assertFalse((base/'EXECUTED').exists())
                self.assertEqual(victim.read_text(),'preserved')
                self.assertEqual(victim.stat().st_mode & 0o777,0o600)
    @unittest.skipUnless(os.geteuid() == 0,'Root ownership boundary requires root fixture')
    def test_permission_repair_never_adopts_hardlinked_executable(self):
        import pwd
        account=pwd.getpwnam('asterisk')
        secure='secure_piper_runtime_tree() {'+VOICE_INSTALLER.split('secure_piper_runtime_tree() {',1)[1].split('\nsecure_piper_voice_tree() {',1)[0]
        with tempfile.TemporaryDirectory(prefix='sls-piper-metadata-',dir='/root') as directory:
            base=Path(directory); runtime=base/'runtime'; (runtime/'venv').mkdir(parents=True)
            victim=base/'victim'; victim.write_text('preserved'); victim.chmod(0o600); os.chown(victim,account.pw_uid,account.pw_gid)
            os.link(victim,runtime/'venv/payload.py')
            script=VALIDATE_RUNTIME+'\n'+secure+'\nPIPER_DIR="$1"; secure_piper_runtime_tree'
            result=subprocess.run(['/bin/bash','-c',script,'_',str(runtime)],capture_output=True,text=True,timeout=5)
            self.assertNotEqual(result.returncode,0,result.stderr)
            self.assertEqual((victim.stat().st_uid,victim.stat().st_mode & 0o777),(account.pw_uid,0o600))
    def test_healthy_exact_environment_does_not_install(self):
        result, installs, rebuilt = self.runtime_fixture('exact')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(installs, [])
        self.assertFalse(rebuilt)

    def test_healthy_but_stale_environment_cannot_short_circuit(self):
        result, installs, rebuilt = self.runtime_fixture('stale')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(installs), 2)
        self.assertTrue(rebuilt)
        self.assertIn('piper-packaging.lock', installs[0])
        self.assertIn('pip==' + PINS['pip'], (RUNTIME / 'piper-packaging.lock').read_text())
        self.assertIn(' -r ', installs[1])
        for command in installs:
            self.assertIn('--no-cache-dir --only-binary=:all:', command)
            self.assertIn('--require-hashes', command)
            self.assertIn('--isolated', command)

    def test_install_and_post_install_validation_failures_propagate(self):
        for mode in ('install-failure', 'postcheck-failure'):
            with self.subTest(mode=mode):
                result, installs, rebuilt = self.runtime_fixture(mode)
                self.assertNotEqual(result.returncode, 0)
                self.assertTrue(installs)
                self.assertFalse(rebuilt)

    @unittest.skipUnless(os.geteuid() == 0, 'Managed runtime repair requires root ownership')
    def test_executable_but_broken_interpreter_or_pip_is_rebuilt(self):
        for mode in ('missing-python', 'broken-python', 'broken-pip', 'invalid-prefix'):
            with self.subTest(mode=mode):
                result, installs, rebuilt = self.runtime_fixture(mode)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertTrue(rebuilt)
                self.assertEqual(len(installs), 2)

    @unittest.skipUnless(os.geteuid() == 0, 'Managed runtime repair requires root ownership')
    def test_reset_rejects_unsafe_paths_before_removing_files(self):
        for mode in ('wrong-path', 'parent-link', 'venv-link', 'hard-link', 'writable', 'foreign-owner', 'fifo'):
            with self.subTest(mode=mode), tempfile.TemporaryDirectory(prefix='sls-piper-reset-', dir='/root') as name:
                fixture = Path(name)
                root = fixture / 'runtime'
                venv = root / 'venv'
                venv.mkdir(parents=True)
                sentinel = fixture / 'sentinel'
                sentinel.write_text('preserve')
                marker = venv / 'old-runtime-marker'
                marker.write_text('preserve old tree on refusal')
                target = root
                if mode == 'parent-link':
                    (fixture / 'alias').symlink_to(root, target_is_directory=True)
                    target = fixture / 'alias'
                elif mode == 'venv-link':
                    venv.rename(fixture / 'outside-venv')
                    venv.symlink_to(fixture / 'outside-venv', target_is_directory=True)
                elif mode == 'hard-link':
                    os.link(sentinel, venv / 'linked-file')
                elif mode == 'writable':
                    marker.chmod(0o666)
                elif mode == 'foreign-owner':
                    os.chown(marker, 65534, 65534)
                elif mode == 'fifo':
                    os.mkfifo(venv / 'pipe')
                source = RESET_RUNTIME
                if mode != 'wrong-path':
                    source = source.replace('"/usr/local/bin/sls_mass_notify/piper"', repr(str(target)))
                result = subprocess.run(['bash', '-c', source + '\nPIPER_DIR="$1"\nreset_managed_piper_venv', '_', str(target)],
                                        capture_output=True, text=True, timeout=15)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(sentinel.read_text(), 'preserve')
                self.assertEqual(marker.read_text(), 'preserve old tree on refusal')

    @unittest.skipUnless(os.geteuid() == 0, 'Managed runtime repair requires root ownership')
    def test_reset_unlinks_standard_venv_links_without_following_them(self):
        with tempfile.TemporaryDirectory(prefix='sls-piper-reset-', dir='/root') as name:
            fixture = Path(name)
            venv = fixture / 'venv'
            (venv / 'bin').mkdir(parents=True)
            (venv / 'lib').mkdir()
            sentinel = fixture / 'sentinel'
            sentinel.write_text('preserve')
            (venv / 'lib64').symlink_to('lib', target_is_directory=True)
            (venv / 'bin/python').symlink_to('/usr/bin/python3')
            (venv / 'outside-link').symlink_to(sentinel)
            source = RESET_RUNTIME.replace('"/usr/local/bin/sls_mass_notify/piper"', repr(str(fixture)))
            result = subprocess.run(['bash', '-c', source + '\nPIPER_DIR="$1"\nreset_managed_piper_venv', '_', str(fixture)],
                                    capture_output=True, text=True, timeout=15)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertFalse(venv.exists())
            self.assertEqual(sentinel.read_text(), 'preserve')


if __name__ == '__main__':
    unittest.main()
