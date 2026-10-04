#!/usr/bin/python3
"""Actual Enterprise administrator controller with private settings and inert PBX plumbing."""
from http.client import HTTPConnection
from pathlib import Path
from urllib.parse import urlencode
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / 'slsmassnotifyserver'
isolated = ((os.environ.get('SLS_TEST_NAMESPACE') == 'entered'
             and os.environ.get('SLS_TEST_PARENT_NET_NS') not in (None, os.readlink('/proc/self/ns/net')))
            or (os.environ.get('SLS_BUILD_STAGE') == 'isolated'
                and os.environ.get('SLS_BUILD_PARENT_NET') not in (None, os.readlink('/proc/self/ns/net'))))
if not isolated:
    raise SystemExit('Use the isolated fixture runner; no administrator HTTP server was started.')


class EnterpriseControllerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        subprocess.run([shutil.which('ip'), 'link', 'set', 'lo', 'up'], check=True, capture_output=True)
        cls.temporary = tempfile.TemporaryDirectory(prefix='sls-enterprise-controller-')
        cls.directory = Path(cls.temporary.name)
        (cls.directory / 'sessions').mkdir(mode=0o700)
        router = r'''<?php
declare(strict_types=1);
interface BMO{}
require MODULE.'/Slsmassnotifyserver.class.php';
session_start();
$reflection=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);$methods='';
foreach(['getCsrfToken','validateCsrfToken'] as $name){$method=$reflection->getMethod($name);$methods.=implode('',array_slice(file($method->getFileName()),$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1));}
$class= <<<'PHP'
class EnterpriseControllerFixture {
 use \FreePBX\modules\SlsEnterpriseAdministration;
 const CSRF_SESSION_KEY='controller_fixture_csrf';
 public function currentOperator():array {
  $kind=$_SERVER['HTTP_X_FIXTURE_ACCOUNT']??'admin';
  if($kind==='missing'){throw new DomainException('No fixture account');}
  return ['id'=>$kind==='sender'?'api_'.str_repeat('a',24):'recovery_admin','username'=>'fixture-admin','operator_role'=>$kind==='sender'?'sender':'administrator'];
 }
 public function getActiveSettings():array{return ['labs_safety'=>\SLS\MassNotify\LabsSafety::defaults()];}
 public function renderEnterpriseLabsDashboard():string{return '<p>SLS private controller fixture</p>';}
 public function getEnterpriseFloorplanImage(string $id):array{throw new DomainException('No private fixture image');}
}
PHP;
eval(substr($class,0,strrpos($class,'}')).$methods.'}');
class FreePBX {public static function create():object{return (object)['Slsmassnotifyserver'=>new EnterpriseControllerFixture];}}
$kind=$_SERVER['HTTP_X_FIXTURE_ACCOUNT']??'admin';
if($kind==='portal'){$GLOBALS['sls_operator_portal_principal']=['id'=>'fixture'];}
if($kind==='control'){$GLOBALS['sls_control_principal']=['id'=>'fixture'];}
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/csrf'){header('Content-Type: application/json');echo json_encode(['token'=>(new EnterpriseControllerFixture)->getCsrfToken()]);exit;}
require MODULE.'/page.slsmassnotifyserver_enterprise.php';
'''.replace('MODULE', json.dumps(str(MODULE)))
        (cls.directory / 'router.php').write_text(router)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            cls.port = listener.getsockname()[1]
        cls.log = (cls.directory / 'server.log').open('w+')
        cls.server = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'session.use_cookies=1',
                                       '-d', 'session.save_path=' + str(cls.directory / 'sessions'),
                                       '-S', f'127.0.0.1:{cls.port}', str(cls.directory / 'router.php')],
                                      cwd=cls.directory, stdout=cls.log, stderr=cls.log)
        deadline = time.monotonic() + 5
        while time.monotonic() < deadline:
            try:
                connection = HTTPConnection('127.0.0.1', cls.port, timeout=1)
                connection.request('GET', '/csrf')
                result = connection.getresponse()
                data = json.loads(result.read())
                cls.cookie = result.getheader('Set-Cookie').split(';')[0]
                cls.token = data['token']
                connection.close()
                break
            except (OSError, ValueError, TypeError):
                if cls.server.poll() is not None:
                    raise RuntimeError('Private controller fixture failed to start.')
                time.sleep(0.05)
        else:
            raise RuntimeError('Private controller fixture did not become available.')

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        try:
            cls.server.wait(timeout=3)
        except subprocess.TimeoutExpired:
            cls.server.kill()
            cls.server.wait(timeout=3)
        cls.log.close()
        cls.temporary.cleanup()

    def request(self, method='GET', data=None, account='admin', path='/enterprise', cookie=True):
        connection = HTTPConnection('127.0.0.1', self.port, timeout=5)
        headers = {'X-Fixture-Account': account}
        if cookie:
            headers['Cookie'] = self.cookie
        body = None if data is None else urlencode(data)
        if body is not None:
            headers['Content-Type'] = 'application/x-www-form-urlencoded'
        connection.request(method, path, body, headers)
        result = connection.getresponse()
        status, returned_headers, answer = result.status, dict(result.getheaders()), result.read().decode()
        connection.close()
        return status, returned_headers, answer

    def post(self, payload, **kwargs):
        return self.request('POST', {'slsmassnotifyserver_csrf': self.token, 'payload': payload}, **kwargs)

    def test_admin_scope_precedes_actions_and_images(self):
        for account in ['missing', 'sender', 'portal', 'control']:
            for method, data, path in [('GET', None, '/enterprise'),
                                       ('POST', {'slsmassnotifyserver_csrf': self.token, 'payload': '{}'}, '/enterprise'),
                                       ('GET', None, '/enterprise?image_id=img_' + 'a' * 64)]:
                self.assertEqual(self.request(method, data, account, path)[0], 403)

    def test_real_csrf_token_and_missing_or_other_session_denial(self):
        self.assertEqual(len(self.token), 64)
        for token in ['', 'wrong', 'a' * 64]:
            status, _, body = self.request('POST', {'slsmassnotifyserver_csrf': token, 'payload': '{}'})
            self.assertEqual(status, 403)
            self.assertFalse(json.loads(body)['success'])
        self.assertEqual(self.post('{}', cookie=False)[0], 403)

    def test_structured_request_bounds_and_allowed_actions(self):
        for payload in ['{', '[]', 'null', 'false', '"scalar"']:
            self.assertEqual(self.post(payload)[0] in (400, 422), True)
        self.assertEqual(self.post(' ' * 2097153)[0], 413)
        for payload in [{'action': 'unknown', 'input': {}}, {'action': [], 'input': {}},
                        {'action': 'danger_begin', 'input': {}, 'actor': 'pbx:other'}]:
            status, _, body = self.post(json.dumps(payload))
            self.assertEqual(status, 422)
            self.assertFalse(json.loads(body)['success'])

    def test_method_and_private_image_guards(self):
        for method in ['PUT', 'PATCH', 'DELETE']:
            status, headers, _ = self.request(method)
            self.assertEqual(status, 405)
            self.assertEqual(headers['Allow'], 'GET, POST')
        for image in ['image_id[]=bad', 'image_id=../../etc/passwd', 'image_id=img_' + 'a' * 64]:
            self.assertEqual(self.request(path='/enterprise?' + image)[0], 404)
        status, headers, _ = self.request(path='/enterprise?action=integrations_door')
        self.assertEqual(status, 200)
        self.assertIn('no-store', headers['Cache-Control'])
        self.assertEqual(headers['X-Content-Type-Options'], 'nosniff')

    def test_actual_warning_rejects_early_acceptance(self):
        payload = {'action': 'danger_begin', 'input': {'feature': 'enterprise_cluster'}}
        status, _, body = self.post(json.dumps(payload))
        self.assertEqual(status, 200)
        challenge = json.loads(body)
        self.assertEqual(challenge['delay_seconds'], 5)
        payload = {'action': 'danger_accept', 'input': {'feature': 'enterprise_cluster',
                   'challenge': challenge['challenge'], 'agree': True}}
        status, _, body = self.post(json.dumps(payload))
        self.assertEqual(status, 422)
        self.assertIn('five full seconds', json.loads(body)['message'])


if __name__ == '__main__':
    unittest.main()
