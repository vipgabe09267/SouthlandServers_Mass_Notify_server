#!/usr/bin/env python3
"""Verify full native webhook text and independent provider size failures."""
import importlib.util
from pathlib import Path
import sys
import tempfile
import unittest

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('webhook_content', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_notification_destinations.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class WebhookContent(unittest.TestCase):
    def test_queue_retains_full_operational_fields(self):
        with tempfile.TemporaryDirectory() as name:
            state=Path(name)/'external.json'
            field=('Complete instruction. '*40).strip()
            key=m.queue_external_delivery(state,{},'private-fields','Alert','Body',source='nws',fields=[('Instructions',field)])
            self.assertEqual(m._load_retry_state(state)['deliveries'][key]['payload']['fields'],[['Instructions',field]])
    def test_full_paragraphs_and_final_instructions_are_preserved(self):
        body = '\n\n'.join(['Affected area: campus'] * 8) + '\n' + 'Details. ' * 270 + '\nFinal instruction: avoid the east entrance.'
        self.assertLess(len(body), 3000)
        self.assertEqual(m.build_discord_payload({}, 'Alert', body)['embeds'][0]['description'], body)
        self.assertEqual(m.build_generic_payload('Alert', body)['alert']['message'], body)

    def test_discord_rejects_complete_oversized_text_and_fields(self):
        for body, fields in [('x' * 4097, []), ('x' * 3500, [('Area', 'a' * 1000)] * 3),
                             ('Body', [('Action', 'a' * 1025)]), ('Body', [('Area', 'x')] * 26)]:
            with self.subTest(length=len(body), fields=len(fields)):
                with self.assertRaises(m.DestinationError) as error:
                    m.build_discord_payload({}, 'Alert', body, fields=fields)
                self.assertEqual(error.exception.code, 'payload_too_large')
                self.assertFalse(error.exception.submission_possible)
        fields = [('Instructions', 'a' * 900), ('Contact', 'b' * 200)]
        self.assertEqual([(f['name'], f['value']) for f in m.build_discord_payload({}, 'Alert', 'Body', fields=fields)['embeds'][0]['fields']], fields)

    def test_weather_discord_limit_does_not_clip_or_block_other_routes(self):
        rows = [{'id': key, 'name': key, 'url': 'https://receiver.example/' + key, 'enabled': '1', 'payload_format': adapter}
                for key, adapter in [('native', 'native'), ('slack', 'slack')]]
        config = {'discord_webhooks': [{'id': 'discord', 'name': 'Discord', 'url': 'https://discord.com/api/webhooks/1/private', 'enabled': '1'}],
                  'generic_webhooks': rows}
        calls = []
        def transport(*args, **kwargs): calls.append(args); return 204, ''
        body = 'x' * 5000 + '\nFinal instruction.'
        result = m.dispatch_webhook_destinations(config, 'Alert', body, source='nws', live=True,
                                                transport=transport, enforce_wall_clock=False)
        self.assertEqual({r['id']: r['status'] for r in result}, {'discord': 'failed', 'native': 'accepted', 'slack': 'accepted'})
        failure = next(r for r in result if r['id'] == 'discord')
        self.assertEqual(failure['attempts'], 0)
        self.assertEqual(failure['error'], 'payload_too_large')
        self.assertEqual(len(calls), 2)
        self.assertEqual(calls[0][2]['alert']['message'], body)
        self.assertIn(body, ''.join(block['text']['text'] for block in calls[1][2]['blocks']))

    def test_announcement_native_limit_is_independent_of_collaboration_route(self):
        rows = [{'id': key, 'name': key, 'url': 'https://receiver.example/' + key, 'enabled': '1', 'payload_format': adapter}
                for key, adapter in [('native', 'native'), ('slack', 'slack')]]
        calls = []
        def transport(*args, **kwargs): calls.append(args); return 204, ''
        result = m.dispatch_announcement_webhooks({'announcement_webhooks': rows}, 'Alert', 'x' * 5000,
            destination_ids=['native', 'slack'], expected_fingerprints={r['id']: m.destination_fingerprint(r) for r in rows},
            live=True, transport=transport, enforce_wall_clock=False)
        self.assertEqual({r['id']: r['status'] for r in result}, {'native': 'failed', 'slack': 'accepted'})
        self.assertEqual(len(calls), 1)


if __name__ == '__main__':
    unittest.main()
