#!/usr/bin/python3
"""Isolated real HTTP entrypoints; canonical encrypted settings and inert email proofs."""
import http.client,json,os,pathlib,re,shutil,signal,socket,subprocess,tempfile,time,unittest,urllib.parse
ROOT=pathlib.Path(__file__).resolve().parents[1];MODULE=ROOT/'slsmassnotifyserver'
isolated=((os.environ.get('SLS_TEST_NAMESPACE')=='entered' and os.environ.get('SLS_TEST_PARENT_NET_NS') not in (None,os.readlink('/proc/self/ns/net')))
    or (os.environ.get('SLS_BUILD_STAGE')=='isolated' and os.environ.get('SLS_BUILD_PARENT_NET') not in (None,os.readlink('/proc/self/ns/net'))))
if not isolated:raise SystemExit('Use tools/run_isolated_tests.sh; no HTTP server started.')
class EnterpriseHttp(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  subprocess.run([shutil.which('ip'),'link','set','lo','up'],check=True,capture_output=True)
  cls.temp=tempfile.TemporaryDirectory(prefix='sls-enterprise-http-');cls.root=pathlib.Path(cls.temp.name);cls.root.chmod(0o700)
  # Match the installer topology exactly. /var/www/html is a private tmpfs
  # in this already verified mount namespace; deployed entrypoints are copied
  # byte for byte and never receive a filesystem-path rewrite.
  cls.deployment=pathlib.Path('/var/www/html')
  subprocess.run(['mount','-t','tmpfs','-o','mode=755,size=32M','tmpfs',str(cls.deployment)],check=True,capture_output=True)
  cls.module=cls.deployment/'admin/modules/slsmassnotifyserver'
  cls.module.parent.mkdir(parents=True);shutil.copytree(MODULE,cls.module)
  shutil.copytree(MODULE/'portal',cls.deployment/'mass-notify')
  for name in ['sso','subscriber']:
   assert (cls.deployment/'mass-notify'/f'{name}.php').read_bytes()==(MODULE/'portal'/f'{name}.php').read_bytes()
  # Only the copied protocol transport is inert. The real deployed entrypoint,
  # module paths, encrypted config paths, incidents and journals stay unchanged.
  adapter=cls.module/'EnterpriseIdentityOidc.php'
  source=adapter.read_text();source=source.replace("$this->http=$http??[EnterpriseIdentityHttp::class,'request'];", "$this->http=$http??'enterpriseFixtureHttp';")
  adapter.write_text(source)
  cls.root=pathlib.Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin');cls.root.chmod(0o700)
  (cls.root/'sessions').mkdir(mode=0o700)
  (cls.root/'incidents').mkdir(mode=0o750)
  cls.seed='''<?php
require MODULE.'/SubscriberService.php';require MODULE.'/IncidentService.php';require MODULE.'/ConfigCrypto.php';require MODULE.'/EnterpriseIdentity.php';
use SLS\\MassNotify\\{SubscriberConfig,SubscriberService,EnterpriseIdentityStore,IncidentStore,IncidentConfig,IncidentService};
$root=$argv[1];$now=time();$settings=['public_pbx_host'=>'pbx.example.test','subscriber_browser'=>['schema'=>1,'enabled'=>true,'public_base_url'=>'https://pbx.example.test/mass-notify/subscriber.php','link_ttl_seconds'=>900,'people'=>[['id'=>'sub_'.bin2hex(random_bytes(12)),'person_id'=>'fixture-person','name'=>'Fixture Person','email'=>'person@example.test','enabled'=>true,'version'=>str_repeat('a',64),'registered_at'=>$now-60]]],'enterprise_identity'=>['schema'=>1,'enabled'=>false,'providers'=>[],'grants'=>[]],'operator_access'=>['schema'=>1,'enabled'=>false,'portal_enabled'=>true,'accounts'=>[]]];
$t=IncidentConfig::template(['id'=>'tpl_'.str_repeat('a',24),'name'=>'Fixture','title'=>'Fixture <incident>','message'=>'Fixture message','delivery'=>['extensions'=>['1000']],'roster'=>[['id'=>'fixture-person','name'=>'Fixture Person'],['id'=>'another-person','name'=>'Private Person']]]);
$store=new IncidentStore($root.'/incidents');$service=new IncidentService($store,fn()=>$t,fn($d)=>$d,fn()=>['success'=>true,'job_id'=>'job_'.str_repeat('a',32)],fn()=>[],fn()=>$now);
$incident=$service->start(['template_id'=>$t['id'],'request_id'=>bin2hex(random_bytes(16)),'fields'=>[],'is_test'=>true],['identity'=>'Fixture admin']);
$subscriber=new SubscriberService(new EnterpriseIdentityStore($root.'/enterprise-state'),$store);$issued=$subscriber->issue($settings,$settings['subscriber_browser']['people'][0]['id'],$incident['id'],$now);$subscriber->delivery($issued['token_hash'],'accepted');
$settings['desktop_auth_key']=base64_encode(str_repeat('k',32));$account=['id'=>'api_'.str_repeat('b',24),'source'=>'portal','username'=>'fixture-viewer','name'=>'Fixture viewer','email'=>'viewer@example.test','role'=>'viewer','enabled'=>true,'site_ids'=>[],'group_ids'=>[],'personal_members'=>['extensions'=>['1000']]];
$account['auth']=['password_hash'=>SLS\\MassNotify\\OperatorAuth::dummyHash(),'version'=>str_repeat('b',64),'force_password_change'=>false,'totp_secret_enc'=>SLS\\MassNotify\\OperatorAuth::seal('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',$account['id'],$settings),'totp_last_counter'=>-1,'recovery_hashes'=>[]];$account['identity']=SLS\\MassNotify\\OperatorAuth::identity($account);$settings['operator_access']['accounts'][]=$account;
$provider=['id'=>'idp_'.str_repeat('c',24),'vendor'=>'okta','protocol'=>'oidc','name'=>'Fixture SSO','enabled'=>true,'issuer'=>'https://idp.example.test','callback_url'=>'https://pbx.example.test/mass-notify/sso.php','discovery_url'=>'https://idp.example.test/discovery','client_id'=>'fixture-client','client_secret'=>'inert-secret','token_auth'=>'client_secret_basic','endpoint_hosts'=>['idp.example.test'],'hosted_domain'=>''];
$settings['enterprise_identity']['providers']=[$provider];$settings['enterprise_identity']['grants']=[['provider_id'=>$provider['id'],'subject'=>'stable-42','operator_account_id'=>$account['id'],'enabled'=>true]];
if(!file_exists($root.'/fixture-private.pem')){$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);openssl_pkey_export($key,$private);file_put_contents($root.'/fixture-private.pem',$private);$d=openssl_pkey_get_details($key);$b=static fn($x)=>rtrim(strtr(base64_encode($x),'+/','-_'),'=');file_put_contents($root.'/fixture-keys.json',json_encode(['keys'=>[['kty'=>'RSA','alg'=>'RS256','kid'=>'fixture','n'=>$b($d['rsa']['n']),'e'=>$b($d['rsa']['e'])]]]));}
file_put_contents($root.'/mass-notifications.config',FreePBX\\modules\\SlsConfigCrypto::encode($settings));chmod($root.'/mass-notifications.config',0640);echo json_encode(['settings'=>$settings,'incident_id'=>$incident['id'],'token'=>$issued['token']]);
'''.replace('MODULE',json.dumps(str(cls.module)))
  (cls.root/'seed.php').write_text(cls.seed)
  cls.router='''<?php
$_SERVER['HTTPS']=isset($_GET['insecure'])?'off':'on';if(isset($_GET['insecure']))unset($_GET['insecure']);
function enterpriseFixtureHttp($url,$hosts,$method,$form,$headers){$base='https://idp.example.test';if($url===$base.'/discovery'){return ['issuer'=>$base,'authorization_endpoint'=>$base.'/authorize','token_endpoint'=>$base.'/token','jwks_uri'=>$base.'/keys','response_types_supported'=>['code'],'id_token_signing_alg_values_supported'=>['RS256'],'token_endpoint_auth_methods_supported'=>['client_secret_basic'],'code_challenge_methods_supported'=>['S256']];}if($url===$base.'/keys'){return json_decode(file_get_contents(__DIR__.'/fixture-keys.json'),true);}if($url===$base.'/token'){return ['id_token'=>trim(file_get_contents(__DIR__.'/fixture-token.jwt'))];}throw new RuntimeException('Unexpected synthetic provider endpoint');}
$p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(in_array($p,['/fixture-login','/fixture-current'])){
require MODULE.'/OperatorPortal.php';session_name(SLS\\MassNotify\\OperatorPortal::COOKIE);session_set_cookie_params(['lifetime'=>0,'path'=>'/mass-notify/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_start();header('Content-Type: application/json');
if($p==='/fixture-login'){echo json_encode(['csrf'=>SLS\\MassNotify\\OperatorPortal::csrf($_SESSION)]);exit;}
$settings=SLS\\MassNotify\\LivePagingSession::loadSettings(__DIR__.'/mass-notifications.config');$b=$_SESSION['portal_identity']??[];$account=$settings['operator_access']['accounts'][0];$valid=SLS\\MassNotify\\OperatorPortal::current($b,$account,1800,time())&&SLS\\MassNotify\\EnterpriseIdentity::current($settings,$b['enterprise_identity']??[],$account['id'],time());http_response_code($valid?200:401);echo json_encode(['valid'=>$valid,'principal'=>$valid?SLS\\MassNotify\\OperatorAccess::principal($settings,$account['id']):null]);exit;}
if(!in_array($p,['/mass-notify/subscriber.php','/mass-notify/sso.php'])){http_response_code(404);exit;}require $_SERVER['DOCUMENT_ROOT'].$p;
'''.replace('MODULE',json.dumps(str(cls.module)))
  (cls.root/'router.php').write_text(cls.router)
  # Exercise the real human-audit producer after verified SSO. Only the PBX
  # container bootstrap is replaced inside this disposable mount namespace.
  bootstrap='''<?php
require MODULE.'/OperatorRecoveryService.php';
class EnterpriseSsoAuditFixture{use \\FreePBX\\modules\\SlsOperatorRecovery;
 const RUNTIME_DIR='/usr/local/bin/sls_mass_notify';
 public function getActiveSettings(){return \\SLS\\MassNotify\\LivePagingSession::loadSettings('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config');}
}
class FreePBX{public static function create(){return (object)['Slsmassnotifyserver'=>new EnterpriseSsoAuditFixture()];}}
'''.replace('MODULE',json.dumps(str(cls.module)))
  (cls.root/'sso-bootstrap.php').write_text(bootstrap)
  subprocess.run(['mount','--bind',str(cls.root/'sso-bootstrap.php'),'/etc/freepbx.conf'],check=True,capture_output=True)
  sock=socket.socket();sock.bind(('127.0.0.1',0));cls.port=sock.getsockname()[1];sock.close();cls.log=(cls.root/'server.log').open('w')
  cls.server=subprocess.Popen(['php','-d','session.save_path='+str(cls.root/'sessions'),'-S','127.0.0.1:'+str(cls.port),'-t',str(cls.deployment),str(cls.root/'router.php')],cwd=cls.root,stdout=cls.log,stderr=cls.log,start_new_session=True)
  for _ in range(100):
   try:c=http.client.HTTPConnection('127.0.0.1',cls.port,timeout=1);c.request('GET','/health');c.getresponse().read();c.close();break
   except OSError:time.sleep(.02)
 @classmethod
 def tearDownClass(cls):
  os.killpg(cls.server.pid,signal.SIGTERM);cls.server.wait(timeout=5);cls.log.close();subprocess.run(['umount',str(cls.deployment)],check=True,capture_output=True);cls.temp.cleanup()
 def setUp(self):
  self.fixture=json.loads(subprocess.check_output(['php',str(self.root/'seed.php'),str(self.root)],text=True));self.settings=self.fixture['settings']
 def write_settings(self):
  code="require "+json.dumps(str(MODULE/'ConfigCrypto.php'))+";echo FreePBX\\modules\\SlsConfigCrypto::encode(json_decode(stream_get_contents(STDIN),true));"
  result=subprocess.check_output(['php','-r',code],input=json.dumps(self.settings),text=True);(self.root/'mass-notifications.config').write_text(result);(self.root/'mass-notifications.config').chmod(0o640)
 def request(self,path='/mass-notify/subscriber.php',post=None,cookie='',origin='https://pbx.example.test',site='same-origin'):
  c=http.client.HTTPConnection('127.0.0.1',self.port,timeout=5);headers={'Host':'pbx.example.test'}
  if cookie:headers['Cookie']=cookie
  if post is not None:headers.update({'Content-Type':'application/x-www-form-urlencoded','Origin':origin,'Sec-Fetch-Site':site})
  c.request('GET' if post is None else 'POST',path,urllib.parse.urlencode(post) if post is not None else None,headers);r=c.getresponse();data=(r.status,dict(r.getheaders()),r.read().decode());c.close();return data
 def anonymous(self):
  status,h,body=self.request();self.assertEqual(status,200);self.assertIn('no-store',h['Cache-Control']);self.assertIn('HttpOnly',h['Set-Cookie']);self.assertIn('SameSite=Strict',h['Set-Cookie']);self.assertEqual(h['Referrer-Policy'],'no-referrer')
  csrf=re.search(r'portal_csrf:"([a-f0-9]{64})"',body)
  if not csrf:csrf=re.search(r'"portal_csrf":"([a-f0-9]{64})"',body)
  self.assertIsNotNone(csrf,body);return h['Set-Cookie'].split(';',1)[0],csrf[1]
 def verify(self):
  cookie,csrf=self.anonymous();status,h,_=self.request(post={'subscriber_action':'verify','portal_csrf':csrf,'token':self.fixture['token']},cookie=cookie);self.assertEqual(status,303);cookie=h['Set-Cookie'].split(';',1)[0];status,_,body=self.request(cookie=cookie);self.assertEqual(status,200);return cookie,re.search(r'name="portal_csrf" value="([a-f0-9]{64})"',body)[1],body
 def test_copied_deployment_entrypoints_keep_exact_source_paths(self):
  for name in ['sso','subscriber']:
   self.assertEqual((self.deployment/'mass-notify'/f'{name}.php').read_bytes(),(MODULE/'portal'/f'{name}.php').read_bytes())
  self.assertNotEqual(self.module.parent,self.deployment)
  self.assertEqual(self.request()[0],200)
  self.assertEqual(self.request('/mass-notify/sso.php')[0],404)
 def test_email_proof_response_and_privacy(self):
  cookie,csrf,body=self.verify();self.assertIn('Fixture &lt;incident&gt;',body);self.assertNotIn('Private Person',body);self.assertNotIn(self.fixture['token'],body)
  post={'subscriber_action':'respond','portal_csrf':csrf,'request_id':'b'*32,'response':'needs_assistance','note':'Fixture help'};self.assertEqual(self.request(post=post,cookie=cookie)[0],303)
  status,_,body=self.request(cookie=cookie);self.assertEqual(status,200);self.assertIn('I need help',body)
  record=json.loads((self.root/'incidents'/(self.fixture['incident_id']+'.json')).read_text());self.assertEqual(record['responses']['fixture-person']['source'],'human_browser_response');self.assertNotIn('another-person',record['responses'])
  # Changing a saved identity invalidates the existing verified session.
  self.settings['subscriber_browser']['people'][0]['version']='c'*64;self.write_settings();self.assertEqual(self.request(cookie=cookie)[0],400)
 def test_expired_replayed_and_cross_person_proofs(self):
  cookie,csrf=self.anonymous();self.assertEqual(self.request(post={'subscriber_action':'verify','portal_csrf':csrf,'token':'0'*64},cookie=cookie)[0],400)
  cookie,csrf,body=self.verify();post={'subscriber_action':'respond','portal_csrf':csrf,'request_id':'d'*32,'response':'safe','person_id':'another-person'};self.assertEqual(self.request(post=post,cookie=cookie)[0],400)
  cookie2,csrf2=self.anonymous();self.assertEqual(self.request(post={'subscriber_action':'verify','portal_csrf':csrf2,'token':self.fixture['token']},cookie=cookie2)[0],400)
 def test_csrf_https_and_disabled_entrypoints(self):
  cookie,csrf=self.anonymous();post={'subscriber_action':'verify','portal_csrf':csrf,'token':self.fixture['token']}
  for origin,site in [('https://attacker.example.test','cross-site'),('null','cross-site'),('https://pbx.example.test:8443','same-origin')]:self.assertEqual(self.request(post=post,cookie=cookie,origin=origin,site=site)[0],403)
  self.assertEqual(self.request('/mass-notify/subscriber.php?insecure=1')[0],403)
  self.assertEqual(self.request('/mass-notify/sso.php')[0],404)
  self.settings['subscriber_browser']['enabled']=False;self.write_settings();self.assertEqual(self.request()[0],404);self.assertEqual(self.request(post=post,cookie=cookie)[0],404)
 def test_oidc_actual_http_callback_local_grant_and_revocation(self):
  self.settings['enterprise_identity']['enabled']=True;self.write_settings()
  status,h,body=self.request('/fixture-login');self.assertEqual(status,200);cookie=h['Set-Cookie'].split(';',1)[0];csrf=json.loads(body)['csrf']
  status,h,_=self.request('/mass-notify/sso.php',post={'provider_id':self.settings['enterprise_identity']['providers'][0]['id'],'portal_csrf':csrf},cookie=cookie);self.assertEqual(status,303);self.assertIn('SameSite=None',h['Set-Cookie']);browser=h['Set-Cookie'].split(';',1)[0]
  query=urllib.parse.parse_qs(urllib.parse.urlparse(h['Location']).query);self.assertEqual(query['code_challenge_method'],['S256']);nonce=query['nonce'][0];state=query['state'][0]
  code="require "+json.dumps(str(MODULE/'identity-libs/vendor/autoload.php'))+";$c=['iss'=>'https://idp.example.test','aud'=>'fixture-client','sub'=>'stable-42','nonce'=>$argv[1],'iat'=>time(),'exp'=>time()+300,'roles'=>['administrator']];echo Firebase\\JWT\\JWT::encode($c,file_get_contents($argv[2]),'RS256','fixture');"
  token=subprocess.check_output(['php','-r',code,nonce,str(self.root/'fixture-private.pem')],text=True);(self.root/'fixture-token.jwt').write_text(token)
  callback='/mass-notify/sso.php?'+urllib.parse.urlencode({'state':state,'code':'inert-code'})
  status,h,_=self.request(callback,cookie=browser);self.assertEqual(status,303);portal=h['Set-Cookie'].split(';',1)[0]
  status,_,body=self.request('/fixture-current',cookie=portal);self.assertEqual(status,200);self.assertEqual(json.loads(body)['principal']['operator_role'],'viewer')
  audit=[json.loads(row) for row in (self.root/'security-audit.jsonl').read_text().splitlines()]
  self.assertEqual(len(audit),1);self.assertEqual(audit[0]['action'],'operator_sign_in_completed');self.assertEqual(audit[0]['operator_id'],self.settings['operator_access']['accounts'][0]['id'])
  self.assertEqual(audit[0]['actor'],'operator:fixture-viewer');self.assertEqual(audit[0]['method'],'GET');self.assertNotIn('stable-42',json.dumps(audit));self.assertNotIn(token,json.dumps(audit));self.assertFalse((self.root/'control-api-audit.jsonl').exists())
  self.assertEqual(self.request(callback,cookie=browser)[0],401)
  self.assertEqual(len((self.root/'security-audit.jsonl').read_text().splitlines()),1)
  self.settings['enterprise_identity']['grants'][0]['enabled']=False;self.write_settings();self.assertEqual(self.request('/fixture-current',cookie=portal)[0],401)
 def test_readiness_and_journal_missing_fail_closed(self):
  self.assertTrue(json.loads((self.root/'mass-notifications.config').read_text()).get('ciphertext'))
  (self.root/'enterprise-state/subscriber_tokens.json').unlink();cookie,csrf=self.anonymous();status,_,body=self.request(post={'subscriber_action':'verify','portal_csrf':csrf,'token':self.fixture['token']},cookie=cookie);self.assertEqual(status,503);self.assertNotIn(self.fixture['token'],body)
if __name__=='__main__':unittest.main()
