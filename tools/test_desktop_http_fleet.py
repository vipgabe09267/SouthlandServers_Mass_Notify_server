#!/usr/bin/python3
"""Actual Apache/mod_php HTTPS, shared-IP polling, burst receipts and SSE cleanup.

Requires the isolated test/build wrapper. SLS_FLEET_CLIENTS=1000 selects the full
synthetic polling population; stream concurrency remains deliberately bounded.
This never targets the production web server, database, devices or SIP sockets.
"""
import base64
from concurrent.futures import ThreadPoolExecutor
import datetime as dt
import fcntl
import grp
import http.client
import json
import os
from pathlib import Path
import pwd
import shutil
import socket
import ssl
import statistics
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')


class DesktopHttpFleetTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        parent = os.environ.get('SLS_TEST_PARENT_NET_NS') or os.environ.get('SLS_BUILD_PARENT_NET')
        if not parent or parent == os.readlink('/proc/self/ns/net'):
            raise RuntimeError('Run this HTTP fleet test through tools/run_isolated_tests.sh or the isolated release gate.')
        subprocess.run(['ip','link','set','lo','up'],check=True,timeout=5)
        cls.count=int(os.environ.get('SLS_FLEET_CLIENTS','25'))
        if not 2 <= cls.count <= 1000: raise RuntimeError('Fixture client count must be 2–1000.')
        cls.temp=tempfile.TemporaryDirectory(prefix='sls-http-fleet-'); cls.root=Path(cls.temp.name)
        cls.runtime=pwd.getpwnam('asterisk'); cls.root.chmod(0o750); os.chown(cls.root,cls.runtime.pw_uid,cls.runtime.pw_gid)
        cls.server_root=cls.root/'apache';cls.server_root.mkdir(mode=0o700);os.chown(cls.server_root,cls.runtime.pw_uid,cls.runtime.pw_gid)
        api=cls.root/'api';shutil.copytree(ROOT/'slsmassnotifyserver/api',api)
        # Only synthetic data is writable in the namespace. All host files and
        # the repository are mounted read-only by the required wrapper.
        DATA.mkdir(parents=True,exist_ok=True);os.chown(DATA,cls.runtime.pw_uid,cls.runtime.pw_gid);DATA.chmod(0o750)
        for folder in ('sipnotify','sipnotify/acknowledgements'):
            target=DATA/folder;target.mkdir(exist_ok=True);os.chown(target,cls.runtime.pw_uid,cls.runtime.pw_gid);target.chmod(0o750)
        key=cls.root/'key.pem';cert=cls.root/'cert.pem'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),
                        '-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost'],
                       stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=True,timeout=10)
        with socket.socket() as lease:lease.bind(('127.0.0.1',0));cls.port=lease.getsockname()[1]
        config=cls.root/'httpd.conf'
        config.write_text(f'''ServerRoot "{cls.server_root}"
ServerName localhost
Listen 127.0.0.1:{cls.port}
PidFile "{cls.server_root}/apache.pid"
ErrorLog "{cls.server_root}/error.log"
LogLevel warn
LoadModule mpm_prefork_module /usr/lib/apache2/modules/mod_mpm_prefork.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
LoadModule alias_module /usr/lib/apache2/modules/mod_alias.so
LoadModule env_module /usr/lib/apache2/modules/mod_env.so
LoadModule setenvif_module /usr/lib/apache2/modules/mod_setenvif.so
LoadModule socache_shmcb_module /usr/lib/apache2/modules/mod_socache_shmcb.so
LoadModule ssl_module /usr/lib/apache2/modules/mod_ssl.so
LoadModule php_module /usr/lib/apache2/modules/libphp8.2.so
User asterisk
Group {grp.getgrgid(cls.runtime.pw_gid).gr_name}
StartServers 2
MinSpareServers 2
MaxSpareServers 4
ServerLimit 12
MaxRequestWorkers 12
MaxConnectionsPerChild 300
Timeout 35
KeepAlive Off
SSLEngine on
SSLCertificateFile "{cert}"
SSLCertificateKeyFile "{key}"
SSLProtocol -all +TLSv1.2 +TLSv1.3
DocumentRoot "{api}"
Alias /api/sipnotify/ "{api}/sipnotify/index.php/"
SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
<Directory "{api}">
Require all granted
AllowOverride None
AcceptPathInfo On
<FilesMatch "\\.php$">
SetHandler application/x-httpd-php
</FilesMatch>
</Directory>
php_admin_value memory_limit 64M
php_admin_flag display_errors Off
php_admin_flag log_errors On
php_admin_value error_log "{cls.server_root}/php-error.log"
''')
        encrypt=cls.root/'seed.php'
        encrypt.write_text('''<?php
        $key=random_bytes(32);$nonce=random_bytes(12);$cipher=openssl_encrypt('fixture-password','aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag);
        $clients=[];for($i=0;$i<(int)$argv[1];$i++)$clients[]=['client_id'=>'fixture-'.$i,'username'=>'fixture-'.$i,'enabled'=>'1','password_enc'=>'v1:'.base64_encode($nonce.$tag.$cipher)];
        echo json_encode(['desktop_auth_key'=>base64_encode($key),'desktop_clients'=>$clients,'desktop_client_limit'=>(int)$argv[1],
            'public_pbx_host'=>'localhost','advertised_api_port'=>(int)$argv[2],'advertised_api_port_enabled'=>'1','log_retention_days'=>30]);
        ''')
        raw=subprocess.check_output(['php',str(encrypt),str(cls.count),str(cls.port)],timeout=5)
        cls.write_state('mass-notifications.config',raw);cls.events([])
        cls.context=ssl.create_default_context(cafile=str(cert))
        cls.process=subprocess.Popen(['/usr/sbin/apache2','-f',str(config),'-DFOREGROUND'],stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,start_new_session=True)
        try:
            deadline=time.monotonic()+5
            while time.monotonic()<deadline:
                if cls.process.poll() is not None:raise RuntimeError(cls.process.stderr.read().decode())
                try:
                    if cls.request(0)[0]==200:break
                except (OSError,http.client.HTTPException):pass
                time.sleep(.05)
            else:raise RuntimeError('Private Apache did not become ready.')
        except BaseException:
            cls.tearDownClass();raise

    @classmethod
    def tearDownClass(cls):
        if hasattr(cls,'process'):
            cls.process.terminate()
            try:cls.process.communicate(timeout=5)
            except subprocess.TimeoutExpired:cls.process.kill();cls.process.communicate(timeout=5)
        if hasattr(cls,'temp'):cls.temp.cleanup()

    @classmethod
    def write_state(cls,name,raw):
        path=DATA/name;temporary=path.with_name(path.name+'.fixture-next');temporary.write_bytes(raw)
        os.chown(temporary,cls.runtime.pw_uid,cls.runtime.pw_gid);temporary.chmod(0o640);temporary.replace(path)

    @classmethod
    def events(cls,events):cls.write_state('sipnotify/sipnotify_events.jsonl',b''.join(json.dumps(e).encode()+b'\n' for e in events))

    @classmethod
    def event(cls,name,recipients):
        return {'id':name,'schema_version':1,'kind':'announcement','is_test':True,'created_at':dt.datetime.now(dt.timezone.utc).isoformat(),
                'title':'Private synthetic fixture','message':'No production delivery','desktop_enabled':True,'desktop_all':False,
                'desktop_recipients':recipients,'display_timeout_seconds':30}

    @classmethod
    def request(cls,client,path='/api/sipnotify/desktop',payload=None,raw=False):
        # SSE's expected heartbeat gap is 15 seconds. Keep the socket deadline
        # above that gap; the test still independently requires activity <18s.
        connection=http.client.HTTPSConnection('localhost',cls.port,context=cls.context,timeout=20 if raw else 15)
        started=time.monotonic();headers={'Authorization':'Basic '+base64.b64encode(('fixture-'+str(client)+':fixture-password').encode()).decode(),'Accept':'application/json'}
        if payload is not None:headers['Content-Type']='application/json'
        try:
            connection.request('POST' if payload is not None else 'GET',path,body=json.dumps(payload) if payload is not None else None,headers=headers)
            response=connection.getresponse()
            if raw:return connection,response
            body=response.read(1024*1024)
            return response.status,json.loads(body),time.monotonic()-started,dict(response.headers)
        finally:
            if not raw:connection.close()

    def test_shared_ip_burst_polling_and_exact_durable_receipts(self):
        recipients=['fixture-'+str(i) for i in range(self.count)]
        self.events([self.event('fixture-first',recipients),self.event('fixture-private',['fixture-0']),self.event('fixture-second',recipients)])
        timings=[]
        with ThreadPoolExecutor(max_workers=8) as pool:
            for round_number in range(3):
                started=time.monotonic()
                results=list(pool.map(lambda client:self.request(client,'/api/sipnotify/desktop?last_event_id=%40sls%3Aempty'),range(self.count)))
                for client,(status,body,elapsed,headers) in enumerate(results):
                    self.assertEqual(status,200,(client,body));self.assertTrue(body['ok']);self.assertIn('no-store',headers['Cache-Control'])
                    ids=[event['id'] for event in body['events']]
                    self.assertEqual(ids,['fixture-first']+(['fixture-private'] if client==0 else [])+['fixture-second'])
                    timings.append(elapsed)
                self.assertLess(time.monotonic()-started,30,'This isolated fleet batch exceeded its qualification deadline.')
            receipts=list(pool.map(lambda client:self.request(client,'/api/sipnotify/desktop/ack',{'event_id':'fixture-second'}),range(self.count)))
        for client,(status,body,elapsed,headers) in enumerate(receipts):
            self.assertEqual(status,200,(client,body));self.assertTrue(body['ok']);self.assertEqual(body['event_id'],'fixture-second')
        duplicate=self.request(0,'/api/sipnotify/desktop/ack',{'event_id':'fixture-second'})
        self.assertEqual(duplicate[0],200)
        rejected=self.request(1,'/api/sipnotify/desktop/ack',{'event_id':'fixture-private'})
        self.assertEqual(rejected[0],400)
        self.assertFalse(rejected[1]['ok'])
        actual=[]
        for path in (DATA/'sipnotify/acknowledgements').glob('*.json'):
            ledger=json.loads(path.read_text());actual.extend(ledger['acknowledgements'].values())
        self.assertEqual(sum(row['event_id']=='fixture-second' for row in actual),self.count)
        self.assertEqual(len({row['username'] for row in actual if row['event_id']=='fixture-second'}),self.count)
        print(json.dumps({'clients':self.count,'poll_requests':self.count*3,'receipt_requests':self.count+2,
                          'poll_p95_ms':round(sorted(timings)[int(len(timings)*.95)-1]*1000,3),
                          'poll_mean_ms':round(statistics.mean(timings)*1000,3),'production_requests':0,'protocol':'actual private HTTPS Apache/mod_php'}),flush=True)

    def test_actual_http_sse_heartbeat_disconnect_and_reuse(self):
        connection,response=self.request(0,'/api/sipnotify/desktop/stream?stream_seconds=20',raw=True)
        try:
            self.assertEqual(response.status,200);self.assertIn('text/event-stream',response.getheader('Content-Type'))
            def frame():
                lines=[]
                while True:
                    line=response.fp.readline(65536)
                    if not line:raise AssertionError('SSE disconnected without an expected frame.')
                    lines.append(line)
                    if line in (b'\n',b'\r\n'):return b''.join(lines)
            got=b''
            while b': keepalive ' not in got:got+=frame()
            self.assertIn(b'"protocol_version":2',got);began=time.monotonic();got=b''
            while b': keepalive ' not in got:got+=frame()
            self.assertGreater(time.monotonic()-began,10);self.assertLess(time.monotonic()-began,18)
        finally:response.close();connection.close()
        # Output on the next heartbeat detects a disconnected socket. A slot
        # must become reusable by the normal endpoint lifetime, without repair.
        deadline=time.monotonic()+22
        paths=list((DATA/'sipnotify/stream-slots').glob('*.lock'))
        while time.monotonic()<deadline:
            reusable=True
            for path in paths:
                with path.open('rb') as handle:
                    try:fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
                    except BlockingIOError:reusable=False
            if reusable:break
            time.sleep(.1)
        self.assertTrue(reusable,'SSE slots survived an actual HTTP disconnect.')
        _,replacement=self.request(0,'/api/sipnotify/desktop/stream?stream_seconds=1',raw=True)
        self.assertEqual(replacement.status,200);replacement.read();replacement.close()


if __name__=='__main__':unittest.main(verbosity=2)
