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
import unittest

ROOT=Path(__file__).resolve().parents[1]
SOURCE=ROOT/'slsmassnotifyserver'
private_test=all(os.environ.get('SLS_TEST_NAMESPACE')=='entered' and os.environ.get('SLS_TEST_PARENT_'+kind+'_NS') not in (None,os.readlink('/proc/self/ns/'+namespace)) for kind,namespace in [('MOUNT','mnt'),('NET','net'),('PID','pid')])
private_build=os.environ.get('SLS_BUILD_STAGE')=='isolated' and os.environ.get('SLS_BUILD_PARENT_NET') not in (None,os.readlink('/proc/self/ns/net'))
if not (private_test or private_build):
    raise SystemExit('Use tools/run_isolated_tests.sh; no deployment mount or worker was started.')

class CopiedCluster(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        subprocess.run(['ip','link','set','lo','up'],check=True,capture_output=True)
        cls.deployment=Path('/var/www/html')
        subprocess.run(['mount','-t','tmpfs','-o','mode=755,size=16M','tmpfs',str(cls.deployment)],check=True,capture_output=True)
        cls.addClassCleanup(subprocess.run,['umount',str(cls.deployment)],check=True,capture_output=True)
        cls.module=cls.deployment/'admin/modules/slsmassnotifyserver';cls.api=cls.deployment/'api/sls-mass-notify'
        cls.module.mkdir(parents=True);cls.api.mkdir(parents=True)
        names=['module.xml','EnterpriseClusterConfig.php','EnterpriseClusterRuntime.php','EnterpriseClusterProtocol.php','EnterpriseClusterStore.php','EnterpriseClusterPbxContract.php','EnterpriseClusterEdge.php','LabsSafety.php','ConfigCrypto.php','api/sls-mass-notify/config-crypto.php','bin/sls_mass_notify/sls_announcement_jobs.php']
        for name in names:
            target=cls.module/name;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(SOURCE/name,target)
        for name in ['index.php','peer.php','edge.php','edge-view.php','security.php','contract.php','event-log.php','config-crypto.php']:
            shutil.copyfile(SOURCE/'api/sls-mass-notify'/name,cls.api/name)
        for item in cls.deployment.rglob('*'):
            item.chmod(0o755 if item.is_dir() else 0o644)
        cls.runtime=Path('/usr/local/bin/sls_mass_notify/sls_mass_notify_cluster_worker.php')
        assert cls.runtime.read_bytes()==(SOURCE/'bin/sls_mass_notify_cluster_worker.php').read_bytes()
        cls.account=pwd.getpwnam('asterisk');cls.data=Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
        # Nested disposable state mount restores the outer fixture's exact
        # settings/journals after this suite, including any passive markers.
        subprocess.run(['mount','-t','tmpfs','-o',f'mode=750,size=8M,uid={cls.account.pw_uid},gid={cls.account.pw_gid}','tmpfs',str(cls.data)],check=True,capture_output=True)
        cls.addClassCleanup(subprocess.run,['umount',str(cls.data)],check=True,capture_output=True)
        cls.activity=cls.data/'announcement-activity.lock';cls.activity.write_bytes(b'');cls.activity.chmod(0o640);os.chown(cls.activity,cls.account.pw_uid,cls.account.pw_gid)
        cls.lifecycle=cls.data/'enterprise-worker.lock'
        cls.token='T'*48
        cls.settings={'enabled':'0','control_api':{'enabled':False},'enterprise_cluster':{'enabled':True,'remote_enabled':True,'mode':'edge','role':'node','cluster_id':'copied-test','node_id':'edge-a','site_id':'site-a','tls_cert':'/unused-local-fixture-cert','tls_key':'/unused-local-fixture-key','tls_ca':'/unused-local-fixture-ca','site_recipients':['device-a'],'edge_devices':[{'id':'device-a','token_sha256':__import__('hashlib').sha256(cls.token.encode()).hexdigest(),'channels':['desktop']} ]}}
        cls.write_settings()
        initialized=subprocess.run(['php',str(cls.runtime),'--initialize'],capture_output=True,text=True,timeout=5)
        assert initialized.returncode==0,(initialized.stdout,initialized.stderr)
    @classmethod
    def write_settings(cls):
        code='require '+json.dumps(str(cls.module/'ConfigCrypto.php'))+'; echo FreePBX\\modules\\SlsConfigCrypto::encode(json_decode(stream_get_contents(STDIN),true));'
        body=subprocess.check_output(['php','-r',code],input=json.dumps(cls.settings).encode())
        target=cls.data/'mass-notifications.config';target.write_bytes(body);target.chmod(0o640);os.chown(target,cls.account.pw_uid,cls.account.pw_gid)
    def entry(self,name,path,*,device=False):
        server={'REQUEST_METHOD':'GET','HTTPS':'on','REMOTE_ADDR':'127.0.0.1','REQUEST_URI':path}
        query={}
        if device:server['HTTP_AUTHORIZATION']='Bearer '+self.token;query={'action':'poll','device_id':'device-a'}
        code="$_SERVER=json_decode("+json.dumps(json.dumps(server))+",true);$_GET=json_decode("+json.dumps(json.dumps(query))+",true);register_shutdown_function(static function(){fwrite(STDERR,json_encode(['status'=>http_response_code()?:200,'runtime'=>class_exists('SLS\\\\MassNotify\\\\EnterpriseClusterRuntime',false)?(new ReflectionClass('SLS\\\\MassNotify\\\\EnterpriseClusterRuntime'))->getFileName():null]));});require "+json.dumps(str(self.api/name))+";"
        result=subprocess.run(['php','-r',code],capture_output=True,text=True,timeout=5)
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        return result.stdout,json.loads(result.stderr)
    def test_unmodified_copied_peer_edge_and_viewer(self):
        for name in ['index.php','peer.php','edge.php','edge-view.php']:
            self.assertEqual((self.api/name).read_bytes(),(SOURCE/'api/sls-mass-notify'/name).read_bytes())
        body,meta=self.entry('peer.php','/api/sls-mass-notify/peer.php')
        self.assertEqual(json.loads(body)['error'],'peer_authentication_or_authority_rejected');self.assertEqual(meta['status'],403)
        self.assertEqual(meta['runtime'],str(self.module/'EnterpriseClusterRuntime.php'))
        body,meta=self.entry('edge.php','/api/sls-mass-notify/edge.php',device=True)
        self.assertEqual(json.loads(body),{'events':[]});self.assertEqual(meta['status'],200)
        self.assertEqual(meta['runtime'],str(self.module/'EnterpriseClusterRuntime.php'))
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
            subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
                '-keyout',str(key),'-out',str(cert),'-subj','/CN=localhost',
                '-addext','subjectAltName=DNS:localhost','-addext','extendedKeyUsage=serverAuth,clientAuth'],
                check=True,capture_output=True)
            key.chmod(0o600)
            server=http.server.HTTPServer(('127.0.0.1',0),Routes)
            context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(str(cert),str(key))
            context.load_verify_locations(cafile=str(cert));context.verify_mode=ssl.CERT_REQUIRED
            server.socket=context.wrap_socket(server.socket,server_side=True)
            thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
            client=ssl.create_default_context(cafile=str(cert));client.load_cert_chain(str(cert),str(key))
            pin=hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest()
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
    def test_maintenance_exclusion_and_watch_lifetime(self):
        with self.lifecycle.open('r+b') as locked:
            fcntl.flock(locked,fcntl.LOCK_EX)
            result=subprocess.run(['php',str(self.runtime),'--status'],capture_output=True,text=True,timeout=3)
            self.assertEqual(result.returncode,1);self.assertEqual(result.stdout,'')
            fcntl.flock(locked,fcntl.LOCK_UN)
        process=subprocess.Popen(['php',str(self.runtime),'--edge-watch'],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
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
                    '--data',str(self.data),'--timeout','0.1','hold'],input=b'',capture_output=True,timeout=3)
                self.assertNotEqual(guarded.returncode,0)
                self.assertIn(b'active worker did not become idle: enterprise-worker.lock',guarded.stderr)
                # Taking the lifecycle lease first avoids a lock-order inversion
                # and prevents entry into inner worker/activity/settings locks.
                self.assertFalse((self.data/'schedule-runner.lock').exists())
                fcntl.flock(probe,fcntl.LOCK_UN)
        finally:
            if process.poll() is None:process.send_signal(signal.SIGTERM)
            process.wait(timeout=5);process.stdout.close();process.stderr.close()
        with self.lifecycle.open('r+b') as released:
            fcntl.flock(released,fcntl.LOCK_EX|fcntl.LOCK_NB);fcntl.flock(released,fcntl.LOCK_UN)
        guard=subprocess.Popen(['/usr/bin/python3','-I',str(SOURCE/'bin/sls_mass_notify/sls_install_guard.py'),
            '--data',str(self.data),'--timeout','0.3','hold'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        try:
            self.assertTrue(select.select([guard.stdout],[],[],5)[0])
            self.assertEqual(guard.stdout.readline(),b'ready\n')
            restarted=subprocess.run(['php',str(self.runtime),'--status'],capture_output=True,text=True,timeout=3)
            self.assertEqual(restarted.returncode,1);self.assertEqual(restarted.stdout,'')
            guard.communicate(b'release\n',timeout=5);self.assertEqual(guard.returncode,0)
        finally:
            if guard.poll() is None:guard.communicate(b'release\n',timeout=5)
            guard.stdout.close();guard.stderr.close()
        restarted=subprocess.run(['php',str(self.runtime),'--status'],capture_output=True,text=True,timeout=3)
        self.assertEqual(restarted.returncode,0,restarted.stderr)

if __name__=='__main__':unittest.main()
