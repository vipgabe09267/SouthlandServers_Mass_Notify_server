#!/usr/bin/env python3
"""Optional isolated Chromium audit; browser/driver paths are explicit local test dependencies."""
import base64
import importlib.util
import json
import os
from pathlib import Path
import re
import socket
import ssl
import sys
import threading
import time
import unittest
from http.client import HTTPConnection
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / 'slsmassnotifyserver'
if os.environ.get('SLS_TEST_NAMESPACE') != 'entered' or os.environ.get('SLS_TEST_PARENT_NET_NS') == os.readlink('/proc/self/ns/net'):
    raise SystemExit('Use the isolated fixture runner; no browser was started.')
driver = os.environ.get('SLS_UI_PYTHON_PATH')
browser_path = os.environ.get('SLS_UI_CHROMIUM')
if not driver or not browser_path or not Path(browser_path).is_file():
    print('SKIPPED optional browser audit: set SLS_UI_PYTHON_PATH and SLS_UI_CHROMIUM to preinstalled local dependencies. No browser checks ran; this fixture downloads nothing.')
    raise SystemExit(0)
sys.path.insert(0, driver)
try:
    from playwright.sync_api import sync_playwright
except ImportError:
    print('SKIPPED optional browser audit: the configured local Python driver is unavailable. No browser checks ran.')
    raise SystemExit(0)
spec = importlib.util.spec_from_file_location('enterprise_portal_browser_helper', ROOT / 'tools/test_operator_portal_http.py')
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)


class EnterpriseBrowserTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture = helper.PortalHttpTests
        cls.fixture.setUpClass()
        cls.root = cls.fixture.root
        settings = {**cls.fixture.original, 'enterprise_identity': {}, 'directory_sync': {}, 'subscriber_browser': {}}
        cls.fixture.write_settings(settings)
        php = '''<?php
declare(strict_types=1);
require MODULE.'/EnterpriseIdentityManagement.php';require MODULE.'/EnterpriseAdministration.php';
require MODULE.'/EnterpriseClusterConfig.php';require MODULE.'/EnterpriseOperationsConfig.php';require MODULE.'/EnterpriseIntegrations.php';require MODULE.'/ConfigCrypto.php';
use SLS\\MassNotify\\{EnterpriseIdentityConfig,DirectoryConfig,SubscriberConfig,IncidentConfig,IncidentStore,EnterpriseOperationsConfig,EnterpriseClusterConfig,EnterpriseIntegrationsConfig,LabsSafety};
use FreePBX\\modules\\SlsConfigCrypto;
define('SLS_IDENTITY_FIXTURE',FIXTURE);define('FREEPBX_IS_AUTH',true);
function load_view($path,$variables=[]){extract($variables);ob_start();include $path;return ob_get_clean();}
if(!function_exists('_')){function _($v){return $v;}}
'''
        management = (ROOT / 'tools/test_enterprise_identity_management.php').read_text()
        start = management.index('class EnterpriseIdentityFixture\n')
        end = management.index('$module=new EnterpriseIdentityFixture()', start)
        model = management[start:end].replace('class EnterpriseIdentityFixture', 'class EnterpriseBrowserCentral')
        model = model.replace('use \\FreePBX\\modules\\SlsEnterpriseLabs;', '''use \\FreePBX\\modules\\SlsEnterpriseLabs;
 use \\FreePBX\\modules\\SlsEnterpriseAdministration {enterpriseAdministratorAction as private actualAdministratorAction;}
 use \\FreePBX\\modules\\SlsEnterpriseIntegrations;''')
        model = re.sub(r' protected function assertEnterpriseAdministrator\(\):void\{[^\n]+\}\n', '', model)
        model = model[:model.rfind('}')] + '''
 public function currentOperator():array{return ['id'=>'recovery_admin','username'=>'fixture-admin','operator_role'=>'administrator'];}
 public function getCsrfToken():string{return $_SESSION['central_csrf']??=bin2hex(random_bytes(32));}
 public function validateCsrfToken($value):bool{return is_string($value)&&hash_equals($this->getCsrfToken(),$value);}
 private function configurationPathMetadata($path){return file_exists($path)?lstat($path):null;}
 private function getDefaultSettings():array{return ['enterprise_operations'=>EnterpriseOperationsConfig::defaults()];}
 public function enterpriseAdministratorAction(string $action,array $input):array{file_put_contents(FIXTURE.'/central-actions.jsonl',json_encode(['action'=>$action,'input'=>$input])."\\n",FILE_APPEND|LOCK_EX);return $this->actualAdministratorAction($action,$input);}
 public function getConfiguredPjsipExtensionNumbers():array{return ['1000'];}
 public function getEnterpriseClusterConfiguration():array{return EnterpriseClusterConfig::defaults();}
 public function renderClusterPage(string $endpoint,string $csrf):string{return load_view(MODULE.'/views/cluster.php',['config'=>$this->getEnterpriseClusterConfiguration(),'endpoint'=>$endpoint,'csrf'=>$csrf,'revision'=>str_repeat('a',64)]);}
 public function configureEnterpriseCluster(array $input):array{$settings=$this->getActiveSettings();$settings['enterprise_cluster']=$input;$this->assertEnterpriseActivationSafe($settings);return ['success'=>true,'revision'=>str_repeat('b',64),'message'=>'Inert browser fixture: no cluster activation.'];}
 public function getEnterpriseIntegrationState():array{$cfg=EnterpriseIntegrationsConfig::normalize($this->getActiveSettings()['enterprise_integrations']??[]);return ['config'=>EnterpriseIntegrationsConfig::redacted($cfg),'revision'=>hash('sha256',json_encode($cfg,JSON_THROW_ON_ERROR)),'catalog'=>EnterpriseIntegrationsConfig::catalog(),'history'=>[],'sensor_health'=>[],'storage_error'=>'','extensions'=>['1000'],'sms_recipients'=>[],'voice_recipients'=>[],'people'=>[],'incidents'=>[]];}
 public function enterpriseOperationsState():array{$cfg=EnterpriseOperationsConfig::normalize($this->getActiveSettings()['enterprise_operations']??[]);return ['config'=>$cfg,'revision'=>hash('sha256',json_encode($cfg,JSON_THROW_ON_ERROR)),'sites'=>[['id'=>'site_a','name'=>'Fixture Site']],'people'=>[['id'=>'pbx:fixture-admin','name'=>'Fixture administrator'],['id'=>'pbx:reviewer','name'=>'Fixture reviewer']],'templates'=>[['id'=>'tpl_'.str_repeat('a',24),'name'=>'Browser template','fields'=>[]]],'audiences'=>[['id'=>'grp_'.str_repeat('a',24),'name'=>'Saved fixture audience']],'marker_choices'=>[['kind'=>'location','id'=>'room_a','name'=>'Fixture room']],'tones'=>[],'timezone'=>'UTC','timezones'=>['UTC','America/Chicago'],'reviews'=>[],'drill_report'=>[],'incident_choices'=>[['id'=>'inc_'.str_repeat('a',32),'name'=>'Assigned browser incident']]];}
 public function getEnterpriseFloorplanImage(string $id):array{if($id!=='img_'.str_repeat('a',64)){throw new DomainException('Unknown private fixture image.');}return ['type'=>'image/png','bytes'=>base64_decode(substr(file_get_contents(FIXTURE.'/browser-image.txt'),22),true)];}
 public function enterpriseOperationsAction(string $action,array $input):array{if($action==='floorplan_overlay'){if($input!==['plan_id'=>'plan_'.str_repeat('a',24),'incident_id'=>'inc_'.str_repeat('a',32)]){throw new DomainException('Unexpected private overlay selection.');}return ['success'=>true,'plan'=>['image_id'=>'img_'.str_repeat('a',64),'markers'=>[['label'=>'Fixture room','x'=>50,'y'=>50,'status'=>'unconfirmed']]],'observed_at'=>gmdate('c'),'meaning'=>'Human responses remain separate from transport receipts.'];}if($action==='drill_report'){return ['success'=>true,'assignments'=>[]];}if($action!=='operations_save'){throw new DomainException('Only editor saves are inert in this fixture.');}$cfg=EnterpriseOperationsConfig::normalize($input['config']);$this->saveEnterpriseNamespace('enterprise_operations',$cfg,$input['revision']);return ['success'=>true,'message'=>'Fixture coordination settings saved.'];}
}
class FreePBX{public static function create(){return (object)['Slsmassnotifyserver'=>new EnterpriseBrowserCentral()];}}
session_name('SLSCENTRALFIXTURE');session_start();
require MODULE.'/page.slsmassnotifyserver_enterprise.php';
'''
        # The canonical writer remains under test; fixture configuration and
        # operational storage are private temporary files, never host PBX data.
        model = model.replace("'incident_workflows'=>IncidentConfig::class", "'incident_workflows'=>IncidentConfig::class,'enterprise_operations'=>EnterpriseOperationsConfig::class,'enterprise_integrations'=>EnterpriseIntegrationsConfig::class,'labs_safety'=>LabsSafety::class")
        model = model.replace('protected function assertEnterpriseActivationSafe(array $s):void{}', '')
        source = re.sub(r'\bFIXTURE\b',lambda _:json.dumps(str(cls.root)),(php + model).replace('MODULE', json.dumps(str(MODULE))))
        (cls.root / 'central.php').write_text(source)
        # Render the actual account view in FreePBX's table-width shell with
        # populated protected activity, including long and escaped actor text.
        activity=[]
        for action, actor, ok in [('operator_sign_in_completed','operator:'+'a'*140,True),
                                  ('operator_login_failed_password','operator:<script>window.auditInjected=true</script>',False),
                                  ('operator_reset_link_issued','operator:fixture-administrator',True),
                                  ('config_export_encrypted','web:'+'b'*140,True)]:
            activity.append({'created_at':'2026-10-04T00:00:00+00:00','ip':'2001:db8:ffff:ffff:ffff:ffff:ffff:ffff',
                             'method':'POST','action':action,'actor':actor,'status':200 if ok else 401,'ok':ok,
                             'operator_id':'api_'+'a'*24,'operator_username':'saved-'+'c'*134})
        (cls.root/'browser-security.json').write_text(json.dumps(activity))
        operator_view='''<?php
require MODULE.'/SecurityAudit.php';
if(!function_exists('_')){function _($v){return $v;}}
$rows=json_decode(file_get_contents(__DIR__.'/browser-security.json'),true,16,JSON_THROW_ON_ERROR);
$state=['access'=>['enabled'=>false,'portal_enabled'=>false,'accounts'=>[]],
 'directory'=>['accounts'=>[],'warnings'=>[]],'sites'=>[],'locations'=>[],'devices'=>[],'audiences'=>[],
 'revision'=>str_repeat('a',64),'security_activity'=>['events'=>array_map([\\SLS\\MassNotify\\SecurityAudit::class,'project'],$rows),'notices'=>[]]];
$csrf_token=str_repeat('b',64);$endpoint='/operator-config.php';
?><!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>
*{box-sizing:border-box}body{margin:0;font:14px Arial,sans-serif}#page_body{display:table;width:100%}.container-fluid{padding:0 15px}.btn{display:inline-block;padding:6px 12px}.table{margin-bottom:20px}th{font-weight:700}
</style></head><body><div id="page_body"><?php include MODULE.'/views/operators.php';?></div></body></html>
'''.replace('MODULE',json.dumps(str(MODULE)))
        (cls.root/'operator-config.php').write_text(operator_view)
        seed = '''require MODULE.'/ConfigCrypto.php';require MODULE.'/IncidentConfig.php';require MODULE.'/EnterpriseOperationsConfig.php';
$s=['incident_workflows'=>['schema'=>1,'templates'=>[\\SLS\\MassNotify\\IncidentConfig::template(['id'=>'tpl_'.str_repeat('a',24),'name'=>'Browser template','title'=>'Fixture','message'=>'Fixture','delivery'=>['extensions'=>['1000']]])]],'operator_access'=>['accounts'=>[]]];
$s['enterprise_operations']=\\SLS\\MassNotify\\EnterpriseOperationsConfig::normalize(['enabled'=>true,'floorplans'=>['enabled'=>true,'plans'=>[['id'=>'plan_'.str_repeat('a',24),'name'=>'Private assigned plan','enabled'=>true,'site_id'=>'site_a','image_id'=>'img_'.str_repeat('a',64),'markers'=>[]]]]]);
file_put_contents(FIXTURE.'/active.config',\\FreePBX\\modules\\SlsConfigCrypto::encode($s));chmod(FIXTURE.'/active.config',0640);'''
        import subprocess
        subprocess.run(['php', '-r', seed.replace('MODULE', json.dumps(str(MODULE))).replace('FIXTURE', json.dumps(str(cls.root)))], check=True, capture_output=True)
        router = '''<?php
$_SERVER['HTTPS']='on';$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/config.php'){require __DIR__.'/central.php';return;}
if($path==='/operator-config.php'&&$_SERVER['REQUEST_METHOD']==='GET'){require __DIR__.'/operator-config.php';return;}
if(preg_match('~^/modules/slsmassnotifyserver/views/([a-z_]+\\.(?:js|css))$~D',$path,$m)){header('Content-Type: '.(str_ends_with($m[1],'.js')?'text/javascript':'text/css'));readfile(MODULE.'/views/'.$m[1]);return;}
if($path==='/mass-notify/'){require __DIR__.'/mass-notify/index.php';return;}http_response_code(404);
'''.replace('MODULE', json.dumps(str(MODULE)))
        (cls.root / 'router.php').write_text(router)
        # Real local password+MFA sessions feed the production portal view and
        # script. The fixture's submission adapter records payloads only.
        cls.template_id='tpl_'+'a'*24;cls.incident_id='inc_'+'a'*32;cls.review_id='review_'+'a'*32;cls.drill_id='drill_'+'a'*24;cls.run_id='run_'+'a'*64
        incident={'id':cls.incident_id,'title':'Assigned browser incident','severity':'warning','state':'open','is_test':True,'created_at':'2026-10-03T00:00:00Z',
                  'roster':[{'id':'assigned-person','name':'Assigned person','location':'Fixture room'}],'responses':{},
                  'response_counts':{'expected':1,'safe':0,'received':0,'needs_assistance':0,'missing':0,'no_response':1},'can_update':True,'submission_states':['awaiting_approval']}
        review={'id':cls.review_id,'state':'awaiting_approval','requested_by':'fixture-owner','expires_at':'2099-01-01T00:00:00Z','message':'Frozen fixture wording','title':'Assigned approval',
                'is_test':True,'recipients':{'phones':['1000']},'approvals':{},'history':[]}
        expired={**review,'id':'review_'+'b'*32,'title':'Expired fixture approval','expires_at':'2000-01-01T00:00:00Z'}
        run={'id':cls.run_id,'state':'queued','created_at':'2026-10-03T00:00:00Z','incident_id':cls.incident_id,'reviews':[],
             'assignment':{'id':cls.drill_id,'name':'Assigned exercise','template_id':cls.template_id,'objectives':['Observe the test alert']}}
        drills=[{'id':cls.drill_id,'name':'Assigned exercise','enabled':True,'template_id':cls.template_id,'due_at':'2027-01-01T00:00:00Z',
                 'objectives':['Observe the test alert'],'status':'launched_unreviewed','can_launch':True,'runs':[run]}]
        cls.plan_id='plan_'+'a'*24;cls.image_id='img_'+'a'*64
        view={'templates':[{'id':cls.template_id,'name':'Browser template','title':'Fixture notice','message':'Fixture exercise','fields':[],'language_variants':[]}],
              'incidents':[incident],'enterprise':{'enabled':True,'approvals':[review,expired],'drills':drills,'floorplans':[{'id':cls.plan_id,'name':'Private assigned plan'}],'warnings':[]}}
        (cls.root/'browser-operations.json').write_text(json.dumps(view))
        image=subprocess.check_output(['php','-r','$i=imagecreatetruecolor(2,2);ob_start();imagepng($i);echo base64_encode(ob_get_clean());'],text=True)
        (cls.root/'browser-image.txt').write_text('data:image/png;base64,'+image)
        bootstrap=(cls.root/'bootstrap.php').read_text()
        insertion="if(file_exists("+json.dumps(str(cls.root/'browser-operations.json'))+")){if($p['operator_role']==='administrator'){$state=array_replace($state,json_decode(file_get_contents("+json.dumps(str(cls.root/'browser-operations.json'))+"),true));}}\n"
        bootstrap=bootstrap.replace("  foreach(['1000','2000']",insertion+"  foreach(['1000','2000']")
        adapter='''function operatorAction($action,$input){
 $p=$this->currentOperator();$v=json_decode(file_get_contents(FIXTURE.'/browser-operations.json'),true);
 if($p['operator_role']==='administrator'&&in_array($action,['approval_approve','approval_submit','approval_reject','drill_start','drill_review','drill_report','floorplan_overlay','floorplan_image'],true)){
  file_put_contents(FIXTURE.'/browser-action.json',json_encode([$action,$input]));
  if($action==='drill_report')return ['success'=>true,'assignments'=>$v['enterprise']['drills']];
  if($action==='floorplan_image')return ['success'=>true,'image'=>file_get_contents(FIXTURE.'/browser-image.txt')];
  if($action==='floorplan_overlay')return ['success'=>true,'plan'=>['image_id'=>'img_'.str_repeat('a',64),'markers'=>[['label'=>'Fixture room','x'=>50,'y'=>50,'status'=>'unconfirmed','response_counts'=>['no_response'=>1]]]],'observed_at'=>gmdate('c'),'meaning'=>'Human responses remain separate from transport receipts.'];
  return ['success'=>true,'message'=>'Inert UI fixture: no submission.'];
 }
'''.replace('FIXTURE',json.dumps(str(cls.root)))
        bootstrap=bootstrap.replace('function operatorAction($action,$input){',adapter)
        (cls.root/'bootstrap.php').write_text(bootstrap)
        # Browser security checks use real HTTPS on an ephemeral loopback
        # proxy. Only this fixture certificate is trusted by its context.
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=127.0.0.1',
                        '-keyout',str(cls.root/'browser-key.pem'),'-out',str(cls.root/'browser-cert.pem')],check=True,capture_output=True)
        class Proxy(BaseHTTPRequestHandler):
            def log_message(self,*args):pass
            def forward(self):
                connection=HTTPConnection('127.0.0.1',cls.fixture.port,timeout=15)
                data=self.rfile.read(int(self.headers.get('Content-Length','0')))
                headers={key:value for key,value in self.headers.items() if key.lower() not in ['connection','content-length']}
                connection.request(self.command,self.path,body=data or None,headers=headers)
                response=connection.getresponse();body=response.read();self.send_response(response.status)
                for key,value in response.getheaders():
                    if key.lower() not in ['connection','content-length','transfer-encoding']:self.send_header(key,value)
                self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body);connection.close()
            do_GET=forward
            do_POST=forward
        cls.proxy=ThreadingHTTPServer(('127.0.0.1',0),Proxy)
        tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(str(cls.root/'browser-cert.pem'),str(cls.root/'browser-key.pem'))
        cls.proxy.socket=tls.wrap_socket(cls.proxy.socket,server_side=True)
        cls.proxy_thread=threading.Thread(target=cls.proxy.serve_forever,daemon=True);cls.proxy_thread.start()
        cls.browser_url='https://127.0.0.1:'+str(cls.proxy.server_port)
        cls.playwright = sync_playwright().start()
        cls.browser = cls.playwright.chromium.launch(executable_path=browser_path, headless=True,
                                                   args=['--no-sandbox', '--disable-dev-shm-usage', '--disable-background-networking'])
        cls.context = cls.browser.new_context(viewport={'width': 1440, 'height': 1000},ignore_https_errors=True)
        def fixture_route(route):
            url = route.request.url
            if not url.startswith(cls.browser_url + '/'):
                route.abort();return
            route.continue_()
        cls.context.route('**/*', fixture_route)
        cls.page = cls.context.new_page()
        cls.errors = []
        cls.page.on('pageerror', lambda error: cls.errors.append(str(error)))

    @classmethod
    def tearDownClass(cls):
        errors=[line for line in (cls.root/'server.log').read_text().splitlines() if any(term in line for term in ['Fatal error','Parse error','Warning:'])]
        if errors:print('\n'.join(errors[-10:]),flush=True)
        cls.context.close();cls.browser.close();cls.playwright.stop();cls.proxy.shutdown();cls.proxy.server_close();cls.proxy_thread.join();cls.fixture.tearDownClass()

    def test_central_tabs_identity_revisions_typed_editors_and_warning_delay(self):
        page = self.page
        url = self.browser_url + '/config.php?display=slsmassnotifyserver_enterprise'
        page.goto(url)
        self.assertEqual(page.locator('#sls-enterprise').count(), 1, page.content()[:2000])
        page.locator('#sls-labs-danger[open]').wait_for()
        self.assertEqual(page.locator('[data-danger-review]').count(), 0)
        self.assertIn('DANGER! DO NOT USE ON PRODUCTION READY SERVERS!', page.locator('#sls-labs-danger-title').inner_text())
        self.assertTrue(page.locator('#sls-labs-danger-agree').is_disabled())
        page.locator('#sls-labs-danger-cancel').click()
        page.reload()
        page.locator('#sls-labs-danger[open]').wait_for()
        started = time.monotonic()
        self.assertTrue(page.locator('#sls-labs-danger-agree').is_disabled())
        self.assertTrue(page.locator('#sls-labs-danger-accept').is_disabled())
        page.wait_for_timeout(4100)
        self.assertTrue(page.locator('#sls-labs-danger-agree').is_disabled())
        page.wait_for_function('!document.getElementById("sls-labs-danger-agree").disabled')
        self.assertGreaterEqual(time.monotonic() - started, 4.8)
        page.locator('#sls-labs-danger-agree').check()
        page.locator('#sls-labs-danger-accept').click()
        page.wait_for_function('!document.getElementById("sls-labs-danger").open')
        page.reload()
        page.wait_for_timeout(300)
        self.assertEqual(page.locator('#sls-labs-danger[open]').count(), 0)
        self.assertFalse(page.locator('#sls-cluster-config input[name=enabled]').is_checked())
        self.assertFalse(page.locator('#sls-cluster-config input[name=mirroring_enabled]').is_checked())
        page.locator('[data-labs-tab="identity"]').click()
        page.locator('[data-save-config="identity"]').click()
        page.locator('[data-identity-status]').get_by_text('Enterprise identity saved.', exact=False).wait_for()
        self.assertEqual(page.locator('#sls-labs-danger[open]').count(), 0)
        page.locator('[data-add-provider]').click()
        provider = json.loads(page.locator('[data-config="identity"]').input_value())['providers'][0]
        self.assertEqual(provider['vendor'], 'entra');self.assertFalse(provider['enabled'])
        page.locator('[data-labs-tab="operations"]').click()
        for section, fields in [('dual_approval', {'Name':'Fixture approval','Review lifetime (seconds)':'300'}),
                                ('drills', {'Name':'Fixture drill','Completion deadline':'2027-01-01T12:00'}),
                                ('shift_routing', {'Name':'Fixture shift','Start time':'08:00','End time':'17:00'})]:
            page.locator('[data-add-operation="' + section + '"]').click()
            dialog = page.locator('#sls-enterprise-editor')
            for label, value in fields.items():
                dialog.get_by_label(label, exact=True).fill(value)
            if section == 'dual_approval':
                dialog.get_by_label('Channels requiring review', exact=False).select_option(['phones'])
                dialog.get_by_label('Authorized people', exact=False).select_option(['pbx:fixture-admin','pbx:reviewer'])
            if section == 'drills':
                dialog.get_by_label('Review objectives', exact=False).fill('Observe the test\nRecord an observation')
            if section == 'shift_routing':
                dialog.get_by_label('Incident templates', exact=False).select_option(['tpl_' + 'a'*24])
                dialog.get_by_label('Start weekdays', exact=False).select_option(['1','2'])
                dialog.get_by_label('On-duty saved audiences', exact=False).select_option(['grp_' + 'a'*24])
            dialog.locator('[type="submit"]').click()
            self.assertEqual(page.locator('[data-operation-list="'+section+'"] .sls-ent-row').count(),1)
        page.get_by_role('button',name='View incident overlay',exact=True).click()
        page.locator('#sls-enterprise-editor [type=submit]').click()
        page.locator('#sls-enterprise-editor .sls-ent-map img').wait_for()
        page.wait_for_function('document.querySelector("#sls-enterprise-editor .sls-ent-map img").naturalWidth===2')
        self.assertTrue(page.locator('#sls-enterprise-editor').evaluate('(n)=>n.open'))
        self.assertEqual(page.locator('#sls-enterprise-editor .sls-ent-marker').get_attribute('data-status'),'unconfirmed')
        page.locator('#sls-enterprise-editor [type=submit]').click()
        self.assertTrue(page.locator('#sls-enterprise-editor').evaluate('(n)=>n.open'))
        page.locator('#sls-enterprise-editor [data-close-enterprise]').first.click()
        page.locator('[data-add-operation="floorplans"]').click()
        self.assertEqual(page.locator('#sls-enterprise-editor input[type=file]').count(),1)
        page.locator('#sls-enterprise-editor [data-close-enterprise]').first.click()
        with page.expect_navigation():page.locator('#sls-operations-save').click()
        self.assertEqual(page.locator('[data-operation-list="drills"] .sls-ent-row').count(),1)
        page.locator('[data-labs-tab="integrations"]').click()
        page.locator('[data-add="speaker"]').click()
        self.assertEqual(page.locator('#sls-integration-speakers .sls-integration-row').count(),1)
        page.locator('[data-labs-tab="continuity"]').click()
        page.locator('#sls-cluster-config input[name=enabled]').check()
        page.locator('#sls-cluster-config input[name=mirroring_enabled]').check()
        page.wait_for_timeout(200)
        self.assertEqual(page.locator('#sls-labs-danger[open]').count(),0)
        page.locator('#sls-cluster-config input[name=mirroring_enabled]').uncheck()
        page.locator('#sls-cluster-config input[name=mirroring_enabled]').check()
        self.assertEqual(page.locator('#sls-labs-danger[open]').count(),0)
        self.assertEqual(self.errors,[])
        # Inspect responsiveness using actual rendered central widgets.
        page.set_viewport_size({'width':390,'height':844})
        page.locator('[data-labs-tab="identity"]').click()
        self.assertLessEqual(page.evaluate('document.documentElement.scrollWidth'), 420)
        page.set_viewport_size({'width':1440,'height':1000})
        print('Central Chromium: tabs, revisioned identity POST, typed approval/drill/shift/floorplan editors, private overlay refresh, speaker row, five-second warning and mobile width passed.',flush=True)

    def test_portal_approval_drill_review_and_private_overlay(self):
        page=self.page;helper_instance=helper.PortalHttpTests()
        cookie=helper_instance.login();name,value=cookie.split('=',1)
        self.context.add_cookies([{'name':name,'value':value,'domain':'127.0.0.1','path':'/mass-notify/','secure':True,'httpOnly':True,'sameSite':'Strict'}])
        url=self.browser_url+'/mass-notify/'
        page.goto(url)
        self.assertEqual(page.locator('#sls-operations').count(),1,page.content()[:1500])
        expired='review_'+'b'*32
        for action in ['approve','submit']:
            self.assertTrue(page.locator('[data-approval="'+expired+'"][data-approval-action="'+action+'"]').is_disabled())
        self.assertFalse(page.locator('[data-approval="'+expired+'"][data-approval-action="reject"]').is_disabled())
        page.get_by_text('Assigned approval',exact=False).first.click()
        page.locator('[data-approval="'+self.review_id+'"][data-approval-action="approve"]').click()
        self.assertIn('Frozen fixture wording',page.locator('#ops-modal-body').inner_text())
        try:
            with page.expect_navigation(timeout=5000):page.locator('#ops-modal-submit').click()
        except Exception as error:
            raise AssertionError('Portal approval UI failed: '+page.locator('#ops-modal-status').inner_text()+'; browser errors: '+json.dumps(self.errors)) from error
        self.assertEqual(json.loads((self.root/'browser-action.json').read_text()),['approval_approve',{'review_id':self.review_id}])
        page.get_by_text('Assigned exercise',exact=False).first.click()
        page.locator('[data-drill-review]').click()
        page.locator('#ops-modal input[type=checkbox]').check()
        page.locator('#ops-modal textarea').fill('Browser fixture observation')
        with page.expect_navigation():page.locator('#ops-modal-submit').click()
        action,input_=json.loads((self.root/'browser-action.json').read_text());self.assertEqual(action,'drill_review')
        self.assertEqual(input_,{'run_id':self.run_id,'completed':[True],'note':'Browser fixture observation'})
        page.get_by_text('Assigned exercise',exact=False).first.click()
        page.locator('[data-drill-start]').click()
        self.assertIn('DRILL / TEST',page.locator('#ops-modal-body').inner_text())
        with page.expect_navigation():page.locator('#ops-modal-submit').click()
        action,input_=json.loads((self.root/'browser-action.json').read_text());self.assertEqual(action,'drill_start')
        self.assertEqual(input_['assignment_id'],self.drill_id);self.assertRegex(input_['request_id'],r'^[a-f0-9]{32}$')
        page.locator('[data-floorplan]').click()
        page.locator('#ops-modal-submit').click()
        page.locator('.ops-floorplan img').wait_for()
        self.assertEqual(page.locator('.ops-floorplan-marker').get_attribute('data-status'),'unconfirmed')
        self.assertEqual(page.locator('.ops-floorplan-marker').evaluate('(n)=>n.style.left'),'50%')
        self.assertIn('no response: 1',page.locator('#ops-modal-body').inner_text())
        self.assertEqual(page.locator('.ops-floorplan img').evaluate('(n)=>n.naturalWidth'),2)
        self.assertEqual(self.errors,[])
        page.locator('#ops-modal [data-close]').first.click()
        page.set_viewport_size({'width':390,'height':844})
        self.assertLessEqual(page.evaluate('document.documentElement.scrollWidth'),420)
        print('Portal Chromium: actual local password/MFA session, expired approval controls, frozen review POST, drill launch/review POST, private response overlay and mobile width passed.',flush=True)

    def test_populated_operator_security_activity_is_safe_and_bounded(self):
        page=self.page
        for width,height in [(390,844),(1440,1000)]:
            page.set_viewport_size({'width':width,'height':height})
            page.goto(self.browser_url+'/operator-config.php')
            table=page.locator('.ops-audit-scroll table')
            self.assertEqual(table.locator('tbody tr').count(),4,page.content()[:1500])
            self.assertEqual(table.locator('thead th').count(),7)
            self.assertLessEqual(page.evaluate('document.documentElement.scrollWidth'),width)
            self.assertLessEqual(page.locator('.ops-audit-scroll').evaluate('(n)=>n.getBoundingClientRect().right'),width)
            self.assertFalse(page.evaluate('window.auditInjected===true'))
            self.assertIn('<script>window.auditInjected=true</script>',table.inner_text())
            self.assertNotIn('security_activity',page.locator('#sls-operator-data').text_content())
            self.assertTrue(table.locator('th').evaluate_all('(ns)=>ns.every(n=>n.getBoundingClientRect().width>20)'))
            # Account editing still opens normally; activity remains a read view.
            page.locator('#ops-add').click()
            self.assertTrue(page.locator('#ops-modal').is_visible())
            page.locator('#ops-modal [data-close]').first.click()
        self.assertEqual(self.errors,[])
        print('Operator Access Chromium: four attributable security rows, long/escaped actors, seven readable headers, account editor, and exact 390/1440 page bounds passed.',flush=True)


if __name__ == '__main__':
    unittest.main()
