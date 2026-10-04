#!/usr/bin/env python3
"""Real publisher and authenticated desktop incident routing, wholly disposable."""
import configparser
import importlib.util
import json
import re
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
API = ROOT / 'slsmassnotifyserver/api/sipnotify/index.php'
spec = importlib.util.spec_from_file_location('incident_fixture_notify', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py')
notify = importlib.util.module_from_spec(spec)
spec.loader.exec_module(notify)
CONTEXT = {'schema': 1, 'incident_id': 'inc_' + 'a' * 32, 'sequence': 1, 'kind': 'initial', 'severity': 'critical', 'is_test': True}


class PublisherTests(unittest.TestCase):
    def test_actual_api_only_publication_preserves_context_expiry_and_recipients(self):
        with tempfile.TemporaryDirectory(prefix='sls-incident-publisher-') as folder:
            events = Path(folder) / 'journal.jsonl'
            config = configparser.ConfigParser(interpolation=None)
            config.read_dict({'api': {'events_file': str(events)}})
            notify.push_announcement(config, 'DRILL / TEST. Fixture instructions', [], api_only=True, print_results=False,
                                     desktop_targets=['alice'], timeout_seconds=45, is_test=True, incident=CONTEXT)
            record = json.loads(events.read_text())
            self.assertEqual(record['incident'], CONTEXT)
            self.assertIs(record['is_test'], True)
            self.assertEqual(record['desktop_recipients'], ['alice'])
            self.assertFalse(record['desktop_all'])
            self.assertEqual(record['display_timeout_seconds'], 45)
            self.assertTrue(record['created_at'])
            self.assertTrue(record['display_expires_at'])
            original_expiry = record['display_expires_at']
            notify.append_sipnotify_event(config, record | {'display_timeout_seconds': 600, 'display_expires_at': '2099-01-01T00:00:00Z'})
            self.assertEqual(json.loads(events.read_text())['display_expires_at'], original_expiry)

    def test_publication_context_is_explicit_strict_and_not_inferred(self):
        plain = notify.announcement_api_record('fixture', 'All clear. This says drill.', '', [], desktop_targets=['alice'])
        self.assertNotIn('incident', plain)
        self.assertIs(plain['is_test'], False)
        for mutation in ({'schema': True}, {'sequence': True}, {'sequence': 251}, {'kind': 'cancel'}, {'is_test': 'true'}, {'extra': 1}, {'incident_id': '../file'}):
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                notify.normalize_incident_context(CONTEXT | mutation)
        with self.assertRaises(ValueError):
            notify.announcement_api_record('fixture', 'message', '', [], is_test=False, incident=CONTEXT)


class DesktopIncidentEndpointTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-incident-endpoint-')
        self.directory = Path(self.temp.name)
        source = API.read_text()
        source = source.replace("dirname(__DIR__) . '/sls-mass-notify/security.php'", json.dumps(str(API.parent.parent / 'sls-mass-notify/security.php')))
        source = source.replace("dirname(__DIR__) . '/sls-mass-notify/config-crypto.php'", json.dumps(str(API.parent.parent / 'sls-mass-notify/config-crypto.php')))
        for name, relative in {'EVENTS_FILE': 'events.jsonl', 'SETTINGS_FILE': 'settings.json', 'DESKTOP_LAST_SEEN_FILE': 'seen.json', 'DESKTOP_AUTH_RATE_FILE': 'rate.json', 'DESKTOP_ACK_DIRECTORY': 'acks'}.items():
            source, count = re.subn(rf"const {name} = '[^']*';", f'const {name} = {json.dumps(str(self.directory / relative))};', source, count=1)
            self.assertEqual(count, 1)
        # Replace only FreePBX bootstrap boundary, preserving actual auth,
        # routing, request validation and response projection behavior.
        start = source.index('function desktop_incident_module()')
        end = source.index('\nfunction settings_config()', start)
        source = source[:start] + "function desktop_incident_module() { return $GLOBALS['fixture_module']; }\n" + source[end:]
        self.prefix, self.endpoint = source.split('\n$endpoint = endpoint_slug();', 1)

    def tearDown(self):
        self.temp.cleanup()

    def request(self, path='desktop/incident', username='alice', password='fixture-password', payload=None):
        setup = r'''
require_once SERVICE_FILE;
$key = random_bytes(32); $nonce = random_bytes(12);
$cipher = openssl_encrypt('fixture-password', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
$password = 'v1:' . base64_encode($nonce . $tag . $cipher);
file_put_contents(SETTINGS_FILE, json_encode(['desktop_auth_key' => base64_encode($key), 'desktop_clients' => [
 ['enabled' => '1', 'username' => 'alice', 'client_id' => 'cli_alice', 'password_enc' => $password],
 ['enabled' => '1', 'username' => 'bob', 'client_id' => 'cli_bob', 'password_enc' => $password]
]]));
chmod(SETTINGS_FILE, 0640);
$template = \SLS\MassNotify\IncidentConfig::template(['id' => 'tpl_' . str_repeat('a', 24), 'name' => 'Fixture', 'title' => 'Fixture', 'message' => 'Fixture',
 'delivery' => ['desktop_clients' => ['alice']], 'roster' => [['id' => 'alice_person', 'name' => 'Alice', 'location' => 'Office', 'desktop_username' => 'alice'],
 ['id' => 'private_person', 'name' => 'PRIVATE OTHER PERSON', 'location' => 'Private location', 'desktop_username' => '']]]);
$service = new \SLS\MassNotify\IncidentService(new \SLS\MassNotify\IncidentStore(INCIDENT_DIR), static fn() => $template,
 static fn(array $d): array => $d, static fn(): array => ['success' => true, 'job_id' => 'job_' . str_repeat('a', 32)], static fn(): array => []);
$incident = $service->start(['template_id' => $template['id'], 'request_id' => str_repeat('1', 32), 'fields' => [], 'is_test' => true], ['identity' => 'Fixture', 'source' => 'fixture']);
$GLOBALS['fixture_module'] = new class($service) {
 private $service; public function __construct($service) { $this->service = $service; }
 private function assertAuthentication($username, $authentication) {
  $settings = json_decode(file_get_contents(SETTINGS_FILE), true);
  foreach ($settings['desktop_clients'] as $client) {
   if ($client['username'] === $username && $authentication === ['client_id' => $client['client_id'], 'credential_fingerprint' => hash('sha256', $client['password_enc'])]) { return; }
  }
  throw new RuntimeException('API lost the authenticated desktop identity.');
 }

 public function getIncidentForDesktop($id, $username, $authentication) { try { $this->assertAuthentication($username, $authentication); return $this->service->forDesktop($id, $username); } catch (Throwable $e) { return ['success' => false, 'message' => 'Incident is not available to this identity.']; } }
 public function respondToIncident($id, $username, $input, $authentication) { try { $this->assertAuthentication($username, $authentication); return $this->service->respond($id, $input, ['identity' => $username, 'source' => 'desktop'], $username); } catch (Throwable $e) { return ['success' => false, 'message' => 'Response is not permitted.']; } }
};
'''.replace('SERVICE_FILE', json.dumps(str(ROOT / 'slsmassnotifyserver/IncidentService.php'))).replace('INCIDENT_DIR', json.dumps(str(self.directory / 'incidents')))
        import hashlib
        incident_id = 'inc_' + hashlib.sha256(('1' * 32).encode()).hexdigest()[:32]
        server = {'REMOTE_ADDR': '192.0.2.20', 'HTTPS': 'on', 'PHP_AUTH_USER': username, 'PHP_AUTH_PW': password,
                  'REQUEST_URI': '/api/sipnotify/' + path, 'REQUEST_METHOD': 'POST' if payload is not None else 'GET', 'CONTENT_TYPE': 'application/json'}
        setup += '\n$_SERVER = json_decode(' + json.dumps(json.dumps(server)) + ', true);\n'
        setup += '\n$_GET = ' + "['incident_id' => " + json.dumps(incident_id) + '];\n'
        endpoint = self.endpoint
        if payload is not None:
            body = self.directory / 'request.json'
            body.write_text(json.dumps({'incident_id': incident_id} | payload))
            endpoint = endpoint.replace("'php://input'", json.dumps(str(body)))
        script = self.directory / 'endpoint.php'
        script.write_text(self.prefix + setup + '\n$endpoint = endpoint_slug();' + endpoint)
        result = subprocess.run(['php', str(script)], text=True, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def test_authenticated_self_projection_omits_other_people(self):
        result = self.request()
        self.assertTrue(result['ok'])
        self.assertEqual(result['person']['id'], 'alice_person')
        self.assertNotIn('PRIVATE OTHER PERSON', json.dumps(result))
        self.assertNotIn('template', result)
        self.assertNotIn('timeline', result)

    def test_wrong_password_and_unrelated_authenticated_client_are_rejected(self):
        self.assertEqual(self.request(password='wrong')['error'], 'unauthorized')
        self.assertFalse(self.request(username='bob')['ok'])

    def test_response_uses_authenticated_identity_and_rejects_spoofed_body_identity(self):
        body = {'request_id': '2' * 32, 'response': 'safe', 'note': 'At assembly point'}
        result = self.request('desktop/incident/respond', payload=body)
        self.assertTrue(result['ok'])
        self.assertEqual(result['response']['actor']['identity'], 'alice')
        self.assertEqual(result['response']['source'], 'human_desktop_response')
        spoofed = self.request('desktop/incident/respond', payload=body | {'username': 'bob'})
        self.assertEqual(spoofed['error'], 'invalid_incident_response')


if __name__ == '__main__':
    unittest.main()
