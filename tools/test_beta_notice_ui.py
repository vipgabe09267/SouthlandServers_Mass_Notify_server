#!/usr/bin/env python3
"""Exercise the real warning views and five-second server challenge offline."""
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
if (os.environ.get('SLS_TEST_NAMESPACE') != 'entered'
        or os.environ.get('SLS_TEST_PARENT_NET_NS') == os.readlink('/proc/self/ns/net')):
    raise SystemExit('Use the isolated fixture runner; no browser was started.')
driver = os.environ.get('SLS_UI_PYTHON_PATH')
browser = os.environ.get('SLS_UI_CHROMIUM')
if not driver or not browser or not Path(browser).is_file():
    print('SKIPPED optional beta notice browser checks: supply preinstalled SLS_UI_PYTHON_PATH and SLS_UI_CHROMIUM. No downloads ran.')
    raise SystemExit(0)
sys.path.insert(0, driver)
from playwright.sync_api import sync_playwright


class BetaNoticeBrowserTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='sls-beta-browser-')
        cls.root = Path(cls.temp.name)
        (cls.root / 'modules').mkdir()
        (cls.root / 'modules/slsmassnotifyserver').symlink_to(ROOT / 'slsmassnotifyserver', target_is_directory=True)
        router = r'''<?php
declare(strict_types=1);
require MODULE.'/BetaNotice.php';require MODULE.'/LabsSafety.php';require MODULE.'/EnterpriseOperationsConfig.php';
use SLS\MassNotify\{BetaNotice,LabsSafety,EnterpriseOperationsConfig};
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/modules/')){return false;}
session_save_path(__DIR__);session_start();
$csrf=$_SESSION['csrf']??=bin2hex(random_bytes(32));
if($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['fixture_actor'])){
 if(!in_array($_GET['fixture_actor'],['owner','admin2','admin3'],true)){http_response_code(403);return;}
 $_SESSION['actor']=$_GET['fixture_actor'];
}
$actor=$_SESSION['actor']??'owner';
$settings=is_file(__DIR__.'/settings.json')?json_decode(file_get_contents(__DIR__.'/settings.json'),true):[];
if($_SERVER['REQUEST_METHOD']==='POST'){
 header('Content-Type: application/json');header('Cache-Control: no-store');
 if(!is_string($_POST['slsmassnotifyserver_csrf']??null)||!hash_equals($csrf,$_POST['slsmassnotifyserver_csrf'])){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Invalid fixture CSRF']);return;}
 try{
  if(isset($_POST['payload'])){
   $request=json_decode($_POST['payload'],true,16,JSON_THROW_ON_ERROR);$action=$request['action'];$input=$request['input'];
   if($action==='danger_begin'){$result=['success'=>true]+LabsSafety::begin($_SESSION,$input['feature'],'owner',session_id());}
   elseif($action==='danger_accept'){$accepted=LabsSafety::accept($_SESSION,$input,'owner',session_id());$settings['labs_safety']['receipts'][$accepted['feature']]=$accepted['receipt'];$result=['success'=>true];}
   else{throw new DomainException('Unexpected Labs fixture action');}
  }else{
   $action=$_POST['slsmassnotifyserver_action'];
   if($action==='beta_notice_begin'){$result=['success'=>true,'acknowledged'=>false]+BetaNotice::begin($_SESSION,$actor,session_id());}
   elseif($action==='beta_notice_accept'){$settings['beta_notice']=BetaNotice::merge($settings['beta_notice']??[],BetaNotice::accept($_SESSION,['challenge'=>$_POST['challenge']??'','agree'=>($_POST['agree']??'')==='1'],$actor,session_id()));$result=['success'=>true,'acknowledged'=>true];}
   else{throw new DomainException('Unexpected beta fixture action');}
  }
  file_put_contents(__DIR__.'/settings.json',json_encode($settings));echo json_encode($result);
 }catch(Throwable $e){http_response_code(403);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
 return;
}
class InertEnterpriseView {
 public function renderClusterPage(...$args):string{return '';}
 public function renderEnterpriseLabsPage(...$args):string{return '';}
 public function renderEnterpriseIntegrations():string{return '';}
}
$csrf_token=$csrf;$module=new InertEnterpriseView();
$operations=['config'=>EnterpriseOperationsConfig::defaults(),'sites'=>[],'people'=>[],'templates'=>[],'audiences'=>[],
 'marker_choices'=>[],'tones'=>[],'timezone'=>'UTC','timezones'=>['UTC'],'reviews'=>[],'drill_report'=>[],'incident_choices'=>[]];
$safety=LabsSafety::normalize($settings['labs_safety']??[]);
?><!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<?php if(!BetaNotice::acknowledged($settings,$actor)){require MODULE.'/views/beta_notice.php';} ?>
</head><body><p id="pbx-dashboard">Private FreePBX shell</p><?php if(isset($_GET['labs'])){require MODULE.'/views/enterprise.php';} ?></body></html>
'''.replace('MODULE', json.dumps(str(ROOT / 'slsmassnotifyserver')))
        (cls.root / 'router.php').write_text(router)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        cls.origin = f'http://127.0.0.1:{port}'
        cls.log = (cls.root / 'server.log').open('w')
        cls.server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(cls.root), str(cls.root / 'router.php')], stdout=cls.log, stderr=cls.log)
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1): break
            except OSError: time.sleep(.02)
        else: raise RuntimeError('Private fixture server did not start')
        cls.playwright = sync_playwright().start()
        cls.browser = cls.playwright.chromium.launch(executable_path=browser, headless=True, args=['--no-sandbox'])

    @classmethod
    def tearDownClass(cls):
        cls.browser.close(); cls.playwright.stop()
        cls.server.terminate(); cls.server.wait(timeout=5); cls.log.close(); cls.temp.cleanup()

    def setUp(self):
        (self.root / 'settings.json').unlink(missing_ok=True)

    def test_responsive_modal_cannot_close_and_acceptance_persists(self):
        for width in (1280, 390):
            with self.subTest(width=width):
                (self.root / 'settings.json').unlink(missing_ok=True)
                context = self.browser.new_context(viewport={'width': width, 'height': 800})
                page = context.new_page(); errors = []; page.on('pageerror', lambda error: errors.append(str(error)))
                page.goto(self.origin + '/index.php')
                dialog = page.locator('dialog.sls-beta-notice'); dialog.wait_for(state='visible')
                self.assertEqual(dialog.locator('button').count(), 1)
                self.assertEqual(dialog.locator('[aria-label*="Close"]').count(), 0)
                button = dialog.locator('button'); self.assertTrue(button.is_disabled())
                started = time.monotonic()
                page.keyboard.press('Escape'); self.assertTrue(dialog.is_visible())
                page.mouse.click(2, 2); self.assertTrue(dialog.is_visible())
                page.wait_for_timeout(3500); self.assertTrue(button.is_disabled())
                page.wait_for_function("!document.querySelector('dialog.sls-beta-notice button').disabled")
                self.assertGreaterEqual(time.monotonic() - started, 4.8)
                rect = dialog.bounding_box(); self.assertGreaterEqual(rect['x'], 0); self.assertLessEqual(rect['x'] + rect['width'], width)
                self.assertFalse(dialog.evaluate('(el) => el.scrollWidth > el.clientWidth'))
                page.keyboard.press('Escape'); self.assertTrue(dialog.is_visible())
                button.click(); dialog.wait_for(state='detached')
                self.assertIn('receipt', json.loads((self.root / 'settings.json').read_text())['beta_notice'])
                page.reload(); self.assertEqual(page.locator('dialog.sls-beta-notice').count(), 0)
                self.assertEqual(errors, []); context.close()

    def test_each_administrator_agrees_independently_and_new_logins_remember_it(self):
        contexts = []
        try:
            for actor in ('owner', 'admin2'):
                context = self.browser.new_context(); contexts.append(context)
                page = context.new_page()
                page.goto(self.origin + '/index.php?fixture_actor=' + actor)
                dialog = page.locator('dialog.sls-beta-notice'); dialog.wait_for(state='visible')
                self.assertTrue(dialog.locator('button').is_disabled())
                page.wait_for_function("!document.querySelector('dialog.sls-beta-notice button').disabled")
                dialog.locator('button').click(); dialog.wait_for(state='detached')
            saved = json.loads((self.root / 'settings.json').read_text())['beta_notice']
            self.assertEqual({row['actor'] for row in saved['receipts'].values()}, {'owner', 'admin2'})
            for actor in ('owner', 'admin2', 'admin3'):
                context = self.browser.new_context(); contexts.append(context)
                page = context.new_page()
                page.goto(self.origin + '/index.php?fixture_actor=' + actor)
                if actor == 'admin3':
                    page.locator('dialog.sls-beta-notice').wait_for(state='visible')
                    self.assertTrue(page.locator('dialog.sls-beta-notice button').is_disabled())
                else:
                    self.assertEqual(page.locator('#sls-beta-notice-template').count(), 0)
                    self.assertEqual(page.locator('dialog.sls-beta-notice').count(), 0)
        finally:
            for context in contexts: context.close()

    def test_labs_waits_until_beta_is_saved_then_starts_its_own_timer(self):
        context = self.browser.new_context(); page = context.new_page(); errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.goto(self.origin + '/config.php?labs=1')
        beta = page.locator('dialog.sls-beta-notice'); beta.wait_for(state='visible')
        self.assertFalse(page.locator('#sls-labs-danger').is_visible())
        page.wait_for_function("!document.querySelector('dialog.sls-beta-notice button').disabled")
        self.assertFalse(page.locator('#sls-labs-danger').is_visible())
        beta.locator('button').click(); beta.wait_for(state='detached')
        labs = page.locator('#sls-labs-danger'); labs.wait_for(state='visible')
        self.assertTrue(page.locator('#sls-labs-danger-agree').is_disabled())
        page.wait_for_timeout(1000); self.assertTrue(page.locator('#sls-labs-danger-agree').is_disabled())
        self.assertEqual(errors, []); context.close()

    def test_network_failure_keeps_warning_locked(self):
        context = self.browser.new_context(); page = context.new_page()
        page.route('**/config.php?display=slsmassnotifyserver', lambda route: route.fulfill(status=503, content_type='application/json', body='{"success":false,"message":"Private fixture refusal"}'))
        page.goto(self.origin + '/index.php')
        page.wait_for_function("document.querySelector('.sls-beta-notice-status').textContent.includes('Private fixture refusal')")
        page.wait_for_timeout(5100)
        self.assertTrue(page.locator('dialog.sls-beta-notice').is_visible())
        self.assertTrue(page.locator('dialog.sls-beta-notice button').is_disabled())
        page.keyboard.press('Escape'); self.assertTrue(page.locator('dialog.sls-beta-notice').is_visible()); context.close()

    def test_hidden_document_does_not_consume_reading_time(self):
        context = self.browser.new_context(); page = context.new_page()
        page.goto(self.origin + '/index.php'); page.locator('dialog.sls-beta-notice').wait_for(state='visible')
        page.evaluate("Object.defineProperty(document, 'visibilityState', {configurable: true, get: () => 'hidden'}); document.dispatchEvent(new Event('visibilitychange'))")
        page.wait_for_timeout(5200)
        self.assertTrue(page.locator('dialog.sls-beta-notice button').is_disabled())
        page.evaluate("delete document.visibilityState; document.dispatchEvent(new Event('visibilitychange'))")
        page.wait_for_timeout(3500); self.assertTrue(page.locator('dialog.sls-beta-notice button').is_disabled())
        page.wait_for_function("!document.querySelector('dialog.sls-beta-notice button').disabled")
        context.close()

    def test_viewport_resize_and_body_focus_do_not_strand_confirmation(self):
        context = self.browser.new_context(viewport={'width': 1280, 'height': 800})
        page = context.new_page()
        page.goto(self.origin + '/index.php')
        dialog = page.locator('dialog.sls-beta-notice'); dialog.wait_for(state='visible')
        page.wait_for_function("document.querySelector('.sls-beta-notice-status').textContent.startsWith('Read this warning')")
        page.set_viewport_size({'width': 390, 'height': 800})
        # FreePBX layout changes can clear the dialog's focused element while
        # the browser itself remains focused. Exercise that actual DOM state.
        page.evaluate('document.activeElement.blur()')
        self.assertEqual(page.evaluate('document.activeElement.tagName'), 'BODY')
        self.assertTrue(page.evaluate("document.visibilityState === 'visible' && document.hasFocus()"))
        started = time.monotonic()
        page.wait_for_timeout(3500); self.assertTrue(dialog.locator('button').is_disabled())
        page.wait_for_function("!document.querySelector('dialog.sls-beta-notice button').disabled", timeout=8000)
        self.assertGreaterEqual(time.monotonic() - started, 4.5)
        self.assertEqual(page.evaluate('document.activeElement.tagName'), 'BODY')
        page.keyboard.press('Escape'); self.assertTrue(dialog.is_visible())
        dialog.locator('button').click(); dialog.wait_for(state='detached')
        self.assertIn('receipt', json.loads((self.root / 'settings.json').read_text())['beta_notice'])
        context.close()

    def test_unfocused_page_does_not_consume_reading_time(self):
        context = self.browser.new_context()
        page = context.new_page(); page.goto(self.origin + '/index.php')
        page.locator('dialog.sls-beta-notice').wait_for(state='visible')
        page.wait_for_function("document.querySelector('.sls-beta-notice-status').textContent.startsWith('Read this warning')")
        # Playwright forces every headless page to appear focused. Restore
        # Chromium's unfocused window state through CDP rather than replacing
        # document.hasFocus(), then restore the normal foreground emulation.
        session = context.new_cdp_session(page)
        session.send('Emulation.setFocusEmulationEnabled', {'enabled': False})
        other = context.new_page(); other.goto('about:blank'); other.bring_to_front()
        page.wait_for_function('!document.hasFocus()', timeout=3000)
        page.wait_for_timeout(5200)
        self.assertTrue(page.locator('dialog.sls-beta-notice button').is_disabled())
        page.bring_to_front()
        session.send('Emulation.setFocusEmulationEnabled', {'enabled': True})
        page.wait_for_function("document.visibilityState === 'visible' && document.hasFocus()", timeout=3000)
        page.wait_for_timeout(3500); self.assertTrue(page.locator('dialog.sls-beta-notice button').is_disabled())
        page.wait_for_function("!document.querySelector('dialog.sls-beta-notice button').disabled", timeout=8000)
        context.close()

    def test_failed_acceptance_keeps_beta_and_labs_locked(self):
        context = self.browser.new_context(); page = context.new_page()
        def intercept(route):
            if 'slsmassnotifyserver_action=beta_notice_accept' in (route.request.post_data or ''):
                route.fulfill(status=503, content_type='application/json', body='{"success":false,"message":"Private save failure"}')
            else: route.continue_()
        page.route('**/config.php?display=slsmassnotifyserver', intercept)
        page.goto(self.origin + '/config.php?labs=1'); beta = page.locator('dialog.sls-beta-notice'); beta.wait_for(state='visible')
        page.wait_for_function("!document.querySelector('dialog.sls-beta-notice button').disabled")
        beta.locator('button').click()
        page.wait_for_function("document.querySelector('dialog.sls-beta-notice button').textContent === 'Reload required'")
        self.assertTrue(beta.is_visible()); self.assertTrue(beta.locator('button').is_disabled())
        self.assertFalse(page.locator('#sls-labs-danger').is_visible())
        self.assertNotIn('beta_notice', json.loads((self.root / 'settings.json').read_text()))
        page.keyboard.press('Escape'); self.assertTrue(beta.is_visible()); context.close()


if __name__ == '__main__': unittest.main()
