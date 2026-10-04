#!/usr/bin/env python3
"""Actual Control API HTTP routing with real enterprise template authorization and inert delivery."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
isolated = (os.environ.get('SLS_TEST_NAMESPACE') == 'entered'
            and os.environ.get('SLS_TEST_PARENT_NET_NS') not in (None, os.readlink('/proc/self/ns/net')))
if not isolated:
    raise SystemExit('Use tools/run_isolated_tests.sh; no HTTP listener was started.')
spec = importlib.util.spec_from_file_location('enterprise_api_http_helper', ROOT / 'tools/test_api_security.py')
helper_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper_module)


class EnterpriseControlHttpTests(unittest.TestCase):
    def setUp(self):
        self.helper = helper_module.ApiSecurityTests()
        self.helper.setUp()
        self.named = self.helper.issue(scopes=('read', 'send'), extensions=['1000'])
        self.read = self.helper.issue(scopes=('read',), extensions=['1000'])
        self.send = self.helper.issue(scopes=('send',), extensions=['1000'])
        self.template_id = 'tpl_' + 'a' * 24
        self.offsite_id = 'tpl_' + 'b' * 24
        self.settings = {
            'control_api': {'enabled': True, 'rate_limit_enabled': False,
                            'api_key': 'legacy-example-key-1234567890',
                            'credentials': [self.named['credential'], self.read['credential'], self.send['credential']]},
            'enterprise_operations': {'schema': 1, 'enabled': True},
            'enterprise_identity': {'enabled': False, 'providers': [{'client_secret': 'never-export-this'}]},
            'incident_workflows': {'schema': 1, 'templates': [
                {'id': self.template_id, 'name': 'Allowed template', 'title': 'Fixture', 'message': 'Isolated fixture',
                 'delivery': {'extensions': ['1000']},
                 'roster': [{'id': 'private-person', 'name': 'Private roster HTTP name', 'location': 'Private room'}]},
                {'id': self.offsite_id, 'name': 'Off-site hidden name', 'title': 'Other', 'message': 'Private wording',
                 'delivery': {'extensions': ['2000']}}]}}
        self.write()
        self.helper.start_api()
        directory = self.helper.directory
        fixture = (ROOT / 'tools/test_enterprise_operator_api.php').read_text().split('\ntry{\n    $fixture=')[0]
        fixture = fixture.replace("__DIR__.'/../slsmassnotifyserver/", json.dumps(str(ROOT / 'slsmassnotifyserver')) + ".'/")
        first = fixture.index('$base=sys_get_temp_dir()')
        end = fixture.index("define('ENTERPRISE_OPERATOR_API_FIXTURE'", first)
        state = directory / 'enterprise-state'
        fixture = fixture[:first] + '$base=' + json.dumps(str(state)) + ';if(!is_dir($base)){mkdir($base,0700);}\n' + fixture[end:]
        (directory / 'enterprise-module.php').write_text(fixture)
        source = (directory / 'index.php').read_text()
        first = source.index('function freepbx_module(')
        end = source.index('$config = config();', first)
        source = source[:first] + '''function freepbx_module() {
            require_once ''' + json.dumps(str(directory / 'enterprise-module.php')) + ''';
            $module=new EnterpriseOperatorApiFixture();$module->settings=config();return $module;
        }
        ''' + source[end:]
        (directory / 'index.php').write_text(source)

    def tearDown(self):
        self.helper.tearDown()

    def write(self):
        self.helper.write_config(**self.settings)

    def request(self, token=None, body=None, resource=None):
        return self.helper.request(token=token, body=body, path='/' if resource is None else '/?resource=' + resource)

    def test_scoped_metadata_and_revocable_template_launch(self):
        status, body = self.request(self.named['secret'], resource='incident_templates')
        self.assertEqual(status, 200, body)
        self.assertEqual([row['id'] for row in body['templates']], [self.template_id])
        self.assertNotIn('Private roster HTTP name', json.dumps(body))
        self.assertNotIn('Off-site hidden name', json.dumps(body))
        payload = {'action': 'enterprise_template_start', 'template_id': self.template_id,
                   'request_id': '1' * 32, 'fields': {}, 'is_test': True}
        status, launched = self.request(self.named['secret'], payload)
        self.assertEqual(status, 200, launched)
        self.assertTrue(launched['is_test'])
        self.assertNotIn('Private roster HTTP name', json.dumps(launched))
        self.assertNotIn('template', launched)
        self.assertEqual(self.request(self.named['secret'], payload)[1]['id'], launched['id'])
        status, denied = self.request(self.named['secret'], {**payload, 'template_id': self.offsite_id, 'request_id': '2' * 32})
        self.assertEqual(status, 403, denied)
        self.assertEqual(self.request(self.named['secret'], {**payload, 'actor': 'pbx:owner'})[0], 400)
        self.settings['control_api']['credentials'][0]['revoked_at'] = '2026-10-03T00:00:00Z'
        self.write()
        self.assertEqual(self.request(self.named['secret'], resource='incident_templates')[0], 401)
        self.assertEqual(self.request(self.named['secret'], payload)[0], 401)

    def test_named_scopes_disabled_features_and_no_human_impersonation(self):
        self.assertEqual(self.request(self.read['secret'], resource='incident_templates')[0], 200)
        self.assertEqual(self.request(self.send['secret'], resource='incident_templates')[0], 403)
        payload = {'action': 'enterprise_template_start', 'template_id': self.template_id,
                   'request_id': '3' * 32, 'fields': {}, 'is_test': True}
        self.assertEqual(self.request(self.read['secret'], payload)[0], 403)
        self.assertEqual(self.request(self.settings['control_api']['api_key'], payload)[0], 403)
        self.assertEqual(self.request(self.settings['control_api']['api_key'], resource='enterprise_capabilities')[0], 403)
        for action in ['approval_approve', 'approval_submit', 'approval_reject', 'drill_start', 'drill_review', 'operations_save']:
            self.assertEqual(self.request(self.named['secret'], {'action': action, 'operator_id': 'pbx:owner'})[0], 400)
        status, discovery = self.request(self.named['secret'], resource='enterprise_capabilities')
        self.assertEqual(status, 200)
        self.assertTrue(discovery['features']['operations'])
        self.assertNotIn('never-export-this', json.dumps(discovery))
        self.settings['enterprise_operations']['enabled'] = False
        self.write()
        status, templates = self.request(self.named['secret'], resource='incident_templates')
        self.assertEqual(status, 200)
        self.assertFalse(templates['enabled'])
        self.assertEqual(templates['templates'], [])
        self.assertEqual(self.request(self.named['secret'], payload)[0], 403)


if __name__ == '__main__':
    subprocess.run(['ip', 'link', 'set', 'dev', 'lo', 'up'], check=True, capture_output=True)
    unittest.main()
