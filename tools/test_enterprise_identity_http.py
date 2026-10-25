#!/usr/bin/python3
"""Isolated real HTTP entrypoints; canonical encrypted settings and inert email proofs."""
import http.client,json,os,pathlib,re,shutil,signal,socket,subprocess,sys,tempfile,time,unittest,urllib.parse
ROOT=pathlib.Path(__file__).resolve().parents[1];MODULE=ROOT/'slsmassnotifyserver'
IP='/usr/sbin/ip';MOUNT='/usr/bin/mount';UMOUNT='/usr/bin/umount';PHP='/usr/bin/php';MAX_RESPONSE=2*1024*1024
isolated=all(os.environ.get('SLS_TEST_NAMESPACE')=='entered' and
             os.environ.get('SLS_TEST_PARENT_'+label+'_NS') not in
             (None,'',os.readlink('/proc/self/ns/'+kind))
             for label,kind in [('MOUNT','mnt'),('NET','net'),('PID','pid')])
if not isolated:raise SystemExit('Use tools/run_isolated_tests.sh; no HTTP server started.')
def decode_json(raw):
 def invalid_number(value):raise ValueError('Invalid JSON number: '+value)
 def unique(pairs):
  value={}
  for key,item in pairs:
   if key in value:raise ValueError('Repeated JSON key: '+key)
   value[key]=item
  return value
 return json.loads(raw,object_pairs_hook=unique,parse_constant=invalid_number)
