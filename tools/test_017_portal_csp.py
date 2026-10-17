#!/usr/bin/python3
"""Actual Chromium worker registration and cross-origin federated navigation."""
import importlib.util
import json
import os
from pathlib import Path
import ssl
import subprocess
import sys
import threading
import unittest
from http.client import HTTPConnection
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

ROOT=Path(__file__).resolve().parents[1]
if os.environ.get('SLS_TEST_NAMESPACE')!='entered' or os.environ.get('SLS_TEST_PARENT_NET_NS') in (None, '', os.readlink('/proc/self/ns/net')):
    raise SystemExit('Use the isolated test runner; no browser was started.')
driver=os.environ.get('SLS_UI_PYTHON_PATH');browser=os.environ.get('SLS_UI_CHROMIUM')
if not driver or not browser or not Path(browser).is_file():
    print('Browser dependencies unavailable; CSP cases did not run.')
    raise SystemExit(1 if os.environ.get('SLS_UI_REQUIRED')=='1' else 0)
sys.path.insert(0,driver)
from playwright.sync_api import sync_playwright
spec=importlib.util.spec_from_file_location('csp_http_fixture',ROOT/'tools/test_enterprise_identity_http.py')
helper=importlib.util.module_from_spec(spec);spec.loader.exec_module(helper)


