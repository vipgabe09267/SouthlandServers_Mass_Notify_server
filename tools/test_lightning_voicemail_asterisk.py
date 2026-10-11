#!/usr/bin/python3
"""Exercise automatic Lightning voicemail against private Asterisk and a SIP peer.

The peer simulates unanswered, busy and answered handsets. No production phone,
provider, mailbox or network is contacted.
"""
import json
import math
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import struct
import subprocess
import sys
import tempfile
import threading
import time
import wave

if (os.environ.get('SLS_TEST_NAMESPACE') != 'entered'
        or os.environ.get('SLS_TEST_PARENT_NET_NS') in (None, os.readlink('/proc/self/ns/net'))
        or os.environ.get('SLS_TEST_PARENT_MOUNT_NS') in (None, os.readlink('/proc/self/ns/mnt'))):
    raise SystemExit('Use the verified isolated runner.')
sys.dont_write_bytecode = True
repo = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(repo / 'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_mass_notify_xweather_poll as lightning
import sls_voicemail as vm
from sls_notification_destinations import weather_source_validity

data = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
account = pwd.getpwnam('asterisk')
with tempfile.TemporaryDirectory(prefix='sls-lightning-vm-') as folder:
    root = Path(folder)
    for name in ('etc', 'run', 'log', 'spool/outgoing', 'spool/tmp', 'data/documentation', 'data/sounds', 'agi', 'cache', 'state'):
        (root / name).mkdir(parents=True, exist_ok=True)
    shutil.copyfile('/tmp/sls-asterisk-vendor-docs/core-en_US.xml', root/'data/documentation/core-en_US.xml')
    modules = next(Path(path) for path in ('/usr/lib/asterisk/modules','/usr/lib64/asterisk/modules',
        '/usr/lib/x86_64-linux-gnu/asterisk/modules') if (Path(path)/'chan_pjsip.so').is_file())
    paths = {'astetcdir':root/'etc','astmoddir':modules,'astvarlibdir':root/'data','astdbdir':root/'data',
             'astdatadir':root/'data','astagidir':root/'agi','astspooldir':root/'spool','astrundir':root/'run',
             'astlogdir':root/'log','astcachedir':root/'cache'}
    config = root/'etc/asterisk.conf'
    config.write_text('[directories]\n'+''.join(k+'='+str(v)+'\n' for k,v in paths.items())+'[options]\nverbose=3\n')
    loaded = ('res_timing_timerfd','res_speech','res_sorcery_config','res_sorcery_memory','res_sorcery_astdb',
              'res_pjproject','res_pjsip','res_pjsip_pubsub','res_rtp_asterisk','res_pjsip_session','res_pjsip_sdp_rtp',
              'chan_pjsip','res_pjsip_header_funcs','res_agi','res_clioriginate','res_adsi','res_smdi',
              'app_playback','app_read','app_senddtmf','app_voicemail','app_stack','app_exec','app_verbose',
              'app_page','app_confbridge','pbx_config','pbx_spool','bridge_softmix','bridge_simple',
              'bridge_builtin_features','func_channel','func_callerid','func_db','func_vmcount','func_dialplan',
              'func_strings','func_pjsip_aor','func_pjsip_contact','format_wav','codec_ulaw','codec_alaw')
    (root/'etc/modules.conf').write_text('[modules]\nautoload=no\n'+''.join('load='+n+'.so\n' for n in loaded))
    (root/'etc/logger.conf').write_text('[general]\n[logfiles]\nevidence => notice,warning,error,verbose\n')
    (root/'etc/confbridge.conf').write_text('[general]\n[default_bridge]\ntype=bridge\ninternal_sample_rate=8000\n[default_user]\ntype=user\nquiet=yes\n')
    (root/'etc/manager.conf').write_text('[general]\nenabled=yes\nport=5038\nbindaddr=127.0.0.1\n'
                                      '[fixture]\nsecret=isolated-fixture-only\nread=all\nwrite=all\n')
    (root/'etc/pjsip.conf').write_text('[transport]\ntype=transport\nprotocol=udp\nbind=127.0.0.1:5060\n'
                                    '[1001]\ntype=endpoint\ntransport=transport\naors=1001\ncontext=default\ndisallow=all\nallow=ulaw\n'
                                    '[1001]\ntype=aor\ncontact=sip:fixture@127.0.0.1:5062\n')
    voicemail = '[general]\nformat=wav\nmaxsecs=60\nminsecs=1\nmaxsilence=0\nreview=no\n[default]\n1001=1234,Fixture,,,attach=no|delete=no\n'
    (root/'etc/voicemail.conf').write_text(voicemail)
    Path('/etc/asterisk/voicemail.conf').write_text(voicemail)
    subprocess.run(['mount','--bind',str(root/'state'),str(data)],check=True)
    subprocess.run(['mount','--bind',str(root/'spool'),'/var/spool/asterisk'],check=True)
    settings = {'runtime_enabled':True,'phone_device_limit':25,'generated_media_min_free_mib':64,
                'enterprise_cluster':{'enabled':False},'system_notification_emails':[],
                'ami':{'host':'127.0.0.1','port':5038,'username':'fixture','password':'isolated-fixture-only'},
                'xweather':{'enabled':'1','voicemail_enabled':'1','groups':[
                    {'id':'lightning_fixture','name':'Fixture','enabled':'1','location':'30,-97',
                     'extensions':['1001'],'radius_miles':25,'all_clear':'send'}]}}
    settings_file = data/'mass-notifications.config'
    settings_file.write_text(json.dumps(settings)); settings_file.chmod(0o640)
    os.chown(settings_file,account.pw_uid,account.pw_gid)
    worker = data/'enterprise-worker.lock'; worker.write_bytes(b''); worker.chmod(0o640)
    os.chown(worker,account.pw_uid,account.pw_gid)
    speech = data/'sounds/tts/fixture.wav'; speech.parent.mkdir(parents=True)
    with wave.open(str(speech),'wb') as handle:
        handle.setnchannels(1);handle.setsampwidth(2);handle.setframerate(8000)
        handle.writeframes(b''.join(struct.pack('<h',int(9000*math.sin(i*math.tau*700/8000))) for i in range(32000)))
    speech.chmod(0o640);os.chown(speech,account.pw_uid,account.pw_gid)
    (root/'data/sounds/SLS_Mass_Notifications_Plugin').symlink_to(data/'sounds')
    source = (repo/'slsmassnotifyserver/Slsmassnotifyserver.class.php').read_text()
    start = source.index('$block = "[sls-alert-autoanswer]')
    end = source.index('\n\t\t$this->writeManagedBlock',start)
    native = '[sls-alert-autoanswer]\n'+''.join(json.loads(match.replace(r'\$', '$')) for match in re.findall(r'\.\s*("(?:[^"\\]|\\.)*")',source[start:end]))
    native = native.replace('{$pagingAnswerTimeout}','3')
    (root/'etc/extensions.conf').write_text('[general]\nstatic=yes\n'+native)
    Path('/var/spool/asterisk/tmp').mkdir(parents=True,exist_ok=True)
    lightning.SPOOL_DIR = Path('/var/spool/asterisk/outgoing')
    lightning.ASTERISK_SOUNDS_DIR = root/'data/sounds'
    lightning.configure_group_runtime(lightning.select_group(settings['xweather']))

    stop = threading.Event()
    peer = socket.socket(socket.AF_INET,socket.SOCK_DGRAM);peer.bind(('127.0.0.1',5062));peer.settimeout(.1)
    behavior = {'mode':'timeout'}
    requests = []
    def serve():
        invitations = {}
        while not stop.is_set():
            try: raw,address = peer.recvfrom(65536)
            except socket.timeout:continue
            lines = raw.decode().split('\r\n'); method=lines[0].split()[0]
            headers={line.split(':',1)[0].lower():line.split(':',1)[1].strip() for line in lines[1:] if ':' in line}
            def response(code,fields=headers,sdp=''):
                body='SIP/2.0 '+code+'\r\n'
                for name in ('via','from','to','call-id','cseq'):
                    value=fields[name]+(';tag=fixture' if name=='to' and ';tag=' not in fields[name] else '')
                    body+=name+': '+value+'\r\n'
                if sdp:
                    body+='Contact: <sip:fixture@127.0.0.1:5062>\r\nContent-Type: application/sdp\r\n'
                peer.sendto((body+'Content-Length: '+str(len(sdp))+'\r\n\r\n'+sdp).encode(),address)
            if method=='INVITE':
                requests.append(behavior['mode']);invitations[headers['call-id']]=headers
                if behavior['mode']=='answered':
                    response('200 OK',sdp='v=0\r\no=fixture 1 1 IN IP4 127.0.0.1\r\ns=Fixture\r\nc=IN IP4 127.0.0.1\r\nt=0 0\r\nm=audio 5090 RTP/AVP 0\r\na=rtpmap:0 PCMU/8000\r\na=sendrecv\r\n')
                else:response('180 Ringing' if behavior['mode']=='timeout' else '486 Busy Here')
            elif method=='CANCEL':
                response('200 OK')
                if headers['call-id'] in invitations: response('487 Request Terminated',invitations[headers['call-id']])
            elif method=='BYE':response('200 OK')
    thread=threading.Thread(target=serve,daemon=True);thread.start()
    collector=process=observer=None
    with (root/'console.log').open('wb') as console:
        try:
            process=subprocess.Popen(['asterisk','-C',str(config),'-f','-g','-n'],stdout=console,stderr=console)
            def cli(command):return subprocess.check_output(['asterisk','-C',str(config),'-rx',command],text=True,timeout=8)
            for _ in range(100):
                if (root/'run/asterisk.ctl').exists():break
                if process.poll() is not None:raise RuntimeError('Private Asterisk failed')
                time.sleep(.05)
            cli('core waitfullybooted');cli('database put AMPUSER/1001 voicemail default')
            assert 'Running' in cli('module show like chan_pjsip.so') and 'Not Running' not in cli('module show like chan_pjsip.so')
            collector=subprocess.Popen(['/usr/bin/python3','-I','/usr/local/bin/sls_mass_notify/sls_phone_events.py'],stdout=console,stderr=console)
            for _ in range(100):
                try:
                    state=json.loads((data/'phone-admission.json').read_bytes())
                    if (state.get('collector') or {}).get('heartbeat_tick'):break
                except (OSError,ValueError):pass
                if collector.poll() is not None:raise RuntimeError('Private collector failed')
                time.sleep(.05)
            events=[]
            observer=vm.PhoneAmi(settings,events=True,callback=events.append);observer.__enter__()
            for mode in ('timeout','busy','all_clear','answered'):
                behavior['mode']='busy' if mode=='all_clear' else mode
                now=int(time.time())
                kind='clear' if mode=='all_clear' else 'entry'
                lightning.STATE_FILE.write_text(json.dumps({'active':kind=='entry','cluster_started':now,'last_observed_at':now}))
                context={'event':'Lightning All Clear' if kind=='clear' else 'Lightning Radius Alert',
                         'severity':'All Clear' if kind=='clear' else 'Warning','event_id':'xweather-'+mode,
                         'source_validity':weather_source_validity('xweather','lightning_fixture',observed_at=now,
                         event_kind=kind,cluster_started=now,configuration_identity=lightning.lightning_area_identity(settings,lightning.select_group(settings['xweather'])))}
                previous=set(json.loads((data/'phone-admission.json').read_bytes())['batches'])
                with vm.PhoneAmi(settings) as ami:assert ami.contacts(['1001'])['1001']
                lightning.queue_audio(['1001'],'SLS_Mass_Notifications_Plugin/tts/fixture',voicemail_context=context)
                deadline=time.monotonic()+30
                while time.monotonic()<deadline:
                    state=json.loads((data/'phone-admission.json').read_bytes())
                    parents=[(token,row) for token,row in state['batches'].items() if token not in previous and row.get('purpose','audio')=='audio']
                    deposits=[row for row in state['batches'].values() if row.get('purpose')=='voicemail' and row['voicemail']['source_token'] in {t for t,r in parents}]
                    if deposits and deposits[0]['voicemail'].get('result'):break
                    if mode=='answered' and parents and all(parent['status']=='closed' for token,parent in parents) and time.monotonic()>deadline-18:break
                    time.sleep(.1)
                observer.action({'Action':'Ping'})
                if mode=='answered':
                    assert not deposits,'Answered page must not create voicemail'
                    assert any(row.get('answered_at') is not None or row.get('joined_at') is not None
                        for token,parent in parents for row in parent['channels'].values() if row['kind']=='contact')
                elif not deposits or deposits[0]['voicemail'].get('result',{}).get('status')!='SUCCESS':
                    print((root/'console.log').read_text()[-2000:]);print((root/'log/evidence').read_text()[-3500:])
                    raise RuntimeError('Automatic Lightning fallback was not stored for '+mode)
                else:
                    assert len(deposits)==1,'A source must create exactly one native deposit'
                    assert deposits[0]['voicemail']['playback_completed']
                assert len(parents)==1 and behavior['mode'] in requests
                assert any(row['kind']=='contact' for token,parent in parents for row in parent['channels'].values())
                if mode=='timeout':
                    contacts=[row for token,parent in parents for row in parent['channels'].values() if row['kind']=='contact']
                    assert all(row['dial_status']=='NOANSWER' and row['dial_status_source']=='hangup' for row in contacts)
                assert vm.drain_voicemail(data)==0,'Fallback must not create a second deposit'
                events.clear()
            messages=list((root/'spool/voicemail/default/1001/INBOX').glob('msg*.wav'))
            assert len(messages)==3,'Unanswered, busy and all-clear pages must each store one message'
            for message in messages:
                with wave.open(str(message),'rb') as handle:
                    assert 3.9<=handle.getnframes()/handle.getframerate()<=6.5,'Complete alert audio must be stored'
                    assert max(abs(value[0]) for value in struct.iter_unpack('<h',handle.readframes(handle.getnframes())))>3000
            print('PASS: automatic Lightning unanswered/busy/all-clear native voicemail, complete audio, answered-call exclusion and duplicate suppression; private SIP/Asterisk only.')
        finally:
            if observer is not None:observer.close()
            for child in (collector,process):
                if child is not None:
                    child.terminate()
                    try:child.wait(timeout=5)
                    except subprocess.TimeoutExpired:child.kill();child.wait(timeout=5)
            stop.set();thread.join(1);peer.close()
            subprocess.run(['umount',str(data)],check=True)
            subprocess.run(['umount','/var/spool/asterisk'],check=True)
