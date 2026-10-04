#!/usr/bin/env python3
"""Actual producer and ACK handler in inert, private fixtures; no PBX or network."""
import ast
from contextlib import redirect_stdout
import io
import json
import re
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
API = ROOT / 'slsmassnotifyserver/api/sipnotify/index.php'


class ReceiptContractTests(unittest.TestCase):
    def test_publication_reports_exact_id_only_after_durable_success(self):
        source = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py'
        tree = ast.parse(source.read_text())
        function = next(node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name == 'push_announcement')
        normalize = next(node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name == 'normalize_incident_context')
        namespace = {'json': json, 're': re, 'normalize_announcement_timeout_seconds': int,
                     'build_announcement_xml': mock.Mock(return_value='xml'),
                     'new_announcement_id': mock.Mock(return_value='exact-event'),
                     'announcement_api_record': lambda event, *args, **kwargs: {'id': event},
                     'append_sipnotify_event': mock.Mock()}
        exec(compile(ast.Module(body=[normalize, function], type_ignores=[]), str(source), 'exec'), namespace)
        capture = io.StringIO()
        with redirect_stdout(capture):
            namespace['push_announcement']({}, 'fixture', [], api_only=True, desktop_targets=['alice'])
        self.assertEqual(json.loads(capture.getvalue().removeprefix('SLS_DESKTOP_PUBLICATION ')), {'event_id': 'exact-event'})
        self.assertEqual(namespace['append_sipnotify_event'].call_args.args[1]['id'], 'exact-event')
        namespace['append_sipnotify_event'].side_effect = OSError('fixture storage failure')
        capture = io.StringIO()
        with redirect_stdout(capture), self.assertRaises(OSError):
            namespace['push_announcement']({}, 'fixture', [], api_only=True, desktop_targets=['alice'])
        self.assertEqual(capture.getvalue(), '')

    def run_ack(self, event_id, recipient='alice', broken_storage=False):
        source = API.read_text()
        route = source[source.index("if ($endpoint === 'desktop/ack') {"):source.index('$streamRequested =')]
        blocks = []
        for start, end in [('function atomic_replace_journal', 'function retained_events'),
                           ('function record_desktop_acknowledgement', '\n\n$endpoint ='),
                           ('function desktop_event_routed_to_client', 'function desktop_stream_events_after_cursor')]:
            offset = source.index(start)
            blocks.append(source[offset:source.index(end, offset)])
        with tempfile.TemporaryDirectory(prefix='sls-receipt-handler-') as temporary:
            directory = Path(temporary) / 'acks'
            if broken_storage:
                directory.write_text('preserved')
            route = route.replace("file_get_contents('php://input', false, null, 0, 4097)",
                                  'json_encode(["event_id"=>' + json.dumps(event_id) + '])')
            fixture = '''<?php
const RETENTION_MAX_EVENTS = 1000;
function retention_days($settings) { return 90; }
function respond($status, $body) { echo json_encode(['status'=>$status, 'body'=>$body]); exit; }
function update_desktop_seen(...$args) {}
function retained_events($settings) { return [['id'=>'fixture-event', 'desktop_recipients'=>[RECIPIENT]]]; }
$endpoint='desktop/ack'; $username='alice'; $client=['username'=>'alice','client_id'=>'fixture-client']; $settings=[];
'''.replace('RECIPIENT', json.dumps(recipient))
            fixture += '\nconst DESKTOP_ACK_DIRECTORY = ' + json.dumps(str(directory)) + ';\n'
            script = Path(temporary) / 'fixture.php'
            script.write_text(fixture + '\n'.join(blocks) + route)
            result = subprocess.run(['php', str(script)], capture_output=True, text=True, check=True, timeout=10)
            self.assertEqual(result.stderr, '')
            return json.loads(result.stdout)

    def test_authorized_and_unauthorized_acknowledgements(self):
        accepted = self.run_ack('fixture-event')
        self.assertEqual(accepted['status'], 200)
        self.assertEqual(accepted['body']['event_id'], 'fixture-event')
        self.assertEqual(self.run_ack('fixture-event', recipient='bob')['status'], 400)
        self.assertEqual(self.run_ack('unknown')['status'], 400)

    def test_storage_failure_is_retryable_http503(self):
        failed = self.run_ack('fixture-event', broken_storage=True)
        self.assertEqual(failed['status'], 503)
        self.assertFalse(failed['body']['ok'])
        self.assertTrue(failed['body']['retryable'])
        source = API.read_text()
        self.assertIn("header('Retry-After: 5');", source[source.index("if ($endpoint === 'desktop/ack') {"):source.index('$streamRequested =')])


if __name__ == '__main__':
    unittest.main()
