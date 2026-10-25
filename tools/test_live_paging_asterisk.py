#!/usr/bin/python3
"""Real AGI menu/PIN, AMI admission and Page dispatch in a disposable Asterisk.

Uses only private Asterisk AMI and loopback test endpoints; never contacts the production PBX. Run with the repository
isolation wrapper so the process has private network, state and control sockets.
"""
import json
import math
import os
import pwd
import re
from pathlib import Path
import shutil
import socket
import threading
import subprocess
import struct
import tempfile
import time
import wave

repo = Path(__file__).resolve().parents[1]
isolated = all(os.environ.get('SLS_TEST_NAMESPACE') == 'entered' and
               os.environ.get('SLS_TEST_PARENT_' + label + '_NS') not in
               (None, '', os.readlink('/proc/self/ns/' + kind))
               for label, kind in [('MOUNT', 'mnt'), ('NET', 'net'), ('PID', 'pid')])
asterisk = shutil.which('asterisk')
if not isolated or not asterisk:
    raise SystemExit('This fixture requires Asterisk and tools/run_isolated_tests.sh; no PBX was started.')

with tempfile.TemporaryDirectory(prefix='sls-paging-agi-') as folder:
    root = Path(folder)
    for name in ['etc','run','log','spool/outgoing','data/documentation','agi','cache','state']:
        (root/name).mkdir(parents=True, exist_ok=True)
    shutil.copyfile('/tmp/sls-asterisk-vendor-docs/core-en_US.xml', root/'data/documentation/core-en_US.xml')
    modules = next(Path(p) for p in ['/usr/lib/asterisk/modules','/usr/lib64/asterisk/modules','/usr/lib/x86_64-linux-gnu/asterisk/modules']
                   if (Path(p)/'app_read.so').is_file())
    config = root/'etc/asterisk.conf'
    paths = {'astetcdir':root/'etc','astmoddir':modules,'astvarlibdir':root/'data','astdbdir':root/'data',
             'astdatadir':root/'data','astagidir':root/'agi','astspooldir':root/'spool',
             'astrundir':root/'run','astlogdir':root/'log','astcachedir':root/'cache'}
    config.write_text('[directories]\n'+''.join(k+' = '+str(v)+'\n' for k,v in paths.items())+'[options]\nverbose = 3\n')
    (root/'etc/modules.conf').write_text('[modules]\nautoload=no\n'+''.join('load='+name+'.so\n' for name in [
        'app_playtones','app_waitforsilence','res_timing_timerfd','res_speech','res_agi','res_mutestream','app_playback','app_mixmonitor','app_read','app_senddtmf','app_verbose',
        'res_pjproject','res_sorcery_config','res_sorcery_memory','res_sorcery_astdb',
        'res_rtp_asterisk','res_pjsip','res_pjsip_session','res_pjsip_sdp_rtp','res_pjsip_pubsub','res_pjsip_registrar',
        'res_pjsip_authenticator_digest','res_pjsip_endpoint_identifier_user','res_pjsip_endpoint_identifier_ip','res_pjsip_notify','chan_pjsip',
        'app_page','app_confbridge','app_stack','app_exec','bridge_softmix','bridge_simple',
        'bridge_builtin_features','func_pjsip_aor','func_pjsip_contact','func_db',
        'pbx_config','pbx_spool','func_channel','func_callerid','func_dialplan','func_timeout','func_module','format_wav','codec_ulaw','codec_alaw']))
    (root/'etc/logger.conf').write_text('[general]\n[logfiles]\nevidence => notice,warning,error,verbose\n')
    (root/'etc/confbridge.conf').write_text('[general]\n[default_bridge]\ntype=bridge\ninternal_sample_rate=8000\n[default_user]\ntype=user\nquiet=yes\n')
    (root/'etc/pjsip_notify.conf').write_text('[fixture-unused]\nEvent=sls-private-fixture\n')
    library = repo/'slsmassnotifyserver/bin/sls_mass_notify/sls_live_paging.php'
    bootstrap = root/'agi/configure.php'
    bootstrap.write_text('''<?php
require $argv[1];
$settings=['announcement_groups'=>[], 'live_paging'=>[
    'enabled'=>'1','voicemail_enabled'=>'0','extension'=>'799','external_access'=>'1','external_ivr_ids'=>['1'],'groups'=>[
    ['group_id'=>'paging_fixture','name'=>'Fixture staff','menu_number'=>1,'extensions'=>['1001'],
     'allowed_callers'=>['1000'],'allow_external'=>'1','external_callers'=>['+15125550123'],
     'require_pin'=>'0','notify_extensions'=>['1001'],'text_message'=>'Live paging in progress.','pin_hash'=>password_hash('0123',PASSWORD_DEFAULT)]]]];
$settings['live_paging']=\\SLS\\MassNotify\\LivePagingConfig::normalize($settings['live_paging']);
$settings['live_paging']['groups'][]=array_replace($settings['live_paging']['groups'][0],[
    'group_id'=>'paging_ten','name'=>'Fixture group ten','menu_number'=>10]);
file_put_contents($argv[2],json_encode($settings));
echo json_encode(\\SLS\\MassNotify\\LivePagingConfig::promptFiles($settings));
''')
    prompts = json.loads(subprocess.check_output(['php',str(bootstrap),str(library),str(root/'settings.json')]))
    for sound in set(prompts.values()):
        path = root/'data/sounds'/(sound+'.wav'); path.parent.mkdir(parents=True,exist_ok=True)
        with wave.open(str(path),'wb') as handle:
            handle.setnchannels(1); handle.setsampwidth(2); handle.setframerate(8000); handle.writeframes(b'\0\0'*800)
    for name, frequency, seconds in [('beep',880,.3),('fixture-speech',1000,5)]:
        with wave.open(str(root/'data/sounds'/(name+'.wav')),'wb') as handle:
            handle.setnchannels(1);handle.setsampwidth(2);handle.setframerate(8000)
            handle.writeframes(b''.join(struct.pack('<h',round(12000*math.sin(2*math.pi*(3000 if name=='fixture-speech' and n<1200 else frequency)*n/8000))) for n in range(round(seconds*8000))))
    # Real AGI, DTMF, authenticated AMI admission and origin binding. The
    # recipient is a private loopback endpoint with no physical handset.
    script = '''#!/usr/bin/php
<?php
require LIBRARY;
$dispatcher=file_get_contents(DISPATCHER);
$dispatcher=str_replace("['file', '/dev/null', 'w']", "['file', ".var_export(ROOT.'/visual-debug.log',true).", 'a']",$dispatcher);
$start=strpos($dispatcher,'class SlsLivePagingNotifyAgi ');$end=strpos($dispatcher,'$agi = new SlsLivePagingNotifyAgi',$start);
eval(substr($dispatcher,$start,$end-$start));
class FixturePagingAgi extends SlsLivePagingNotifyAgi {
    public array $evidence=[];
    public array $savedTimers=[];
    public ?float $pageReturnedAt=null;
    private int $cueProofCalls=0;
    protected function checkCallerCue(string $request,string $mode='--cue-stdin'): array {
        $this->cueProofCalls++;
        if ($this->cueProofCalls===2 && $this->variable('${SLS_FIXTURE_CASE}')==='immediate_speech') usleep(1000000);
        return parent::checkCallerCue($request,$mode);
    }
    protected function command(string $command,int $timeout=5): string {
        $result=parent::command($command,$timeout);
        if ($command==='EXEC PlayTones "880"' || $command==='SET VARIABLE MUTEAUDIO(in) "off"') {
            file_put_contents(ROOT.'/cue-events.jsonl',json_encode(['command'=>$command,'at'=>microtime(true)])."\\n",FILE_APPEND);
        }
        return $result;
    }
    public function variable(string $expression): string {
        return parent::variable($expression);
    }
    public function menu(array $files): string { $this->evidence[]='menu'; return parent::menu($files); }
    public function digits(string $file,int $max): string { $this->evidence[]='pin_prompt'; return parent::digits($file,$max); }
    public function play(string $file): void {
        $prompts=json_decode(file_get_contents(ROOT.'/prompts.json'),true);
        if ($file===$prompts['denied']) $this->evidence[]='denied';
        if ($file===$prompts['no_receivers']) $this->evidence[]='no_receivers';
        parent::play($file);
    }
    public function admitPhones(array $recipients,string $caller,string $groupId=''): array {
        $this->evidence[]='admission'; return parent::admitPhones($recipients,$caller,$groupId);
    }
    public function notifyGroup(array $group,string $caller,array $settings): array { return parent::notifyGroup($group,$caller,$settings); }
    public function page(string $dial,int $timeout,int $duration): void {
        $this->evidence[]='page';
        $this->command('SET VARIABLE CONFBRIDGE(menu,D) "leave_conference"');
        $this->savedTimers=['digit'=>$this->variable('${TIMEOUT(digit)}'),'response'=>$this->variable('${TIMEOUT(response)}')];
        parent::page($dial,$timeout,$duration);
        $this->pageReturnedAt=microtime(true);
    }
}
$agi=new FixturePagingAgi(STDIN,STDOUT); $env=$agi->environment();
$case=$agi->variable('${SLS_FIXTURE_CASE}');
if (!preg_match('/^[a-z_]+$/D',$case)) exit(64);
if (($argv[1]??'')==='--ready') {
    $cue=$agi->callerReadyCue($env);
    file_put_contents(ROOT.'/cue-results.jsonl',json_encode(['result'=>$cue,'at'=>microtime(true)])."\\n",FILE_APPEND);
    if ($cue!=='ignored') file_put_contents(ROOT.'/cue-result.json',json_encode(['result'=>$cue,'at'=>microtime(true)]));
    exit(0);
}
try {
    $settings=json_decode(file_get_contents(ROOT.'/settings.json'),true);
    $state=ROOT.'/state/'.$case; mkdir($state,0700);
    if ($case==='legacy_empty') {
        foreach (['live-paging-auth.json','live-paging-auth.json.lock'] as $name) {
            file_put_contents($state.'/'.$name,''); chmod($state.'/'.$name,0640);
        }
    }
    $session=new \\SLS\\MassNotify\\LivePagingSession($agi,new \\SLS\\MassNotify\\LivePagingState($state),static fn()=>$settings,
        static fn($file)=>is_file(ROOT.'/data/sounds/'.$file.'.wav'),$env);
    $result=$session->run();
} catch (Throwable $error) { $result=['status'=>'fixture_exception','message'=>$error->getMessage()]; }
file_put_contents(ROOT.'/result-'.$case.'.json',json_encode(['result'=>$result,'evidence'=>$agi->evidence,
    'source_uniqueid'=>$env['agi_uniqueid']??'',
    'page_returned_at'=>$agi->pageReturnedAt,
    'digit_timeout'=>$agi->savedTimers['digit']??$agi->variable('${TIMEOUT(digit)}'),
    'response_timeout'=>$agi->savedTimers['response']??$agi->variable('${TIMEOUT(response)}')]));
if (!$agi->hasReturnedToIvr() && !in_array($case,['visual_connected','no_text_connected','immediate_speech'],true)) { try { $agi->hangup(); } catch(Throwable $ignored) {} }
'''
    script = script.replace('require LIBRARY;', 'require '+json.dumps(str(library))+';').replace('DISPATCHER', json.dumps(str(repo/'slsmassnotifyserver/bin/sls_mass_notify_live_paging.php'))).replace('ROOT', json.dumps(str(root)))
    agi = root/'agi/paging.php'; agi.write_text(script); agi.chmod(0o755)
    speech_marker=root/'agi/speech-start.php'
    speech_marker.write_text('#!/usr/bin/php\n<?php\nwhile (($line=fgets(STDIN))!==false && trim($line)!==\'\') {}\nfile_put_contents('+json.dumps(str(root/'speech-start.json'))+',json_encode(microtime(true)));\n')
    speech_marker.chmod(0o755)
    (root/'prompts.json').write_text(json.dumps(prompts))
    settings = json.loads((root/'settings.json').read_text())
    settings.update(phone_device_limit=25, sipnotify={'format_overrides':{'1001':'yealink'}}, ami={'host':'127.0.0.1','port':5038,'username':'fixture','password':'fixture-isolated-only'})
    data = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin'); data.mkdir(parents=True,exist_ok=True)
    preserved = {}
    for name in ('mass-notifications.config','phone-admission.json','phone-events.lock'):
        path = data/name
        preserved[name] = (path.read_bytes(),path.stat()) if path.exists() else None
    (data/'mass-notifications.config').write_text(json.dumps(settings)); (data/'mass-notifications.config').chmod(0o640)
    account = pwd.getpwnam('asterisk'); os.chown(data/'mass-notifications.config',account.pw_uid,account.pw_gid)
    worker_lock=data/'enterprise-worker.lock';worker_lock.touch(mode=0o640);worker_lock.chmod(0o640)
    os.chown(worker_lock,account.pw_uid,account.pw_gid)
    (root/'etc/manager.conf').write_text('[general]\nenabled=yes\nport=5038\nbindaddr=127.0.0.1\n[fixture]\nsecret=fixture-isolated-only\nread=all\nwrite=all\n')
    pjsip_base = '[global]\ntype=global\ndefault_outbound_endpoint=1001\n[transport]\ntype=transport\nprotocol=udp\nbind=127.0.0.1:5068\n[1001]\ntype=endpoint\ncontext=fixture-receiver\ndisallow=all\nallow=ulaw\naors=1001\nidentify_by=username\n[1001]\ntype=aor\nmax_contacts=1\nremove_existing=yes\nminimum_expiration=1\ndefault_expiration=300\nmaximum_expiration=600\n'
    # A real REGISTER gives both Page and PJSIPShowContacts the same handset
    # identity. Static AOR contacts are not a substitute for this AMI inventory.
    (root/'etc/pjsip.conf').write_text(pjsip_base)
    (root/'etc/extensions.conf').write_text('''[general]
static=yes
writeprotect=yes
[fixture-caller]
exten => s,1,Answer()
 same => n,ExecIf($["${SLS_FIXTURE_CASE}"="visual_connected" | "${SLS_FIXTURE_CASE}"="no_text_connected" | "${SLS_FIXTURE_CASE}"="immediate_speech"]?MixMonitor('''+str(root/'caller.wav')+''',r('''+str(root/'caller-read.wav')+''')))
 same => n,Wait(0.5)
 same => n,SendDTMF(${SLS_FIXTURE_SELECTION},100,100)
 same => n,Wait(${SLS_FIXTURE_MENU_WAIT})
 same => n,SendDTMF(${SLS_FIXTURE_PIN}#,100,100)
 same => n,ExecIf($["${SLS_FIXTURE_CASE}"="visual_connected" | "${SLS_FIXTURE_CASE}"="no_text_connected" | "${SLS_FIXTURE_CASE}"="immediate_speech"]?SendDTMF(A,100,100))
 same => n,Wait(0.3)
 same => n,ExecIf($["${SLS_FIXTURE_CASE}"="visual_connected" | "${SLS_FIXTURE_CASE}"="no_text_connected" | "${SLS_FIXTURE_CASE}"="immediate_speech"]?Wait(0.1):SendDTMF(${SLS_FIXTURE_PIN}#,100,100))
 same => n,Wait(0.3)
 same => n,ExecIf($["${SLS_FIXTURE_CASE}"="visual_connected" | "${SLS_FIXTURE_CASE}"="no_text_connected" | "${SLS_FIXTURE_CASE}"="immediate_speech"]?Wait(0.1):SendDTMF(${SLS_FIXTURE_PIN}#,100,100))
 same => n,ExecIf($["${SLS_FIXTURE_CASE}"="visual_connected" | "${SLS_FIXTURE_CASE}"="no_text_connected" | "${SLS_FIXTURE_CASE}"="immediate_speech"]?SendDTMF(A,100,100))
 same => n,ExecIf($["${SLS_FIXTURE_CASE}"="immediate_speech"]?WaitForNoise(100,1,8))
 same => n,ExecIf($["${SLS_FIXTURE_CASE}"="immediate_speech"]?WaitForSilence(40,1,5))
 same => n,ExecIf($["${SLS_FIXTURE_CASE}"="visual_connected" | "${SLS_FIXTURE_CASE}"="no_text_connected" | "${SLS_FIXTURE_CASE}"="immediate_speech"]?AGI('''+str(speech_marker)+'''))
 same => n,ExecIf($["${SLS_FIXTURE_CASE}"="visual_connected" | "${SLS_FIXTURE_CASE}"="no_text_connected" | "${SLS_FIXTURE_CASE}"="immediate_speech"]?Playback(fixture-speech):Wait(8))
 same => n,SendDTMF(D,100,100)
 same => n,Wait(1)
 same => n,Hangup()
[sls-live-paging-external]
exten => s,1,Set(CALLERID(num)=${SLS_FIXTURE_NUMBER})
 same => n,Set(IVR_CONTEXT=${SLS_FIXTURE_IVR})
 same => n,Set(CALLERID(num-pres)=${SLS_FIXTURE_PRESENTATION})
 same => n,Set(TIMEOUT(digit)=7)
 same => n,Set(TIMEOUT(response)=15)
 same => n,Set(AGISIGHUP=no)
 same => n,Gosub(sls-live-paging-external,s,20)
 same => n,NoOp(SLS_CALLER_STACK_RETURNED_${SLS_FIXTURE_CASE})
 same => n,Hangup()
exten => s,20,AGI('''+str(agi)+''')
 same => n,Return()
[sls-live-paging-autoanswer]
exten => s,1,AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,contact)
 same => n,Return()
[sls-live-paging-ready]
exten => s,1,AGI('''+str(agi)+''',--ready)
 same => n,StopPlayTones()
[sls-phone-ended]
exten => s,1,AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,end)
 same => n,Return()
[ivr-1]
exten => s,1,NoOp(SLS_RETURNED_TO_IVR_${SLS_FIXTURE_CASE})
 same => n,Hangup()
''')
    runtime_worker = Path('/usr/local/bin/sls_mass_notify/sls_live_paging_visual.py')
    worker_source = repo/'slsmassnotifyserver/bin/sls_mass_notify/sls_live_paging_visual.py'
    runtime_worker.write_text("""#!/usr/bin/python3
import importlib.util,sys
sys.dont_write_bytecode=True
sys.path.insert(0,SOURCE_DIR)
spec=importlib.util.spec_from_file_location('fixture_visual_monitor',SOURCE)
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
real_run=module.notify.subprocess.run
def private_cli(argv,*args,**kwargs):
    if isinstance(argv,list) and argv and argv[0].endswith('/asterisk') and '-rx' in argv and '-C' not in argv:
        argv=[argv[0],'-C',CONFIG]+argv[1:]
    return real_run(argv,*args,**kwargs)
module.notify.subprocess.run=private_cli
original_info=module.notify.get_registered_endpoint_info
def diagnostic_info(*args,**kwargs):
    result=original_info(*args,**kwargs)
    print('SYNTHETIC CONTACT INFO '+repr(result),file=sys.stderr)
    return result
module.notify.get_registered_endpoint_info=diagnostic_info
original_action=module.notify.AmiClient.action
def diagnostic_action(self,fields,*args,**kwargs):
    result=original_action(self,fields,*args,**kwargs)
    if fields.get('Action')=='PJSIPShowContacts':print('SYNTHETIC CONTACT EVENTS '+repr(result),file=sys.stderr)
    if fields.get('Action')=='PJSIPNotify':print('SYNTHETIC NOTIFY RESPONSE '+repr(result),file=sys.stderr)
    return result
module.notify.AmiClient.action=diagnostic_action
original_send=module.VisualMonitor.send
def diagnostic_send(self,*args,**kwargs):
    try:return original_send(self,*args,**kwargs)
    except Exception:
        import traceback;traceback.print_exc();raise
module.VisualMonitor.send=diagnostic_send
raise SystemExit(module.main())
""".replace('SOURCE_DIR',repr(str(worker_source.parent))).replace('SOURCE',repr(str(worker_source))).replace('CONFIG',repr(str(config))))
    runtime_worker.chmod(0o755)
    peer_stop=threading.Event();behavior={'answer':False};sip_evidence=[];answer_timers=[];rtp_evidence=[]
    registration_condition=threading.Condition();registration_responses={};registration_sequence=0
    peer=socket.socket(socket.AF_INET,socket.SOCK_DGRAM);peer.bind(('127.0.0.1',5999));peer.settimeout(.1)
    rtp=socket.socket(socket.AF_INET,socket.SOCK_DGRAM);rtp.bind(('127.0.0.1',5090));rtp.settimeout(.1)
    def serve_rtp():
        while not peer_stop.is_set():
            try:packet,_=rtp.recvfrom(65536)
            except socket.timeout:continue
            if len(packet)>=12 and packet[1]&127==0:
                def magnitude(octet):
                    value=(~octet)&255
                    return ((((value&15)<<3)+132)<<((value>>4)&7))-132
                samples=[-magnitude(byte) if ((~byte)&128) else magnitude(byte) for byte in packet[12:]]
                def energy(frequency):
                    coefficient=2*math.cos(2*math.pi*frequency/8000);previous=before=0
                    for sample in samples:
                        current=sample+coefficient*previous-before;before,previous=previous,current
                    return previous*previous+before*before-coefficient*previous*before
                rtp_evidence.append({'at':time.time(),'peak':max(map(abs,samples),default=0),'leading_marker':energy(3000)>4*energy(1000)})
    rtp_thread=threading.Thread(target=serve_rtp,daemon=True);rtp_thread.start()
    def serve_peer():
        invitations={}
        while not peer_stop.is_set():
            try:raw,address=peer.recvfrom(65536)
            except socket.timeout:continue
            text=raw.decode();lines=text.split('\r\n');method=lines[0].split()[0]
            headers={line.split(':',1)[0].lower():line.split(':',1)[1].strip() for line in lines[1:] if ':' in line}
            if method=='SIP/2.0':
                if headers.get('cseq','').endswith(' REGISTER'):
                    with registration_condition:
                        registration_responses[int(headers['cseq'].split()[0])]=lines[0]
                        registration_condition.notify_all()
                continue
            sip_evidence.append({'method':method,'at':time.time(),'body':text.split('\r\n\r\n',1)[-1]})
            def reply(code,fields=headers,sdp=''):
                body='SIP/2.0 '+code+'\r\n'
                for name in ('via','from','to','call-id','cseq'):
                    value=fields[name]+(';tag=fixture' if name=='to' and ';tag=' not in fields[name] else '')
                    body+=name+': '+value+'\r\n'
                if sdp:body+='Contact: <sip:1001@127.0.0.1:5999>\r\nContent-Type: application/sdp\r\n'
                peer.sendto((body+'Content-Length: '+str(len(sdp))+'\r\n\r\n'+sdp).encode(),address)
            if method=='INVITE':
                duplicate=headers['call-id'] in invitations
                invitations[headers['call-id']]=headers
                if behavior['answer']:
                    reply('180 Ringing')
                    if not duplicate:
                        timer=threading.Timer(2.5,lambda answer=reply:answer('200 OK',sdp='v=0\r\no=fixture 1 1 IN IP4 127.0.0.1\r\ns=Fixture\r\nc=IN IP4 127.0.0.1\r\nt=0 0\r\nm=audio 5090 RTP/AVP 0\r\na=rtpmap:0 PCMU/8000\r\na=sendrecv\r\n'))
                        answer_timers.append(timer);timer.start()
                else:reply('180 Ringing')
            elif method=='CANCEL':
                reply('200 OK')
                if headers['call-id'] in invitations:reply('487 Request Terminated',invitations[headers['call-id']])
            elif method in ('BYE','NOTIFY','OPTIONS'):reply('200 OK')
    peer_thread=threading.Thread(target=serve_peer,daemon=True);peer_thread.start()
    def register_contact(expires):
        global registration_sequence
        registration_sequence+=1
        sequence=registration_sequence
        message=('REGISTER sip:127.0.0.1:5068 SIP/2.0\r\n'
            'Via: SIP/2.0/UDP 127.0.0.1:5999;branch=z9hG4bKfixture'+str(sequence)+'\r\n'
            'From: <sip:1001@127.0.0.1>;tag=fixture-registration\r\n'
            'To: <sip:1001@127.0.0.1>\r\nCall-ID: private-fixture-registration@localhost\r\n'
            'CSeq: '+str(sequence)+' REGISTER\r\n'
            'Contact: <sip:1001@127.0.0.1:5999>;expires='+str(expires)+'\r\n'
            'Expires: '+str(expires)+'\r\nMax-Forwards: 70\r\n'
            'User-Agent: Yealink T48G private fixture\r\nContent-Length: 0\r\n\r\n')
        request=message.encode()
        deadline=time.monotonic()+4
        next_transmit=time.monotonic()
        interval=.5
        # An endpoint can appear in config inventory before the UDP transport
        # starts receiving. Retransmit this same non-INVITE transaction using
        # SIP T1/T2 intervals; do not extend its four-second response deadline.
        with registration_condition:
            while sequence not in registration_responses:
                now=time.monotonic()
                if now>=deadline:
                    raise RuntimeError('Private SIP REGISTER timed out: '+(root/'console.log').read_text()[-3000:])
                if now>=next_transmit:
                    peer.sendto(request,('127.0.0.1',5068))
                    next_transmit=now+interval
                    interval=min(interval*2,2)
                registration_condition.wait_for(lambda:sequence in registration_responses,
                    timeout=max(0,min(next_transmit,deadline)-time.monotonic()))
            response=registration_responses.pop(sequence)
        if not response.startswith('SIP/2.0 200 '):
            raise RuntimeError('Private SIP REGISTER failed: '+response+'; '+(root/'console.log').read_text()[-3000:])
        for _ in range(40):
            aor=subprocess.check_output([asterisk,'-C',str(config),'-rx','pjsip show aor 1001'],text=True,timeout=5)
            if ('sip:1001@127.0.0.1:5999' in aor)==bool(expires):return
            time.sleep(.1)
        raise RuntimeError('Private registration state did not match its acknowledged expiry: '+aor)
    with (root/'console.log').open('wb') as console:
        process = subprocess.Popen([asterisk,'-C',str(config),'-f','-g','-n'],stdout=console,stderr=subprocess.STDOUT)
        collector = None
        try:
            for _ in range(100):
                if (root/'run/asterisk.ctl').exists(): break
                if process.poll() is not None: raise RuntimeError('Private Asterisk startup failed: '+(root/'console.log').read_text()[-2000:])
                time.sleep(.1)
            else: raise RuntimeError('Private Asterisk startup timed out')
            for _ in range(60):
                endpoint=subprocess.check_output([asterisk,'-C',str(config),'-rx','pjsip show endpoint 1001'],text=True,timeout=5)
                if re.search(r'(?m)^\s*Endpoint:\s+1001(?:/|\s)',endpoint):break
                time.sleep(.1)
            else:raise RuntimeError('Private PJSIP endpoint did not initialize: '+endpoint)
            register_contact(300)
            collector = subprocess.Popen(['/usr/bin/python3','-I','/usr/local/bin/sls_mass_notify/sls_phone_events.py'],stdout=console,stderr=console)
            for _ in range(100):
                try:
                    state = json.loads((data/'phone-admission.json').read_bytes())
                    if (state.get('collector') or {}).get('heartbeat_tick'): break
                except (OSError,ValueError): pass
                if collector.poll() is not None: raise RuntimeError('Private collector failed: '+(root/'console.log').read_text()[-2000:])
                time.sleep(.1)
            else: raise RuntimeError('Private collector did not establish AMI readiness')
            cases = [('unlisted','+15125550124','allowed','0123','external_caller_not_approved','ivr-1'),
                     ('withheld','+15125550123','prohib_not_screened','0123','external_caller_not_approved','ivr-1'),
                     ('approved','5125550123','allowed','0123','page_ended','ivr-1'),
                     ('single_digit','5125550123','allowed','0123','page_ended','ivr-1'),
                     ('legacy_empty','5125550123','allowed','0123','page_ended','ivr-1'),
                     ('group_ten','5125550123','allowed','0123','page_ended','ivr-1'),
                     ('visual_connected','5125550123','allowed','0123','page_ended','ivr-1'),
                     ('immediate_speech','5125550123','allowed','0123','page_ended','ivr-1'),
                     ('no_text_connected','5125550123','allowed','0123','page_ended','ivr-1'),
                     ('ready_unanswered','5125550123','allowed','0123','page_ended','ivr-1'),
                     ('offline','5125550123','allowed','0123','no_reachable_recipients','ivr-1'),
                     ('reregistered','5125550123','allowed','0123','page_ended','ivr-1'),
                     ('wrong_pin','+15125550123','allowed','9999','invalid_pin','ivr-1'),
                     ('unselected_ivr','+15125550124','allowed','0123','external_ivr_not_approved','ivr-2')]
            if os.environ.get('SLS_PAGING_VISUAL_ONLY')=='1':
                cases=[case for case in cases if case[0] in ('visual_connected','immediate_speech')]
            for case, number, presentation, pin, expected, ivr in cases:
                behavior['answer'] = case in ('visual_connected','no_text_connected','immediate_speech')
                sip_start=len(sip_evidence);rtp_start=len(rtp_evidence)
                for name in ('cue-result.json','cue-results.jsonl','cue-events.jsonl','caller.wav','caller-read.wav','speech-start.json'):(root/name).unlink(missing_ok=True)
                current=json.loads(json.dumps(settings))
                if case=='no_text_connected':
                    for group in current['live_paging']['groups']:group['notify_extensions']=[]
                (root/'settings.json').write_text(json.dumps(current))
                (data/'mass-notifications.config').write_text(json.dumps(current))
                if case in ('offline', 'reregistered'):
                    # Remove and restore the actual private Asterisk contact;
                    # retain the group, PIN and caller settings unchanged.
                    register_contact(0 if case=='offline' else 300)
                call = root/'pending.call'
                call.write_text('Channel: Local/s@fixture-caller/n\nMaxRetries: 0\nWaitTime: 10\n'
                    +'Setvar: __SLS_FIXTURE_CASE='+case+'\nSetvar: __SLS_FIXTURE_NUMBER='+number+'\n'
                    +'Setvar: __SLS_FIXTURE_PRESENTATION='+presentation+'\nSetvar: __SLS_FIXTURE_PIN='+pin+'\n'
                    +'Setvar: __SLS_FIXTURE_SELECTION='+('1' if case in ('single_digit','legacy_empty') else '10' if case=='group_ten' else '1#')+'\n'
                    +'Setvar: __SLS_FIXTURE_MENU_WAIT='+('3' if case in ('single_digit','legacy_empty','group_ten') else '0.3')+'\n'
                    +'Setvar: __SLS_FIXTURE_IVR='+ivr+'\n'
                    +'Context: sls-live-paging-external\nExtension: s\nPriority: 1\n')
                call.rename(root/'spool/outgoing/fixture.call')
                result = root/('result-'+case+'.json')
                for _ in range(160):
                    if result.exists(): break
                    time.sleep(.1)
                else: raise RuntimeError('No private paging result: '+(root/'log/evidence').read_text()[-3000:])
                evidence = json.loads(result.read_text())
                assert evidence['result']['status'] == expected, (case,evidence,(root/'log/evidence').read_text()[-2000:], (root/'console.log').read_text()[:4500])
                if expected == 'external_caller_not_approved': assert evidence['evidence'] == ['denied'], evidence
                elif case in ('approved','single_digit','legacy_empty','group_ten','reregistered','visual_connected','no_text_connected','immediate_speech','ready_unanswered'): assert evidence['evidence'] == ['menu','pin_prompt','admission','page'], evidence
                elif case == 'offline':
                    assert evidence['evidence'] == ['menu','pin_prompt','no_receivers'], evidence
                    assert evidence['result']['phone_admission_error'] == 'phone_no_registered_contacts', evidence
                    assert evidence['result']['unavailable_recipients'] == ['1001'] and evidence['result']['dialed_contacts'] == 0, evidence
                elif case == 'wrong_pin': assert evidence['evidence'] == ['menu','pin_prompt','pin_prompt','pin_prompt','denied'], evidence
                else: assert evidence['evidence'] == [], evidence
                assert float(evidence['digit_timeout']) == 7 and float(evidence['response_timeout']) == 15, evidence
                returned = expected in ('external_caller_not_approved','invalid_pin')
                assert evidence['result'].get('returned_to_ivr', False) is returned, evidence
                case_sip=sip_evidence[sip_start:]
                if case in ('visual_connected','no_text_connected','immediate_speech'):
                    assert evidence['result']['text_notification']['status']=='monitoring', evidence
                    assert evidence['result']['text_completion'].get('attempted_contacts')==(0 if case=='no_text_connected' else 1), {
                        'result':evidence,'sip':sip_evidence,'ledger':json.loads((data/'phone-admission.json').read_text()),
                        'asterisk_log':(root/'log/evidence').read_text()[-5000:]}
                    assert evidence['result']['text_completion']['status']==('not_requested' if case=='no_text_connected' else 'submitted'), {
                        'result':evidence,'debug':(root/'visual-debug.log').read_text(),'sip':sip_evidence}
                    seen=[item for item in case_sip if item['method']=='NOTIFY']
                    assert len(seen)==(0 if case=='no_text_connected' else 2), case_sip
                    joined=json.loads((data/'phone-admission.json').read_text())['batches']
                    selected=[batch for batch in joined.values() if evidence['source_uniqueid'] in batch['channels']]
                    contacts=[row for batch in selected for row in batch['channels'].values()
                              if row['kind']=='contact' and row.get('joined_at') is not None]
                    cue=json.loads((root/'cue-result.json').read_text())
                    assert cue['result']=='ready', (cue,(root/'visual-debug.log').read_text(),(root/'log/evidence').read_text()[-4000:])
                    cue_events=[json.loads(line) for line in (root/'cue-events.jsonl').read_text().splitlines()]
                    played=[item['at'] for item in cue_events if item['command']=='EXEC PlayTones "880"']
                    opened=[item['at'] for item in cue_events if item['command']=='SET VARIABLE MUTEAUDIO(in) "off"']
                    assert len(played)==len(opened)==1 and opened[0]>=played[0]+.3 and played[0]>=max(row['joined_at'] for row in contacts), (cue_events,contacts)
                    active=[item for item in rtp_evidence[rtp_start:] if item['peak']>1000]
                    assert active and min(item['at'] for item in active)>=opened[0], (cue_events,rtp_evidence[rtp_start:])
                    speech_started=json.loads((root/'speech-start.json').read_text())
                    if case=='immediate_speech':
                        marker_packets=sum(item['leading_marker'] for item in rtp_evidence[rtp_start:])
                        print('Immediate post-tone speech: '+json.dumps({'leading_150ms_marker_packets':marker_packets,'protected_check_delay_ms':1000,'speech_to_first_rtp_ms':round(1000*(min(item['at'] for item in active)-speech_started),3)}),flush=True)
                        assert marker_packets>=6 and speech_started>=opened[0] and opened[0]-played[0]>=1.2, (marker_packets,speech_started,cue_events)
                    else:
                        assert speech_started<min(row['joined_at'] for row in contacts), (speech_started,contacts)
                    for _ in range(40):
                        try:
                            with wave.open(str(root/'caller-read.wav'),'rb') as recorded:audio=recorded.readframes(recorded.getnframes())
                            if audio:break
                        except (OSError,wave.Error,EOFError):pass
                        time.sleep(.05)
                    else:raise AssertionError('Caller capture was not finalized')
                    assert sum(abs(value[0])>500 for value in struct.iter_unpack('<h',audio))/8000>(1 if case=='immediate_speech' else .25), 'Caller cue did not persist until input was ready'
                    assert 'SLS_CALLER_STACK_RETURNED_'+case in (root/'log/evidence').read_text(), 'Caller IVR/Gosub stack was not preserved'
                    origins=[row for batch in selected for uid,row in batch['channels'].items()
                             if uid==evidence['source_uniqueid'] and row['kind']=='origin']
                    ended=[row['ended_at'] for row in origins if row.get('ended_at') is not None]
                    completed=[value for value in [evidence['page_returned_at'],*ended] if value is not None]
                    if case in ('visual_connected','immediate_speech'):
                        assert 'Live paging in progress.' in seen[0]['body'] and 'Paging ended.' in seen[1]['body'], seen
                        assert "Timeout='1'" in seen[1]['body'] and "Beep='no'" in seen[1]['body'], seen[1]
                        assert contacts and seen[0]['at'] >= max(row['joined_at'] for row in contacts), (contacts,seen)
                        assert any(item['method']=='ACK' and item['at']<=seen[0]['at'] for item in case_sip), case_sip
                        assert origins and completed and seen[1]['at']>=min(completed), (evidence,origins,seen)
                elif case=='ready_unanswered':
                    cue=json.loads((root/'cue-result.json').read_text())
                    assert cue['result']=='unavailable', cue
                    assert not (root/'cue-events.jsonl').exists(), 'Unanswered recipients produced a ready cue or open microphone'
                    assert not any(item['method']=='NOTIFY' for item in case_sip),case_sip
                marker = 'SLS_RETURNED_TO_IVR_'+case
                for _ in range(20):
                    if marker in (root/'log/evidence').read_text(): break
                    time.sleep(.1)
                assert (marker in (root/'log/evidence').read_text()) is returned, evidence
            print('PASS: private Asterisk group1/group10/pound, restored timers, allowlist/PIN, real REGISTER/unregister/reregister, admission and Page; caller-only audible ready tone follows SIP ACK and settled collector joins, input stays muted before tone, early/duplicate cue DTMF is ignored, original IVR/Gosub stack survives, audio-only pages retain readiness and unanswered pages never cue. Progress text follows connection; quiet one-second completion follows actual page end. No production phone/trunk delivery.')
        finally:
            for timer in answer_timers:timer.cancel();timer.join(timeout=2)
            peer_stop.set();peer_thread.join(timeout=2);peer.close()
            rtp_thread.join(timeout=2);rtp.close()
            if collector is not None:
                collector.terminate()
                try: collector.wait(timeout=5)
                except subprocess.TimeoutExpired: collector.kill(); collector.wait(timeout=5)
            process.terminate()
            try: process.wait(timeout=5)
            except subprocess.TimeoutExpired: process.kill(); process.wait(timeout=5)
            for name, previous in preserved.items():
                path = data/name
                if previous is None: path.unlink(missing_ok=True)
                else:
                    body, info = previous; path.write_bytes(body)
                    path.chmod(info.st_mode & 0o777); os.chown(path,info.st_uid,info.st_gid)
