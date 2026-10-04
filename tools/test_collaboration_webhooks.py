#!/usr/bin/env python3
"""Isolated Teams/Slack payload, rejection, uncertainty and route-binding tests."""
import copy
import hashlib
import importlib.util
import contextlib
import io
import json
import os
import socket
import subprocess
import re
from html.parser import HTMLParser
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('collaboration_destinations', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_notification_destinations.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class CollaborationWebhooks(unittest.TestCase):
    def setUp(self):
        self.rows = [{'id': adapter, 'name': adapter, 'enabled': '1', 'url': 'https://hooks.example.com/' + adapter,
                      'payload_format': adapter, 'bearer_token': 'must-not-forward', 'signing_secret': 'must-not-forward'}
                     for adapter in ('slack', 'teams_workflow')]
        self.config = {'generic_webhooks': copy.deepcopy(self.rows), 'announcement_webhooks': copy.deepcopy(self.rows)}
        self.calls = []

    def transport(self, *args, **kwargs):
        self.calls.append((args, kwargs))
        return 200, ''

    def dispatch(self, **kwargs):
        return m.dispatch_webhook_destinations(self.config, 'Warning <@U123>', 'Take shelter.\nRemain inside.', 'Tornado Warning', 'Extreme',
                                              [('Zone', 'TXZ163')], '2026-09-20T12:00:00Z', 'nws', 'event-123',
                                              live=True, transport=self.transport, enforce_wall_clock=False, **kwargs)

    def test_weather_slack_teams_shapes_identity_and_no_receiver_auth(self):
        results = self.dispatch()
        self.assertEqual([r['status'] for r in results], ['accepted', 'accepted'])
        for args, kwargs in self.calls:
            self.assertEqual(args[5], 'event-123')
            self.assertEqual(kwargs, {})
        slack = self.calls[0][0][2]
        self.assertFalse(slack['mrkdwn'])
        self.assertFalse(slack['link_names'])
        self.assertNotIn('<@', slack['text'])
        self.assertEqual(slack['blocks'][0]['text']['type'], 'plain_text')
        self.assertIn('Take shelter.\nRemain inside.', slack['blocks'][0]['text']['text'])
        teams = self.calls[1][0][2]
        self.assertEqual(teams['type'], 'message')
        attachment = teams['attachments'][0]
        self.assertEqual(attachment['contentType'], 'application/vnd.microsoft.card.adaptive')
        self.assertIsNone(attachment['contentUrl'])
        card = attachment['content']
        self.assertEqual(card['version'], '1.2')
        self.assertTrue(all(block['type'] == 'RichTextBlock' and block['inlines'][0]['type'] == 'TextRun' for block in card['body']))
        self.assertNotIn('msteams', card)
        self.assertNotIn('actions', card)
        self.assertIn('Severity: Extreme', json.dumps(card))
        self.assertNotIn('must-not-forward', json.dumps([(args[2], kwargs) for args, kwargs in self.calls]))

    def test_alert_markup_remains_literal_plain_text(self):
        text = '<!channel> <@U1> <at>Everyone</at> [click](https://evil.invalid) **bold**'
        for adapter in ('slack', 'teams_workflow'):
            payload = m.build_collaboration_payload(adapter, text, text)
            if adapter == 'slack':
                self.assertEqual(payload['blocks'][0]['text']['text'], text + '\n\n' + text)
                self.assertNotIn('<', payload['text'])
            else:
                self.assertEqual(payload['attachments'][0]['content']['body'][0]['inlines'][0]['text'], text)
                self.assertNotIn('entities', json.dumps(payload))

    def test_long_body_complete_or_rejected_before_any_post(self):
        body = 'Emergency instructions ' * 250
        slack = m.build_collaboration_payload('slack', 'Title', body)
        self.assertGreater(len(slack['blocks']), 1)
        self.assertEqual(''.join(b['text']['text'] for b in slack['blocks']), 'Title\n\n' + body.strip())
        self.assertTrue(all(len(b['text']['text']) <= 3000 for b in slack['blocks']))
        self.config['generic_webhooks'] = self.rows
        result = m.dispatch_webhook_destinations(self.config, 'Title', '🚨' * 7000, source='nws', live=True,
                                                transport=self.transport, enforce_wall_clock=False)
        self.assertEqual([r['error'] for r in result], ['payload_too_large'] * 2)
        self.assertEqual(self.calls, [])
        self.assertTrue(all(r['attempts'] == 0 and not r['retryable'] for r in result))

    def test_unknown_adapter_fails_closed_without_native_downgrade(self):
        for value in ('teams_legacy', '', None, {}, []):
            self.config['generic_webhooks'] = [dict(self.rows[0], payload_format=value)]
            self.assertEqual(self.dispatch()[0]['error'], 'invalid_payload_format')
        self.assertEqual(self.calls, [])

    def test_announcement_selects_only_frozen_provider_routes(self):
        fingerprints = {row['id']: m.destination_fingerprint(row) for row in self.rows}
        def send():
            return m.dispatch_announcement_webhooks(self.config, 'Original title', 'Original message', event_id='announcement-fixed',
                timestamp='2026-09-20T12:00:00Z', destination_ids=['slack'], expected_fingerprints=fingerprints,
                live=True, transport=self.transport, enforce_wall_clock=False)
        self.assertEqual(send()[0]['status'], 'accepted')
        self.assertEqual(len(self.calls), 1)
        self.assertEqual(self.calls[0][0][5], 'announcement-fixed')
        self.assertIn('Original message', json.dumps(self.calls[0][0][2]))
        self.config['announcement_webhooks'][0]['payload_format'] = 'teams_workflow'
        self.assertEqual(send()[0]['error'], 'destination_changed')
        self.assertEqual(len(self.calls), 1)

    def test_weather_retry_format_change_revokes_frozen_route(self):
        original = m.external_destination_fingerprints(self.config)
        record = {'webhook_pending': ['generic:slack'], 'routing_snapshot': {'webhook_fingerprints': original}}
        group = {'generic_webhook_ids': ['slack', 'teams_workflow']}
        self.assertEqual(m._remaining_external_routes(record, group, self.config)[1], ['generic:slack'])
        self.config['generic_webhooks'][0]['payload_format'] = 'teams_workflow'
        self.assertEqual(m._remaining_external_routes(record, group, self.config)[1], [])

    def test_incident_links_use_the_configured_origin_and_exact_typed_identity(self):
        incident_id = 'inc_' + 'a' * 32
        self.config.update(public_pbx_host='pbx.example.org', control_api={'base_url': 'https://pbx.example.org:8443/api/sls-mass-notify'})
        url = 'https://pbx.example.org:8443/admin/config.php?display=slsmassnotifyserver_incidents&incident_id=' + incident_id
        results = m.dispatch_announcement_webhooks(self.config, 'Incident <@U123>', 'Go to <https://evil.invalid|here> & stay safe.',
            timestamp='2026-09-27T12:00:00Z', event_id='announcement-fixed', destination_ids=[r['id'] for r in self.rows],
            expected_fingerprints={r['id']: m.destination_fingerprint(r) for r in self.rows}, incident_id=incident_id,
            live=True, transport=self.transport, enforce_wall_clock=False)
        self.assertEqual([r['status'] for r in results], ['accepted', 'accepted'])
        slack = self.calls[0][0][2]
        self.assertEqual(slack['blocks'][-1], {'type': 'context', 'elements': [{'type': 'mrkdwn',
            'text': '<' + url.replace('&', '&amp;') + '|Open incident in SLS>', 'verbatim': True}]})
        self.assertTrue(all(b['text']['type'] == 'plain_text' for b in slack['blocks'][:-1]))
        self.assertIn('<https://evil.invalid|here>', slack['blocks'][0]['text']['text'])
        self.assertIn(incident_id, slack['blocks'][0]['text']['text'])
        card = self.calls[1][0][2]['attachments'][0]['content']
        self.assertEqual(card['actions'], [{'type': 'Action.OpenUrl', 'title': 'Open incident in SLS', 'url': url}])
        self.assertNotIn('Action.Submit', json.dumps(card))
        self.assertFalse(slack['unfurl_links'])

    def test_ordinary_weather_and_wording_cannot_create_incident_links(self):
        for adapter in ('slack', 'teams_workflow'):
            payload = m.build_collaboration_payload(adapter, 'All clear inc_' + 'a' * 32, 'Incident https://evil.invalid',
                fields=[('Incident ID', 'inc_' + 'a' * 32)], config={'public_pbx_host': 'pbx.example.org'})
            if adapter == 'slack':
                self.assertTrue(all(b['type'] == 'section' and b['text']['type'] == 'plain_text' for b in payload['blocks']))
            else:
                self.assertNotIn('actions', payload['attachments'][0]['content'])

    def test_real_cli_environment_reaches_the_provider_payload(self):
        incident_id = 'inc_' + 'c' * 32
        self.config.update(public_pbx_host='pbx.example.org', control_api={'base_url':'https://pbx.example.org/api/sls-mass-notify'})
        sent = []
        def deliver(row, payload, *unused):
            sent.append(payload)
            return {'id':row['id'], 'status':'accepted'}
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'fixture.config'; path.write_text(json.dumps(self.config))
            environment = {'SLS_NOTIFICATION_LIVE':'1', 'SLS_DESTINATION_SOURCE':'dashboard', 'SLS_DESTINATION_IDS':'teams_workflow',
                'SLS_DESTINATION_SUBJECT':'Fixture', 'SLS_DESTINATION_BODY':'Full instructions', 'SLS_DESTINATION_TIME':'2026-09-27T12:00:00Z',
                'SLS_DESTINATION_EVENT_ID':'announcement-fixed', 'SLS_DESTINATION_INCIDENT_ID':incident_id,
                'SLS_DESTINATION_INCIDENT_JSON':json.dumps({'schema':1, 'incident_id':incident_id, 'sequence':2,
                    'kind':'all_clear', 'severity':'critical', 'is_test':True}),
                'SLS_DESTINATION_FINGERPRINTS_JSON':json.dumps({r['id']:m.destination_fingerprint(r) for r in self.rows})}
            with mock.patch.dict(os.environ, environment, clear=True), mock.patch.object(sys, 'argv', [str(m.__file__),str(path),'--announcement']), \
                    mock.patch.object(m, '_deliver', side_effect=deliver), contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(m.main(), 0)
        self.assertEqual(len(sent), 1)
        self.assertTrue(sent[0]['attachments'][0]['content']['actions'][0]['url'].endswith('&incident_id=' + incident_id))
        text = json.dumps(sent[0])
        self.assertIn('Severity: Critical', text); self.assertIn('Event: Incident all-clear', text)
        self.assertIn('Drill / test: Yes', text); self.assertIn('Incident sequence: 2', text)
        self.assertNotIn('Severity: Information', text)

    def test_incident_labels_are_typed_and_invalid_context_never_posts(self):
        context = {'schema':1, 'incident_id':'inc_' + 'd' * 32, 'sequence':1, 'kind':'initial', 'severity':'critical', 'is_test':False}
        for kind, label in (('initial','Incident'),('update','Incident update'),('all_clear','Incident all-clear'),('escalation','Incident escalation')):
            payload = m.build_collaboration_payload('slack', 'Fixture', 'Instructions', event='Announcement', severity='Information',
                incident_context=dict(context, kind=kind))
            text = payload['blocks'][0]['text']['text']
            self.assertIn('Event: ' + label, text); self.assertIn('Severity: Critical', text); self.assertIn('Drill / test: No', text)
        for mutation in ({'schema':True}, {'sequence':True}, {'sequence':251}, {'kind':'cancel'}, {'is_test':'true'}, {'extra':1}, {'incident_id':'../file'}, {'severity':[]}):
            results = m.dispatch_announcement_webhooks(self.config, 'Fixture', 'Instructions', event_id='fixed', timestamp='2026-09-27T12:00:00Z',
                destination_ids=[r['id'] for r in self.rows], expected_fingerprints={r['id']:m.destination_fingerprint(r) for r in self.rows},
                incident_context=context | mutation, live=True, transport=self.transport, enforce_wall_clock=False)
            self.assertEqual([r['error'] for r in results], ['invalid_incident_context'] * 2)
        self.assertEqual(self.calls, [])
        with self.assertRaises(m.DestinationError):
            m.build_collaboration_payload('teams_workflow','Fixture','Instructions',incident_id='inc_' + 'e' * 32,incident_context=context)

    def test_incident_link_rejects_unsafe_or_mismatched_address_without_losing_message(self):
        incident_id = 'inc_' + 'b' * 32
        for base in ('http://pbx.example.org/api/sls-mass-notify', 'https://other.example.org/api/sls-mass-notify',
                'https://user:secret@pbx.example.org/api/sls-mass-notify', 'https://pbx.example.org:0/api/sls-mass-notify',
                'https://pbx.example.org:65536/api/sls-mass-notify', 'https://pbx.example.org/api/sls-mass-notify?x=1',
                'https://pbx.example.org/api/sls-mass-notify#x', 'https://pbx.example.org/api/sls-mass-notify\n',
                'https://pbx.example.org\\@evil.invalid/api/sls-mass-notify', 'https://pbx.example.org./api/sls-mass-notify', None, {}):
            with self.subTest(base=base):
                config = {'public_pbx_host': 'pbx.example.org', 'control_api': {'base_url': base}}
                self.assertEqual(m.incident_operator_link(config, incident_id), '')
                card = m.build_collaboration_payload('teams_workflow', 'Title', 'Complete safety instructions',
                    incident_id=incident_id, config=config)['attachments'][0]['content']
                self.assertNotIn('actions', card)
                self.assertIn('Complete safety instructions', json.dumps(card))
        for host in ('pbx.example.org><@U123>', '', None, 'x' * 254, 'pbx.example.org\n'):
            self.assertEqual(m.incident_operator_link({'public_pbx_host': host}, incident_id), '')
        good = {'public_pbx_host': 'PBX.EXAMPLE.ORG', 'control_api': {'base_url': 'https://pbx.example.org:443/api/sls-mass-notify/'}}
        self.assertTrue(m.incident_operator_link(good, incident_id).startswith('https://pbx.example.org/admin/'))
        for value in ('inc_' + 'a' * 31, 'inc_' + 'a' * 32 + '\n', 'https://evil.invalid', None, {}, []):
            with self.assertRaises(m.DestinationError) as error:
                m.build_collaboration_payload('slack', 'Title', 'Body', incident_id=value, config=good)
            self.assertEqual(error.exception.code, 'invalid_incident_context')

    def test_native_fingerprint_compatibility(self):
        row = {'url': 'https://hooks.example.com/receiver'}
        self.assertEqual(m.destination_fingerprint(row), hashlib.sha256(row['url'].encode()).hexdigest())
        self.assertEqual(m.destination_fingerprint(dict(row, payload_format='native')), m.destination_fingerprint(row))
        self.assertNotEqual(m.destination_fingerprint(dict(row, payload_format='slack')), m.destination_fingerprint(row))

    def test_provider_errors_do_not_expose_secret_or_repeat_uncertain_posts(self):
        for code, expected in ((403, 'failed'), (404, 'failed'), (500, 'uncertain')):
            with self.subTest(code=code):
                transport = mock.Mock(return_value=(code, ''))
                result = m.dispatch_webhook_destinations(self.config, 'Title', 'Message', source='nws', live=True,
                    transport=transport, enforce_wall_clock=False)
                self.assertEqual(transport.call_count, 2)
                self.assertTrue(all(row['status'] == expected for row in result))
                self.assertNotIn('https://', json.dumps(result))
        transport = mock.Mock(side_effect=m.DestinationError('request_timeout', submission_possible=True))
        result = m.dispatch_webhook_destinations(self.config, 'Title', 'Message', source='nws', live=True,
            transport=transport, enforce_wall_clock=False)
        self.assertEqual(transport.call_count, 2)
        self.assertTrue(all(row['status'] == 'uncertain' and row['attempts'] == 1 for row in result))

    def test_private_dns_and_redirects_use_existing_transport_safeguards(self):
        private = lambda *_a, **_kw: [(socket.AF_INET, socket.SOCK_STREAM, 6, '', ('127.0.0.1', 443))]
        with mock.patch.object(m, '_PinnedHTTPSConnection', side_effect=AssertionError('connection must not be opened')):
            result = m.dispatch_webhook_destinations(self.config, 'Title', 'Message', source='nws', live=True,
                resolver=private, enforce_wall_clock=False)
        self.assertTrue(all(row['error'] == 'private_address_blocked' for row in result))
        transport = mock.Mock(return_value=(302, ''))
        result = m.dispatch_webhook_destinations(self.config, 'Title', 'Message', source='nws', live=True,
            transport=transport, enforce_wall_clock=False)
        self.assertTrue(all(row['error'] == 'redirect_blocked' for row in result))
        self.assertEqual(transport.call_count, 2)

    def test_rendered_editor_preserves_formats_secrets_and_sparse_row_names(self):
        php = r'''<?php
function _($value) { return $value; }
function load_view($path, $variables = []) { return ''; }
require_once 'slsmassnotifyserver/api/sls-mass-notify/security.php';
require_once 'slsmassnotifyserver/api/sls-mass-notify/sms/Service.php';
set_error_handler(static function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$settings = ['generic_webhooks'=>[['id'=>'slack','name'=>'Slack','enabled'=>'1','url'=>'https://secret.invalid/DO-NOT-RENDER','payload_format'=>'slack']], 'announcement_webhooks'=>[['id'=>'teams','name'=>'Teams','enabled'=>'1','url'=>'https://secret.invalid/DO-NOT-RENDER','payload_format'=>'teams_workflow']]];
include 'slsmassnotifyserver/views/other_settings.php';
'''
        process = subprocess.run(['php', '-n', '-d', 'extension=mbstring'], input=php, text=True, cwd=ROOT, capture_output=True)
        self.assertEqual(process.returncode, 0, process.stdout[-4000:] + process.stderr)
        rendered = process.stdout
        self.assertNotIn('Fatal error', rendered)
        self.assertNotIn('DO-NOT-RENDER', rendered)
        class Selects(HTMLParser):
            def __init__(self):
                super().__init__()
                self.current = None
                self.selected = {}
            def handle_starttag(self, tag, attrs):
                attrs = dict(attrs)
                if tag == 'select':
                    self.current = attrs.get('name')
                elif tag == 'option' and self.current and 'selected' in attrs:
                    self.selected[self.current] = attrs.get('value')
            def handle_endtag(self, tag):
                if tag == 'select':
                    self.current = None
        parser = Selects()
        parser.feed(rendered)
        self.assertEqual(parser.selected['generic_webhooks[0][payload_format]'], 'slack')
        self.assertEqual(parser.selected['announcement_webhooks[0][payload_format]'], 'teams_workflow')
        for script in re.findall(r'<script(?:\s[^>]*)?>(.*?)</script>', rendered, re.S):
            if not script.lstrip().startswith('<'):
                subprocess.run(['node', '--check'], input=script, text=True, capture_output=True, check=True)
        source = (ROOT / 'slsmassnotifyserver/views/other_settings.php').read_text()
        script = source[source.index('\tfunction reindexWebhooks(type) {'):source.index("\t['discord', 'generic', 'announcement'].forEach")]
        fixture = r'''
const vm = require('vm'), assert = require('assert');
const rows = ['slack','native','teams_workflow'].map((format, i) => {
  const fields = Object.fromEntries(['enabled-hidden','enabled','id','name','url','payload_format'].map(key => [key,{value:key==='payload_format'?format:'',name:'old['+(i*2)+']['+key+']'}]));
  const authFields = [{getAttribute(){return 'bearer_token';}}];
  const auth = {hidden:false,querySelectorAll(){return authFields;}};
  return {fields,auth,querySelector(selector){if(selector==='[data-webhook-auth]')return auth;const m=selector.match(/data-field="([^"]+)"/);return m?fields[m[1]]:null;}};
});
const list={querySelectorAll(){return rows;}}, button={setAttribute(){}}, limit={};
const document={getElementById(){return list;},querySelector(selector){return selector.includes('data-add-webhook')?button:limit;}};
const context={document,updateDestinationEmptyState(){}};
vm.createContext(context); vm.runInContext(SCRIPT,context); context.reindexWebhooks('generic');
rows.forEach((row,index)=>assert.equal(row.fields.payload_format.name,`generic_webhooks[${index}][payload_format]`));
assert.equal(rows[0].auth.hidden,true);assert.equal(rows[1].auth.hidden,false);assert.equal(rows[2].auth.hidden,true);
'''
        subprocess.run(['node'], input=fixture.replace('SCRIPT', json.dumps(script)), text=True, capture_output=True, check=True)

    def test_manual_tests_never_contact_providers(self):
        self.assertEqual(self.dispatch(test=True), [])
        self.assertEqual(self.dispatch(dry_run=True), [])
        self.assertEqual(self.calls, [])


if __name__ == '__main__':
    unittest.main()
