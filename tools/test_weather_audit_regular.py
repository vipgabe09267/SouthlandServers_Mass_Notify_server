#!/usr/bin/env python3
"""Ordinary private Weather audit append and successful-status checks."""
import concurrent.futures
import importlib.util
import json
import os
import pwd
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile
import unittest

if os.environ.get('SLS_TEST_NAMESPACE') != 'entered' or any(
    os.environ.get('SLS_TEST_PARENT_' + label + '_NS') in
    (None, '', os.readlink('/proc/self/ns/' + kind))
    for label, kind in [('MOUNT', 'mnt'), ('NET', 'net'), ('PID', 'pid')]
):
    raise SystemExit('Use tools/run_isolated_tests.sh; no production Weather storage was accessed.')

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'
spec = importlib.util.spec_from_file_location('ordinary_audit_writer', RUNTIME / 'sls_atomic_json.py')
atomic = importlib.util.module_from_spec(spec)
spec.loader.exec_module(atomic)


def isolated_append_function(name):
    source = (ROOT / 'slsmassnotifyserver/bin' / name).read_text()
    function = source[source.index('append_event_log() {'):source.index('\nrender_template() {')]
    marker = "sys.path.insert(0, '/usr/local/bin/sls_mass_notify')"
    if function.count(marker) != 1:
        raise AssertionError(f'{name} audit helper path could not be isolated')
    function = function.replace(marker, f'sys.path.insert(0, {str(RUNTIME)!r})')
    if '/usr/local/bin/sls_mass_notify' in function:
        raise AssertionError(f'{name} retained an installed-runtime audit helper path')
    return function


class RegularAuditTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='sls-audit-ordinary-')
        self.root = Path(self.temporary.name)

    def tearDown(self):
        self.temporary.cleanup()

    def test_existing_history_and_inode_are_preserved(self):
        path = self.root / 'events.jsonl'
        old = b'{"event_id":"prior","is_test":false}\n'
        path.write_bytes(old)
        path.chmod(0o640)
        inode = path.stat().st_ino
        atomic.append_json_line(path, {'event_id': 'new', 'is_test': True, 'message': 'Prueba de alerta'})
        self.assertTrue(path.read_bytes().startswith(old))
        self.assertEqual(path.stat().st_ino, inode)
        self.assertEqual(path.stat().st_mode & 0o777, 0o640)
        rows = [json.loads(line) for line in path.read_text().splitlines()]
        self.assertEqual(rows[-1]['message'], 'Prueba de alerta')

    def test_valid_freepbx_group_writable_ancestor_keeps_the_sls_child_private(self):
        account=pwd.getpwnam('asterisk')
        ancestor=self.root/'pbx-data';ancestor.mkdir()
        os.chown(ancestor,account.pw_uid,account.pw_gid);ancestor.chmod(0o2775)
        private=ancestor/'sls-state';private.mkdir()
        os.chown(private,account.pw_uid,account.pw_gid);private.chmod(0o750)
        status=private/'status.json'
        with atomic.locked_json(status,runtime_owner=True) as data:
            data['completed']=True
        self.assertEqual(json.loads(status.read_text()),{'completed':True})
        self.assertEqual(private.stat().st_mode&0o777,0o750)
        self.assertEqual(ancestor.stat().st_mode&0o7777,0o2775)
        atomic.append_json_line(private/'events.jsonl',{'event_id':'normal','is_test':True})
        self.assertEqual(status.stat().st_uid,account.pw_uid)

    def test_regular_concurrent_appends_remain_complete(self):
        path = self.root / 'events.jsonl'
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            list(pool.map(lambda index: atomic.append_json_line(path, {'event_id': f'event-{index}', 'is_test': True}), range(20)))
        rows = [json.loads(line) for line in path.read_text().splitlines()]
        self.assertEqual(len(rows), 20)
        self.assertEqual({row['event_id'] for row in rows}, {f'event-{index}' for index in range(20)})

    def test_actual_weather_shell_appenders_keep_their_schema(self):
        for name, kind, arguments in [('sls_mass_notify_test.sh', 'test', 'subject body tone'),
                                      ('sls_mass_notify_nws_poll.sh', 'nws', 'event Severe Alert tone alert subject body submitted tone')]:
            function = isolated_append_function(name)
            path = self.root / (kind + '.jsonl')
            script = 'EVENTS_LOG=' + shlex.quote(str(path)) + '\n'
            script += 'DELIVERY_TARGETS=1000 DESKTOP_DELIVERY_TARGETS=fixture TEST_AUDIO_LABEL=tone SOURCE_EXTENSION=1000 SOURCE_NAME=Fixture TRIGGER_EXTENSION=1000 TRIGGER_NAME=Fixture TEST_DELIVERY_STATUS=submitted NWS_ZONE=TXZ001\n'
            result = subprocess.run(['bash'], input=script + function + '\nappend_event_log ' + arguments,
                                    text=True, capture_output=True, timeout=5)
            self.assertEqual(result.returncode, 0, result.stderr)
            row = json.loads(path.read_text())
            self.assertEqual(row['type'], kind)
            self.assertIs(row['is_test'], kind == 'test')
            self.assertEqual(row['page_group'], '1000')

    def test_large_nws_audit_text_does_not_exceed_process_argument_limits(self):
        function=isolated_append_function('sls_mass_notify_nws_poll.sh')
        text='Complete fixture instructions. '+('x'*140000)
        body=self.root/'body.txt';body.write_text(text)
        path=self.root/'large.jsonl'
        script='EVENTS_LOG='+shlex.quote(str(path))+'\n'
        script+='DELIVERY_TARGETS=1000 SOURCE_EXTENSION=1000 SOURCE_NAME=Fixture NWS_ZONE=TXZ001\n'
        script+='BODY=$(cat '+shlex.quote(str(body))+')\n'
        result=subprocess.run(['bash'],input=script+function+'\nappend_event_log event Severe Alert tone alert subject "$BODY" submitted tone',
                              text=True,capture_output=True,timeout=5)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(json.loads(path.read_text())['mail_body'],text)

    def test_successful_manual_status_persistence_remains_successful(self):
        source = (ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_test.sh').read_text()
        start = source.index("\nif ! update_status \"$(printf '{\"last_delivery_at")
        end = source.index('\nCURRENT_TIME=', start)
        script = '''
DELIVERY_TS=fixture TEST_DELIVERY_STATUS=submitted TEST_AUDIO_LABEL=fixture TEST_DELIVERY_MESSAGE=fixture
DELIVERY_TARGETS=1000 DESKTOP_DELIVERY_TARGETS=fixture TEST_DELIVERY_STAGE=
NWS_ALERT_RECIPIENTS=(1000) NWS_DESKTOP_CLIENTS=(fixture) DELIVERY_FAILURES=()
update_status() { python3 -c 'import json,sys; json.loads(sys.argv[1])' "$1"; }
'''
        result = subprocess.run(['bash'], input=script + source[start:end] + '\n[ "${#DELIVERY_FAILURES[@]}" -eq 0 ]',
                                text=True, capture_output=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == '__main__':
    unittest.main()