class EnterpriseHttp(unittest.TestCase):
 @staticmethod
 def bounded_bytes(path,maximum):
  with path.open('rb') as handle:data=handle.read(maximum+1)
  if len(data)>maximum:raise RuntimeError('Fixture file exceeded its bound: '+str(path))
  return data
 @staticmethod
 def tail(path,maximum):
  with path.open('rb') as handle:
   handle.seek(0,os.SEEK_END);size=handle.tell();handle.seek(max(0,size-maximum));return handle.read(maximum)
 @classmethod
 def command(cls,args,**kwargs):
  result=subprocess.run(args,check=True,capture_output=True,timeout=kwargs.pop('timeout',20),env=cls.env,**kwargs)
  return result
 @classmethod
 def php(cls,args,input_text=None,maximum=2*1024*1024):
  with tempfile.TemporaryFile(dir=cls.root) as output,tempfile.TemporaryFile(dir=cls.root) as errors:
   result=subprocess.run([PHP,*args],input=input_text,text=True,stdout=output,stderr=errors,timeout=20,env=cls.env,check=False)
   output.flush();errors.flush();output_size=output.tell();error_size=errors.tell()
   if output_size>maximum or error_size>65536:raise RuntimeError('PHP fixture output exceeded its bound.')
   output.seek(0);errors.seek(0);stdout=output.read().decode('utf-8');stderr=errors.read().decode('utf-8','replace')
  if result.returncode:raise RuntimeError('PHP fixture failed with exit '+str(result.returncode)+': '+stderr[-4096:])
  if stderr:raise RuntimeError('PHP fixture wrote to stderr: '+stderr[-4096:])
  return stdout
 @classmethod
 def mounted(cls,args,target):
  cls.command([MOUNT,*args]);cls.mounts.append(str(target))
 @classmethod
 def cleanup(cls):
  failures=[]
  if cls.server is not None and cls.server.poll() is None:
   try:os.killpg(cls.server.pid,signal.SIGTERM)
   except ProcessLookupError:pass
   try:cls.server.wait(timeout=5)
   except subprocess.TimeoutExpired:
    try:os.killpg(cls.server.pid,signal.SIGKILL);cls.server.wait(timeout=5)
    except BaseException as error:failures.append(error)
  if cls.log is not None and not cls.log.closed:cls.log.close()
  if cls.server_failed and cls.log_path is not None and cls.log_path.exists():
   print('\nBounded HTTP 5xx responses:\n'+repr(cls.failed_responses[-20:]),file=sys.stderr)
   print('\nBounded PHP server diagnostics:\n'+cls.tail(cls.log_path,65536).decode('utf-8','replace'),file=sys.stderr)
  for target in reversed(cls.mounts):
   try:cls.command([UMOUNT,target],timeout=10)
   except BaseException as error:failures.append(error)
  if cls.temp is not None:
   try:cls.temp.cleanup()
   except BaseException as error:failures.append(error)
  if failures:raise RuntimeError('Isolated HTTP fixture cleanup failed.') from failures[0]
 @classmethod
 def setUpClass(cls):
  cls.temp=None;cls.server=None;cls.log=None;cls.log_path=None;cls.mounts=[];cls.server_failed=False;cls.failed_responses=[]
  cls.addClassCleanup(cls.cleanup)
  cls.temp=tempfile.TemporaryDirectory(prefix='sls-enterprise-http-');cls.root=pathlib.Path(cls.temp.name);cls.root.chmod(0o700)
  cls.env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','HOME':str(cls.root),'TMPDIR':str(cls.root),'LANG':'C','LC_ALL':'C'}
  cls.command([IP,'link','set','lo','up'])
  cls.data=pathlib.Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
  cls.data.mkdir(parents=True,exist_ok=True)
  cls.private_data=cls.root/'state';cls.private_data.mkdir(mode=0o700)
  cls.mounted(['--bind',str(cls.private_data),str(cls.data)],cls.data)
  # Match the installer topology exactly. /var/www/html is a private tmpfs
  # in this already verified mount namespace; deployed entrypoints are copied
  # byte for byte and never receive a filesystem-path rewrite.
  cls.deployment=pathlib.Path('/var/www/html')
  cls.mounted(['-t','tmpfs','-o','mode=755,size=32M','tmpfs',str(cls.deployment)],cls.deployment)
  cls.module=cls.deployment/'admin/modules/slsmassnotifyserver'
  cls.module.parent.mkdir(parents=True);shutil.copytree(MODULE,cls.module)
  shutil.copytree(MODULE/'portal',cls.deployment/'mass-notify')
  for name in ['sso','subscriber']:
   if (cls.deployment/'mass-notify'/f'{name}.php').read_bytes()!=(MODULE/'portal'/f'{name}.php').read_bytes():
    raise RuntimeError('Deployed entrypoint copy changed: '+name)
  # Only the copied protocol transport is inert. The real deployed entrypoint,
  # module paths, encrypted config paths, incidents and journals stay unchanged.
  adapter=cls.module/'EnterpriseIdentityOidc.php'
  source=adapter.read_text(encoding='utf-8');needle="$this->http=$http??[EnterpriseIdentityHttp::class,'request'];"
  if source.count(needle)!=1:raise RuntimeError('OIDC transport injection boundary changed.')
  replacement="$this->http=$http??'enterpriseFixtureHttp';";updated=source.replace(needle,replacement)
  if adapter.write_text(updated,encoding='utf-8')!=len(updated):raise RuntimeError('OIDC fixture adapter write was incomplete.')
  cls.root=pathlib.Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin');cls.root.chmod(0o700)
  (cls.root/'sessions').mkdir(mode=0o700)
  (cls.root/'incidents').mkdir(mode=0o750)
  cls.seed='''<?php
require MODULE.'/SubscriberService.php';require MODULE.'/IncidentService.php';require MODULE.'/ConfigCrypto.php';require MODULE.'/EnterpriseIdentity.php';
use SLS\\MassNotify\\{SubscriberConfig,SubscriberService,EnterpriseIdentityStore,IncidentStore,IncidentConfig,IncidentService};
$root=$argv[1];$now=time();$settings=['enterprise_labs'=>['enabled'=>true],'public_pbx_host'=>'pbx.example.test','control_api'=>['base_url'=>'https://pbx.example.test/api/sls-mass-notify'],'subscriber_browser'=>['schema'=>1,'enabled'=>true,'public_base_url'=>'https://pbx.example.test/mass-notify/subscriber.php','link_ttl_seconds'=>900,'people'=>[['id'=>'sub_'.bin2hex(random_bytes(12)),'person_id'=>'fixture-person','name'=>'Fixture Person','email'=>'person@example.test','enabled'=>true,'version'=>str_repeat('a',64),'registered_at'=>$now-60]]],'enterprise_identity'=>['schema'=>1,'enabled'=>false,'providers'=>[],'grants'=>[]],'operator_access'=>['schema'=>1,'enabled'=>false,'portal_enabled'=>true,'accounts'=>[]]];
$t=IncidentConfig::template(['id'=>'tpl_'.str_repeat('a',24),'name'=>'Fixture','title'=>'Fixture <incident>','message'=>'Fixture message','delivery'=>['extensions'=>['1000']],'roster'=>[['id'=>'fixture-person','name'=>'Fixture Person'],['id'=>'another-person','name'=>'Private Person']]]);
$store=new IncidentStore($root.'/incidents');$service=new IncidentService($store,fn()=>$t,fn($d)=>$d,fn()=>['success'=>true,'job_id'=>'job_'.str_repeat('a',32)],fn()=>[],fn()=>$now);
$incident=$service->start(['template_id'=>$t['id'],'request_id'=>bin2hex(random_bytes(16)),'fields'=>[],'is_test'=>true],['identity'=>'Fixture admin']);
$subscriber=new SubscriberService(new EnterpriseIdentityStore($root.'/enterprise-state'),$store);$issued=$subscriber->issue($settings,$settings['subscriber_browser']['people'][0]['id'],$incident['id'],$now);$subscriber->delivery($issued['token_hash'],'accepted');
$settings['desktop_auth_key']=base64_encode(str_repeat('k',32));$account=['id'=>'api_'.str_repeat('b',24),'source'=>'portal','username'=>'fixture-viewer','name'=>'Fixture viewer','email'=>'viewer@example.test','role'=>'viewer','enabled'=>true,'site_ids'=>[],'group_ids'=>[],'personal_members'=>['extensions'=>['1000']]];
$account['auth']=['password_hash'=>SLS\\MassNotify\\OperatorAuth::dummyHash(),'version'=>str_repeat('b',64),'force_password_change'=>false,'totp_secret_enc'=>SLS\\MassNotify\\OperatorAuth::seal('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',$account['id'],$settings),'totp_last_counter'=>-1,'recovery_hashes'=>[]];$account['identity']=SLS\\MassNotify\\OperatorAuth::identity($account);$settings['operator_access']['accounts'][]=$account;
$provider=['id'=>'idp_'.str_repeat('c',24),'vendor'=>'okta','protocol'=>'oidc','name'=>'Fixture SSO','enabled'=>true,'issuer'=>'https://idp.example.test','callback_url'=>'https://pbx.example.test/mass-notify/sso.php','discovery_url'=>'https://idp.example.test/discovery','client_id'=>'fixture-client','client_secret'=>'inert-secret','token_auth'=>'client_secret_basic','endpoint_hosts'=>['idp.example.test'],'hosted_domain'=>''];
$settings['enterprise_identity']['providers']=[$provider];$settings['enterprise_identity']['grants']=[['provider_id'=>$provider['id'],'subject'=>'stable-42','operator_account_id'=>$account['id'],'enabled'=>true]];
$write=static function(string $path,string $bytes):void{if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes)){throw new RuntimeException('Fixture write failed.');}};
if(!file_exists($root.'/fixture-private.pem')){$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);if($key===false||!openssl_pkey_export($key,$private)){throw new RuntimeException('Fixture key generation failed.');}$write($root.'/fixture-private.pem',$private);if(!chmod($root.'/fixture-private.pem',0600)){throw new RuntimeException('Fixture key mode failed.');}$d=openssl_pkey_get_details($key);if(!is_array($d)||!isset($d['rsa']['n'],$d['rsa']['e'])){throw new RuntimeException('Fixture key details unavailable.');}$b=static fn($x)=>rtrim(strtr(base64_encode($x),'+/','-_'),'=');$write($root.'/fixture-keys.json',json_encode(['keys'=>[['kty'=>'RSA','alg'=>'RS256','kid'=>'fixture','n'=>$b($d['rsa']['n']),'e'=>$b($d['rsa']['e'])]]],JSON_THROW_ON_ERROR));}
$config=FreePBX\\modules\\SlsConfigCrypto::encode($settings);$write($root.'/mass-notifications.config',$config);if(!chmod($root.'/mass-notifications.config',0640)){throw new RuntimeException('Fixture config mode failed.');}echo json_encode(['settings'=>$settings,'incident_id'=>$incident['id'],'token'=>$issued['token']],JSON_THROW_ON_ERROR);
'''.replace('MODULE',json.dumps(str(cls.module)))
  if (cls.root/'seed.php').write_text(cls.seed,encoding='utf-8')!=len(cls.seed):raise RuntimeError('Seed fixture write was incomplete.')
  (cls.root/'seed.php').chmod(0o600)
  cls.router='''<?php
$_SERVER['HTTPS']=isset($_GET['insecure'])?'off':'on';if(isset($_GET['insecure']))unset($_GET['insecure']);
function enterpriseFixtureHttp($url,$hosts,$method,$form,$headers){$base='https://idp.example.test';if($url===$base.'/discovery'){return ['issuer'=>$base,'authorization_endpoint'=>$base.'/authorize','token_endpoint'=>$base.'/token','jwks_uri'=>$base.'/keys','response_types_supported'=>['code'],'id_token_signing_alg_values_supported'=>['RS256'],'token_endpoint_auth_methods_supported'=>['client_secret_basic'],'code_challenge_methods_supported'=>['S256']];}if($url===$base.'/keys'){$raw=file_get_contents(__DIR__.'/fixture-keys.json');if(!is_string($raw)){throw new RuntimeException('Fixture keys unavailable.');}return json_decode($raw,true,16,JSON_THROW_ON_ERROR);}if($url===$base.'/token'){$raw=file_get_contents(__DIR__.'/fixture-token.jwt');if(!is_string($raw)){throw new RuntimeException('Fixture token unavailable.');}return ['id_token'=>trim($raw)];}throw new RuntimeException('Unexpected synthetic provider endpoint');}
$p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(in_array($p,['/fixture-login','/fixture-current'])){
require MODULE.'/OperatorPortal.php';session_name(SLS\\MassNotify\\OperatorPortal::COOKIE);session_set_cookie_params(['lifetime'=>0,'path'=>'/mass-notify/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);if(!session_start()){throw new RuntimeException('Fixture session unavailable.');}header('Content-Type: application/json');
if($p==='/fixture-login'){echo json_encode(['csrf'=>SLS\\MassNotify\\OperatorPortal::csrf($_SESSION)],JSON_THROW_ON_ERROR);exit;}
$settings=SLS\\MassNotify\\LivePagingSession::loadSettings(__DIR__.'/mass-notifications.config');$b=$_SESSION['portal_identity']??[];$account=$settings['operator_access']['accounts'][0];$valid=SLS\\MassNotify\\OperatorPortal::current($b,$account,1800,time())&&SLS\\MassNotify\\EnterpriseIdentity::current($settings,$b['enterprise_identity']??[],$account['id'],time());http_response_code($valid?200:401);echo json_encode(['valid'=>$valid,'pending'=>isset($_SESSION['portal_pending']),'principal'=>$valid?SLS\\MassNotify\\OperatorAccess::principal($settings,$account['id']):null],JSON_THROW_ON_ERROR);exit;}
if(!in_array($p,['/mass-notify/subscriber.php','/mass-notify/sso.php','/mass-notify/display.php'])){http_response_code(404);exit;}require $_SERVER['DOCUMENT_ROOT'].$p;
'''.replace('MODULE',json.dumps(str(cls.module)))
  if (cls.root/'router.php').write_text(cls.router,encoding='utf-8')!=len(cls.router):raise RuntimeError('Router fixture write was incomplete.')
  (cls.root/'router.php').chmod(0o600)
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
  if (cls.root/'sso-bootstrap.php').write_text(bootstrap,encoding='utf-8')!=len(bootstrap):raise RuntimeError('SSO bootstrap fixture write was incomplete.')
  (cls.root/'sso-bootstrap.php').chmod(0o600)
  cls.mounted(['--bind',str(cls.root/'sso-bootstrap.php'),'/etc/freepbx.conf'],'/etc/freepbx.conf')
  with socket.socket() as sock:sock.bind(('127.0.0.1',0));cls.port=sock.getsockname()[1]
  cls.log_path=cls.root/'server.log';cls.log=cls.log_path.open('w',encoding='utf-8')
  cls.server=subprocess.Popen([PHP,'-d','session.save_path='+str(cls.root/'sessions'),'-S','127.0.0.1:'+str(cls.port),'-t',str(cls.deployment),str(cls.root/'router.php')],cwd=cls.root,stdout=cls.log,stderr=cls.log,start_new_session=True,env=cls.env)
  for _ in range(100):
   if cls.server.poll() is not None:raise RuntimeError('PHP fixture server exited during startup.')
   c=http.client.HTTPConnection('127.0.0.1',cls.port,timeout=1)
   try:c.request('GET','/health');c.getresponse().read(1024);break
   except (OSError,http.client.HTTPException):time.sleep(.02)
   finally:c.close()
  else:raise RuntimeError('PHP fixture server did not become ready.')
 def setUp(self):
  self.fixture=decode_json(self.php([str(self.root/'seed.php'),str(self.root)]));self.assertIsInstance(self.fixture,dict)
  self.assertEqual(set(self.fixture),{'settings','incident_id','token'});self.settings=self.fixture['settings'];self.assertIsInstance(self.settings,dict)
 def run(self,result=None):
  active=result if result is not None else self.defaultTestResult();before=len(active.failures)+len(active.errors)
  returned=super().run(active)
  if len(active.failures)+len(active.errors)>before:type(self).server_failed=True
  return returned
 def read_json(self,path,maximum=4*1024*1024):
  raw=self.bounded_bytes(path,maximum)
  return decode_json(raw.decode('utf-8'))
 def capture(self,pattern,text):
  match=re.search(pattern,text);self.assertIsNotNone(match,text[-4096:]);return match.group(1)
 def write_settings(self):
  code="require "+json.dumps(str(MODULE/'ConfigCrypto.php'))+";$r=stream_get_contents(STDIN);$v=json_decode($r,true,64,JSON_THROW_ON_ERROR);echo FreePBX\\modules\\SlsConfigCrypto::encode($v);"
  result=self.php(['-r',code],json.dumps(self.settings,separators=(',',':'),allow_nan=False),1048576);path=self.root/'mass-notifications.config';temporary=self.root/('.config-fixture-'+os.urandom(12).hex())
  try:
   with temporary.open('x',encoding='utf-8') as handle:written=handle.write(result);handle.flush();os.fsync(handle.fileno())
   if written!=len(result):raise RuntimeError('Encrypted settings fixture write was incomplete.')
   temporary.chmod(0o640);os.replace(temporary,path)
  finally:
   if temporary.exists():temporary.unlink()
  directory=os.open(self.root,os.O_RDONLY|os.O_DIRECTORY)
  try:os.fsync(directory)
  finally:os.close(directory)
 def request(self,path='/mass-notify/subscriber.php',post=None,cookie='',origin='https://pbx.example.test',site='same-origin',host='pbx.example.test',extra_headers=None):
  c=http.client.HTTPConnection('127.0.0.1',self.port,timeout=5);headers={'Host':host}
  try:
   if extra_headers:headers.update(extra_headers)
   if cookie:headers['Cookie']=cookie
   if post is not None:headers.update({'Content-Type':'application/x-www-form-urlencoded','Origin':origin,'Sec-Fetch-Site':site})
   c.request('GET' if post is None else 'POST',path,urllib.parse.urlencode(post) if post is not None else None,headers);r=c.getresponse();raw=r.read(MAX_RESPONSE+1)
   if len(raw)>MAX_RESPONSE:raise AssertionError('HTTP fixture response exceeded 2 MiB.')
   data=(r.status,dict(r.getheaders()),raw.decode('utf-8'))
  finally:c.close()
  if data[0]>=500:type(self).failed_responses.append((path,data[0],data[2][-4096:]))
  return data
 def anonymous(self):
  status,h,body=self.request();self.assertEqual(status,200);self.assertIn('no-store',h['Cache-Control']);self.assertIn('HttpOnly',h['Set-Cookie']);self.assertIn('SameSite=Strict',h['Set-Cookie']);self.assertEqual(h['Referrer-Policy'],'no-referrer')
  csrf=re.search(r'portal_csrf:"([a-f0-9]{64})"',body)
  if not csrf:csrf=re.search(r'"portal_csrf":"([a-f0-9]{64})"',body)
  self.assertIsNotNone(csrf,body[-4096:]);return h['Set-Cookie'].split(';',1)[0],csrf.group(1)
 def verify(self):
  cookie,csrf=self.anonymous();status,h,_=self.request(post={'subscriber_action':'verify','portal_csrf':csrf,'token':self.fixture['token']},cookie=cookie);self.assertEqual(status,303);cookie=h['Set-Cookie'].split(';',1)[0];status,_,body=self.request(cookie=cookie);self.assertEqual(status,200);return cookie,self.capture(r'name="portal_csrf" value="([a-f0-9]{64})"',body),body
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
  record=self.read_json(self.root/'incidents'/(self.fixture['incident_id']+'.json'));self.assertEqual(record['responses']['fixture-person']['source'],'human_browser_response');self.assertNotIn('another-person',record['responses'])
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
 def test_master_disables_subscriber_effects_but_preserves_authenticated_cleanup(self):
  cookie,csrf,_=self.verify()
  before=self.bounded_bytes(self.root/'incidents'/(self.fixture['incident_id']+'.json'),4*1024*1024)
  for master in [None,False]:
   if master is None:self.settings.pop('enterprise_labs',None)
   else:self.settings['enterprise_labs']={'enabled':master}
   self.write_settings()
   self.assertEqual(self.request(cookie=cookie)[0],404)
   for action in ['verify','respond','push_enroll']:
    status,_,_=self.request(post={'subscriber_action':action,'portal_csrf':csrf},cookie=cookie)
    self.assertEqual(status,404)
  self.settings['subscriber_browser']['enabled']=False;self.settings['subscriber_browser']['push']={'enabled':False};self.write_settings()
  cleanup={'subscriber_action':'push_remove_all','portal_csrf':csrf}
  self.assertEqual(self.request(post=cleanup,cookie=cookie,origin='https://attacker.example.test',site='cross-site')[0],403)
  self.assertEqual(self.request(post=cleanup|{'portal_csrf':'0'*64},cookie=cookie)[0],403)
  status,_,body=self.request(post=cleanup,cookie=cookie,extra_headers={'Accept':'application/json'})
  self.assertEqual(status,200);self.assertEqual(decode_json(body),{'success':True})
  self.assertEqual(self.bounded_bytes(self.root/'incidents'/(self.fixture['incident_id']+'.json'),4*1024*1024),before)
  self.settings['subscriber_browser']['people'][0]['enabled']=False;self.write_settings()
  self.assertEqual(self.request(post=cleanup,cookie=cookie,extra_headers={'Accept':'application/json'})[0],400)
 def test_browser_display_requires_explicit_master(self):
  self.settings['enterprise_integrations']={'enabled':'1','browser_display':{'enabled':'1'}}
  for master,expected in [(None,404),(False,404),(True,200)]:
   if master is None:self.settings.pop('enterprise_labs',None)
   else:self.settings['enterprise_labs']={'enabled':master}
   self.write_settings();status,_,body=self.request('/mass-notify/display.php')
   self.assertEqual(status,expected)
   self.assertEqual('id="display-login"' in body,expected==200)
 def test_sso_rejects_non_boolean_portal_enablement(self):
  rate_path=self.root/'operator-sso-rate.json';before=self.bounded_bytes(rate_path,65536) if rate_path.exists() else None
  self.settings['enterprise_identity']['enabled']=True;self.settings['operator_access']['portal_enabled']='true';self.write_settings()
  status,_,body=self.request('/mass-notify/sso.php');self.assertEqual(status,404);self.assertNotIn('boolean enable settings',body)
  self.assertEqual(self.bounded_bytes(rate_path,65536) if rate_path.exists() else None,before)
 def test_oidc_actual_http_callback_local_grant_and_revocation(self):
  self.settings['enterprise_labs']={'enabled':False}
  control_path=self.root/'control-api-audit.jsonl';control_before=self.bounded_bytes(control_path,4*1024*1024) if control_path.exists() else None
  self.settings['enterprise_identity']['enabled']=True;self.write_settings()
  self.assertEqual(self.request('/mass-notify/sso.php',host='attacker.example.test')[0],403)
  self.assertEqual(self.request('/mass-notify/sso.php',host='pbx.example.test:8443')[0],403)
  self.assertEqual(self.request('/mass-notify/sso.php',extra_headers={'X-Forwarded-Host':'attacker.example.test','X-Forwarded-Proto':'http'})[0],401)
  status,h,body=self.request('/fixture-login');self.assertEqual(status,200);cookie=h['Set-Cookie'].split(';',1)[0];csrf=decode_json(body)['csrf']
  status,h,body=self.request('/mass-notify/sso.php',post={'provider_id':self.settings['enterprise_identity']['providers'][0]['id'],'portal_csrf':csrf},cookie=cookie);self.assertEqual(status,200);self.assertIn('SameSite=None',h['Set-Cookie']);browser=h['Set-Cookie'].split(';',1)[0]
  target=decode_json(self.capture(r'window\.location\.replace\((\"[^;]+\")\);',body))
  query=urllib.parse.parse_qs(urllib.parse.urlparse(target).query,strict_parsing=True);self.assertEqual(query['code_challenge_method'],['S256']);self.assertEqual(len(query.get('nonce',[])),1);self.assertEqual(len(query.get('state',[])),1);nonce=query['nonce'][0];state=query['state'][0]
  code="require "+json.dumps(str(MODULE/'identity-libs/vendor/autoload.php'))+";$c=['iss'=>'https://idp.example.test','aud'=>'fixture-client','sub'=>'stable-42','nonce'=>$argv[1],'iat'=>time(),'exp'=>time()+300,'roles'=>['administrator']];echo Firebase\\JWT\\JWT::encode($c,file_get_contents($argv[2]),'RS256','fixture');"
  token=self.php(['-r',code,nonce,str(self.root/'fixture-private.pem')],maximum=16384);token_path=self.root/'fixture-token.jwt'
  if token_path.write_text(token,encoding='utf-8')!=len(token):raise RuntimeError('Fixture token write was incomplete.')
  token_path.chmod(0o600)
  callback='/mass-notify/sso.php?'+urllib.parse.urlencode({'state':state,'code':'inert-code','scope':'openid email https://www.googleapis.com/auth/userinfo.email'})
  self.assertEqual(self.request(callback)[0],401)
  status,h,_=self.request(callback,cookie=browser);self.assertEqual(status,303);portal=h['Set-Cookie'].split(';',1)[0]
  status,_,body=self.request('/fixture-current',cookie=portal);self.assertEqual(status,401)
  current=decode_json(body);self.assertTrue(current['pending']);self.assertIsNone(current['principal'])
  audit_path=self.root/'security-audit.jsonl'
  audit=[decode_json(row) for row in self.bounded_bytes(audit_path,4*1024*1024).decode('utf-8').splitlines()] if audit_path.exists() else []
  self.assertFalse(any(row['action']=='operator_sign_in_completed' for row in audit),'Identity callback completed login before local TOTP.')
  identity=self.read_json(self.root/'enterprise-state/identity_audit.json')
  self.assertEqual(next(reversed(identity.values()))['kind'],'enterprise_identity_verified')
  self.assertNotIn('stable-42',json.dumps(audit));self.assertNotIn(token,json.dumps(audit))
  self.assertEqual(self.bounded_bytes(control_path,4*1024*1024) if control_path.exists() else None,control_before)
  self.assertEqual(self.request(callback,cookie=browser)[0],401)
  self.settings['enterprise_identity']['grants'][0]['enabled']=False;self.write_settings();self.assertEqual(self.request('/fixture-current',cookie=portal)[0],401)
 def test_oidc_bad_signature_is_unauthorized_and_privately_categorized(self):
  self.settings['enterprise_identity']['enabled']=True;self.write_settings()
  status,h,body=self.request('/fixture-login');self.assertEqual(status,200);cookie=h['Set-Cookie'].split(';',1)[0];csrf=decode_json(body)['csrf']
  status,h,body=self.request('/mass-notify/sso.php',post={'provider_id':self.settings['enterprise_identity']['providers'][0]['id'],'portal_csrf':csrf},cookie=cookie);self.assertEqual(status,200);browser=h['Set-Cookie'].split(';',1)[0]
  target=decode_json(self.capture(r'window\.location\.replace\((\"[^;]+\")\);',body));query=urllib.parse.parse_qs(urllib.parse.urlparse(target).query,strict_parsing=True);self.assertEqual(len(query.get('nonce',[])),1);self.assertEqual(len(query.get('state',[])),1);nonce=query['nonce'][0];state=query['state'][0]
  code="require "+json.dumps(str(MODULE/'identity-libs/vendor/autoload.php'))+";$c=['iss'=>'https://idp.example.test','aud'=>'fixture-client','sub'=>'stable-42','nonce'=>$argv[1],'iat'=>time(),'exp'=>time()+300];echo Firebase\\JWT\\JWT::encode($c,file_get_contents($argv[2]),'RS256','fixture');"
  token=self.php(['-r',code,nonce,str(self.root/'fixture-private.pem')],maximum=16384);parts=token.split('.');self.assertEqual(len(parts),3);self.assertTrue(parts[2]);parts[2]=('A' if parts[2][0]!='A' else 'B')+parts[2][1:];token='.'.join(parts);token_path=self.root/'fixture-token.jwt'
  if token_path.write_text(token,encoding='utf-8')!=len(token):raise RuntimeError('Fixture token write was incomplete.')
  token_path.chmod(0o600)
  callback='/mass-notify/sso.php?'+urllib.parse.urlencode({'state':state,'code':'inert-code'})
  status,_,body=self.request(callback,cookie=browser);self.assertEqual(status,401);self.assertNotIn(token,body);self.assertNotIn('SignatureInvalidException',body)
  identity=self.read_json(self.root/'enterprise-state/identity_audit.json');record=next(reversed(identity.values()))
  self.assertEqual(record,{'at':record['at'],'kind':'enterprise_identity_rejected','provider_id':self.settings['enterprise_identity']['providers'][0]['id'],'protocol':'oidc','reason':'signature'})
  self.assertNotIn(token,json.dumps(identity));self.assertNotIn('stable-42',json.dumps(identity));self.assertNotIn('inert-code',json.dumps(identity))
 def test_readiness_and_journal_missing_fail_closed(self):
  self.assertTrue(self.read_json(self.root/'mass-notifications.config',1048576).get('ciphertext'))
  ledger=self.root/'enterprise-state/subscriber_tokens.json';quarantine=self.root/'enterprise-state/subscriber_tokens.test-missing'
  os.replace(ledger,quarantine)
  try:
   cookie,csrf=self.anonymous();status,_,body=self.request(post={'subscriber_action':'verify','portal_csrf':csrf,'token':self.fixture['token']},cookie=cookie);self.assertEqual(status,503);self.assertNotIn(self.fixture['token'],body)
  finally:os.replace(quarantine,ledger)
if __name__=='__main__':unittest.main()
