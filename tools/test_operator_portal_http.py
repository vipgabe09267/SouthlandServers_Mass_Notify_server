#!/usr/bin/python3
"""Private HTTP portal with real auth/scopes/transactions and inert delivery."""
from concurrent.futures import ThreadPoolExecutor
from http.client import HTTPConnection
from pathlib import Path
from urllib.parse import urlencode
import base64, hashlib, hmac, json, os, re, shutil, signal, socket, struct, subprocess, tempfile, time, unittest

ROOT=Path(__file__).resolve().parents[1]; MODULE=ROOT/'slsmassnotifyserver'
isolated=((os.environ.get('SLS_TEST_NAMESPACE')=='entered' and os.environ.get('SLS_TEST_PARENT_NET_NS') not in (None,os.readlink('/proc/self/ns/net')))
    or (os.environ.get('SLS_BUILD_STAGE')=='isolated' and os.environ.get('SLS_BUILD_PARENT_NET') not in (None,os.readlink('/proc/self/ns/net'))))
if not isolated: raise SystemExit('Use the isolated fixture runner; no portal server was started.')

class PortalHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        subprocess.run([shutil.which('ip'),'link','set','lo','up'],check=True,capture_output=True)
        cls.temp=tempfile.TemporaryDirectory(prefix='sls-portal-http-'); cls.root=Path(cls.temp.name)
        (cls.root/'sessions').mkdir(mode=0o700); (cls.root/'mass-notify').mkdir()
        source=(MODULE/'portal/index.php').read_text()
        replacements={
          "'/var/www/html/admin/modules/slsmassnotifyserver/OperatorPortal.php'":json.dumps(str(MODULE/'OperatorPortal.php')),
          "'/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config'":json.dumps(str(cls.root/'settings.json')),
          "'/var/lib/asterisk/SLS_Mass_Notifications_Plugin/operator-login-rate.json'":json.dumps(str(cls.root/'rate.json')),
          "'/var/lib/asterisk/SLS_Mass_Notifications_Plugin/operator-login-failures.json'":json.dumps(str(cls.root/'failures.json')),
          "'/var/lib/asterisk/SLS_Mass_Notifications_Plugin/operator-recovery-rate.json'":json.dumps(str(cls.root/'recovery-rate.json')),
          "'/var/lib/asterisk/SLS_Mass_Notifications_Plugin/operator-reset-rate.json'":json.dumps(str(cls.root/'reset-rate.json')),
          "'/var/lib/asterisk/SLS_Mass_Notifications_Plugin/operator-mfa-rate.json'":json.dumps(str(cls.root/'mfa-rate.json')),
          "'/var/lib/asterisk/SLS_Mass_Notifications_Plugin/operator-password-work'":json.dumps(str(cls.root/'password-work')),
          "'/etc/freepbx.conf'":json.dumps(str(cls.root/'bootstrap.php')),
          "'/var/www/html/admin/libraries/ampuser.class.php'":json.dumps(str(cls.root/'ampuser.php'))}
        for old,new in replacements.items():
            assert source.count(old)==1,old; source=source.replace(old,new)
        (cls.root/'mass-notify/index.php').write_text(source); (cls.root/'ampuser.php').write_text('<?php')
        seed_php='''require MODULE.'/OperatorAccess.php'; use SLS\\MassNotify\\OperatorAuth as A;
$s=['enabled'=>'1','desktop_auth_key'=>base64_encode(str_repeat('k',32)),'public_pbx_host'=>'pbx.example.test','operator_access'=>['schema'=>1,'enabled'=>false,'portal_enabled'=>true,'accounts'=>[]]];
foreach(['owner'=>'administrator','viewer'=>'viewer','newuser'=>'sender'] as $name=>$role){
 $id='api_'.substr(hash('sha256',$name),0,24);$auth=A::create('initial fixture password '.$name);
 if($name!=='newuser'){$auth['force_password_change']=false;$auth['totp_secret_enc']=A::seal('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',$id,$s);}
 $row=['id'=>$id,'username'=>$name,'name'=>'Fixture '.$name,'email'=>$name.'@example.test','source'=>'portal','enabled'=>true,'role'=>$role,'site_ids'=>[],'group_ids'=>[],'personal_members'=>$role==='administrator'?[]:['extensions'=>['1000']],'auth'=>$auth];
 $row['identity']=A::identity($row);$s['operator_access']['accounts'][]=$row;
}echo json_encode($s);'''.replace('MODULE',json.dumps(str(MODULE)))
        cls.original=json.loads(subprocess.check_output(['php','-r',seed_php],text=True))
        bootstrap='''<?php
require MODULE.'/Operators.php';
function load_view($path,$variables=[]){extract($variables);ob_start();include $path;return ob_get_clean();}
if(!function_exists('_')){function _($text){return $text;}}
class FreePBX {
 static function create(){return (object)['Slsmassnotifyserver'=>new PortalFixtureModule];}
 static function Config(){return new class{function get($name){return $name==='SESSION_TIMEOUT'?120:'';}};}
}
class PortalFixtureModule {
 use \\FreePBX\\modules\\SlsOperators;
 const SETTINGS_JSON=FIXTURE.'/settings.json'; const PENDING_SETTINGS_JSON=FIXTURE.'/pending.json';
 function getActiveSettings(){return json_decode(file_get_contents(self::SETTINGS_JSON),true);}
 private function acquireSettingsLock(...$args){$f=fopen(FIXTURE.'/config.lock','c+b');if(!flock($f,LOCK_EX))throw new RuntimeException('fixture lock');return $f;}
 private function releaseSettingsLock($f){fclose($f);}
 private function loadSettingsFile($p){return json_decode(file_get_contents($p),true);}
 private function writeSettingsFileUnlocked($p,$v,$active){file_put_contents($p.'.tmp',json_encode($v));chmod($p.'.tmp',0640);rename($p.'.tmp',$p);}
 function operatorSecurityEvent($action,$account=null,$ok=true,$actor=null):bool{if(file_exists(FIXTURE.'/audit-failure'))return false;file_put_contents(FIXTURE.'/audit.jsonl',json_encode(['action'=>$action,'username'=>$account['username']??'','ok'=>$ok])."\\n",FILE_APPEND|LOCK_EX);return true;}
 protected function sendOperatorRecoveryEmail($settings,$recipient,$subject,$body):string{file_put_contents(FIXTURE.'/mail.jsonl',json_encode(compact('recipient','subject','body'))."\\n",FILE_APPEND|LOCK_EX);return 'accepted';}
 function renderOperationsPage($cursor,$endpoint,$csrf){
  $p=$this->currentOperator();$allowed=\\SLS\\MassNotify\\ApiSecurity::allowedAudience($p,$this->getActiveSettings());
  $state=['principal'=>$p,'can_send'=>\\SLS\\MassNotify\\OperatorAccess::may($p,'send'),'can_schedule'=>false,'can_roll_call'=>false,'choices'=>array_fill_keys(['extensions','desktop_clients','voice_recipient_ids','email_recipient_ids','sms_recipient_ids','webhook_ids'],[]),'tones'=>[],'recordings'=>[],'tone_defaults'=>[],'templates'=>[],'incidents'=>[],'next_cursor'=>'','schedules'=>[],'timezone'=>'UTC'];
  foreach(['1000','2000'] as $id)if($p['operator_role']==='administrator'||in_array($id,$allowed['phones'],true))$state['choices']['extensions'][]=['id'=>$id,'name'=>'Fixture '.$id];
  return load_view(MODULE.'/views/operations.php',['state'=>$state,'endpoint'=>$endpoint,'csrf_token'=>$csrf]);
 }
 function renderOperatorsPage($endpoint,$csrf){$p=$this->currentOperator();if($p['operator_role']!=='administrator')throw new DomainException('Denied');return '<section id="portal-access-fixture">Operator login management</section>';}
 function operatorAction($action,$input){$p=$this->currentOperator();if(in_array($action,['approval_list','drill_report'],true)&&$input===[])return ['success'=>true,'empty_object_accepted'=>true];if(!\\SLS\\MassNotify\\OperatorAccess::may($p,'send')||!\\SLS\\MassNotify\\OperatorAccess::delivery($p,$input['delivery']??[],$this->getActiveSettings()))return ['success'=>false,'message'=>'Denied'];file_put_contents(FIXTURE.'/action.json',json_encode([$action,$input]));return ['success'=>true,'message'=>'Inert fixture only'];}
 function saveOperatorAccess($input){$this->currentOperator();return ['success'=>true,'message'=>'Inert fixture only'];}
}
'''.replace('MODULE',json.dumps(str(MODULE))).replace('FIXTURE',json.dumps(str(cls.root)))
        # Keep the production action implementation under test in PHP scope
        # suites; only delivery is inert in this real HTTP controller fixture.
        (cls.root/'bootstrap.php').write_text(bootstrap)
        (cls.root/'router.php').write_text('''<?php
$_SERVER['HTTPS']=isset($_GET['insecure'])?'off':'on';if(isset($_GET['insecure']))unset($_GET['insecure']);
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)!=='/mass-notify/'){http_response_code(404);exit;}require __DIR__.'/mass-notify/index.php';''')
        sock=socket.socket();sock.bind(('127.0.0.1',0));cls.port=sock.getsockname()[1];sock.close()
        cls.log=(cls.root/'server.log').open('w')
        cls.server=subprocess.Popen(['php','-d','session.save_path='+str(cls.root/'sessions'),'-S','127.0.0.1:'+str(cls.port),str(cls.root/'router.php')],cwd=cls.root,stdout=cls.log,stderr=cls.log,start_new_session=True,env={**os.environ,'PHP_CLI_SERVER_WORKERS':'4'})
        cls.write_settings(cls.original)
        for _ in range(100):
            conn=HTTPConnection('127.0.0.1',cls.port,timeout=1)
            try:conn.request('GET','/health');conn.getresponse().read();break
            except OSError:time.sleep(.02)
            finally:conn.close()
    @classmethod
    def tearDownClass(cls):
        os.killpg(cls.server.pid,signal.SIGTERM);cls.server.wait(timeout=5);cls.log.close();cls.temp.cleanup()
    @classmethod
    def write_settings(cls,value):
        (cls.root/'settings.json').write_text(json.dumps(value));(cls.root/'settings.json').chmod(0o640)
    def setUp(self):
        self.write_settings(self.original)
        for name in ['rate.json','mfa-rate.json','action.json','pending.json','failures.json','recovery-rate.json','reset-rate.json','audit.jsonl','mail.jsonl','audit-failure']:(self.root/name).unlink(missing_ok=True)
    def request(self,method='GET',post=None,cookie='',origin='https://pbx.example.test',query='',host='pbx.example.test'):
        conn=HTTPConnection('127.0.0.1',self.port,timeout=10)
        headers={'Host':host}
        if cookie:headers['Cookie']=cookie
        if method=='POST':headers.update({'Content-Type':'application/x-www-form-urlencoded','Origin':origin,'Sec-Fetch-Site':'same-origin'})
        conn.request(method,'/mass-notify/'+query,urlencode(post or {}) if method=='POST' else None,headers)
        r=conn.getresponse();result=(r.status,dict(r.getheaders()),r.read().decode());conn.close();return result
    @staticmethod
    def cookie(headers,old=''):return headers.get('Set-Cookie',old).split(';',1)[0]
    @staticmethod
    def token(body):return re.search(r'name="portal_csrf" value="([a-f0-9]{64})"',body)[1]
    @staticmethod
    def otp(secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'):
        msg=struct.pack('>Q',int(time.time())//30);digest=hmac.new(base64.b32decode(secret),msg,hashlib.sha1).digest();at=digest[-1]&15
        return str((struct.unpack('>I',digest[at:at+4])[0]&0x7fffffff)%1000000).zfill(6)
    def password_login(self,name='owner'):
        _,h,body=self.request();cookie=self.cookie(h);token=self.token(body)
        status,h,_=self.request('POST',{'portal_action':'login','portal_csrf':token,'username':name,'password':'initial fixture password '+name},cookie)
        self.assertEqual(status,303);cookie=self.cookie(h,cookie)
        status,_,body=self.request(cookie=cookie);self.assertEqual(status,200)
        return cookie,self.token(body),body
    def login(self,name='owner'):
        cookie,token,_=self.password_login(name)
        status,_,_=self.request('POST',{'portal_action':'totp','portal_csrf':token,'code':self.otp()},cookie);self.assertEqual(status,303)
        status,h,_=self.request(cookie=cookie);self.assertEqual(status,303);cookie=self.cookie(h,cookie)
        self.assertEqual(self.request(cookie=cookie)[0],200);return cookie
    def test_origin_https_cookie_and_wrong_login(self):
        status,h,body=self.request();self.assertEqual(status,200);self.assertEqual(h['Referrer-Policy'],'same-origin')
        self.assertIn('secure',h['Set-Cookie'].lower());self.assertIn('HttpOnly',h['Set-Cookie']);self.assertIn('SameSite=Strict',h['Set-Cookie']);self.assertIn('path=/mass-notify/',h['Set-Cookie'].lower());self.assertIn('no-store',h['Cache-Control'])
        cookie=self.cookie(h);post={'portal_action':'login','portal_csrf':self.token(body),'username':'missing','password':'invalid fixture password'}
        for origin in ['null','https://other.example.test','https://pbx.example.test:8443']:
            self.assertEqual(self.request('POST',post,cookie,origin)[0],403)
        self.assertEqual(self.request('POST',post,cookie)[0],401)
        self.assertEqual(self.request(query='?insecure=1')[0],403)
        self.assertNotIn('Set-Cookie',self.request(query='?insecure=1')[1])
    def test_password_is_not_full_login(self):
        cookie,token,body=self.password_login();self.assertIn('Verify your identity',body)
        self.assertNotIn('sls-operations-data',body)
        request={'slsmassnotifyserver_action':'preview','slsmassnotifyserver_csrf':token,'payload':'{"delivery":{"extensions":["1000"]}}'}
        self.assertEqual(self.request('POST',request,cookie)[0],401);self.assertFalse((self.root/'action.json').exists())
    def test_disabled_by_default_and_all_portal_posts_blocked(self):
        settings=json.loads((self.root/'settings.json').read_text())
        del settings['operator_access']['portal_enabled'];self.write_settings(settings)
        status,headers,body=self.request()
        self.assertEqual(status,200);self.assertIn('This feature has not been enabled.',body)
        self.assertNotIn('<form',body);self.assertNotIn('Set-Cookie',headers)
        for action in ['login','password','enroll','totp','recovery','logout','request_reset','begin_reset','reset_password']:
            status,headers,body=self.request('POST',{'portal_action':action,'username':'owner','password':'initial fixture password owner'})
            self.assertEqual(status,403);self.assertNotIn('Set-Cookie',headers)
        for action in ['preview','send','save_access']:
            status,_,_=self.request('POST',{'slsmassnotifyserver_action':action,'payload':'{}'})
            self.assertEqual(status,403)
        self.assertFalse((self.root/'rate.json').exists());self.assertFalse((self.root/'action.json').exists())
        self.assertFalse((self.root/'mfa-rate.json').exists())
    def test_disabled_portal_rejects_authenticated_cookie(self):
        cookie=self.login()
        settings=json.loads((self.root/'settings.json').read_text());settings['operator_access']['portal_enabled']=False;self.write_settings(settings)
        self.assertIn('This feature has not been enabled.',self.request(cookie=cookie)[2])
        self.assertEqual(self.request('POST',{'slsmassnotifyserver_action':'send','payload':'{}'},cookie)[0],403)
        self.assertFalse((self.root/'action.json').exists())
    def test_roles_and_immediate_revocation(self):
        owner=self.login();self.assertIn('sls-operations-data',self.request(cookie=owner)[2]);self.assertEqual(self.request(cookie=owner,query='?view=access')[0],200)
        viewer=self.login('viewer');body=self.request(cookie=viewer)[2]
        self.assertNotIn('data-channel="extensions" value="2000"',body);self.assertEqual(self.request(cookie=viewer,query='?view=access')[0],403)
        settings=json.loads((self.root/'settings.json').read_text());settings['operator_access']['accounts'][0]['enabled']=False;self.write_settings(settings)
        self.assertIn('Sign in to Mass Notify',self.request(cookie=owner)[2])
    def test_empty_enterprise_objects_require_authenticated_csrf(self):
        owner=self.login();body=self.request(cookie=owner)[2]
        token=self.token(body)
        for action in ['approval_list','drill_report']:
            request={'slsmassnotifyserver_action':action,'slsmassnotifyserver_csrf':token,'payload':'{}'}
            status,_,answer=self.request('POST',request,owner)
            self.assertEqual(status,200);self.assertTrue(json.loads(answer)['empty_object_accepted'])
            self.assertEqual(self.request('POST',{**request,'slsmassnotifyserver_csrf':'wrong'},owner)[0],403)
            self.assertEqual(self.request('POST',request,'')[0],403)
            for malformed in ['[]','null','false']:
                self.assertEqual(self.request('POST',{**request,'payload':malformed},owner)[0],400)
    def test_parallel_otp_reuse_is_rejected(self):
        a=self.password_login();b=self.password_login();code=self.otp()
        def submit(binding):return self.request('POST',{'portal_action':'totp','portal_csrf':binding[1],'code':code},binding[0])[0]
        with ThreadPoolExecutor(max_workers=2) as pool:statuses=list(pool.map(submit,[a,b]))
        self.assertEqual(sorted(statuses),[303,401])
    def test_first_signin_enrollment_and_recovery(self):
        cookie,token,body=self.password_login('newuser');self.assertIn('Set your personal password',body)
        status,_,_=self.request('POST',{'portal_action':'password','portal_csrf':token,'new_password':'personal fixture passphrase 829','confirm_password':'personal fixture passphrase 829'},cookie);self.assertEqual(status,303)
        _,_,body=self.request(cookie=cookie);self.assertIn('Set up your authenticator',body)
        secret=re.search(r'<code class="portal-secret">([A-Z2-7]{32})</code>',body)[1];token=self.token(body)
        _,_,again=self.request(cookie=cookie);self.assertIn(secret,again)
        status,_,_=self.request('POST',{'portal_action':'enroll','portal_csrf':token,'code':self.otp(secret)},cookie);self.assertEqual(status,303)
        _,_,body=self.request(cookie=cookie);self.assertIn('Save your recovery codes',body)
        codes=re.findall(r'<li><code>([a-f0-9-]{35})</code></li>',body);self.assertEqual(len(codes),10)
        settings=json.loads((self.root/'settings.json').read_text());account=settings['operator_access']['accounts'][2]
        self.assertNotIn(secret,json.dumps(settings));self.assertNotIn(codes[0],json.dumps(settings));self.assertTrue(account['auth']['totp_secret_enc'].startswith('op1:'))
        status,h,_=self.request('POST',{'portal_action':'recovery','portal_csrf':self.token(body)},cookie);self.assertEqual(status,303);cookie=self.cookie(h,cookie)
        self.assertIn('sls-operations-data',self.request(cookie=cookie)[2])
        # Two independent password sessions cannot reuse one recovery code.
        def new_binding():
            _,h,body=self.request();c=self.cookie(h)
            status,h,_=self.request('POST',{'portal_action':'login','portal_csrf':self.token(body),'username':'newuser','password':'personal fixture passphrase 829'},c)
            self.assertEqual(status,303);c=self.cookie(h,c);return c,self.token(self.request(cookie=c)[2])
        a=new_binding();b=new_binding()
        for binding,status in [(a,303),(b,401)]:self.assertEqual(self.request('POST',{'portal_action':'totp','portal_csrf':binding[1],'code':codes[0]},binding[0])[0],status)
    def test_rate_limit_is_not_reset_by_new_cookies(self):
        for _ in range(6):
            _,h,body=self.request();self.assertEqual(self.request('POST',{'portal_action':'login','portal_csrf':self.token(body),'username':'guess','password':'bad'},self.cookie(h))[0],401)
        _,h,body=self.request();status,headers,_=self.request('POST',{'portal_action':'login','portal_csrf':self.token(body),'username':'guess','password':'bad'},self.cookie(h))
        self.assertEqual(status,429);self.assertGreater(int(headers['Retry-After']),0)

    def audit(self):
        return [json.loads(line) for line in (self.root/'audit.jsonl').read_text().splitlines()]

    def admin_reset(self,owner=None,account=0,action='generate_reset_link'):
        owner=owner or self.login()
        _,_,body=self.request(cookie=owner,query='?view=access')
        # The fixture access view is inert, but its real header carries CSRF.
        token=self.token(body)
        settings=json.loads((self.root/'settings.json').read_text())
        status,_,body=self.request('POST',{'slsmassnotifyserver_action':action,'slsmassnotifyserver_csrf':token,'payload':json.dumps({'id':settings['operator_access']['accounts'][account]['id']})},owner,query='?view=access')
        self.assertEqual(status,200,body);answer=json.loads(body);self.assertTrue(answer['success'],answer)
        return answer

    def begin_reset(self,secret):
        _,h,body=self.request();cookie=self.cookie(h)
        status,h,body=self.request('POST',{'portal_action':'begin_reset','portal_csrf':self.token(body),'reset_token':secret},cookie)
        self.assertEqual(status,303,body);cookie=self.cookie(h,cookie)
        status,_,body=self.request(cookie=cookie);self.assertEqual(status,200)
        self.assertIn('Reset your password',body)
        return cookie,self.token(body)

    def test_complex_password_trimmed_username_and_forwarded_origin(self):
        password='Symbols + & = \\" apostrophe \' café 😃 fixture passphrase'
        code='require '+json.dumps(str(MODULE/'OperatorAuth.php'))+';echo \\SLS\\MassNotify\\OperatorAuth::password(stream_get_contents(STDIN));'
        hashed=subprocess.check_output(['php','-r',code],input=password,text=True)
        settings=json.loads((self.root/'settings.json').read_text());settings['operator_access']['accounts'][0]['auth']['password_hash']=hashed
        self.write_settings(settings)
        _,h,body=self.request(host='pbx.example.test:8443');cookie=self.cookie(h)
        status,h,body=self.request('POST',{'portal_action':'login','portal_csrf':self.token(body),'username':'  OwNeR  ','password':password},cookie,origin='https://pbx.example.test:8443',host='pbx.example.test:8443')
        self.assertEqual(status,303,body);cookie=self.cookie(h,cookie)
        self.assertIn('Verify your identity',self.request(cookie=cookie)[2])
        self.assertEqual(self.audit()[-1]['action'],'operator_login_password_accepted')
        self.assertNotIn(password,(self.root/'audit.jsonl').read_text())

    def test_two_email_limit_uniform_response_and_totp_only_reset(self):
        settings=json.loads((self.root/'settings.json').read_text());settings['sipnotify']={'base_url':'https://pbx.example.test:8443/api/sipnotify'};self.write_settings(settings)
        for user,email in [('missing','owner@example.test'),('owner','wrong@example.test'),('owner','owner@example.test'),('owner','owner@example.test'),('owner','owner@example.test')]:
            _,h,body=self.request(query='?view=forgot');cookie=self.cookie(h)
            started=time.monotonic()
            status,_,body=self.request('POST',{'portal_action':'request_reset','portal_csrf':self.token(body),'username':user,'email':email},cookie)
            self.assertEqual(status,303,body);self.assertGreaterEqual(time.monotonic()-started,2.95)
            _,_,body=self.request(cookie=cookie,query='?view=forgot');self.assertIn('If this enabled login and registered email match',body)
        emails=[json.loads(line) for line in (self.root/'mail.jsonl').read_text().splitlines()]
        self.assertEqual(len(emails),2);self.assertIn('https://pbx.example.test:8443/mass-notify/#reset=',emails[-1]['body'])
        secret=re.search('#reset=([a-f0-9]{64})',emails[-1]['body'])[1]
        cookie,token=self.begin_reset(secret);password='Replacement complex fixture passphrase + & 884'
        status,_,body=self.request('POST',{'portal_action':'reset_password','portal_csrf':token,'new_password':password,'confirm_password':password,'code':self.otp()},cookie)
        self.assertEqual(status,303,body)
        self.assertIn('Sign in to Mass Notify',self.request()[2])
        self.assertNotIn(secret,(self.root/'settings.json').read_text());self.assertNotIn(password,(self.root/'settings.json').read_text())
        _,h,body=self.request();c=self.cookie(h)
        self.assertEqual(self.request('POST',{'portal_action':'login','portal_csrf':self.token(body),'username':'owner','password':password},c)[0],303)
        self.assertIn('operator_password_reset_completed',[r['action'] for r in self.audit()])

    def test_admin_revoke_and_two_bad_authenticator_codes(self):
        owner=self.login();out=self.admin_reset(owner,1);secret=re.search('#reset=([a-f0-9]{64})',out['reset_url'])[1]
        cookie,token=self.begin_reset(secret);self.admin_reset(owner,1,'revoke_reset_link')
        post={'portal_action':'reset_password','portal_csrf':token,'new_password':'New fixture password 847','confirm_password':'New fixture password 847','code':self.otp()}
        status,_,body=self.request('POST',post,cookie);self.assertEqual(status,400);self.assertIn('invalid, expired or revoked',body)
        out=self.admin_reset(owner,1);secret=re.search('#reset=([a-f0-9]{64})',out['reset_url'])[1]
        cookie,token=self.begin_reset(secret);post['portal_csrf']=token;post['code']='wrong'
        for _ in range(2):self.assertEqual(self.request('POST',post,cookie)[0],400)
        settings=json.loads((self.root/'settings.json').read_text());self.assertIsNone(settings['operator_access']['accounts'][1]['auth']['password_recovery']['token'])
        # Recovery never grants a portal session by itself.
        self.assertEqual(self.request('POST',{'slsmassnotifyserver_action':'send','slsmassnotifyserver_csrf':token,'payload':'{}'},cookie)[0],401)

    def test_verified_reset_clears_daily_ip_lock_and_revokes_sessions(self):
        owner=self.login();out=self.admin_reset(owner,1);secret=re.search('#reset=([a-f0-9]{64})',out['reset_url'])[1]
        # Seed the durable limiter as if twenty failures finished just now.
        now=int(time.time());ipkey=hashlib.sha256(socket.inet_pton(socket.AF_INET,'127.0.0.1')).hexdigest()
        failures=self.root/'failures.json';failures.write_text(json.dumps({'schema':1,'last_time':now,'ips':{ipkey:{'failures':[now]*20,'pending':{},'locked_until':now+86400}}}));failures.chmod(0o640)
        _,h,body=self.request();cookie=self.cookie(h)
        status,headers,body=self.request('POST',{'portal_action':'login','portal_csrf':self.token(body),'username':'viewer','password':'initial fixture password viewer'},cookie)
        self.assertEqual(status,429);self.assertGreater(int(headers['Retry-After']),86390);self.assertIn('Forgot password',body)
        cookie,token=self.begin_reset(secret);password='Unlocked fixture passphrase 827'
        status,headers,body=self.request('POST',{'portal_action':'reset_password','portal_csrf':token,'new_password':password,'confirm_password':password,'code':self.otp()},cookie)
        self.assertEqual(status,303,body)
        _,h,body=self.request();cookie=self.cookie(h)
        self.assertEqual(self.request('POST',{'portal_action':'login','portal_csrf':self.token(body),'username':'viewer','password':password},cookie)[0],303)
        # Resetting owner revokes its already authenticated browser as well.
        out=self.admin_reset(owner,0);secret=re.search('#reset=([a-f0-9]{64})',out['reset_url'])[1];cookie,token=self.begin_reset(secret)
        # Use the next tolerated TOTP interval because owner signed in earlier.
        code=self.otp_at(int(time.time())+30)
        status,_,body=self.request('POST',{'portal_action':'reset_password','portal_csrf':token,'new_password':'Replaced owner fixture password 872','confirm_password':'Replaced owner fixture password 872','code':code},cookie)
        self.assertEqual(status,303,body);self.assertIn('Sign in to Mass Notify',self.request(cookie=owner)[2])

    @staticmethod
    def otp_at(now):
        secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';digest=hmac.new(base64.b32decode(secret),struct.pack('>Q',now//30),hashlib.sha1).digest();at=digest[-1]&15
        return str((struct.unpack('>I',digest[at:at+4])[0]&0x7fffffff)%1000000).zfill(6)

    def test_reset_recovery_csrf_and_account_isolation(self):
        owner=self.login();out=self.admin_reset(owner,1);secret=re.search('#reset=([a-f0-9]{64})',out['reset_url'])[1]
        _,h,body=self.request();cookie=self.cookie(h);token=self.token(body)
        for post in [{'portal_action':'request_reset','username':'viewer','email':'viewer@example.test'}, {'portal_action':'begin_reset','reset_token':secret}]:
            self.assertEqual(self.request('POST',{**post,'portal_csrf':token},cookie,origin='https://attacker.example.test')[0],403)
            self.assertEqual(self.request('POST',{**post,'portal_csrf':'bad'},cookie)[0],403)
        self.assertFalse((self.root/'mail.jsonl').exists())
        viewer=self.login('viewer');_,_,body=self.request(cookie=viewer)
        post={'slsmassnotifyserver_action':'generate_reset_link','slsmassnotifyserver_csrf':self.token(body),'payload':json.dumps({'id':self.original['operator_access']['accounts'][0]['id']})}
        self.assertEqual(self.request('POST',post,viewer,query='?view=access')[0],403)

    def test_recovery_audit_fault_does_not_reveal_a_matching_account(self):
        (self.root/'audit-failure').touch()
        replies=[]
        for username in ['owner','missing']:
            _,h,body=self.request(query='?view=forgot');cookie=self.cookie(h)
            status,_,_=self.request('POST',{'portal_action':'request_reset','portal_csrf':self.token(body),'username':username,'email':'owner@example.test'},cookie)
            self.assertEqual(status,303);_,_,body=self.request(cookie=cookie,query='?view=forgot')
            replies.append(re.search(r'<p class="portal-notice" role="status">(.*?)</p>',body)[1])
        self.assertEqual(replies[0],replies[1]);self.assertFalse((self.root/'mail.jsonl').exists())
        self.assertNotIn('password_recovery',json.loads((self.root/'settings.json').read_text())['operator_access']['accounts'][0]['auth'])

if __name__=='__main__':unittest.main()