class PortalCsp(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=helper.EnterpriseHttp
        def cleanup_fixture():
            cls.fixture.doClassCleanups()
            if cls.fixture.tearDown_exceptions:
                raise RuntimeError('Private HTTP fixture cleanup failed.') from cls.fixture.tearDown_exceptions[0][1]
        cls.addClassCleanup(cleanup_fixture)
        cls.fixture.setUpClass();cls.root=cls.fixture.root
        cls.seed=cls.fixture(methodName='test_oidc_actual_http_callback_local_grant_and_revocation')
        cls.seed.setUp();cls.seed.settings['enterprise_identity']['enabled']=True;cls.seed.write_settings()
        provider=cls.seed.settings['enterprise_identity']['providers'][0]['id']
        route=r'''
if($p==='/mass-notify/csp-fixture'){
 require MODULE.'/OperatorPortal.php';
 $nonce=base64_encode(random_bytes(18));SLS\MassNotify\OperatorPortal::headers($nonce);
 session_name(SLS\MassNotify\OperatorPortal::COOKIE);session_start();
 $csrf=SLS\MassNotify\OperatorPortal::csrf($_SESSION);
 echo SLS\MassNotify\OperatorPortal::document('<form method="post" action="/mass-notify/sso.php"><input name="portal_csrf" type="hidden" value="'.$csrf.'"><input name="provider_id" type="hidden" value="PROVIDER"><button type="submit">Federated sign-in</button></form>',$nonce,[],$csrf,'sign-in');exit;
}
if($p==='/mass-notify/subscriber-worker.js'){header('Content-Type: text/javascript');readfile($_SERVER['DOCUMENT_ROOT'].$p);exit;}
'''.replace('MODULE',json.dumps(str(cls.fixture.module))).replace('PROVIDER',provider)
        router=cls.fixture.router.replace("if(in_array($p,['/fixture-login'",route+"if(in_array($p,['/fixture-login'",1)
        (cls.root/'router.php').write_text(router)
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost',
            '-keyout',str(cls.root/'csp-key.pem'),'-out',str(cls.root/'csp-cert.pem')],check=True,capture_output=True)
        class Proxy(BaseHTTPRequestHandler):
            def log_message(self,*args):pass
            def forward(self):
                connection=HTTPConnection('127.0.0.1',cls.fixture.port,timeout=10)
                length=int(self.headers.get('Content-Length','0'));body=self.rfile.read(length) if length else None
                headers={key:value for key,value in self.headers.items() if key.lower() not in {'connection','content-length'}}
                connection.request(self.command,self.path,body=body,headers=headers)
                reply=connection.getresponse();data=reply.read();self.send_response(reply.status)
                for key,value in reply.getheaders():
                    if key.lower() not in {'connection','content-length','transfer-encoding'}:self.send_header(key,value)
                self.send_header('Content-Length',str(len(data)));self.end_headers();self.wfile.write(data);connection.close()
            do_GET=forward
            do_POST=forward
        cls.proxy=ThreadingHTTPServer(('127.0.0.1',0),Proxy)
        tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(str(cls.root/'csp-cert.pem'),str(cls.root/'csp-key.pem'))
        cls.proxy.socket=tls.wrap_socket(cls.proxy.socket,server_side=True)
        cls.thread=threading.Thread(target=cls.proxy.serve_forever,daemon=True);cls.thread.start()
        cls.origin='https://127.0.0.1:'+str(cls.proxy.server_port)
        cls.seed.settings['control_api']['base_url']=cls.origin+'/api/sls-mass-notify'
        for configured in cls.seed.settings['enterprise_identity']['providers']:
            configured['callback_url']=cls.origin+'/mass-notify/sso.php'
        cls.seed.write_settings()
        cls.playwright=sync_playwright().start()
        cls.browser=cls.playwright.chromium.launch(executable_path=browser,headless=True,
            args=['--no-sandbox','--disable-dev-shm-usage','--disable-background-networking','--ignore-certificate-errors'])

    @classmethod
    def tearDownClass(cls):
        cls.browser.close();cls.playwright.stop();cls.proxy.shutdown();cls.proxy.server_close();cls.thread.join()

    def context(self):
        context=self.browser.new_context(ignore_https_errors=True)
        def route(request):
            target=request.request.url
            if target.startswith(self.origin+'/'):request.continue_()
            elif target.startswith('https://idp.example.test/authorize?'):request.fulfill(status=200,content_type='text/html',body='<h1>Isolated identity provider</h1>')
            else:request.abort()
        context.route('**/*',route);self.addCleanup(context.close)
        return context

    def test_production_policy_allows_actual_subscriber_worker_registration(self):
        page=self.context().new_page();reply=page.goto(self.origin+'/mass-notify/csp-fixture')
        self.assertIn("worker-src 'self'",reply.headers['content-security-policy'])
        result=page.evaluate('''async()=>{const registration=await navigator.serviceWorker.register('/mass-notify/subscriber-worker.js');await navigator.serviceWorker.ready;const worker=registration.active;if(worker.state!=='activated')await new Promise(resolve=>worker.addEventListener('statechange',()=>{if(worker.state==='activated')resolve();}));const value={scope:registration.scope,active:worker.state};await registration.unregister();return value;}''')
        self.assertEqual(result,{'scope':self.origin+'/mass-notify/','active':'activated'})

    def test_real_post_start_navigates_to_a_separate_identity_provider_origin(self):
        page=self.context().new_page();page.goto(self.origin+'/mass-notify/csp-fixture')
        errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
        page.on('console',lambda message:errors.append(message.text) if message.type=='error' else None)
        with page.expect_response(lambda reply:reply.url==self.origin+'/mass-notify/sso.php') as response:
            page.get_by_role('button',name='Federated sign-in').click()
        self.assertEqual(response.value.status,200,response.value.text()[-2000:])
        try:page.wait_for_url('https://idp.example.test/authorize?**',timeout=5000)
        except Exception:
            self.fail('SSO navigation did not complete: '+page.locator('main').inner_text()+'; browser errors: '+json.dumps(errors))
        self.assertEqual(page.get_by_role('heading').inner_text(),'Isolated identity provider')
        query=parse_qs(urlparse(page.url).query)
        self.assertEqual(query['redirect_uri'],[self.origin+'/mass-notify/sso.php'])
        self.assertEqual(query['code_challenge_method'],['S256'])
        self.assertTrue(query['state'][0]);self.assertTrue(query['nonce'][0])


if __name__=='__main__':unittest.main()
