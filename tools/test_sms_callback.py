#!/usr/bin/env python3
"""Actual callback controller with private config/bootstrap and inert PHP input."""
import base64
import hashlib
import hmac
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from urllib.parse import urlencode

ROOT = Path(__file__).resolve().parents[1]
API = ROOT / 'slsmassnotifyserver/api/sls-mass-notify'

class CallbackTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-sms-callback-')
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name)
        self.url = 'https://pbx.example.com:8443/api/sls-mass-notify/sms-callback.php'
        self.config = {'public_pbx_host': 'pbx.example.com', 'control_api': {'base_url': self.url.rsplit('/', 1)[0]},
                       'announcement_sms': {'enabled': False, 'provider': 'twilio', 'from': '+15555550100',
                         'twilio_account_sid': 'AC' + 'a'*32, 'twilio_auth_token': 'c'*32,
                         'recipients': [{'id': 'sms_'+'a'*24, 'name': 'Fixture', 'number': '+15555550101',
                           'enabled': True, 'consent': True, 'consent_note': 'Fixture only', 'consent_at': '2026-01-01T00:00:00Z'}]}}
        (self.path/'config').write_text(json.dumps(self.config))
        (self.path/'config').chmod(0o640)
        self.fields = {'AccountSid': 'AC'+'a'*32, 'MessageSid': 'SM'+'b'*32, 'SmsStatus': 'received',
                       'From': '+15555550101', 'To': '+15555550100', 'Body': 'STOP'}
        signed = self.url + ''.join(k + self.fields[k] for k in sorted(self.fields))
        self.signature = base64.b64encode(hmac.new(b'c'*32, signed.encode(), hashlib.sha1).digest()).decode()
        bootstrap = '''<?php
class FreePBX {
    static function Create() {
        file_put_contents(__DIR__.'/bootstrapped','1');
        if (file_exists(__DIR__.'/fail-storage')) { throw new RuntimeException('private diagnostic must not escape'); }
        return (object)['Slsmassnotifyserver'=>new class {
            function receiveSmsCallback($id,$raw,$headers) {
                $settings=json_decode(file_get_contents(__DIR__.'/config'),true);
                $service=new \\SLS\\MassNotify\\Sms\\Service(new \\SLS\\MassNotify\\Sms\\Store(__DIR__.'/sms'));
                $service->callback($settings,$id,$raw,$headers,time());
            }
        }];
    }
}
'''
        (self.path/'bootstrap.php').write_text(bootstrap)

    def invoke(self, method='POST', raw=None, signature=None, query=None, legacy_route=None):
        source = (API/'sms-callback.php').read_text().replace('<?php', '', 1).replace('declare(strict_types=1);', '', 1)
        source = source.replace('__DIR__', json.dumps(str(API)))
        source = source.replace("'/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config'", json.dumps(str(self.path/'config')))
        source = source.replace("'/etc/freepbx.conf'", json.dumps(str(self.path/'bootstrap.php')))
        values = {'server': {'REQUEST_METHOD': method, 'REMOTE_ADDR': '127.0.0.1', 'HTTPS': 'on',
                            'CONTENT_TYPE': 'application/x-www-form-urlencoded', 'HTTP_X_TWILIO_SIGNATURE': signature if signature is not None else self.signature},
                  'query': query or {}, 'body': raw if raw is not None else urlencode(self.fields)}
        if legacy_route is not None:
            values['server']['REQUEST_URI'] = legacy_route
        (self.path/'input').write_text(json.dumps(values))
        prefix = '''<?php
declare(strict_types=1);
namespace CallbackFixture;
use \\RuntimeException; use \\Throwable; use \\DomainException; use \\InvalidArgumentException; use \\JsonException; use \\TypeError;
function file_get_contents($path,...$args) { return $path==='php://input' ? substr($GLOBALS['input']['body'],0,65537) : \\file_get_contents($path,...$args); }
function http_response_code($code) { $GLOBALS['response_status']=$code; }
function header($line) { $GLOBALS['response_headers'][]=$line; }
$GLOBALS['input']=json_decode(\\file_get_contents(__DIR__.'/input'),true);
$_SERVER=$GLOBALS['input']['server']; $_GET=$GLOBALS['input']['query'];
ob_start(); register_shutdown_function(static function(){echo json_encode(['status'=>$GLOBALS['response_status']??0,'headers'=>$GLOBALS['response_headers']??[],'body'=>ob_get_clean()]);});
'''
        if legacy_route is not None:
            # Execute the real front-controller routing prefix. The fallback
            # is inert: this fixture must never read real configuration or
            # invoke the Control API beneath the branch being exercised.
            callback = self.path/'callback.php'
            callback.write_text('<?php\nnamespace CallbackFixture;\n' +
                'use \\RuntimeException; use \\Throwable; use \\DomainException; use \\InvalidArgumentException; use \\JsonException; use \\TypeError;\n' + source)
            front = (API/'index.php').read_text()
            # Canonical requests execute the complete production front
            # controller, including its declarations, before callback exit.
            routing = front if legacy_route.split('?', 1)[0] == '/api/sls-mass-notify/sms-callback.php' else front.split('const CONFIG_FILE =', 1)[0]
            routing = routing.replace('<?php', '', 1).replace('declare(strict_types=1);', '', 1)
            routing = routing.replace("__DIR__ . '/sms-callback.php'", json.dumps(str(callback)))
            routing = routing.replace('__DIR__', json.dumps(str(API)))
            source = routing + "http_response_code(418); echo 'control_api_authentication_required'; exit;"
        (self.path/'endpoint.php').write_text(prefix+source)
        result = subprocess.run(['php', str(self.path/'endpoint.php')], capture_output=True, text=True, timeout=8)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def test_rejects_before_bootstrap_or_writable_storage(self):
        for kwargs, expected in [({'method': 'GET'},405), ({'signature': 'bad'},403),
                                 ({'raw': 'A'*65537},413), ({'query': {'delivery': '../bad'}},400),
                                 ({'raw': urlencode(self.fields)+'&Body=STOP'},400)]:
            response = self.invoke(**kwargs)
            self.assertEqual(response['status'], expected, response)
            self.assertIn('Cache-Control: no-store',response['headers'])
            self.assertFalse((self.path/'bootstrapped').exists())
            self.assertFalse((self.path/'sms').exists())

    def test_signed_stop_accepted_even_when_sending_disabled_and_duplicate_safe(self):
        for _ in range(2):
            response=self.invoke()
            self.assertEqual(response['status'],200,response)
            self.assertEqual(response['body'],'<Response/>')
        import sqlite3
        with sqlite3.connect(self.path/'sms/deliveries.sqlite') as db:
            self.assertEqual(db.execute('SELECT count(*) FROM opt_outs').fetchone()[0],1)
            self.assertEqual(db.execute('SELECT count(*) FROM inbound_events').fetchone()[0],1)

    def test_failure_requests_provider_retry_without_private_details(self):
        (self.path/'fail-storage').touch()
        response=self.invoke()
        self.assertEqual(response['status'],503,response)
        self.assertIn('Retry-After: 5',response['headers'])
        self.assertNotIn('private diagnostic',response['body'])

    def test_legacy_alias_dispatches_only_the_canonical_callback(self):
        path='/api/sls-mass-notify/sms-callback.php'
        response=self.invoke(method='GET',legacy_route=path)
        self.assertEqual(response['status'],405,response)
        response=self.invoke(legacy_route=path+'?delivery=')
        self.assertEqual(response['status'],200,response)
        response=self.invoke(legacy_route=path,signature='bad')
        self.assertEqual(response['status'],403,response)
        for other in ['/api/sls-mass-notify/',path+'/',path+'/../index.php',
                      '/api/sls-mass-notify/?resource=sms-callback.php',
                      '/api/sls-mass-notify/sms%2dcallback.php',
                      '/api/sls-mass-notify.php/sms-callback.php']:
            response=self.invoke(method='GET',legacy_route=other)
            self.assertEqual(response['status'],418,response)
            self.assertEqual(response['body'],'control_api_authentication_required')

if __name__ == '__main__': unittest.main()
