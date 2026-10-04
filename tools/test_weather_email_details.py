#!/usr/bin/env python3
"""Exercise complete official alert content through the real shell helper."""
import base64
import importlib.util
import json
import os
import subprocess
import sys
import unittest
from pathlib import Path

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
EMAIL = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_branded_email.py'
POLLER = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_nws_poll.sh'
spec = importlib.util.spec_from_file_location('weather_email_fixture', EMAIL)
mail = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mail)


class WeatherEmailTests(unittest.TestCase):
    def setUp(self):
        self.feature = {'properties': {'event': 'Flood Warning', 'areaDesc': 'Test County; Example City',
            'headline': 'River flooding expected', 'description': 'Locations impacted include Example City.\nWater is over the crossing.',
            'instruction': 'Move to higher ground. Do not drive through flood water.', 'effective': '2026-10-01T08:00:00-05:00',
            'onset': '2026-10-01T09:00:00-05:00', 'expires': '2026-10-01T18:00:00-05:00'}}

    def test_legacy_template_gains_locations_actions_timing_and_details(self):
        old = 'An EAS alert triggered the configured NWS recipients.\nEvent: Flood Warning\nZone: XXZ001'
        result = mail.weather_body(old, self.feature, 'Phone speech fixture')
        for key, value in self.feature['properties'].items():
            self.assertIn(value, result)
        self.assertIn(old, result)
        self.assertIn('Phone speech fixture', result)
        html = mail.build_html('Flood Warning', result)
        self.assertIn('Do not drive through flood water.', html)
        self.assertIn('Water is over the crossing.', html)

    def test_more_than_twelve_labeled_lines_are_not_discarded(self):
        text = 'Header\n' + '\n'.join(f'Field {i}: kept value {i}' for i in range(45))
        html = mail.build_html('Notice', text)
        for i in range(45):
            self.assertIn(f'kept value {i}', html)

    def test_escaping_and_missing_official_values(self):
        self.feature['properties']['instruction'] = '<script>do not run</script>'
        self.feature['properties']['description'] = None
        html = mail.build_html('Weather', mail.weather_body('', self.feature, 'Speech'))
        self.assertNotIn('<script>', html)
        self.assertIn('&lt;script&gt;', html)
        self.assertIn('Not supplied by NWS.', html)

    def test_oversize_invalid_and_incomplete_content_rejected_without_truncation(self):
        for feature in ({}, {'properties': []}, {'properties': {'instruction': 123}}, {'properties': {'description': 'a' * 31000}}):
            with self.assertRaises(ValueError):
                mail.weather_body('', feature, 'Speech')

    def test_actual_shell_handoff_and_complete_phone_wording(self):
        source = POLLER.read_text()
        helper = source[source.index('complete_weather_email_body() {'):source.index('generate_tts_audio() {')]
        command = helper + '\nBRANDED_EMAIL_SCRIPT="$1"\ncomplete_weather_email_body "$2" "$3"\n'
        feature = base64.b64encode(json.dumps(self.feature).encode()).decode()
        result = subprocess.run(['bash', '-c', command, 'fixture', str(EMAIL), feature, 'Event: Flood Warning'],
                                capture_output=True, text=True, timeout=5, env={**os.environ, 'PYTHONDONTWRITEBYTECODE':'1'})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('Flood Warning for Test County; Example City.', result.stdout)
        self.assertIn(self.feature['properties']['instruction'], result.stdout)
        self.assertIn(self.feature['properties']['description'], result.stdout)
        self.assertEqual(source.count('MAIL_BODY="$(complete_weather_email_body "$ALERT_B64" "$MAIL_BODY")"'), 2)


if __name__ == '__main__':
    unittest.main()
