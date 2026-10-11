#!/usr/bin/python3
"""Real AGI menu/PIN, AMI admission and Page dispatch in a disposable Asterisk.

Uses only private Asterisk AMI and loopback test endpoints; never contacts the production PBX. Run with the repository
isolation wrapper so the process has private network, state and control sockets.
"""
import json
import os
import pwd
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import wave

repo = Path(__file__).resolve().parents[1]
isolated = ((os.environ.get('SLS_TEST_NAMESPACE') == 'entered'
             and os.environ.get('SLS_TEST_PARENT_NET_NS') not in (None, os.readlink('/proc/self/ns/net')))
            or (os.environ.get('SLS_BUILD_STAGE') == 'isolated'
                and os.environ.get('SLS_BUILD_PARENT_NET') not in (None, os.readlink('/proc/self/ns/net'))))
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
        'res_timing_timerfd','res_speech','res_agi','app_read','app_senddtmf','app_verbose',
        'res_pjproject','res_sorcery_config','res_sorcery_memory','res_sorcery_astdb',
        'res_rtp_asterisk','res_pjsip','res_pjsip_session','res_pjsip_sdp_rtp','res_pjsip_pubsub','res_pjsip_registrar',
        'res_pjsip_authenticator_digest','res_pjsip_endpoint_identifier_ip','chan_pjsip',
        'app_page','app_confbridge','app_stack','app_exec','bridge_softmix','bridge_simple',
        'bridge_builtin_features','func_pjsip_aor','func_pjsip_contact','func_db',
        'pbx_config','pbx_spool','func_channel','func_callerid','func_dialplan','format_wav','codec_ulaw','codec_alaw']))
    (root/'etc/logger.conf').write_text('[general]\n[logfiles]\nevidence => notice,warning,error,verbose\n')
    library = repo/'slsmassnotifyserver/bin/sls_mass_notify/sls_live_paging.php'
    bootstrap = root/'agi/configure.php'
    bootstrap.write_text('''<?php
require $argv[1];
$settings=['announcement_groups'=>[], 'live_paging'=>[
    'enabled'=>'1','voicemail_enabled'=>'0','extension'=>'799','external_access'=>'1','external_ivr_ids'=>['1'],'groups'=>[
    ['group_id'=>'paging_fixture','name'=>'Fixture staff','menu_number'=>1,'extensions'=>['1001'],
     'allowed_callers'=>['1000'],'allow_external'=>'1','external_callers'=>['+15125550123'],
     'require_pin'=>'0','pin_hash'=>password_hash('0123',PASSWORD_DEFAULT)]]]];
$settings['live_paging']=\\SLS\\MassNotify\\LivePagingConfig::normalize($settings['live_paging']);
file_put_contents($argv[2],json_encode($settings));
echo json_encode(\\SLS\\MassNotify\\LivePagingConfig::promptFiles($settings));
''')
    prompts = json.loads(subprocess.check_output(['php',str(bootstrap),str(library),str(root/'settings.json')]))
    for sound in set(prompts.values()):
        path = root/'data/sounds'/(sound+'.wav'); path.parent.mkdir(parents=True,exist_ok=True)
        with wave.open(str(path),'wb') as handle:
            handle.setnchannels(1); handle.setsampwidth(2); handle.setframerate(8000); handle.writeframes(b'\0\0'*800)
    # Real AGI, DTMF, authenticated AMI admission and origin binding. The
    # recipient is a private loopback endpoint with no physical handset.
    script = '''#!/usr/bin/php
<?php
require LIBRARY;
class FixturePagingAgi extends \\SLS\\MassNotify\\LivePagingAgi {
    public array $evidence=[];
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
    public function notifyGroup(array $group,string $caller,array $settings): array { return ['status'=>'not_requested','requested'=>0]; }
    public function page(string $dial,int $timeout,int $duration): void { $this->evidence[]='page'; parent::page($dial,$timeout,$duration); }
}
$agi=new FixturePagingAgi(STDIN,STDOUT); $env=$agi->environment();
$case=$agi->variable('${SLS_FIXTURE_CASE}');
if (!preg_match('/^[a-z_]+$/D',$case)) exit(64);
try {
    $settings=json_decode(file_get_contents(ROOT.'/settings.json'),true);
    $state=ROOT.'/state/'.$case; mkdir($state,0700);
    $session=new \\SLS\\MassNotify\\LivePagingSession($agi,new \\SLS\\MassNotify\\LivePagingState($state),static fn()=>$settings,
        static fn($file)=>is_file(ROOT.'/data/sounds/'.$file.'.wav'),$env);
    $result=$session->run();
} catch (Throwable $error) { $result=['status'=>'fixture_exception','message'=>$error->getMessage()]; }
file_put_contents(ROOT.'/result-'.$case.'.json',json_encode(['result'=>$result,'evidence'=>$agi->evidence]));
if (!$agi->hasReturnedToIvr()) { try { $agi->hangup(); } catch(Throwable $ignored) {} }
'''
    script = script.replace('require LIBRARY;', 'require '+json.dumps(str(library))+';').replace('ROOT', json.dumps(str(root)))
    agi = root/'agi/paging.php'; agi.write_text(script); agi.chmod(0o755)
    (root/'prompts.json').write_text(json.dumps(prompts))
    settings = json.loads((root/'settings.json').read_text())
    settings.update(phone_device_limit=25, ami={'host':'127.0.0.1','port':5038,'username':'fixture','password':'fixture-isolated-only'})
    data = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin'); data.mkdir(parents=True,exist_ok=True)
    preserved = {}
    for name in ('mass-notifications.config','phone-admission.json','phone-events.lock'):
        path = data/name
        preserved[name] = (path.read_bytes(),path.stat()) if path.exists() else None
    (data/'mass-notifications.config').write_text(json.dumps(settings)); (data/'mass-notifications.config').chmod(0o640)
    account = pwd.getpwnam('asterisk'); os.chown(data/'mass-notifications.config',account.pw_uid,account.pw_gid)
    (root/'etc/manager.conf').write_text('[general]\nenabled=yes\nport=5038\nbindaddr=127.0.0.1\n[fixture]\nsecret=fixture-isolated-only\nread=all\nwrite=all\n')
    pjsip_base = '[transport]\ntype=transport\nprotocol=udp\nbind=127.0.0.1:5068\n[1001]\ntype=endpoint\ncontext=fixture-receiver\ndisallow=all\nallow=ulaw\naors=1001\n[1001]\ntype=aor\n'
    (root/'etc/pjsip.conf').write_text(pjsip_base+'contact=sip:1001@127.0.0.1:5999\n')
    (root/'etc/extensions.conf').write_text('''[general]
static=yes
writeprotect=yes
[fixture-caller]
exten => s,1,Answer()
 same => n,Wait(0.5)
 same => n,SendDTMF(1#,100,100)
 same => n,Wait(0.3)
 same => n,SendDTMF(${SLS_FIXTURE_PIN}#,100,100)
 same => n,Wait(0.3)
 same => n,SendDTMF(${SLS_FIXTURE_PIN}#,100,100)
 same => n,Wait(0.3)
 same => n,SendDTMF(${SLS_FIXTURE_PIN}#,100,100)
 same => n,Wait(5)
 same => n,Hangup()
[sls-live-paging-external]
exten => s,1,Set(CALLERID(num)=${SLS_FIXTURE_NUMBER})
 same => n,Set(IVR_CONTEXT=${SLS_FIXTURE_IVR})
 same => n,Set(CALLERID(num-pres)=${SLS_FIXTURE_PRESENTATION})
 same => n,AGI('''+str(agi)+''')
 same => n,Hangup()
[sls-live-paging-autoanswer]
exten => s,1,Return()
[sls-phone-ended]
exten => s,1,AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,end)
 same => n,Return()
[ivr-1]
exten => s,1,NoOp(SLS_RETURNED_TO_IVR_${SLS_FIXTURE_CASE})
 same => n,Hangup()
''')
    with (root/'console.log').open('wb') as console:
        process = subprocess.Popen([asterisk,'-C',str(config),'-f','-g','-n'],stdout=console,stderr=subprocess.STDOUT)
        collector = None
        try:
            for _ in range(100):
                if (root/'run/asterisk.ctl').exists(): break
                if process.poll() is not None: raise RuntimeError('Private Asterisk startup failed: '+(root/'console.log').read_text()[-2000:])
                time.sleep(.1)
            else: raise RuntimeError('Private Asterisk startup timed out')
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
                     ('offline','5125550123','allowed','0123','no_reachable_recipients','ivr-1'),
                     ('reregistered','5125550123','allowed','0123','page_ended','ivr-1'),
                     ('wrong_pin','+15125550123','allowed','9999','invalid_pin','ivr-1'),
                     ('unselected_ivr','+15125550124','allowed','0123','external_ivr_not_approved','ivr-2')]
            for case, number, presentation, pin, expected, ivr in cases:
                if case in ('offline', 'reregistered'):
                    # Remove and restore the actual private Asterisk contact;
                    # retain the group, PIN and caller settings unchanged.
                    contact = '' if case == 'offline' else 'contact=sip:1001@127.0.0.1:5999\n'
                    (root/'etc/pjsip.conf').write_text(pjsip_base+contact)
                    subprocess.run([asterisk,'-C',str(config),'-rx','module reload res_pjsip.so'],check=True,capture_output=True,timeout=10)
                    for _ in range(40):
                        aor = subprocess.check_output([asterisk,'-C',str(config),'-rx','pjsip show aor 1001'],text=True,timeout=5)
                        if ('sip:1001@127.0.0.1:5999' in aor) is (case == 'reregistered'): break
                        time.sleep(.1)
                    else: raise RuntimeError('Private Asterisk contact state did not change for '+case+': '+aor)
                call = root/'pending.call'
                call.write_text('Channel: Local/s@fixture-caller/n\nMaxRetries: 0\nWaitTime: 10\n'
                    +'Setvar: __SLS_FIXTURE_CASE='+case+'\nSetvar: __SLS_FIXTURE_NUMBER='+number+'\n'
                    +'Setvar: __SLS_FIXTURE_PRESENTATION='+presentation+'\nSetvar: __SLS_FIXTURE_PIN='+pin+'\n'
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
                elif case in ('approved','reregistered'): assert evidence['evidence'] == ['menu','pin_prompt','admission','page'], evidence
                elif case == 'offline':
                    assert evidence['evidence'] == ['menu','pin_prompt','no_receivers'], evidence
                    assert evidence['result']['phone_admission_error'] == 'phone_no_registered_contacts', evidence
                    assert evidence['result']['unavailable_recipients'] == ['1001'] and evidence['result']['dialed_contacts'] == 0, evidence
                elif case == 'wrong_pin': assert evidence['evidence'] == ['menu','pin_prompt','pin_prompt','pin_prompt','denied'], evidence
                else: assert evidence['evidence'] == [], evidence
                returned = expected in ('external_caller_not_approved','invalid_pin')
                assert evidence['result'].get('returned_to_ivr', False) is returned, evidence
                marker = 'SLS_RETURNED_TO_IVR_'+case
                for _ in range(20):
                    if marker in (root/'log/evidence').read_text(): break
                    time.sleep(.1)
                assert (marker in (root/'log/evidence').read_text()) is returned, evidence
            print('PASS: private Asterisk allowlist, PIN, offline-contact failure, registration recovery, real AMI admission, origin binding and Page dispatch. No production phone/trunk delivery.')
        finally:
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
