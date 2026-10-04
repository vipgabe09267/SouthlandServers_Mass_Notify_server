#!/usr/bin/env python3
"""Scope, revocation and proxy regressions against actual PHP API entry points."""
import json
import hashlib
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / 'slsmassnotifyserver/api/sls-mass-notify/security.php'


class ApiSecurityTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='sls-api-security-')
        self.directory = Path(self.temporary.name)
        self.server = None

    def tearDown(self):
        if self.server:
            self.server.terminate()
            self.server.wait(timeout=5)
            self.server.stderr.close()
        self.temporary.cleanup()

    def php(self, body):
        path = self.directory / 'fixture.php'
        path.write_text('<?php\ndeclare(strict_types=1);\nrequire ' + json.dumps(str(HELPER))
                        + ';\nuse SLS\\MassNotify\\ApiSecurity as Security;\n' + body)
        result = subprocess.run(['php', str(path)], capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def issue(self, scopes=('read',), unrestricted=False, **audience):
        return self.php('echo json_encode(Security::issue(' + self.php_value({
            'name': 'Fixture credential', 'scopes': list(scopes),
            'audience': dict(unrestricted=unrestricted, **audience)}) + '));')

    @staticmethod
    def php_value(value):
        return 'json_decode(' + json.dumps(json.dumps(value)) + ',true)'

    def write_config(self, **updates):
        settings = {'control_api': {'enabled': True, 'api_key': 'legacy-example-key-1234567890',
                                   'credentials': [], 'rate_limit_enabled': False}}
        settings.update(updates)
        (self.directory / 'settings.json').write_text(json.dumps(settings))
        (self.directory / 'settings.json').chmod(0o640)

    def start_api(self, desktop=False):
        api = ROOT / ('slsmassnotifyserver/api/sipnotify/index.php' if desktop else 'slsmassnotifyserver/api/sls-mass-notify/index.php')
        source = api.read_text().replace("dirname(__DIR__) . '/sls-mass-notify/security.php'", json.dumps(str(HELPER)))
        source = source.replace("__DIR__ . '/event-log.php'", json.dumps(str(ROOT / 'slsmassnotifyserver/api/sls-mass-notify/event-log.php')))
        source = source.replace("__DIR__ . '/security.php'", json.dumps(str(HELPER)))
        for helper in ['contract.php', 'config-crypto.php']:
            helper_path = json.dumps(str(HELPER.parent / helper))
            source = source.replace("__DIR__ . '/" + helper + "'", helper_path)
            source = source.replace("dirname(__DIR__) . '/sls-mass-notify/" + helper + "'", helper_path)
        for config_constant in ['CONFIG_FILE', 'SETTINGS_FILE']:
            source = source.replace('SlsConfigCrypto::readFile(' + config_constant + ')',
                                    'SlsConfigCrypto::readFile(' + config_constant + ', ' + json.dumps(str(self.directory/'keys.json')) + ')')
        for name, value in {'CONFIG_FILE': 'settings.json', 'SETTINGS_FILE': 'settings.json',
                            'STATUS_FILE': 'status.json', 'EVENTS_FILE': 'events.jsonl',
                            'CONTROL_API_RATE_FILE': 'rate.json', 'DESKTOP_AUTH_RATE_FILE': 'desktop-rate.json',
                            'DESKTOP_LAST_SEEN_FILE': 'seen.json', 'DESKTOP_ACK_DIRECTORY': 'acks',
                            'CONTROL_API_AUDIT_HELPER': 'missing-helper.py'}.items():
            source = re.sub(r"const " + name + r" = '[^']*';", 'const ' + name + ' = ' + json.dumps(str(self.directory / value)) + ';', source)
        function = 'desktop_incident_module' if desktop else 'freepbx_module'
        start = source.index('function ' + function + '(')
        end = source.index('function settings_config(' if desktop else '$config = config();', start)
        source = source[:start] + 'function ' + function + '''() {
                return new class {
                    public function __call($name, $arguments) {
                        file_put_contents(__DIR__ . '/module-calls.jsonl', json_encode([$name,$arguments])."\\n",FILE_APPEND);
                        return ['success'=>true,'method'=>$name,'arguments'=>$arguments];
                    }
                };
            }
            ''' + source[end:]
        (self.directory / 'index.php').write_text(source)
        (self.directory / 'router.php').write_text('''<?php
            $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_FIXTURE_REMOTE'] ?? '192.0.2.5';
            $_SERVER['HTTPS'] = $_SERVER['HTTP_X_FIXTURE_HTTPS'] ?? 'on';
            include __DIR__ . '/index.php';
        ''')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        self.url = f'http://127.0.0.1:{port}'
        self.server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', str(self.directory / 'router.php')],
                                       stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1):
                    return
            except OSError:
                time.sleep(.02)
        self.fail('PHP fixture listener did not start')

    def request(self, token=None, body=None, path='/', **headers):
        if token:
            headers['Authorization'] = 'Bearer ' + token
        if body is not None:
            headers.setdefault('Content-Type', 'application/json')
        request = urllib.request.Request(self.url + path, headers=headers,
                                         data=None if body is None else json.dumps(body).encode())
        try:
            response = urllib.request.urlopen(request, timeout=3)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, json.loads(response.read())

    def test_named_credential_is_random_hashed_revocable_and_strict(self):
        result = self.php('''
            $a=Security::issue(['name'=>'Monitor','scopes'=>['read'],'audience'=>['unrestricted'=>false]]);
            $b=Security::issue(['name'=>'Monitor','scopes'=>['read'],'audience'=>['unrestricted'=>false]]);
            $control=['credentials'=>[$a['credential']]];
            $before=Security::authenticate($control,$a['secret']);
            $control['credentials']=Security::revoke($control['credentials'],$a['credential']['id']);
            $once=$control['credentials'];$control['credentials']=Security::revoke($once,$a['credential']['id']);
            $bad=$a['credential'];$bad['scopes'][]='administrator';
            echo json_encode(['unique'=>$a['secret']!==$b['secret'],'stored'=>strpos(json_encode($a['credential']),$a['secret'])===false,
              'digest'=>$a['credential']['secret_hash']===hash('sha256',$a['secret']), 'id'=>$before['id'],
              'revoked'=>Security::authenticate($control,$a['secret'])===null,'idempotent'=>$once===$control['credentials'],
              'unknown_scope'=>Security::authenticate(['credentials'=>[$bad]],$a['secret'])===null]);
        ''')
        self.assertTrue(all(value for key, value in result.items() if key != 'id'))
        self.assertRegex(result['id'], r'^api_[a-f0-9]{24}$')

    def test_restricted_config_and_malformed_permission_shapes_rejected(self):
        result = self.php('''
            $bad=[['scopes'=>['config'],'audience'=>['unrestricted'=>false]],
                  ['scopes'=>['send','unknown'],'audience'=>['unrestricted'=>true]],
                  ['scopes'=>['send'],'audience'=>['unrestricted'=>'true']],
                  ['scopes'=>['send'],'audience'=>['unrestricted'=>false,'extensions'=>['1000x']]],
                  ['scopes'=>[],'audience'=>['unrestricted'=>true]]];$count=0;
            foreach($bad as $input){try{Security::issue(['name'=>'Fixture']+$input);}catch(DomainException $e){$count++;}}
            echo json_encode($count);
        ''')
        self.assertEqual(result, 5)

    def test_actual_control_entry_enforces_scope_and_revocation_before_actions(self):
        issued = self.issue()
        control = {'enabled': True, 'api_key': 'legacy-example-key-1234567890', 'credentials': [issued['credential']]}
        self.write_config(control_api=control)
        (self.directory / 'status.json').write_text(json.dumps({'secret_incident': 'restricted data'}))
        self.start_api()
        code, body = self.request(issued['secret'])
        self.assertEqual(code, 200)
        self.assertEqual(body['status'], {'control_api_enabled': True})
        for request in ({'action': 'send_announcement', 'targets': ['1000'], 'message': 'No send'},
                        {'action': 'test_nws'}, {'action': 'update_config', 'settings': {'enabled': True}}):
            self.assertEqual(self.request(issued['secret'], request)[0], 403)
        self.assertEqual(self.request(issued['secret'], path='/?resource=events')[0], 403)
        self.assertEqual(self.request(issued['secret'], path='/?resource=readiness')[0], 403)
        self.assertEqual(self.request(issued['secret'], path='/?resource=config')[0], 403)
        self.assertFalse((self.directory / 'module-calls.jsonl').exists())
        control['credentials'][0]['revoked_at'] = '2026-09-22T00:00:00+00:00'
        self.write_config(control_api=control)
        self.assertEqual(self.request(issued['secret'])[0], 401)
        self.assertEqual(self.request('legacy-example-key-1234567890')[0], 200)

    def test_discovery_is_authorized_redacted_and_does_not_bootstrap(self):
        issued = self.issue(scopes=('read', 'send'), extensions=['1000'], desktop_client_ids=['desktop_a'],
                            voice_recipient_ids=['voice_a'], email_recipient_ids=['email_' + 'a'*24],
                            sms_recipient_ids=['sms_' + 'a'*24], webhook_ids=['webhook_a'])
        self.write_config(control_api={'enabled': True, 'credentials': [issued['credential']]},
                          desktop_clients=[{'client_id': 'desktop_a', 'username': 'alice', 'name': 'Alice', 'enabled': True, 'password_enc': 'never-disclose'},
                                           {'client_id': 'desktop_b', 'username': 'bob', 'name': 'Bob', 'enabled': True}],
                          outbound_voice={'enabled': True, 'recipients': [{'id': 'voice_a', 'name': 'Phone', 'number': '+15555550101', 'enabled': True}]},
                          announcement_email={'enabled': True, 'recipients': [{'id': 'email_'+'a'*24, 'name': 'Mail', 'address': 'private@example.com', 'enabled': True}]},
                          announcement_sms={'enabled': True, 'recipients': [{'id': 'sms_'+'a'*24, 'name': 'SMS', 'number': '+15555550102', 'enabled': True, 'consent': True}]},
                          announcement_webhooks=[{'id': 'webhook_a', 'name': 'Hook', 'enabled': True, 'url': 'https://private.example.com/secret'}])
        self.start_api()
        code, capabilities = self.request(issued['secret'], path='/?resource=capabilities')
        self.assertEqual(code, 200)
        self.assertEqual((capabilities['api_version'], capabilities['notification_payload_schema'], capabilities['desktop_sse_protocol']), (1, 1, 2))
        self.assertEqual(capabilities['resources'], ['status', 'capabilities', 'audiences', 'delivery',
                                                    'enterprise_capabilities', 'incident_templates'])
        self.assertEqual([item['action'] for item in capabilities['actions']], ['send_announcement', 'preview_announcement', 'retry_announcement',
                                                                             'enterprise_template_start'])
        code, audience = self.request(issued['secret'], path='/?resource=audiences')
        self.assertEqual(code, 200)
        self.assertEqual(audience['phone_targets'], ['1000'])
        self.assertEqual(audience['desktop_clients'], [{'id': 'desktop_a', 'name': 'Alice', 'username': 'alice'}])
        serialized = json.dumps(audience)
        for secret in ['never-disclose', 'private@example.com', '+15555550101', '+15555550102', 'https://private.example.com/secret', 'bob']:
            self.assertNotIn(secret, serialized)
        self.assertFalse((self.directory / 'module-calls.jsonl').exists())
        self.assertEqual(self.request(path='/?resource=capabilities')[0], 401)

    def test_malformed_query_and_json_media_type_are_rejected_before_module(self):
        issued = self.issue(scopes=('read', 'send'), unrestricted=True)
        self.write_config(control_api={'enabled': True, 'credentials': [issued['credential']]})
        self.start_api()
        for path in ['/?resource[]=status', '/?resource=missing', '/?resource=delivery&job_id[]=bad',
                     '/?resource=events&cursor[]=bad', '/?resource=events&limit[]=1', '/?resource=incident&incident_id=bad']:
            self.assertEqual(self.request(issued['secret'], path=path)[0], 400, path)
        self.assertEqual(self.request(issued['secret'], {'action': 'send_announcement'}, **{'Content-Type': 'application/jsonjunk'})[0], 415)
        self.assertFalse((self.directory / 'module-calls.jsonl').exists())

    def test_encrypted_control_configuration_authenticates_and_tampering_fails_closed(self):
        issued = self.issue(scopes=('read',), unrestricted=True)
        self.write_config(control_api={'enabled': True, 'credentials': [issued['credential']]})
        helper = self.directory / 'encrypt.php'
        helper.write_text('<?php require ' + json.dumps(str(HELPER.parent/'config-crypto.php')) + ';'
                          '$id=bin2hex(random_bytes(16));$ring=["format"=>\\FreePBX\\modules\\SlsConfigCrypto::KEYRING_FORMAT,'
                          '"active"=>$id,"keys"=>[$id=>["created_at"=>time(),"key"=>base64_encode(random_bytes(32))]]];'
                          'file_put_contents(__DIR__."/keys.json",json_encode($ring));chmod(__DIR__."/keys.json",0640);'
                          '$settings=json_decode(file_get_contents(__DIR__."/settings.json"),true);'
                          'file_put_contents(__DIR__."/settings.json",\\FreePBX\\modules\\SlsConfigCrypto::encodeWithKeyring($settings,$ring));')
        result = subprocess.run(['php', str(helper)], capture_output=True, text=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.start_api()
        self.assertEqual(self.request(issued['secret'], path='/?resource=capabilities')[0], 200)
        envelope = json.loads((self.directory/'settings.json').read_text())
        self.assertNotIn(issued['credential']['secret_hash'], json.dumps(envelope))
        ciphertext = bytearray(__import__('base64').b64decode(envelope['ciphertext']))
        ciphertext[-1] ^= 1
        envelope['ciphertext'] = __import__('base64').b64encode(ciphertext).decode()
        (self.directory/'settings.json').write_text(json.dumps(envelope))
        self.assertEqual(self.request(issued['secret'], path='/?resource=capabilities')[0], 503)
        self.assertFalse((self.directory/'module-calls.jsonl').exists())

    def test_preview_action_and_test_metadata_use_validated_public_options(self):
        issued = self.issue(scopes=('send',), unrestricted=True)
        self.write_config(control_api={'enabled': True, 'credentials': [issued['credential']]})
        self.start_api()
        code, response = self.request(issued['secret'], {'action': 'preview_announcement', 'message': 'Fixture', 'targets': ['1000'], 'is_test': True})
        self.assertEqual(code, 200)
        self.assertEqual(response['arguments'][0]['options'], {'_is_test': True, 'preview': True})
        for field in ['_test_channels', '_is_test', 'incident_context']:
            code, _ = self.request(issued['secret'], {'action': 'send_announcement', 'message': 'Fixture', 'options': {field: True}})
            self.assertEqual(code, 400)
        self.assertEqual(len((self.directory/'module-calls.jsonl').read_text().splitlines()), 1)

    def test_status_special_files_oversize_and_corruption_fail_closed(self):
        issued = self.issue(scopes=('read',), unrestricted=True)
        self.write_config(control_api={'enabled': True, 'credentials': [issued['credential']]})
        self.start_api()
        status = self.directory/'status.json'
        for contents in ['x'*(1048576+1), '{broken', '[1]']:
            status.write_text(contents)
            self.assertEqual(self.request(issued['secret'])[0], 503)
        status.unlink()
        os.mkfifo(status, 0o600)
        self.assertEqual(self.request(issued['secret'])[0], 503)
        status.unlink()
        status.write_text('{"valid":true}')
        self.assertEqual(self.request(issued['secret'])[1]['status'], {'valid': True})

    def test_config_permission_cannot_manage_credentials_or_proxy_trust(self):
        issued = self.issue(scopes=('config',), unrestricted=True)
        self.write_config(control_api={'enabled': True, 'credentials': [issued['credential']]})
        self.start_api()
        for patch in ({'api_network': {'trusted_proxy_cidrs': ['192.0.2.0/24']}},
                      {'control_api': {'credentials': []}}, {'control_api': {'api_key': 'new-master-credential'}}):
            self.assertEqual(self.request(issued['secret'], {'action': 'update_config', 'settings': patch})[0], 403)
        self.assertFalse((self.directory / 'module-calls.jsonl').exists())
        self.assertEqual(self.request(issued['secret'], {'action': 'update_config', 'settings': {'announcement_timeout_seconds': 60}})[0], 200)

    def test_readiness_invokes_the_module_only_for_unrestricted_readers(self):
        issued = self.issue(scopes=('read',), unrestricted=True)
        self.write_config(control_api={'enabled': True, 'credentials': [issued['credential']]})
        self.start_api()
        code, body = self.request(issued['secret'], path='/?resource=readiness')
        self.assertEqual(code, 200)
        self.assertTrue(body['ok'])
        self.assertEqual(body['resource'], 'readiness')
        self.assertEqual(body['method'], 'getDeploymentReadiness')
        calls = [json.loads(row) for row in (self.directory/'module-calls.jsonl').read_text().splitlines()]
        self.assertEqual(calls, [['getDeploymentReadiness', []]])

    def test_selected_weather_zones_are_explicit_for_restricted_tokens(self):
        issued = self.issue(scopes=('test',), nws_zone_ids=['zone_one'])
        self.write_config(control_api={'enabled': True, 'credentials': [issued['credential']]},
                          nws_zones=[{'id': 'zone_one'}, {'id': 'zone_two'}])
        self.start_api()
        for body in ({'action': 'test_nws'}, {'action': 'test_nws', 'zone_scope': 'selected', 'zone_ids': []},
                     {'action': 'test_nws', 'zone_scope': 'selected', 'zone_ids': ['zone_two']}):
            self.assertEqual(self.request(issued['secret'], body)[0], 403)
        self.assertEqual(self.request(issued['secret'], {'action': 'test_nws', 'zone_scope': 'selected', 'zone_ids': ['zone_one']})[0], 200)

    def test_effective_audience_uses_ids_groups_and_does_not_grant_unknown_channels(self):
        result = self.php('''
            $p=Security::issue(['name'=>'Sender','scopes'=>['send'],'audience'=>['unrestricted'=>false,
                'extensions'=>['1000'],'desktop_client_ids'=>['device_a'],'announcement_group_ids'=>['group_a']]])['credential'];
            $settings=['desktop_clients'=>[['client_id'=>'device_a','username'=>'alice','enabled'=>true]],
                'announcement_groups'=>[['id'=>'group_a','extensions'=>['1001'],'desktop_clients'=>['bob'],'voice_recipient_ids'=>['voice_a']]]];
            $request=['phones'=>['1000','1001'],'desktops'=>['alice','bob'],'voice_recipient_ids'=>['voice_a'],'webhooks'=>[]];
            $valid=Security::permitsResolvedAnnouncement($p,$request,$settings);
            $bad=$request;$bad['phones'][]='1002';$phone=Security::permitsResolvedAnnouncement($p,$bad,$settings);
            $bad=$request;$bad['emails']=['outside@example.test'];$email=Security::permitsResolvedAnnouncement($p,$bad,$settings);
            $settings['desktop_clients'][0]['client_id']='replacement';
            echo json_encode([$valid,$phone,$email,Security::permitsResolvedAnnouncement($p,$request,$settings)]);
        ''')
        self.assertEqual(result, [True, False, False, False])

    def test_proxy_chain_cidr_and_loopback_rules(self):
        result = self.php('''
            $settings=['api_network'=>['trusted_proxy_cidrs'=>['127.0.0.1','10.0.0.0/24','2001:db8::/32']]];
            $base=['REMOTE_ADDR'=>'127.0.0.1','HTTP_X_FORWARDED_FOR'=>'192.0.2.1, 198.51.100.3, 10.0.0.2','HTTP_X_FORWARDED_PROTO'=>'https'];
            $valid=Security::requestNetwork($base,$settings);
            $spoof=$base;$spoof['REMOTE_ADDR']='203.0.113.1';
            $bad=$base;$bad['HTTP_X_FORWARDED_FOR']='127.0.0.1';$bad['HTTP_X_FORWARDED_PROTO']='http';
            $malformed=$base;$malformed['HTTP_X_FORWARDED_PROTO']='http,https';
            echo json_encode([$valid,Security::requestNetwork($spoof,$settings),Security::requestNetwork($bad,$settings),
                Security::requestNetwork($malformed,$settings),Security::requestNetwork(['REMOTE_ADDR'=>'127.0.0.1'],[]),
                Security::requestNetwork(['REMOTE_ADDR'=>'127.0.0.1'],$settings),
                Security::inCidr('2001:db8:1234::1','2001:db8::/32'),Security::inCidr('2001:db9::1','2001:db8::/32')]);
        ''')
        self.assertEqual(result[0]['ip'], '198.51.100.3')
        self.assertTrue(result[0]['https'])
        self.assertEqual(result[1]['ip'], '203.0.113.1')
        self.assertFalse(result[1]['https'])
        self.assertFalse(result[2]['loopback'])
        self.assertFalse(result[2]['https'])
        self.assertEqual(result[3]['error'], 'invalid_forwarded_headers')
        self.assertTrue(result[4]['loopback'])
        self.assertFalse(result[5]['loopback'])
        self.assertEqual(result[6:], [True, False])

    def test_actual_control_proxy_https_and_ip_allowlist_use_client(self):
        self.write_config(control_api={'enabled': True, 'api_key': 'legacy-example-key-1234567890',
                                      'ip_allowlist_enabled': True, 'ip_allowlist': '198.51.100.3'},
                          api_network={'trusted_proxy_cidrs': ['127.0.0.1']})
        self.start_api()
        headers = {'X-Fixture-Remote': '127.0.0.1', 'X-Fixture-Https': 'off',
                   'X-Forwarded-For': '198.51.100.3', 'X-Forwarded-Proto': 'https'}
        self.assertEqual(self.request('legacy-example-key-1234567890', **headers)[0], 200)
        headers['X-Forwarded-For'] = '198.51.100.4'
        self.assertEqual(self.request('legacy-example-key-1234567890', **headers)[0], 403)
        headers['X-Forwarded-For'] = '127.0.0.1'
        headers['X-Forwarded-Proto'] = 'http'
        self.assertEqual(self.request('legacy-example-key-1234567890', **headers)[0], 426)

    def test_actual_desktop_proxy_never_bypasses_https_with_loopback_spoof(self):
        self.write_config(api_network={'trusted_proxy_cidrs': ['127.0.0.1']})
        self.start_api(desktop=True)
        headers = {'X-Fixture-Remote': '127.0.0.1', 'X-Fixture-Https': 'off',
                   'X-Forwarded-For': '127.0.0.1', 'X-Forwarded-Proto': 'http'}
        self.assertEqual(self.request(path='/api/sipnotify/desktop', **headers)[0], 426)
        headers['X-Forwarded-For'] = '198.51.100.3'
        headers['X-Forwarded-Proto'] = 'https'
        self.assertEqual(self.request(path='/api/sipnotify/desktop', **headers)[0], 401)

    def test_incident_control_routes_require_unrestricted_authority(self):
        issued = self.issue(scopes=('read', 'send'))
        self.write_config(control_api={'enabled': True, 'credentials': [issued['credential']]})
        self.start_api()
        self.assertEqual(self.request(issued['secret'], path='/?resource=incidents')[0], 403)
        self.assertEqual(self.request(issued['secret'], {'action': 'start_incident', 'template_id': 'fixture'})[0], 403)
        self.assertFalse((self.directory / 'module-calls.jsonl').exists())
        issued = self.issue(scopes=('read', 'send', 'config'), unrestricted=True)
        self.write_config(control_api={'enabled': True, 'credentials': [issued['credential']]})
        self.assertEqual(self.request(issued['secret'], path='/?resource=incidents')[0], 200)
        code, body = self.request(issued['secret'], {'action': 'start_incident', 'template_id': 'fixture', 'actor': 'forged'})
        self.assertEqual(code, 200)
        self.assertEqual(body['arguments'][1], {'identity': 'Control API ' + issued['credential']['id'], 'source': 'control_api', 'credential_id': issued['credential']['id']})
        code, body = self.request(issued['secret'], {'action': 'incident_roll_call', 'incident_id': 'inc_' + 'a' * 32})
        self.assertEqual(code, 200)
        self.assertEqual(body['method'], 'recordIncidentRollCall')

    def test_desktop_incident_route_binds_only_authenticated_username(self):
        import base64
        settings = self.php('''
            $key=random_bytes(32);$iv=random_bytes(12);$tag='';
            $encrypted=openssl_encrypt('fixture-pass','aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
            echo json_encode(['desktop_auth_key'=>base64_encode($key),'desktop_clients'=>[
              ['client_id'=>'alice-device','username'=>'alice','enabled'=>true,'password_enc'=>'v1:'.base64_encode($iv.$tag.$encrypted)]]]);
        ''')
        self.write_config(**settings)
        self.start_api(desktop=True)
        headers = {'Authorization': 'Basic ' + base64.b64encode(b'alice:fixture-pass').decode()}
        incident = 'inc_' + 'a' * 32
        code, body = self.request(path='/api/sipnotify/desktop/incident?incident_id=' + incident, **headers)
        self.assertEqual(code, 200)
        identity = {'client_id': 'alice-device', 'credential_fingerprint': hashlib.sha256(settings['desktop_clients'][0]['password_enc'].encode()).hexdigest()}
        self.assertEqual(body['arguments'], [incident, 'alice', identity])
        payload = {'incident_id': incident, 'request_id': 'b' * 32, 'response': 'safe', 'note': ''}
        code, body = self.request(body=payload, path='/api/sipnotify/desktop/incident/respond', **headers)
        self.assertEqual(code, 200)
        self.assertEqual(body['arguments'][1], 'alice')
        self.assertEqual(body['arguments'][3], identity)
        payload['username'] = 'another-client'
        self.assertEqual(self.request(body=payload, path='/api/sipnotify/desktop/incident/respond', **headers)[0], 400)


if __name__ == '__main__':
    # The real HTTP fixtures need loopback, but must never alter the host's
    # interface state or have a route to any real notification destination.
    if 'SLS_API_SECURITY_PARENT_NET' not in os.environ:
        os.environ['SLS_API_SECURITY_PARENT_NET'] = os.readlink('/proc/self/ns/net')
        os.execvp('unshare', ['unshare', '--net', sys.executable, __file__])
    if os.environ['SLS_API_SECURITY_PARENT_NET'] == os.readlink('/proc/self/ns/net'):
        raise SystemExit('API security fixtures require a private network namespace.')
    subprocess.run(['ip', 'link', 'set', 'dev', 'lo', 'up'], check=True)
    unittest.main()
