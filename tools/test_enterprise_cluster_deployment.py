#!/usr/bin/python3
"""Unmodified copied API topology and actual worker/maintenance lock fixtures."""
import fcntl
import hashlib
import http.client
import http.server
import json
import os
from pathlib import Path
import pwd
import select
import shutil
import signal
import ssl
import subprocess
import tempfile
import threading
import time
import unittest

ROOT=Path(__file__).resolve().parents[1]
SOURCE=ROOT/'slsmassnotifyserver'
CLEAN_RUNTIME_ENV={'PATH':'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin','LANG':'C.UTF-8','LC_ALL':'C.UTF-8'}
IP='/usr/sbin/ip'
MOUNT='/usr/bin/mount'
UMOUNT='/usr/bin/umount'
OPENSSL='/usr/bin/openssl'
private_test=all(os.environ.get('SLS_TEST_NAMESPACE')=='entered' and os.environ.get('SLS_TEST_PARENT_'+kind+'_NS') not in (None, '', os.readlink('/proc/self/ns/'+namespace)) for kind,namespace in [('MOUNT','mnt'),('NET','net'),('PID','pid')])
if not private_test:
    raise SystemExit('Use tools/run_isolated_tests.sh; no deployment mount or worker was started.')

def write_exact(path, body):
    with path.open('wb') as handle:
        if handle.write(body) != len(body):
            raise RuntimeError('Fixture write was incomplete: '+str(path))

