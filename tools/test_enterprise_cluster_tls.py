#!/usr/bin/env python3
"""Isolated actual mTLS tests. Temporary configs/journals; no FreePBX bootstrap."""
import hashlib
import hmac
import json
import os
from pathlib import Path
import socket
import shutil
import ssl
import subprocess
import tempfile
import time

ROOT=Path(__file__).resolve().parents[1]
FIXTURE=ROOT/'tools/cluster_tls_fixture.php'


def canonical(value):
    return json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()


def command(args,**kwargs):
    return subprocess.check_output(args,stderr=subprocess.DEVNULL,**kwargs)


def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1',0)); return s.getsockname()[1]


def run():
    private_test=(os.environ.get('SLS_TEST_NAMESPACE')=='entered' and os.environ.get('SLS_TEST_PARENT_NET_NS') not in (None,os.readlink('/proc/self/ns/net')))
    private_build=(os.environ.get('SLS_BUILD_STAGE')=='isolated' and os.environ.get('SLS_BUILD_PARENT_NET') not in (None,os.readlink('/proc/self/ns/net')))
    if private_test or private_build:
        # A fresh network namespace starts with loopback down. Enable only
        # this verified private namespace's loopback; never change host links.
        subprocess.run([shutil.which('ip'),'link','set','lo','up'],check=True,capture_output=True)
    with tempfile.TemporaryDirectory(prefix='sls-cluster-isolated-') as temporary:
        base=Path(temporary); processes=[]
        command(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(base/'ca.key'),'-out',str(base/'ca.pem'),'-subj','/CN=SLS isolated test CA'])
        ids=['node-a','node-b','witness','coordinator']; pins={}; ports={i:port() for i in ids}
        for identity in ids:
            command(['openssl','req','-newkey','rsa:2048','-nodes','-keyout',str(base/(identity+'.key')),'-out',str(base/(identity+'.csr')),'-subj','/CN='+identity])
            (base/'extensions').write_text('subjectAltName=DNS:localhost\nextendedKeyUsage=serverAuth,clientAuth\n')
            command(['openssl','x509','-req','-in',str(base/(identity+'.csr')),'-CA',str(base/'ca.pem'),'-CAkey',str(base/'ca.key'),'-CAcreateserial','-days','1','-out',str(base/(identity+'.pem')),'-extfile',str(base/'extensions')])
            os.chmod(base/(identity+'.key'),0o600)
            pins[identity]=hashlib.sha256(command(['openssl','x509','-in',str(base/(identity+'.pem')),'-outform','DER'])).hexdigest()
        evidence={}
        for key in ['dialplan','endpoints','aors','transports','framework']:
            path=base/(key+'.conf'); path.write_text('matching isolated '+key+'\n'); os.chmod(path,0o640); evidence[key]=str(path)
        hashes={k:hashlib.sha256(Path(v).read_bytes()).hexdigest() for k,v in evidence.items()}
        pbx=hashlib.sha256(canonical(hashes)).hexdigest(); epoch=hashlib.sha256(b'isolated-epoch').hexdigest()
        php="require '"+str(ROOT/'slsmassnotifyserver/LabsSafety.php')+"'; echo SLS\\MassNotify\\LabsSafety::revision();"
        revision=command(['php','-r',php]).decode()
        fixtures={}
        for identity in ids:
            role='witness' if identity=='witness' else 'coordinator' if identity=='coordinator' else 'node'
            peers=[]
            for other in ids:
                if other==identity: continue
                peers.append({'node_id':other,'site_id':'site-a','role':'witness' if other=='witness' else 'coordinator' if other=='coordinator' else 'node',
                              'https_url':'https://localhost:'+str(ports[other])+'/peer','cert_sha256':pins[other],
                              'hmac_secret':hashlib.sha256('|'.join(sorted([identity,other])).encode()).hexdigest()})
            config={'enabled':True,'mode':'notification_ha' if role=='node' else 'standalone','role':role,'cluster_id':'isolated',
                    'site_id':'site-a','node_id':identity,'witness_id':'witness' if role=='node' else '', 'witness_epoch':epoch,
                    'tls_cert':str(base/(identity+'.pem')),'tls_key':str(base/(identity+'.key')),'tls_ca':str(base/'ca.pem'),
                    'peers':peers,'initial_owner':'node-a','auto_failover':True,'lease_seconds':5,'pbx_contract':pbx,
                    'spend_limit_cents':100,'remote_enabled':True,'site_recipients':['device-a'],'offline_cache_enabled':True,
                    'mirroring_enabled':role=='node'}
            settings={'enterprise_cluster':config,'labs_safety':{'schema':1,'receipts':{'enterprise_cluster':{'revision':revision,'accepted_at':int(time.time()),'actor':'isolated test'}}}}
            parent=base/(identity+'-data'); parent.mkdir(mode=0o700); data=parent/'enterprise-cluster'; data.mkdir(mode=0o700)
            fixture={'settings':settings,'directory':str(data),'boot_id':hashlib.sha256((identity+'-boot').encode()).hexdigest(),
                     'pbx_files':evidence,'port':ports[identity]}
            path=base/(identity+'.json'); path.write_text(json.dumps(fixture)); fixtures[identity]=(fixture,path)
            command(['php',str(FIXTURE),str(path),'initialize'])
        def start(identity):
            process=subprocess.Popen(['php',str(FIXTURE),str(fixtures[identity][1])],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,text=True)
            assert process.stdout.readline().strip()=='ready'; processes.append(process); return process
        def request(source,target,action,payload,*,body=None,cert_source=None,declared=None):
            key=hashlib.sha256('|'.join(sorted([source,target])).encode()).hexdigest()
            if body is None:
                value={'version':1,'cluster_id':'isolated','epoch':epoch,'source':source,'target':target,'nonce':os.urandom(24).hex(),
                       'issued_at':int(time.time()),'action':action,'payload':payload}
                value['signature']=hmac.new(bytes.fromhex(key),canonical(value),hashlib.sha256).hexdigest(); body=canonical(value)
            context=ssl.create_default_context(cafile=str(base/'ca.pem')); cert_source=cert_source or source
            context.load_cert_chain(str(base/(cert_source+'.pem')),str(base/(cert_source+'.key')))
            with context.wrap_socket(socket.create_connection(('127.0.0.1',ports[target]),timeout=3),server_hostname='localhost') as connection:
                assert hashlib.sha256(connection.getpeercert(binary_form=True)).hexdigest()==pins[target]
                connection.sendall(('POST /peer HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: '+str(declared if declared is not None else len(body))+'\r\nConnection: close\r\n\r\n').encode()+body)
                response=b''
                while True:
                    part=connection.recv(8192)
                    if not part: break
                    response+=part
            status=int(response.split(b' ',2)[1]); decoded=json.loads(response.split(b'\r\n\r\n',1)[1])
            return status,decoded,body
        def result(source,target,action,payload):
            status,response,_=request(source,target,action,payload)
            assert status==200,(status,response)
            return response['payload']
        try:
            witness=start('witness'); start('node-b')
            active,path=fixtures['node-a']; now=int(time.time())
            active['intent']={'delivery_id':'actual-client-path','channel':'desktop','target':'device-a','site_id':'site-a',
                'created_at':now,'expires_at':now+120,'content_sha256':hashlib.sha256(b'client-path').hexdigest(),
                'estimated_cost_cents':0,'schedule_id':'','incident_id':''}
            active['counter']=str(base/'client-side-effects'); path.write_text(json.dumps(active))
            exercise=json.loads(command(['php',str(FIXTURE),str(path),'exercise']))
            assert exercise['ok'],exercise
            assert (base/'client-side-effects').read_text()=='effect\n'
            assert not json.loads(command(['php',str(FIXTURE),str(path),'exercise']))['ok']
            assert (base/'client-side-effects').read_text()=='effect\n'
            status,response,body=request('node-a','witness','status',[]); assert status==200
            assert request('node-a','witness','status',[],body=body)[0]==403
            assert request('node-a','witness','status',[],cert_source='node-b')[0]==403
            tampered=json.loads(body); tampered['nonce']=os.urandom(24).hex()
            assert request('node-a','witness','status',[],body=canonical(tampered))[0]==403
            assert request('node-a','witness','status',[],declared=1048577)[0]==413
            wrong=json.loads(body); wrong['target']='node-b'; wrong['nonce']=os.urandom(24).hex()
            assert request('node-a','witness','status',[],body=canonical(wrong))[0]==403
            wrong_epoch=json.loads(body); wrong_epoch['epoch']='0'*64; wrong_epoch['nonce']=os.urandom(24).hex()
            unsigned=dict(wrong_epoch); unsigned.pop('signature')
            key=hashlib.sha256(b'node-a|witness').hexdigest()
            wrong_epoch['signature']=hmac.new(bytes.fromhex(key),canonical(unsigned),hashlib.sha256).hexdigest()
            assert request('node-a','witness','status',[],body=canonical(wrong_epoch))[0]==403
            print('PASS actual mutual TLS, exact certificate/node binding, replay, tamper, oversize and isolation')
            boot_a=fixtures['node-a'][0]['boot_id']; boot_b=fixtures['node-b'][0]['boot_id']
            lease=result('node-a','witness','lease.acquire',{'boot_id':boot_a,'pbx_contract':pbx}); assert lease['ok']
            token=lease['result']['token']
            assert not result('node-b','witness','lease.acquire',{'boot_id':boot_b,'pbx_contract':pbx})['ok']
            now=int(time.time()); intent={'delivery_id':'isolated-delivery-1','channel':'desktop','target':'device-a','site_id':'site-a',
                'created_at':now,'expires_at':now+120,'content_sha256':hashlib.sha256(b'immutable').hexdigest(),
                'estimated_cost_cents':10,'schedule_id':'schedule-a','incident_id':'incident-a'}
            begin=result('node-a','witness','effect.begin',{'boot_id':boot_a,'token':token,'intent':intent}); assert begin['ok']
            assert not result('node-a','witness','effect.begin',{'boot_id':boot_a,'token':token,'intent':intent})['ok']
            # Simulate a lost reply: the sender cannot prove whether its admitted
            # external action happened. The witness forbids takeover and replay.
            time.sleep(6)
            assert not result('node-b','witness','lease.acquire',{'boot_id':boot_b,'pbx_contract':pbx})['ok']
            assert not result('node-a','witness','lease.acquire',{'boot_id':hashlib.sha256(b'reboot-before-uncertain-review').hexdigest(),'pbx_contract':pbx})['ok']
            assert not result('node-a','witness','effect.begin',{'boot_id':boot_a,'token':token,'intent':dict(intent,delivery_id='stale-token')})['ok']
            finish=result('node-a','witness','effect.finish',{'id':begin['result']['id'],'boot_id':boot_a,'token':token,'receipt':{'uncertain':False,'category':'confirmed_response'}}); assert finish['ok']
            takeover=result('node-b','witness','lease.acquire',{'boot_id':boot_b,'pbx_contract':pbx}); assert takeover['ok']
            assert json.loads(command(['php',str(FIXTURE),str(fixtures['node-b'][1]),'authority']))['ok']
            assert not json.loads(command(['php',str(FIXTURE),str(fixtures['node-a'][1]),'authority']))['ok']
            assert not result('node-a','witness','lease.acquire',{'boot_id':boot_a,'pbx_contract':pbx})['ok']
            assert not result('node-b','witness','effect.begin',{'boot_id':boot_b,'token':takeover['result']['token'],'intent':intent})['ok']
            print('PASS exclusive lease, stale-token software fencing, reply uncertainty, safe takeover and manual failback')
            # Exercise the actual module recovery method and native private job
            # store. Only untouched, unexpired queued intent is reconstructed.
            native_id='job_'+hashlib.sha256(b'untouched native recovery').hexdigest()[:32]
            recovered_request={'delivery_id':'untouched-recovery','delivery_timestamp':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'phones':[],'desktops':[]}
            native_record={'revision':1,'intent':{'id':native_id,'created_at':recovered_request['delivery_timestamp'],'request':recovered_request},
                'state':'queued','uncertain':False,'schedule_id':'','incident_id':'','updated_at':int(time.time()),'receipts':[]}
            assert result('node-b','witness','state.put',{'kind':'jobs','id':native_id,'record':native_record})['ok']
            for identity in ['node-a','node-b']:
                local,local_path=fixtures[identity]; local['jobs']=str(base/(identity+'-jobs')); local['counter']=str(base/(identity+'-recovery-callback'))
                Path(local['jobs']).mkdir(mode=0o700); local_path.write_text(json.dumps(local))
            assert not json.loads(command(['php',str(FIXTURE),str(fixtures['node-a'][1]),'recover']))['ok']
            assert not Path(fixtures['node-a'][0]['counter']).exists()
            actual=json.loads(command(['php',str(FIXTURE),str(fixtures['node-b'][1]),'recover']))
            assert actual['ok'] and native_id in actual['result'],actual
            local_job=json.loads((Path(fixtures['node-b'][0]['jobs'])/(native_id+'.json')).read_text())
            assert local_job['request']==recovered_request and local_job['state']=='queued'
            assert json.loads(command(['php',str(FIXTURE),str(fixtures['node-b'][1]),'recover']))['result']==[]
            assert Path(fixtures['node-b'][0]['counter']).read_text()==native_id+'\n'
            uncertain_id='job_'+hashlib.sha256(b'uncertain native recovery').hexdigest()[:32]
            uncertain_record=dict(native_record,intent=dict(native_record['intent'],id=uncertain_id),state='uncertain',uncertain=True)
            assert result('node-b','witness','state.put',{'kind':'jobs','id':uncertain_id,'record':uncertain_record})['ok']
            assert json.loads(command(['php',str(FIXTURE),str(fixtures['node-b'][1]),'recover']))['result']==[]
            assert not (Path(fixtures['node-b'][0]['jobs'])/(uncertain_id+'.json')).exists()
            print('PASS actual module queued-job recovery, standby isolation, immutable native snapshots and uncertain-job exclusion')
            record={'revision':1,'intent':{'message':'frozen','phones':['1001']},'state':'queued','uncertain':False,
                    'schedule_id':'schedule-a','incident_id':'incident-a','updated_at':now,'receipts':[]}
            assert result('node-b','witness','state.put',{'kind':'jobs','id':'job-intent','record':record})['ok']
            changed=dict(record,intent={'message':'tampered'})
            assert not result('node-b','witness','state.put',{'kind':'jobs','id':'job-intent','record':changed})['ok']
            recovery=result('node-b','witness','state.pull',[]); assert recovery['result']['replica']['jobs']['job-intent']['intent']==record['intent']
            assert result('node-a','node-b','mirror.put',{'revision':1,'shared':{'quiet_hours_start':'22:00'}})['ok']
            assert not result('node-a','node-b','mirror.put',{'revision':2,'shared':{'ami':{'password':'changed'}}})['ok']
            assert result('coordinator','witness','control.set',{'stopped':True,'spend_limit_cents':100})['ok']
            assert not result('node-b','witness','effect.begin',{'boot_id':boot_b,'token':takeover['result']['token'],'intent':dict(intent,delivery_id='stopped')})['ok']
            assert result('coordinator','witness','control.set',{'stopped':False,'spend_limit_cents':10})['ok']
            assert not result('node-b','witness','effect.begin',{'boot_id':boot_b,'token':takeover['result']['token'],'intent':dict(intent,delivery_id='over-spend')})['ok']
            print('PASS immutable replicated jobs/schedule/incident IDs, prohibited local config mirroring, STOP and spending fence')
            # Real client path must fence its callback when the authority is lost.
            fixture,path=fixtures['node-b']; fixture['intent']=dict(intent,delivery_id='unavailable-witness',estimated_cost_cents=0)
            fixture['counter']=str(base/'external-side-effects'); path.write_text(json.dumps(fixture))
            witness.terminate(); witness.wait(timeout=5)
            assert not json.loads(command(['php',str(FIXTURE),str(path),'authority']))['ok']
            exercise=json.loads(command(['php',str(FIXTURE),str(path),'exercise']))
            assert not exercise['ok'] and not (base/'external-side-effects').exists()
            # Persistent witness reboot retains spent state, immutable intent and
            # completed/uncertain effects, rather than reinitializing authority.
            start('witness'); recovery=result('node-b','witness','state.pull',[])
            assert begin['result']['id'] in recovery['result']['effects']
            assert recovery['result']['control']['spent_cents']==10
            reboot=hashlib.sha256(b'node-b-new-boot').hexdigest()
            assert not result('node-b','witness','lease.acquire',{'boot_id':reboot,'pbx_contract':pbx})['ok']
            print('PASS unavailable witness blocks actual callback, persistent witness reboot and node reboot isolation')
            job_intent={'message':'local approved content','title':'Isolated edge test'}
            job={'id':'site-job-a','delivery_id':'site-delivery-a','site_id':'site-a','created_at':int(time.time()),'expires_at':int(time.time())+30,
                 'recipients':['device-a'],'channels':['desktop'],'intent':job_intent,'content_sha256':hashlib.sha256(canonical(job_intent)).hexdigest(),'offline_approved':True}
            assert result('coordinator','node-b','site.enqueue',job)['ok']
            assert result('coordinator','node-b','site.enqueue',job)['result']['state']=='queued'
            bad=dict(job,recipients=['foreign-device']); bad['id']='foreign-job'
            assert not result('coordinator','node-b','site.enqueue',bad)['ok']
            expired=dict(job,id='expired-job',created_at=int(time.time())-120,expires_at=int(time.time())-1)
            assert not result('coordinator','node-b','site.enqueue',expired)['ok']
            print('PASS site ownership, immutable job idempotency, TTL and expired replay rejection')
            witness_directory=Path(fixtures['witness'][0]['directory'])
            parent_marker=witness_directory.parent/'.enterprise-cluster-required.json'
            assert parent_marker.exists()
            shutil.rmtree(witness_directory)
            assert request('node-b','witness','status',[])[0]==403
            reset=subprocess.run(['php',str(FIXTURE),str(fixtures['witness'][1]),'initialize'],capture_output=True)
            assert reset.returncode!=0 and parent_marker.exists() and not (witness_directory/'worker-state.json').exists()
            assert not json.loads(command(['php',str(FIXTURE),str(fixtures['node-b'][1]),'authority']))['ok']
            print('PASS whole-directory witness loss remains fenced by protected parent marker; same-epoch initialization refused')
        finally:
            for process in processes:
                if process.poll() is None:
                    process.terminate()
                process.wait(timeout=5)
    print('Isolated cluster TLS fault tests passed; no PBX/service/configuration or external notifications were used.')


if __name__=='__main__':
    run()
