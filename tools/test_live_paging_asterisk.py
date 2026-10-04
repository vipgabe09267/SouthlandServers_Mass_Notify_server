#!/usr/bin/python3
"""Real AGI menu/PIN checks in a disposable Asterisk; delivery is stubbed out.

Never contacts phones, trunks, AMI, or the production PBX. Run with the repository
isolation wrapper so the process has private network, state and control sockets.
"""
import json
import os
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
        'pbx_config','pbx_spool','func_channel','func_callerid','func_dialplan','format_wav','codec_ulaw','codec_alaw']))
    (root/'etc/logger.conf').write_text('[general]\n[logfiles]\nevidence => notice,warning,error,verbose\n')
    library = repo/'slsmassnotifyserver/bin/sls_mass_notify/sls_live_paging.php'
    bootstrap = root/'agi/configure.php'
    bootstrap.write_text('''<?php
require $argv[1];
$settings=['announcement_groups'=>[], 'live_paging'=>[
    'enabled'=>'1','extension'=>'799','external_access'=>'1','external_ivr_ids'=>['1'],'groups'=>[
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
    # Real AGI pipe and CALLERID/Read/GET DATA; only contact discovery/admission
    # are stubbed. Correct PIN reaches admission, which refuses physical output.
    script = '''#!/usr/bin/php
<?php
require LIBRARY;
class FixturePagingAgi extends \\SLS\\MassNotify\\LivePagingAgi {
    public array $evidence=[];
    public function variable(string $expression): string {
        if ($expression==='${PJSIP_DIAL_CONTACTS(1001)}') return 'PJSIP/1001/sip:1001@192.0.2.1';
        return parent::variable($expression);
    }
    public function menu(array $files): string { $this->evidence[]='menu'; return parent::menu($files); }
    public function digits(string $file,int $max): string { $this->evidence[]='pin_prompt'; return parent::digits($file,$max); }
    public function play(string $file): void {
        $prompts=json_decode(file_get_contents(ROOT.'/prompts.json'),true);
        if ($file===$prompts['denied']) $this->evidence[]='denied';
        parent::play($file);
    }
    public function admitPhones(array $recipients,string $caller,string $groupId=''): array {
        $this->evidence[]='admission'; return [];
    }
    public function notifyGroup(array $group,string $caller,array $settings): array { throw new RuntimeException('Delivery reached forbidden fixture boundary'); }
    public function page(string $dial,int $timeout,int $duration): void { throw new RuntimeException('Physical page reached forbidden fixture boundary'); }
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
[ivr-1]
exten => s,1,NoOp(SLS_RETURNED_TO_IVR_${SLS_FIXTURE_CASE})
 same => n,Hangup()
''')
    with (root/'console.log').open('wb') as console:
        process = subprocess.Popen([asterisk,'-C',str(config),'-f','-g','-n'],stdout=console,stderr=subprocess.STDOUT)
        try:
            for _ in range(100):
                if (root/'run/asterisk.ctl').exists(): break
                if process.poll() is not None: raise RuntimeError('Private Asterisk startup failed: '+(root/'console.log').read_text()[-2000:])
                time.sleep(.1)
            else: raise RuntimeError('Private Asterisk startup timed out')
            cases = [('unlisted','+15125550124','allowed','0123','external_caller_not_approved','ivr-1'),
                     ('withheld','+15125550123','prohib_not_screened','0123','external_caller_not_approved','ivr-1'),
                     ('approved','5125550123','allowed','0123','phone_admission_rejected','ivr-1'),
                     ('wrong_pin','+15125550123','allowed','9999','invalid_pin','ivr-1'),
                     ('unselected_ivr','+15125550124','allowed','0123','external_ivr_not_approved','ivr-2')]
            for case, number, presentation, pin, expected, ivr in cases:
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
                assert evidence['result']['status'] == expected, (case,evidence,(root/'log/evidence').read_text()[-2000:])
                if expected == 'external_caller_not_approved': assert evidence['evidence'] == ['denied'], evidence
                elif case == 'approved': assert evidence['evidence'] == ['menu','pin_prompt','admission'], evidence
                elif case == 'wrong_pin': assert evidence['evidence'] == ['menu','pin_prompt','pin_prompt','pin_prompt','denied'], evidence
                else: assert evidence['evidence'] == [], evidence
                returned = expected in ('external_caller_not_approved','invalid_pin')
                assert evidence['result'].get('returned_to_ivr', False) is returned, evidence
                marker = 'SLS_RETURNED_TO_IVR_'+case
                for _ in range(20):
                    if marker in (root/'log/evidence').read_text(): break
                    time.sleep(.1)
                assert (marker in (root/'log/evidence').read_text()) is returned, evidence
            print('PASS: private Asterisk caller allowlist, withheld rejection, prepared menu playback, DTMF PIN and admission gate. No phone/trunk delivery.')
        finally:
            process.terminate()
            try: process.wait(timeout=5)
            except subprocess.TimeoutExpired: process.kill(); process.wait(timeout=5)
