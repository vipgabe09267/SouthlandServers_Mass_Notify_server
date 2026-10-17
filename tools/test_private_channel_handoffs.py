#!/usr/bin/env python3
"""Exercise private PHP handoffs with an inert FreePBX bootstrap."""
import json
import os
from pathlib import Path
import pwd
import shutil
import subprocess
import tempfile
import unittest

if os.environ.get('SLS_TEST_NAMESPACE') != 'entered' or any(
    os.environ.get('SLS_TEST_PARENT_' + label + '_NS') in (None, '', os.readlink('/proc/self/ns/' + kind))
    for label, kind in [('MOUNT', 'mnt'), ('NET', 'net'), ('PID', 'pid')]
):
    raise SystemExit('Use the private fixture runner; PHP handoff isolation verification failed.')

ROOT = Path(__file__).resolve().parents[1]
BIN = ROOT / 'slsmassnotifyserver/bin'
DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')


class PrivateHandoffs(unittest.TestCase):
    def setUp(self):
        if os.geteuid() != 0:
            self.fail('Run through tools/run_isolated_tests.sh; no live bootstrap is allowed.')
        self.account = pwd.getpwnam('asterisk')
        self.scratch = Path(tempfile.mkdtemp(prefix='sls-private-channel-'))
        self.scratch.chmod(0o755)
        self.addCleanup(shutil.rmtree, self.scratch)
        self.original_data = DATA.stat()
        self.addCleanup(os.chmod, DATA, self.original_data.st_mode & 0o7777)
        self.addCleanup(os.chown, DATA, self.original_data.st_uid, self.original_data.st_gid)
        os.chown(DATA, self.account.pw_uid, self.account.pw_gid)
        DATA.chmod(0o750)
        self.bootstrap = self.scratch / 'inert-bootstrap.php'
        self.bootstrap.write_text('''<?php
class FreePBX {
    public static function Slsmassnotifyserver() { return new InertModule; }
    public static function Create() { return (object)['Slsmassnotifyserver'=>new InertModule]; }
}
class InertModule {
    public function queueWeatherChannels($input) { return ['success'=>true,'fixture_input'=>$input]; }
    public function receiveIncidentVoiceResponse($input) { return ['state'=>!empty($input['fixture_bad_state'])?'fixture_received':'recorded']; }
}
''')
        self.bootstrap.chmod(0o644)
        self.paths = []
        for name, source in (
            ('weather-source', BIN / 'sls_mass_notify_weather_channels.php'),
            ('weather-deployed', BIN / 'sls_mass_notify_weather_channels.php'),
            ('incident', BIN / 'sls_mass_notify/sls_mass_notify_incident_response.php'),
        ):
            directory = self.scratch / name
            directory.mkdir(mode=0o755)
            directory.chmod(0o755)
            raw = source.read_text()
            self.assertEqual(raw.count("require '/etc/freepbx.conf';"), 1)
            target = directory / source.name
            target.write_text(raw.replace("require '/etc/freepbx.conf';", 'require ' + json.dumps(str(self.bootstrap)) + ';'))
            target.chmod(0o644)
            library = directory / ('sls_mass_notify' if name == 'weather-source' else '')
            library.mkdir(mode=0o755, exist_ok=True)
            library.chmod(0o755)
            shutil.copyfile(BIN / 'sls_mass_notify/sls_atomic_json.php', library / 'sls_atomic_json.php')
            (library / 'sls_atomic_json.php').chmod(0o644)
            self.paths.append(target)

    def invoke(self, path, payload='{}', *, root=False, arguments=()):
        command = ['php', '-d', 'log_errors=1', '-d', 'error_log=/dev/stderr', str(path), *arguments]
        if not root:
            command = ['runuser', '-u', 'asterisk', '--', *command]
        return subprocess.run(command, input=payload, text=True, capture_output=True, timeout=5)

    def test_both_weather_layouts_and_incident_accept_service_requests(self):
        for path in self.paths:
            with self.subTest(path=path.parent.name):
                result = self.invoke(path, '{"message":"Private fixture","is_test":true}')
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                output = json.loads(result.stdout)
                if path.parent.name.startswith('weather'):
                    self.assertEqual(output['fixture_input']['is_test'], True)
                else:
                    self.assertEqual(output, {'ok': True, 'state': 'recorded'})

    def test_incident_helper_rejects_an_unrecognized_module_state(self):
        incident = next(path for path in self.paths if path.parent.name == 'incident')
        result = self.invoke(incident, '{"fixture_bad_state":true}')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(json.loads(result.stdout), {'ok': False, 'error': 'incident_response_unconfirmed'})

    def test_duplicate_keys_overflow_arrays_and_bad_json_never_reach_bootstrap(self):
        for raw in ('{"message":"first","message":"second"}',
                    '{"a":{"is_test":true,"is_test":false}}',
                    '{"number":1e9999}', '[]', '{broken'):
            for path in self.paths:
                with self.subTest(path=path.parent.name, raw=raw):
                    result = self.invoke(path, raw)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertNotIn('fixture_', result.stdout)

    def test_depth_and_byte_limits_are_preserved(self):
        deep = '{}'
        for _ in range(17):
            deep = '{"nested":' + deep + '}'
        for path in self.paths:
            maximum = 262144 if path.parent.name.startswith('weather') else 8192
            for raw in (deep, '{"message":"' + 'x' * maximum + '"}'):
                result = self.invoke(path, raw)
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn('fixture_', result.stdout)

    def test_root_and_unexpected_arguments_rejected_before_bootstrap(self):
        for path in self.paths:
            for result in (self.invoke(path, root=True), self.invoke(path, arguments=('--unexpected',))):
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn('fixture_', result.stdout)


if __name__ == '__main__':
    unittest.main()
