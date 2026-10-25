#!/usr/bin/env python3
"""Isolated actual mTLS tests. Temporary configs/journals; no FreePBX bootstrap."""
import hashlib
import hmac
import json
import os
from pathlib import Path
import select
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
    private_test=(os.environ.get('SLS_TEST_NAMESPACE')=='entered' and os.environ.get('SLS_TEST_PARENT_NET_NS') not in (None,'',os.readlink('/proc/self/ns/net')))
    private_build=(os.environ.get('SLS_BUILD_STAGE')=='isolated' and os.environ.get('SLS_BUILD_PARENT_NET') not in (None,'',os.readlink('/proc/self/ns/net')))
    if not (private_test or private_build):
        raise SystemExit('Use tools/run_isolated_tests.sh; no cluster fixture was started.')
    # Refuse before parsing fixture files, creating keys or opening sockets.
    for overrides in [{}, {'SLS_TEST_NAMESPACE':'entered','SLS_TEST_PARENT_NET_NS':os.readlink('/proc/self/ns/net')},
                      {'SLS_BUILD_STAGE':'isolated','SLS_BUILD_PARENT_NET':os.readlink('/proc/self/ns/net')},
                      {'SLS_TEST_NAMESPACE':'entered','SLS_TEST_PARENT_NET_NS':''},
                      {'SLS_BUILD_STAGE':'isolated','SLS_BUILD_PARENT_NET':''}]:
        environment={key:value for key,value in os.environ.items() if not key.startswith(('SLS_TEST_','SLS_BUILD_'))}
        environment.update(overrides)
        for args in [['php',str(FIXTURE),'/nonexistent-sls-cluster-fixture.json'],['python3','-B',str(Path(__file__).resolve())]]:
            refused=subprocess.run(args,env=environment,capture_output=True,text=True,timeout=3)
            assert refused.returncode!=0 and 'isolated' in refused.stderr, (args,refused.returncode,refused.stderr)
            assert not refused.stdout, 'Unisolated fixture reached configuration or transport setup'
    if private_test or private_build:
        # A fresh network namespace starts with loopback down. Enable only
        # this verified private namespace's loopback; never change host links.
        subprocess.run([shutil.which('ip'),'link','set','lo','up'],check=True,capture_output=True)
    with tempfile.TemporaryDirectory(prefix='sls-cluster-isolated-',dir='/etc/sls-mass-notify') as temporary:
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
        pbx=hashlib.sha256(canonical({'schema':1,'files':hashes})).hexdigest(); epoch=hashlib.sha256(b'isolated-epoch').hexdigest()
        mirror_epoch=hashlib.sha256(b'isolated-mirror-epoch').hexdigest()
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
            config={'enabled':True,'mode':'standalone' if role=='witness' else 'notification_ha','role':role,'cluster_id':'isolated',
                    'site_id':'site-a','node_id':identity,'witness_id':'' if role=='witness' else 'witness', 'witness_epoch':epoch,
                    'tls_cert':str(base/(identity+'.pem')),'tls_key':str(base/(identity+'.key')),'tls_ca':str(base/'ca.pem'),
                    'peers':peers,'initial_owner':'node-a','auto_failover':False,'lease_seconds':5,'pbx_contract':pbx,
                    'spend_limit_cents':100,'remote_enabled':role!='witness','site_recipients':[] if role=='witness' else ['device-a'],'offline_cache_enabled':role!='witness',
                    'mirroring_enabled':role=='node','mirror_authority':'node-a','mirror_epoch':mirror_epoch}
            settings={'enterprise_labs':{'enabled':True},'enterprise_cluster':config,'labs_safety':{'schema':1,'receipts':{'enterprise_cluster':{'revision':revision,'accepted_at':int(time.time()),'actor':'isolated test'}}}}
            parent=base/(identity+'-data'); parent.mkdir(mode=0o700); data=parent/'enterprise-cluster'; data.mkdir(mode=0o700)
            fixture={'settings':settings,'directory':str(data),'boot_id':hashlib.sha256((identity+'-boot').encode()).hexdigest(),
                     'pbx_files':evidence,'port':ports[identity]}
            path=base/(identity+'.json'); path.write_text(json.dumps(fixture)); fixtures[identity]=(fixture,path)
            command(['php',str(FIXTURE),str(path),'initialize'])
        def start(identity):
            process=subprocess.Popen(['php',str(FIXTURE),str(fixtures[identity][1])],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,text=True)
            processes.append(process)
            ready,_,_=select.select([process.stdout],[],[],5)
            assert ready and process.poll() is None and process.stdout.readline().strip()=='ready', 'Cluster fixture did not become ready within five seconds'
            return process
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
            resumed=result('node-a','witness','effect.begin',{'boot_id':boot_a,'token':token,'intent':intent})
            assert resumed['ok'] and resumed['result'].get('resumed') is True
            # Simulate a lost reply: the sender cannot prove whether its admitted
            # external action happened. The witness forbids takeover and replay.
            time.sleep(6)
            assert not result('node-b','witness','lease.acquire',{'boot_id':boot_b,'pbx_contract':pbx})['ok']
            assert not result('node-a','witness','lease.acquire',{'boot_id':hashlib.sha256(b'reboot-before-uncertain-review').hexdigest(),'pbx_contract':pbx})['ok']
            assert not result('node-a','witness','effect.begin',{'boot_id':boot_a,'token':token,'intent':dict(intent,delivery_id='stale-token')})['ok']
            finish=result('node-a','witness','effect.finish',{'id':begin['result']['id'],'boot_id':boot_a,'token':token,
                'delivery_hash':hashlib.sha256(intent['delivery_id'].encode()).hexdigest(),'receipt':{'uncertain':False,'category':'confirmed_response'}}); assert finish['ok']
            assert result('coordinator','witness','control.set',{'stopped':True,'spend_limit_cents':100})['ok']
            assert not result('coordinator','witness','effect.reconcile',{'id':begin['result']['id'],'prior_node_fenced':True,
                'evidence':'completed receipt must remain immutable'})['ok']
            repeated=result('node-a','witness','effect.finish',{'id':begin['result']['id'],'boot_id':boot_a,'token':token,
                'delivery_hash':hashlib.sha256(intent['delivery_id'].encode()).hexdigest(),'receipt':{'uncertain':False,'category':'confirmed_response'}})
            assert repeated['ok'] and repeated['result'].get('idempotent') is True
            assert result('coordinator','witness','lease.handoff',{'target':'node-b'})['ok']
            assert result('coordinator','witness','control.set',{'stopped':False,'spend_limit_cents':100})['ok']
            takeover=result('node-b','witness','lease.acquire',{'boot_id':boot_b,'pbx_contract':pbx}); assert takeover['ok']
            proof_b={'boot_id':boot_b,'token':takeover['result']['token']}
            assert json.loads(command(['php',str(FIXTURE),str(fixtures['node-b'][1]),'authority']))['ok']
            assert not json.loads(command(['php',str(FIXTURE),str(fixtures['node-a'][1]),'authority']))['ok']
            assert not result('node-a','witness','lease.acquire',{'boot_id':boot_a,'pbx_contract':pbx})['ok']
            assert not result('node-b','witness','effect.begin',{'boot_id':boot_b,'token':takeover['result']['token'],'intent':intent})['ok']
            print('PASS exclusive lease, stale-token software fencing, reply uncertainty, safe takeover and manual failback')
            # A process may stop after staging a replication row and before the
            # local source commit is acknowledged. A source mismatch abandons
            # that prepared row; a matching durable source promotes and sends it.
            fixture,path=fixtures['node-b']
            outbox_id='inc_'+hashlib.sha256(b'outbox incident').hexdigest()[:32]
            source={'schema':1,'id':outbox_id,'state':'open','updated_at':'2026-10-15T12:00:00Z'}
            replica={'revision':1,'intent':{'id':outbox_id,'created_at':'2026-10-15T12:00:00Z'},'state':'running',
                     'uncertain':False,'schedule_id':'','incident_id':outbox_id,'updated_at':int(time.time()),'receipts':{'snapshot':source}}
            fixture.update(outbox_kind='incidents',outbox_id=outbox_id,outbox_source=source,outbox_record=replica); path.write_text(json.dumps(fixture))
            staged=json.loads(command(['php',str(FIXTURE),str(path),'stage-outbox']))
            assert staged['ok'] and staged['entry']['state']=='prepared',staged
            fixture['outbox_source']=dict(source,state='closed'); path.write_text(json.dumps(fixture))
            abandoned=json.loads(command(['php',str(FIXTURE),str(path),'flush-outbox']))
            assert abandoned['ok'] and not abandoned['outbox'] and next(iter(abandoned['results'].values()))['state']=='abandoned',abandoned
            before=result('node-b','witness','state.pull',[])['result']['replica']['incidents']
            assert outbox_id not in before
            fixture['outbox_source']=source; path.write_text(json.dumps(fixture))
            staged=json.loads(command(['php',str(FIXTURE),str(path),'stage-outbox']))
            assert staged['ok'] and staged['entry']['state']=='prepared',staged
            restaged=json.loads(command(['php',str(FIXTURE),str(path),'stage-outbox']))
            assert restaged['ok'] and restaged['key']==staged['key'] and restaged['entry']==staged['entry'],restaged
            flushed=json.loads(command(['php',str(FIXTURE),str(path),'flush-outbox']))
            assert flushed['ok'] and not flushed['outbox'] and next(iter(flushed['results'].values()))['state']=='replicated',flushed
            replicated=result('node-b','witness','state.pull',[])['result']['replica']['incidents'][outbox_id]
            assert replicated['revision']==1 and replicated['intent']==replica['intent'] and replicated['receipts']==replica['receipts']
            print('PASS incident replication outbox crash recovery, source-commit binding and witness delivery')
            schedule_id='occ_'+hashlib.sha256(b'schedule delta').hexdigest()[:24]
            schedule_source={'schedule_id':'schedule-a','occurrence_id':schedule_id,'run_at_utc':'2026-10-15T13:00:00Z','state':'queued'}
            schedule_replica={'revision':1,'intent':{'schedule_id':'schedule-a','occurrence_id':schedule_id,'run_at_utc':'2026-10-15T13:00:00Z'},
                              'state':'queued','uncertain':False,'schedule_id':'schedule-a','incident_id':'','updated_at':int(time.time()),
                              'receipts':{'snapshot':schedule_source}}
            fixture.update(outbox_kind='schedules',outbox_id=schedule_id,outbox_source=schedule_source,outbox_record=schedule_replica)
            path.write_text(json.dumps(fixture))
            assert json.loads(command(['php',str(FIXTURE),str(path),'stage-outbox']))['ok']
            schedule_flush=json.loads(command(['php',str(FIXTURE),str(path),'flush-outbox']))
            assert schedule_flush['ok'] and not schedule_flush['outbox'] and next(iter(schedule_flush['results'].values()))['state']=='replicated'
            schedule_remote=result('node-b','witness','state.pull',[])['result']['replica']['schedules'][schedule_id]
            assert schedule_remote['intent']==schedule_replica['intent'] and schedule_remote['receipts']==schedule_replica['receipts']
            print('PASS one-occurrence schedule outbox delivery without retained-ledger fanout')
            # Exercise the actual module recovery method and native private job
            # store. Only untouched, unexpired queued intent is reconstructed.
            native_id='job_'+hashlib.sha256(b'untouched native recovery').hexdigest()[:32]
            recovered_request={'delivery_id':'untouched-recovery','delivery_timestamp':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'phones':[],'desktops':[]}
            native_intent={'schema':1,'id':native_id,'created_at':recovered_request['delivery_timestamp'],'request':recovered_request,
                           'request_sha256':hashlib.sha256(canonical(recovered_request)).hexdigest()}
            native_record={'revision':1,'intent':native_intent,
                'state':'queued','uncertain':False,'schedule_id':'','incident_id':'','updated_at':int(time.time()),'receipts':[]}
            assert not result('node-a','witness','state.put',{'kind':'jobs','id':native_id,'record':native_record,
                                                              'proof':{'boot_id':boot_a,'token':token}})['ok']
            assert not result('node-b','witness','state.put',{'kind':'jobs','id':native_id,'record':native_record,'proof':None})['ok']
            assert result('node-b','witness','state.put',{'kind':'jobs','id':native_id,'record':native_record,'proof':proof_b})['ok']
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
            assert result('node-b','witness','state.put',{'kind':'jobs','id':uncertain_id,'record':uncertain_record,'proof':proof_b})['ok']
            assert json.loads(command(['php',str(FIXTURE),str(fixtures['node-b'][1]),'recover']))['result']==[]
            assert not (Path(fixtures['node-b'][0]['jobs'])/(uncertain_id+'.json')).exists()
            print('PASS actual module queued-job recovery, standby isolation, immutable native snapshots and uncertain-job exclusion')
            frozen_request={'message':'frozen','phones':['1001']}
            frozen_intent={'schema':1,'id':'job-intent','created_at':recovered_request['delivery_timestamp'],'request':frozen_request,
                           'request_sha256':hashlib.sha256(canonical(frozen_request)).hexdigest()}
            record={'revision':1,'intent':frozen_intent,'state':'queued','uncertain':False,
                    'schedule_id':'schedule-a','incident_id':'incident-a','updated_at':now,'receipts':[]}
            assert result('node-b','witness','state.put',{'kind':'jobs','id':'job-intent','record':record,'proof':proof_b})['ok']
            changed_request={'message':'tampered'}
            changed=dict(record,intent=dict(frozen_intent,request=changed_request,
                                            request_sha256=hashlib.sha256(canonical(changed_request)).hexdigest()))
            assert not result('node-b','witness','state.put',{'kind':'jobs','id':'job-intent','record':changed,'proof':proof_b})['ok']
            final_record=dict(record,revision=2,state='complete',updated_at=now+1,receipts={'provider':{'id':'final'}})
            assert result('node-b','witness','state.put',{'kind':'jobs','id':'job-intent','record':final_record,'proof':proof_b})['ok']
            regressed=dict(final_record,revision=3,state='queued',updated_at=now+2,receipts=[])
            assert not result('node-b','witness','state.put',{'kind':'jobs','id':'job-intent','record':regressed,'proof':proof_b})['ok']
            changed_receipt=dict(final_record,revision=3,updated_at=now+2,receipts={'provider':{'id':'changed'}})
            assert not result('node-b','witness','state.put',{'kind':'jobs','id':'job-intent','record':changed_receipt,'proof':proof_b})['ok']
            recovery=result('node-b','witness','state.pull',[]); assert recovery['result']['replica']['jobs']['job-intent']==final_record
            shared={'quiet_hours_start':'22:00'}
            mirror={'schema':1,'authority':'node-a','epoch':mirror_epoch,'revision':1,'shared':shared,'content_sha256':hashlib.sha256(canonical(shared)).hexdigest()}
            assert result('node-a','node-b','mirror.put',mirror)['ok']
            poison={'ami':{'password':'changed'}}
            assert not result('node-a','node-b','mirror.put',dict(mirror,revision=2,shared=poison,content_sha256=hashlib.sha256(canonical(poison)).hexdigest()))['ok']
            malformed={'quiet_hours_start':['22:00']}
            assert not result('node-a','node-b','mirror.put',dict(mirror,revision=2,shared=malformed,
                              content_sha256=hashlib.sha256(canonical(malformed)).hexdigest()))['ok']
            assert not result('coordinator','node-b','mirror.put',dict(mirror,authority='coordinator'))['ok']
            assert result('coordinator','witness','control.set',{'stopped':True,'spend_limit_cents':100})['ok']
            compacted=result('node-b','witness','state.pull',[])
            assert 'job-intent' not in compacted['result']['replica']['jobs']
            replica_status=result('node-b','witness','status',[])['result']
            assert replica_status['replica_tombstones']>=1 and replica_status['replica_tombstones_available']<4096
            assert not result('node-b','witness','state.put',{'kind':'jobs','id':'job-intent','record':final_record,'proof':proof_b})['ok']
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
            delivery_history=recovery['result']['effect_history'][hashlib.sha256(intent['delivery_id'].encode()).hexdigest()]
            assert begin['result']['id'] in delivery_history['effects']
            assert recovery['result']['control']['spent_cents']==10
            reboot=hashlib.sha256(b'node-b-new-boot').hexdigest()
            assert not result('node-b','witness','lease.acquire',{'boot_id':reboot,'pbx_contract':pbx})['ok']
            print('PASS unavailable witness blocks actual callback, persistent witness reboot and node reboot isolation')
            job_intent={'message':'local approved content','title':'Isolated remote-site test','desktop_clients':['device-a'],'audio_mode':'none'}
            job={'id':'site-job-a','delivery_id':'site-delivery-a','site_id':'site-a','created_at':int(time.time()),'expires_at':int(time.time())+30,
                 'recipients':['device-a'],'channels':['desktop'],'intent':job_intent,'content_sha256':hashlib.sha256(canonical(job_intent)).hexdigest(),'offline_approved':True}
            assert result('coordinator','node-b','site.enqueue',job)['ok']
            assert result('coordinator','node-b','site.enqueue',job)['result']['state']=='queued'
            mislabeled=dict(job,id='mislabeled-job',channels=['sms'])
            assert not result('coordinator','node-b','site.enqueue',mislabeled)['ok']
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
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)
    print('Isolated cluster TLS fault tests passed; no PBX/service/configuration or external notifications were used.')


if __name__=='__main__':
    run()
