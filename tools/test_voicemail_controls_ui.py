#!/usr/bin/env python3
"""Check native browser form values and responsive voicemail controls offline."""
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
if (os.environ.get('SLS_TEST_NAMESPACE') != 'entered'
        or os.environ.get('SLS_TEST_PARENT_NET_NS') == os.readlink('/proc/self/ns/net')):
    raise SystemExit('Use the isolated fixture runner; no browser was started.')
driver = os.environ.get('SLS_UI_PYTHON_PATH')
browser = os.environ.get('SLS_UI_CHROMIUM')
if not driver or not browser or not Path(browser).is_file():
    print('SKIPPED optional voicemail browser checks: set SLS_UI_PYTHON_PATH and SLS_UI_CHROMIUM. No downloads ran.')
    raise SystemExit(0)
sys.path.insert(0, driver)
from playwright.sync_api import sync_playwright


def render(view, settings):
    script = r'''
    set_error_handler(function($severity, $message) { throw new RuntimeException($message); });
    function load_view($path, $variables) { return ''; }
    $hero_image=''; $csrf_token='fixture';
    $settings=json_decode($argv[2], true, 16, JSON_THROW_ON_ERROR);
    $paging=$settings['live_paging']??[];
    require $argv[1];
    '''
    html = subprocess.check_output([
        'php', '-r', script, str(ROOT / 'slsmassnotifyserver/views' / (view + '.php')),
        json.dumps(settings),
    ], text=True, timeout=5)
    return '''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
    <style>*{box-sizing:border-box}body{margin:0;padding:12px;font:14px Arial,sans-serif}
    .form-control{display:block;width:100%;padding:7px}label{line-height:1.5}.help-block{line-height:1.5}
    .checkbox label{display:block;padding-left:22px}.checkbox input{float:left;margin-left:-22px}
    .modal{display:none}.row{margin:0}.col-md-3,.col-md-4,.col-md-5,.col-md-6,.col-md-7{min-width:0}
    </style></head><body>''' + html + '</body></html>'


class VoicemailBrowserControls(unittest.TestCase):
    def test_values_labels_and_mobile_bounds(self):
        cases = [
            ('settings', 'nws_voicemail_enabled', 'sls-weather-voicemail', None),
            ('lightning', 'xweather[voicemail_enabled]', 'sls-lightning-voicemail', 'xweather'),
            ('paging', 'live_paging[voicemail_enabled]', 'sls-paging-voicemail', 'live_paging'),
        ]
        with sync_playwright() as playwright:
            instance = playwright.chromium.launch(
                executable_path=browser, headless=True,
                args=['--no-sandbox', '--disable-dev-shm-usage'])
            try:
                for view, name, control_id, nested in cases:
                    for saved in [None, '0', '1']:
                        settings = {'enabled': '0', 'nws_zones': [], 'quiet_critical_events': []}
                        if saved is not None:
                            if nested is None:
                                settings[name] = saved
                            else:
                                settings[nested] = {'voicemail_enabled': saved}
                        context = instance.new_context(java_script_enabled=False)
                        try:
                            page = context.new_page()
                            page.set_content(render(view, settings))
                            control = page.locator('#' + control_id)
                            self.assertEqual(control.is_checked(), saved != '0')
                            self.assertTrue(control.evaluate('(n) => n.form !== null'))
                            for width in [1440, 390]:
                                page.set_viewport_size({'width': width, 'height': 1000})
                                control.evaluate('(n) => n.scrollIntoView({block:"center"})')
                                bounds = control.locator('..').bounding_box()
                                self.assertGreaterEqual(bounds['x'], 0)
                                self.assertLessEqual(bounds['x'] + bounds['width'], width)
                                self.assertTrue(control.is_visible())
                            control.check()
                            self.assertEqual(control.evaluate(
                                '(n) => new FormData(n.form).getAll(n.name)'), ['0', '1'])
                            control.uncheck()
                            self.assertEqual(control.evaluate(
                                '(n) => new FormData(n.form).getAll(n.name)'), ['0'])
                        finally:
                            context.close()
            finally:
                instance.close()


if __name__ == '__main__':
    unittest.main()
