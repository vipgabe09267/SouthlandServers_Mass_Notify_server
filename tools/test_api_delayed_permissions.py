#!/usr/bin/env python3
"""Revoke credentials/change audiences between selection and real guard execution."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
GUARD = ROOT / 'slsmassnotifyserver/ApiPermissionGuards.php'


class DelayedPermissions(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='sls-delayed-permissions-')
        self.path = Path(self.temporary.name)

    def tearDown(self):
        self.temporary.cleanup()

    def php(self, source):
        fixture = self.path / 'fixture.php'
        fixture.write_text('<?php\nrequire ' + json.dumps(str(GUARD)) + ';\n' + source)
        result = subprocess.run(['php', str(fixture)], capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def data(self):
        return self.php('''
          $issued=\\SLS\\MassNotify\\ApiSecurity::issue(['name'=>'Tests','scopes'=>['test','send'],'audience'=>[
            'unrestricted'=>false,'extensions'=>['1000'],'voice_recipient_ids'=>['voice_a'],'nws_zone_ids'=>['zone_a']]]);
          $settings=['enabled'=>true,'control_api'=>['enabled'=>true,'credentials'=>[$issued['credential']]],
            'nws_zones'=>[['id'=>'zone_a','extensions'=>['1000'],'desktop_clients'=>['alice']]],
            'desktop_clients'=>[['client_id'=>'device_a','username'=>'alice','enabled'=>true]]];
          $context=\\SLS\\MassNotify\\ApiPermissionGuards::weatherContext($settings,$issued['credential'],['zone_a'],['1000'],['alice']);
          echo json_encode(['settings'=>$settings,'context'=>$context]);
        ''')

    def runtime(self, data):
        config = self.path / 'settings.config'
        config.write_text(json.dumps(data['settings']))
        original = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_api_test_guard.php'
        helper = self.path / 'guard.php'
        source = original.read_text().replace("'/var/www/html/admin/modules/slsmassnotifyserver/ApiPermissionGuards.php'", json.dumps(str(GUARD)))
        source = source.replace("__DIR__ . '/sls_config_crypto.php'", json.dumps(str(ROOT/'slsmassnotifyserver/api/sls-mass-notify/config-crypto.php')))
        helper.write_text(source)
        environment = dict(os.environ, SLS_API_TEST_CONTEXT=json.dumps(data['context']))
        result = subprocess.run(['php', str(helper), str(config)], env=environment, text=True, capture_output=True, timeout=8)
        return result, config, helper, environment

    def test_runtime_weather_guard_filters_changes_and_revocation_without_additions(self):
        data = self.data()
        result, _, _, _ = self.runtime(data)
        self.assertEqual(result.stdout, '1000\nalice\n0\n')
        data['settings']['nws_zones'][0]['extensions'] = ['1000', '1001']
        self.assertEqual(self.runtime(data)[0].stdout, '1000\nalice\n0\n')
        data['settings']['nws_zones'][0]['extensions'] = ['1001']
        self.assertEqual(self.runtime(data)[0].stdout, '\nalice\n1\n')
        data['settings']['desktop_clients'][0]['client_id'] = 'replacement'
        self.assertEqual(self.runtime(data)[0].stdout, '\n\n2\n')
        data['settings']['control_api']['credentials'][0]['revoked_at'] = '2026-09-22T00:00:00+00:00'
        self.assertEqual(self.runtime(data)[0].stdout, '\n\n2\n')

    def test_weather_scope_removal_and_context_corruption_fail_closed(self):
        data = self.data()
        data['settings']['control_api']['credentials'][0]['scopes'] = ['read']
        self.assertEqual(self.runtime(data)[0].stdout, '\n\n2\n')
        data['context']['credential_id'] = 'legacy'
        result = self.runtime(data)[0]
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout, '')
        self.assertIn('No recipients were authorized', result.stderr)

    def test_real_shell_guard_clears_revoked_recipients_and_rejects_storage_fault(self):
        data = self.data()
        data['settings']['control_api']['credentials'][0]['revoked_at'] = '2026-09-22T00:00:00+00:00'
        _, config, helper, environment = self.runtime(data)
        source = (ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_test.sh').read_text()
        function = source[source.index('refresh_api_test_permissions() {'):source.index('claim_test_cooldown() {')]
        function = function.replace('/usr/local/bin/sls_mass_notify/sls_api_test_guard.php', str(helper))
        body = function + '''
          NWS_ALERT_RECIPIENTS=(1000);NWS_DESKTOP_CLIENTS=(alice);API_TEST_REMOVED_RECIPIENTS=0
          refresh_api_test_permissions
          result=$?
          printf '%s:%s:%s:%s' "$result" "${#NWS_ALERT_RECIPIENTS[@]}" "${#NWS_DESKTOP_CLIENTS[@]}" "$API_TEST_REMOVED_RECIPIENTS"
        '''
        environment['CONFIG_JSON_FILE'] = str(config)
        result = subprocess.run(['bash'], input=body, env=environment, text=True, capture_output=True, timeout=8)
        self.assertEqual(result.stdout, '0:0:0:1')
        config.write_text('{broken')
        result = subprocess.run(['bash'], input=body, env=environment, text=True, capture_output=True, timeout=8)
        self.assertEqual(result.stdout, '1:0:0:0')

    def test_prepared_audio_intersects_original_phone_and_external_ids(self):
        result = self.php('''
          $issued=\\SLS\\MassNotify\\ApiSecurity::issue(['name'=>'Audio','scopes'=>['send'],'audience'=>[
            'unrestricted'=>false,'extensions'=>['1001','1002'],'voice_recipient_ids'=>['voice_b','voice_c']]]);
          $settings=['control_api'=>['enabled'=>true,'credentials'=>[$issued['credential']]]];
          $context=['api_credential_id'=>$issued['credential']['id']];
          $phones=['1000','1001'];$voice=[['id'=>'voice_a','number'=>'original_a'],['id'=>'voice_b','number'=>'original_b']];
          $allowed=\\SLS\\MassNotify\\ApiPermissionGuards::audio($settings,$context,$phones,$voice);
          $settings['control_api']['credentials'][0]['revoked_at']=gmdate('c');
          $revoked=\\SLS\\MassNotify\\ApiPermissionGuards::audio($settings,$context,$phones,$voice);
          echo json_encode([$allowed,$revoked]);
        ''')
        self.assertEqual(result[0]['extensions'], ['1001'])
        self.assertEqual(result[0]['outbound_targets'], [{'id': 'voice_b', 'number': 'original_b'}])
        self.assertEqual(result[0]['removed'], {'phones': ['1000'], 'voice_recipient_ids': ['voice_a']})
        self.assertEqual(result[1]['extensions'], [])
        self.assertEqual(result[1]['outbound_targets'], [])


if __name__ == '__main__':
    unittest.main()
