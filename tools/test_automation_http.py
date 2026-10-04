"""Exercise the shipped trigger endpoint over HTTP in private PBX namespaces."""
import hashlib
import hmac
import http.client
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time

REPO = Path(__file__).resolve().parents[1]
if os.environ.get('SLS_TEST_NAMESPACE') != 'entered' or os.geteuid() != 0:
    raise SystemExit('Use tools/run_isolated_tests.sh; this fixture must never mount over a live PBX.')
for kind, short in [('MOUNT', 'mnt'), ('NET', 'net'), ('PID', 'pid')]:
    if not os.environ.get('SLS_TEST_PARENT_' + kind + '_NS') or os.readlink('/proc/self/ns/' + short) == os.environ['SLS_TEST_PARENT_' + kind + '_NS']:
        raise SystemExit('Fixture isolation is missing.')
if sys.argv[1:] != ['--child']:
    raise SystemExit(subprocess.run(['unshare', '--mount', '--net', '--fork', sys.executable, __file__, '--child']).returncode)
subprocess.run(['mount', '--make-rprivate', '/'], check=True)
subprocess.run(['ip', 'link', 'set', 'lo', 'up'], check=True)
module = '/var/www/html/admin/modules/slsmassnotifyserver'
subprocess.run(['mount', '--bind', str(REPO / 'slsmassnotifyserver'), module], check=True)

with tempfile.TemporaryDirectory(prefix='sls-trigger-http-') as temporary:
    root = Path(temporary)
    data = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
    config_file = data / 'mass-notifications.config'
    secret = 'a' * 64
    sensor = 'trg_' + 'b' * 24
    panic = 'trg_' + 'c' * 24
    action = 'act_' + 'd' * 24
    settings = {'enabled': '1', 'automations': {'schema': 1, 'rules': [
        {'id': sensor, 'name': 'Signed sensor', 'enabled': True, 'kind': 'sensor', 'secret': secret,
         'event': 'door', 'action_ids': [action], 'cooldown_seconds': 10},
        {'id': panic, 'name': 'Desk panic', 'enabled': True, 'kind': 'panic', 'secret': secret,
         'location_id': 'loc_' + 'e' * 24, 'action_ids': [action]}],
        'actions': [{'id': action, 'name': 'Private fixture', 'enabled': True, 'kind': 'brightsign_udp',
                     'host': '192.168.20.2', 'message': 'fixture', 'port': 5000}]},
        'location_directory': {'nodes': [{'id': 'loc_' + 'e' * 24, 'name': 'Office','type':'site'}]}}
    def save_config():
        config_file.write_text(json.dumps(settings))
        config_file.chmod(0o640)
    save_config()
    bootstrap = root / 'freepbx.php'
    bootstrap.write_text('''<?php
require_once '/var/www/html/admin/modules/slsmassnotifyserver/Automations.php';
class FixtureModule {
    use \\FreePBX\\modules\\SlsAutomations;
    private function getActiveSettings() { return json_decode(file_get_contents('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config'), true, 32, JSON_THROW_ON_ERROR); }
    protected function automationStoreDirectory(): string { return '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/http-fixture'; }
    private function startAutomationWorker(string $id): void { /* Durable admission only; never run an action. */ }
}
class FreePBX { public static function create() { return (object)['Slsmassnotifyserver' => new FixtureModule()]; } }
''')
    subprocess.run(['mount', '--bind', str(bootstrap), '/etc/freepbx.conf'], check=True)
    router = root / 'router.php'
    router.write_text('''<?php
if (str_starts_with($_SERVER['REQUEST_URI'], '/tls/')) { $_SERVER['HTTPS'] = 'on'; }
require '/var/www/html/admin/modules/slsmassnotifyserver/api/sls-mass-notify/trigger.php';
''')
    sock = socket.socket(); sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]; sock.close()
    with (root / 'server.log').open('wb') as log:
        server = subprocess.Popen(['php', '-n', '-d', 'extension=posix', '-S', f'127.0.0.1:{port}', str(router)], stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1): break
                except OSError: time.sleep(.02)
            def request(body=None, *, method='POST', rule=sensor, sign=True, tls=True, timestamp=None,
                        content_type='application/json', query=None, extra_headers=None):
                raw = body if isinstance(body, str) else json.dumps(body or {}, separators=(',', ':'))
                timestamp = str(int(time.time()) if timestamp is None else timestamp)
                headers = {'Content-Type': content_type}
                if sign:
                    headers['X-SLS-Timestamp'] = timestamp
                    headers['X-SLS-Signature'] = hmac.new(secret.encode(), (rule + '.' + timestamp + '.' + raw).encode(), hashlib.sha256).hexdigest()
                headers.update(extra_headers or {})
                connection = http.client.HTTPConnection('127.0.0.1', port, timeout=4)
                try:
                    connection.request(method, ('/tls/' if tls else '/plain/') + '?' + (query or 'rule_id=' + rule), raw, headers)
                    response = connection.getresponse()
                    return response.status, dict(response.getheaders()), json.loads(response.read())
                finally: connection.close()
            def expect(status, **kwargs):
                result = request(**kwargs)
                assert result[0] == status, result
                assert result[1].get('Cache-Control') == 'no-store'
                assert result[1].get('X-Content-Type-Options') == 'nosniff'
                return result
            expect(405, method='GET')
            expect(400, query='rule_id[]=bad')
            expect(415, content_type='text/plain')
            expect(413, body='x' * 16385)
            expect(403, sign=False)
            expect(403, timestamp=int(time.time()) - 61)
            expect(403, tls=False, extra_headers={'X-Forwarded-Proto': 'https'})
            expect(403, extra_headers={'X-SLS-Signature': '0' * 64})
            expect(422, body='{invalid')
            expect(400, body='[]')
            assert not (data / 'http-fixture').exists(), 'Unauthenticated traffic created trigger state.'
            now = int(time.time())
            payload = {'operation': 'activate', 'request_id': 'f' * 32, 'sent_at': now, 'expires_at': now + 120,
                       'event': 'door', 'message': 'Fixture only', 'caller': '', 'is_test': False}
            accepted = expect(200, body=payload)
            assert accepted[2]['ok'] and accepted[2]['state'] == 'queued'
            repeated = expect(200, body=payload)
            assert repeated[2]['event_id'] == accepted[2]['event_id']
            expect(422, body={**payload, 'message': 'Altered request'})
            limited = expect(429, body={**payload, 'request_id': '1' * 32})
            assert limited[1]['Retry-After'] == '5'
            status = expect(200, body={'operation': 'status', 'event_id': accepted[2]['event_id']})
            assert status[2]['event_id'] == accepted[2]['event_id']
            expect(422, rule=panic, body={'operation': 'status', 'event_id': accepted[2]['event_id']})
            challenge = expect(200, rule=panic, body={'operation': 'challenge', 'request_id': '2' * 32})
            assert challenge[2]['ok'] and 'challenge' in challenge[2]
            settings['automations']['rules'][0]['enabled'] = False; save_config()
            expect(403, body={'operation': 'status', 'event_id': accepted[2]['event_id']})
            config_file.chmod(0o666)
            failure = expect(503, body=payload)
            assert failure[1]['Retry-After'] == '5'
            print('Trigger HTTP: HTTPS/proxy policy, HMAC, bounds, durable/idempotent admission, cooldown, identity isolation, panic challenge and revocation passed; zero actions executed.')
        finally:
            server.terminate(); server.wait(timeout=5)