class CopiedCluster(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        subprocess.run([IP,'link','set','lo','up'],env=CLEAN_RUNTIME_ENV,check=True,capture_output=True,timeout=5)
        cls.deployment=Path('/var/www/html')
        subprocess.run([MOUNT,'-t','tmpfs','-o','mode=755,size=16M','tmpfs',str(cls.deployment)],env=CLEAN_RUNTIME_ENV,check=True,capture_output=True,timeout=5)
        cls.addClassCleanup(subprocess.run,[UMOUNT,str(cls.deployment)],env=CLEAN_RUNTIME_ENV,check=True,capture_output=True,timeout=5)
        cls.module=cls.deployment/'admin/modules/slsmassnotifyserver';cls.api=cls.deployment/'api/sls-mass-notify'
        cls.module.mkdir(parents=True);cls.api.mkdir(parents=True)
        names=['module.xml','EnterpriseClusterConfig.php','EnterpriseClusterRuntime.php','EnterpriseClusterProtocol.php','EnterpriseClusterStore.php','EnterpriseClusterPbxContract.php','EnterpriseClusterEdge.php','LabsSafety.php','ConfigCrypto.php','api/sls-mass-notify/config-crypto.php','bin/sls_mass_notify/sls_runtime_state.php','bin/sls_mass_notify/sls_announcement_jobs.php','bin/sls_mass_notify/sls_atomic_json.php']
        for name in names:
            target=cls.module/name;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(SOURCE/name,target)
        for name in ['index.php','peer.php','edge.php','edge-view.php','security.php','contract.php','event-log.php','config-crypto.php']:
            shutil.copyfile(SOURCE/'api/sls-mass-notify'/name,cls.api/name)
        for item in cls.deployment.rglob('*'):
            item.chmod(0o755 if item.is_dir() else 0o644)
        cls.runtime=Path('/usr/local/bin/sls_mass_notify/sls_mass_notify_cluster_worker.php')
        if cls.runtime.read_bytes()!=(SOURCE/'bin/sls_mass_notify_cluster_worker.php').read_bytes():
            raise RuntimeError('Installed cluster worker does not match the candidate under test.')
        cls.account=pwd.getpwnam('asterisk');cls.data=Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
        # Nested disposable state mount restores the outer fixture's exact
        # settings/journals after this suite, including any passive markers.
        subprocess.run([MOUNT,'-t','tmpfs','-o',f'mode=750,size=8M,uid={cls.account.pw_uid},gid={cls.account.pw_gid}','tmpfs',str(cls.data)],env=CLEAN_RUNTIME_ENV,check=True,capture_output=True,timeout=5)
        cls.addClassCleanup(subprocess.run,[UMOUNT,str(cls.data)],env=CLEAN_RUNTIME_ENV,check=True,capture_output=True,timeout=5)
        cls.activity=cls.data/'announcement-activity.lock';write_exact(cls.activity,b'');cls.activity.chmod(0o640);os.chown(cls.activity,cls.account.pw_uid,cls.account.pw_gid)
        cls.lifecycle=cls.data/'enterprise-worker.lock'
        cls.token='T'*48
        cls.settings={'enabled':'0','control_api':{'enabled':False},'enterprise_cluster':{'enabled':True,'remote_enabled':True,'mode':'edge','role':'node','cluster_id':'copied-test','node_id':'edge-a','site_id':'site-a','tls_cert':'/unused-local-fixture-cert','tls_key':'/unused-local-fixture-key','tls_ca':'/unused-local-fixture-ca','site_recipients':['device-a'],'edge_devices':[{'id':'device-a','token_sha256':hashlib.sha256(cls.token.encode()).hexdigest(),'channels':['desktop']} ],'peers':[{'node_id':'coordinator','site_id':'site-a','role':'coordinator','https_url':'https://coordinator.invalid/api/sls-mass-notify/peer.php','cert_sha256':'a'*64,'hmac_secret':'b'*64}]}}
        cls.write_settings()
        initialized=subprocess.run(['/usr/bin/php',str(cls.runtime),'--initialize'],env=CLEAN_RUNTIME_ENV,capture_output=True,text=True,timeout=5)
        if initialized.returncode!=0:
            raise RuntimeError('Cluster worker initialization failed: '+initialized.stdout+initialized.stderr)
    @classmethod
    def write_settings(cls):
        code='require '+json.dumps(str(cls.module/'ConfigCrypto.php'))+'; echo FreePBX\\modules\\SlsConfigCrypto::encode(json_decode(stream_get_contents(STDIN),true));'
        body=subprocess.check_output(['/usr/bin/php','-r',code],env=CLEAN_RUNTIME_ENV,input=json.dumps(cls.settings).encode(),timeout=5)
        target=cls.data/'mass-notifications.config';write_exact(target,body);target.chmod(0o640);os.chown(target,cls.account.pw_uid,cls.account.pw_gid)
    def entry(self,name,path,*,device=False,query=None):
        server={'REQUEST_METHOD':'GET','HTTPS':'on','REMOTE_ADDR':'127.0.0.1','REQUEST_URI':path}
        if device:server['HTTP_AUTHORIZATION']='Bearer '+self.token
        query=dict(query) if query is not None else ({'action':'poll','device_id':'device-a'} if device else {})
        code="$_SERVER=json_decode("+json.dumps(json.dumps(server))+",true);$_GET=json_decode("+json.dumps(json.dumps(query))+",true);register_shutdown_function(static function(){fwrite(STDERR,json_encode(['status'=>http_response_code()?:200,'runtime'=>class_exists('SLS\\\\MassNotify\\\\EnterpriseClusterRuntime',false)?(new ReflectionClass('SLS\\\\MassNotify\\\\EnterpriseClusterRuntime'))->getFileName():null]));});require "+json.dumps(str(self.api/name))+";"
        result=subprocess.run(['/usr/bin/php','-r',code],env=CLEAN_RUNTIME_ENV,capture_output=True,text=True,timeout=5)
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        return result.stdout,json.loads(result.stderr)
    def test_unmodified_copied_peer_edge_and_viewer(self):
        for name in ['index.php','peer.php','edge.php','edge-view.php']:
            self.assertEqual((self.api/name).read_bytes(),(SOURCE/'api/sls-mass-notify'/name).read_bytes())
        edge_source=(self.api/'edge.php').read_text(encoding='utf-8')
        self.assertIn('ApiSecurity::jsonObject($raw,false,8)',edge_source)
        self.assertNotIn('json_decode($raw,true,8',edge_source)
        body,meta=self.entry('peer.php','/api/sls-mass-notify/peer.php')
        self.assertEqual(json.loads(body)['error'],'peer_authentication_or_authority_rejected');self.assertEqual(meta['status'],403)
        self.assertEqual(meta['runtime'],str(self.module/'EnterpriseClusterRuntime.php'))
        body,meta=self.entry('edge.php','/api/sls-mass-notify/edge.php',device=True)
        self.assertEqual(json.loads(body),{'events':[]});self.assertEqual(meta['status'],200)
        self.assertEqual(meta['runtime'],str(self.module/'EnterpriseClusterRuntime.php'))
        for query in [
            {'action':'poll','device_id':['device-a']},
            {'action':['poll'],'device_id':'device-a'},
            {'action':'media','device_id':'device-a','event_id':['event-a'],'asset_id':'asset-a'},
            {'action':'media','device_id':'device-a','event_id':'event-a','asset_id':['asset-a']},
        ]:
            body,meta=self.entry('edge.php','/api/sls-mass-notify/edge.php',device=True,query=query)
            self.assertEqual(json.loads(body),{'error':'edge_device_or_event_unavailable'})
            self.assertEqual(meta['status'],403)
        body,meta=self.entry('edge-view.php','/api/sls-mass-notify/edge-view.php')
        self.assertIn('Connect local device',body);self.assertEqual(meta['status'],200)
    def test_literal_index_dispatch_and_encoded_lookalikes(self):
        body,meta=self.entry('index.php','/api/sls-mass-notify/peer.php')
        self.assertEqual(json.loads(body)['error'],'peer_authentication_or_authority_rejected');self.assertIsNotNone(meta['runtime'])
        body,meta=self.entry('index.php','/api/sls-mass-notify/edge.php',device=True)
        self.assertEqual(json.loads(body),{'events':[]});self.assertIsNotNone(meta['runtime'])
        body,meta=self.entry('index.php','/api/sls-mass-notify/edge-view.php')
        self.assertIn('Connect local device',body)
        for value in ['/api/sls-mass-notify/%70eer.php','/api/sls-mass-notify/peer.php/extra','/api/sls-mass-notify/peer.php.bak','/api/sls-mass-notify/../peer.php','/api/sls-mass-notify/edge-view.phpx','/api/sls-mass-notify/%65dge.php']:
            body,meta=self.entry('index.php',value)
            self.assertEqual(json.loads(body)['error'],'control_api_disabled',value);self.assertIsNone(meta['runtime'],value)
    def test_real_https_literal_index_routes(self):
        # The disposable TLS terminator passes the literal request URI and
        # verified HTTPS state to the unchanged copied PHP index. It starts
        # only after the private network namespace was independently verified.
        fixture=self
        class Routes(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                try:
                    body,meta=fixture.entry('index.php',self.path,device=self.headers.get('Authorization')=='Bearer '+fixture.token)
                    encoded=body.encode();self.send_response(meta['status'])
                    self.send_header('Content-Length',str(len(encoded)))
                    self.send_header('X-Fixture-Runtime',meta['runtime'] or '')
                    self.end_headers();self.wfile.write(encoded)
                except Exception:
                    self.send_error(500)
            def log_message(self,*args):pass
        with tempfile.TemporaryDirectory(prefix='sls-copied-route-tls-') as directory:
            base=Path(directory);key=base/'local.key';cert=base/'local.pem'
            subprocess.run([OPENSSL,'req','-x509','-newkey','rsa:2048','-nodes','-days','1',
                '-keyout',str(key),'-out',str(cert),'-subj','/CN=localhost',
                '-addext','subjectAltName=DNS:localhost','-addext','extendedKeyUsage=serverAuth,clientAuth'],
                env=CLEAN_RUNTIME_ENV,check=True,capture_output=True,timeout=10)
            key.chmod(0o600)
            server=http.server.HTTPServer(('127.0.0.1',0),Routes)
            context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(str(cert),str(key))
            context.load_verify_locations(cafile=str(cert));context.verify_mode=ssl.CERT_REQUIRED
            server.socket=context.wrap_socket(server.socket,server_side=True)
            thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
            client=ssl.create_default_context(cafile=str(cert));client.load_cert_chain(str(cert),str(key))
            pin=hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text(encoding='utf-8'))).hexdigest()
            try:
                paths=['/api/sls-mass-notify/peer.php','/api/sls-mass-notify/edge.php','/api/sls-mass-notify/edge-view.php',
                    '/api/sls-mass-notify/%70eer.php','/api/sls-mass-notify/peer.php/extra','/api/sls-mass-notify/peer.php.bak',
                    '/api/sls-mass-notify/../peer.php','/api/sls-mass-notify/edge-view.phpx','/api/sls-mass-notify/%65dge.php']
                for path in paths:
                    connection=http.client.HTTPSConnection('localhost',server.server_port,context=client,timeout=5)
                    try:
                        connection.connect()
                        self.assertEqual(hashlib.sha256(connection.sock.getpeercert(binary_form=True)).hexdigest(),pin)
                        connection.request('GET',path,headers={'Authorization':'Bearer '+self.token})
                        response=connection.getresponse();body=response.read().decode()
                        if path.endswith('/peer.php') and '/..' not in path:
                            self.assertEqual(response.status,403);self.assertEqual(json.loads(body)['error'],'peer_authentication_or_authority_rejected')
                            self.assertEqual(response.getheader('X-Fixture-Runtime'),str(self.module/'EnterpriseClusterRuntime.php'))
                        elif path=='/api/sls-mass-notify/edge.php':
                            self.assertEqual(response.status,200);self.assertEqual(json.loads(body),{'events':[]})
                        elif path=='/api/sls-mass-notify/edge-view.php':
                            self.assertEqual(response.status,200);self.assertIn('Connect local device',body)
                        else:
                            self.assertEqual(json.loads(body)['error'],'control_api_disabled',path)
                            self.assertEqual(response.getheader('X-Fixture-Runtime'),'',path)
                    finally:connection.close()
            finally:
                server.shutdown();server.server_close();thread.join(timeout=5)
                self.assertFalse(thread.is_alive(),'Private TLS server did not stop.')
    def test_maintenance_exclusion_and_watch_lifetime(self):
        with self.lifecycle.open('r+b') as locked:
            fcntl.flock(locked,fcntl.LOCK_EX)
            result=subprocess.run(['/usr/bin/php',str(self.runtime),'--status'],env=CLEAN_RUNTIME_ENV,capture_output=True,text=True,timeout=3)
            self.assertEqual(result.returncode,1);self.assertEqual(result.stdout,'')
            fcntl.flock(locked,fcntl.LOCK_UN)
        process=subprocess.Popen(['/usr/bin/php',str(self.runtime),'--edge-watch'],env=CLEAN_RUNTIME_ENV,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        try:
            self.assertTrue(select.select([process.stdout],[],[],5)[0],'Actual isolated edge worker did not reach its empty dispatch cycle.')
            self.assertEqual(json.loads(process.stdout.readline()),{'edge_jobs':[]})
            with self.lifecycle.open('r+b') as probe:
                with self.assertRaises(BlockingIOError):fcntl.flock(probe,fcntl.LOCK_EX|fcntl.LOCK_NB)
            # Idle watch does not retain a settings/delivery activity lease.
            with self.activity.open('r+b') as probe:
                fcntl.flock(probe,fcntl.LOCK_EX|fcntl.LOCK_NB)
                self.assertFalse((self.data/'schedule-runner.lock').exists())
                guarded=subprocess.run(['/usr/bin/python3','-I',str(SOURCE/'bin/sls_mass_notify/sls_install_guard.py'),
                    '--data',str(self.data),'--timeout','0.1','hold'],env=CLEAN_RUNTIME_ENV,input=b'',capture_output=True,timeout=3)
                self.assertNotEqual(guarded.returncode,0)
                self.assertIn(b'active worker did not become idle: enterprise-worker.lock',guarded.stderr)
                # Taking the lifecycle lease first avoids a lock-order inversion
                # and prevents entry into inner worker/activity/settings locks.
                self.assertFalse((self.data/'schedule-runner.lock').exists())
                fcntl.flock(probe,fcntl.LOCK_UN)
        finally:
            if process.poll() is None:process.send_signal(signal.SIGTERM)
            try: process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill();process.wait(timeout=5)
            process.stdout.close();process.stderr.close()
        with self.lifecycle.open('r+b') as released:
            fcntl.flock(released,fcntl.LOCK_EX|fcntl.LOCK_NB);fcntl.flock(released,fcntl.LOCK_UN)
        guard=subprocess.Popen(['/usr/bin/python3','-I',str(SOURCE/'bin/sls_mass_notify/sls_install_guard.py'),
            '--data',str(self.data),'--timeout','0.3','hold'],env=CLEAN_RUNTIME_ENV,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        try:
            self.assertTrue(select.select([guard.stdout],[],[],5)[0])
            self.assertEqual(guard.stdout.readline(),b'ready\n')
            restarted=subprocess.run(['/usr/bin/php',str(self.runtime),'--status'],env=CLEAN_RUNTIME_ENV,capture_output=True,text=True,timeout=3)
            self.assertEqual(restarted.returncode,1);self.assertEqual(restarted.stdout,'')
            guard.communicate(b'release\n',timeout=5);self.assertEqual(guard.returncode,0)
        finally:
            if guard.poll() is None:
                guard.kill();guard.communicate(timeout=5)
            guard.stdout.close();guard.stderr.close()
        restarted=subprocess.run(['/usr/bin/php',str(self.runtime),'--status'],env=CLEAN_RUNTIME_ENV,capture_output=True,text=True,timeout=3)
        self.assertEqual(restarted.returncode,0,restarted.stderr)
    def test_runtime_policy_is_current_at_each_claim(self):
        original=json.loads(json.dumps(self.settings));state_path=self.data/'enterprise-cluster/worker-state.json'
        original_state=state_path.read_bytes();process=None
        intent={'message':'Private fixture content','title':'Runtime admission'}
        encoded=json.dumps(intent,separators=(',',':'),sort_keys=True).encode();content_hash=hashlib.sha256(encoded).hexdigest()
        now=int(time.time());jobs=[{'id':'runtime-'+suffix,'delivery_id':'runtime-delivery-'+suffix,'site_id':'site-a',
            'created_at':now,'expires_at':now+60,'recipients':['device-a'],'channels':['desktop'],'intent':intent,
            'content_sha256':content_hash,'offline_approved':True} for suffix in ['first','second']]
        self.settings['runtime_enabled']=True
        self.settings['enterprise_cluster'].update({'offline_cache_enabled':True,'approved_cache':[content_hash],
            'peers':[{'node_id':'coordinator','site_id':'site-a','role':'coordinator','https_url':'https://localhost/peer',
                'cert_sha256':'a'*64,'hmac_secret':'b'*64}]})
        bootstrap='require '+json.dumps(str(self.module/'EnterpriseClusterRuntime.php'))+'; $s=FreePBX\\modules\\SlsConfigCrypto::readFile('+json.dumps(str(self.data/'mass-notifications.config'))+'); $r=new SLS\\MassNotify\\EnterpriseClusterRuntime($s); '
        enqueue='$jobs=json_decode('+json.dumps(json.dumps(jobs))+',true);foreach($jobs as $job){$r->dispatch(["source"=>"coordinator","action"=>"site.enqueue","payload"=>$job]);} '
        dispatch='try{$r->drain(static function(array $job):array {echo json_encode(["callback"=>$job["id"]])."\\n";flush();fgets(STDIN);return ["uncertain"=>false,"state"=>"accepted"];},false);echo "{\\"stopped\\":false}\\n";}catch(DomainException $e){echo json_encode(["stopped"=>str_contains($e->getMessage(),"stopped")])."\\n";}'
        try:
            self.write_settings()
            process=subprocess.Popen(['/usr/sbin/runuser','-u','asterisk','--','/usr/bin/php','-r',bootstrap+enqueue+dispatch],env=CLEAN_RUNTIME_ENV,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
            self.assertTrue(select.select([process.stdout],[],[],5)[0],'First private queue callback was not admitted.')
            self.assertEqual(json.loads(process.stdout.readline()),{'callback':'runtime-first'})
            # The real admission releases its shared config lock before an
            # admitted callback, so stop can commit before the next claim.
            with (self.data/'mass-notifications.config.lock').open('r+b') as lock:
                fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
                self.settings['runtime_enabled']=False;self.write_settings()
                fcntl.flock(lock,fcntl.LOCK_UN)
            output,error=process.communicate('continue\n',timeout=5)
            self.assertEqual(process.returncode,0,error);self.assertEqual(json.loads(output),{'stopped':True})
            state=json.loads(state_path.read_text(encoding='utf-8'))
            self.assertEqual(state['queue']['runtime-first']['state'],'complete')
            self.assertEqual(state['queue']['runtime-second']['state'],'queued')
            self.assertEqual(state['queue']['runtime-second']['job']['expires_at'],now+60)
            for policy in [False,'0',None]:
                self.settings['runtime_enabled']=policy;self.write_settings();before=state_path.read_bytes()
                fenced=subprocess.run(['/usr/bin/php',str(self.runtime),'--edge-once'],env=CLEAN_RUNTIME_ENV,capture_output=True,text=True,timeout=5)
                self.assertEqual(fenced.returncode,1);self.assertEqual(fenced.stdout,'');self.assertEqual(state_path.read_bytes(),before)
            status=subprocess.run(['/usr/bin/php',str(self.runtime),'--status'],env=CLEAN_RUNTIME_ENV,capture_output=True,text=True,timeout=5)
            self.assertEqual(status.returncode,0,status.stderr)
            self.settings['runtime_enabled']=True;self.write_settings()
            resume='echo json_encode($r->drain(static fn(array $job):array=>["uncertain"=>false,"state"=>"accepted"],false));'
            resumed=subprocess.run(['/usr/sbin/runuser','-u','asterisk','--','/usr/bin/php','-r',bootstrap+resume],env=CLEAN_RUNTIME_ENV,capture_output=True,text=True,timeout=5)
            self.assertEqual(resumed.returncode,0,resumed.stderr);self.assertEqual(list(json.loads(resumed.stdout)),['runtime-second'])
        finally:
            if process is not None and process.poll() is None:process.kill();process.communicate(timeout=5)
            self.settings.clear();self.settings.update(original);self.write_settings();write_exact(state_path,original_state)

if __name__=='__main__':unittest.main()
