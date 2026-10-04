#!/usr/bin/python3
"""Exercise real Local call-file/AGI playback in a disposable offline Asterisk.

The carrier-answer events are synthetic. This is not a carrier delivery test.
Never starts a PBX outside the repository's private network/mount namespace.
"""
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time
import wave

sys.dont_write_bytecode = True
asterisk = shutil.which('asterisk')
isolated = ((os.environ.get('SLS_TEST_NAMESPACE') == 'entered' and os.environ.get('SLS_TEST_PARENT_NET_NS') != os.readlink('/proc/self/ns/net'))
            or (os.environ.get('SLS_BUILD_STAGE') == 'isolated' and os.environ.get('SLS_BUILD_PARENT_NET') != os.readlink('/proc/self/ns/net')))
if not asterisk or not isolated:
    print('SKIP: real Asterisk playback fixture requires Asterisk and the isolated test/build wrapper.')
    raise SystemExit(0)
repo = Path(__file__).resolve().parents[1]
library = repo / 'slsmassnotifyserver/bin/sls_mass_notify'
sys.path.insert(0, str(library))
from sls_phone_admission import PhoneAdmissionStore

with tempfile.TemporaryDirectory(prefix='sls-asterisk-playback-') as directory:
    root = Path(directory)
    for name in ['etc', 'run', 'log', 'spool/outgoing', 'data', 'agi', 'cache', 'state']:
        (root / name).mkdir(parents=True, exist_ok=True)
    documentation = Path('/tmp/sls-asterisk-vendor-docs/core-en_US.xml')
    if not documentation.is_file(): raise RuntimeError('Isolated Asterisk vendor documentation was not preserved by the wrapper')
    (root/'data/documentation').mkdir()
    shutil.copyfile(documentation,root/'data/documentation/core-en_US.xml')
    modules = next((Path(path) for path in ['/usr/lib/asterisk/modules','/usr/lib64/asterisk/modules','/usr/lib/x86_64-linux-gnu/asterisk/modules']
                    if (Path(path)/'pbx_spool.so').is_file()), None)
    if modules is None: raise RuntimeError('Asterisk fixture could not locate its installed modules')
    config = root / 'etc/asterisk.conf'
    config.write_text('[directories]\nastetcdir = '+str(root/'etc')+'\nastmoddir = '+str(modules)+'\nastvarlibdir = '+str(root/'data')+
        '\nastdbdir = '+str(root/'data')+'\nastdatadir = '+str(root/'data')+'\nastagidir = '+str(root/'agi')+'\nastspooldir = '+str(root/'spool')+
        '\nastrundir = '+str(root/'run')+'\nastlogdir = '+str(root/'log')+'\nastcachedir = '+str(root/'cache')+'\n[options]\nverbose = 3\n')
    (root/'etc/modules.conf').write_text('[modules]\nautoload=no\n'+''.join('load='+name+'.so\n' for name in
        ['res_timing_timerfd','res_speech','res_agi','app_playback','app_read','app_senddtmf','app_exec','app_verbose','pbx_config','pbx_spool','func_channel','format_wav','codec_ulaw','codec_alaw']))
    (root/'etc/logger.conf').write_text('[general]\n[logfiles]\nevidence => notice,warning,error,verbose\n')
    for name in ['sls_phone_admission.py','sls_nws_delivery_claims.py','sls_config_crypto.py']:
        content = (library/name).read_text()
        if name == 'sls_phone_admission.py':
            setting = "DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')"
            assert content.count(setting) == 1
            content = content.replace(setting, 'DATA = Path('+repr(str(root/'state'))+')')
        (root/'agi'/name).write_text(content)
    gate = root/'agi/sls_mass_notify_phone_agi.py'
    shutil.copyfile(repo/'slsmassnotifyserver/bin/sls_mass_notify_phone_agi.py',gate); gate.chmod(0o755)
    shutil.copyfile(library/'external-acknowledgement.wav',root/'agi/external-acknowledgement.wav')
    origin = root/'agi/fixture_origin.py'
    origin.write_text('''#!/usr/bin/python3
import sys
from sls_mass_notify_phone_agi import PhoneAgi
from sls_phone_admission import PhoneAdmissionStore, accountcode
a=PhoneAgi(); s=PhoneAdmissionStore()
t=a.variable('${SLS_PHONE_TOKEN}'); r=a.variable('${SLS_PHONE_RECIPIENT}')
uid=a.environment['agi_uniqueid']; channel=a.environment['agi_channel']; dest=uid+'.trunk'
s.bind_origin(t,r,uid,channel)
s.record_event({'Event':'DialBegin','Uniqueid':uid,'Channel':channel,'AccountCode':accountcode(t),'DestUniqueid':dest,'DestChannel':'PJSIP/fixturetrunk-00000001'})
if sys.argv[1]=='1':
    s.record_event({'Event':'DialEnd','DestUniqueid':dest,'DestAccountCode':accountcode(t),'DestChannel':'PJSIP/fixturetrunk-00000001','DialStatus':'ANSWER'})
'''); origin.chmod(0o755)
    source = (repo/'slsmassnotifyserver/Slsmassnotifyserver.class.php').read_text()
    start = source.index('. "[sls-outbound-playback]')
    end = source.index('. "[sls-phone-ended]',start)
    block = ''.join(json.loads(match.replace(r'\$', '$')) for match in re.findall(r'\.\s*("(?:[^"\\]|\\.)*")',source[start:end]))
    assert 'GotoIf($["${SLS_PHONE_ALLOW}"!="1"]?done)' in block
    block = block.replace('/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py',str(gate))
    block = block.replace('same => n(done),Hangup()', 'same => n(done),Log(NOTICE,SLS_GATE_RESULT ${SLS_PHONE_ALLOW}:${PLAYBACKSTATUS})\n same => n,Hangup()')
    (root/'etc/extensions.conf').write_text('[general]\nstatic=yes\nwriteprotect=yes\n[sls-outbound-voice]\nexten => _voice_.,1,AGI('+str(origin)+',${SLS_FIXTURE_ANSWER})\n same => n,Answer()\n same => n,Wait(1)\n same => n,ExecIf($["${SLS_FIXTURE_DIGIT}"!=""]?SendDTMF(${SLS_FIXTURE_DIGIT}))\n same => n,Wait(12)\n same => n,Hangup()\n'+block)
    sound = root/'data/sounds/SLS_Mass_Notifications_Plugin/tts/fixture.wav'; sound.parent.mkdir(parents=True)
    with wave.open(str(sound),'wb') as handle:
        handle.setnchannels(1);handle.setsampwidth(2);handle.setframerate(8000);handle.writeframes(b'\0\0'*1600)
    store = PhoneAdmissionStore(root/'state'); generation='f'*32
    evidence = root/'log/evidence'
    with (root/'console.log').open('wb') as console:
        process = subprocess.Popen([asterisk,'-C',str(config),'-f','-g','-n'],stdout=console,stderr=subprocess.STDOUT)
        try:
            for _ in range(100):
                if (root/'run/asterisk.ctl').exists(): break
                if process.poll() is not None: raise RuntimeError('Isolated Asterisk startup failed: '+(root/'console.log').read_text()[-3000:])
                time.sleep(.1)
            else: raise RuntimeError('Isolated Asterisk startup timed out')
            for answered, digit, acknowledgement, keypad in [(True,'a',False,''),(False,'b',False,''),(True,'c',True,'1'),(True,'d',True,'2'),(True,'e',True,'')]:
                store.collector_heartbeat(generation)
                recipient = 'voice_'+digit*24
                target={'id':recipient,'number':'+15551234567'+('' if answered else '8'),'route_mode':'trunk','trunk_id':'1','caller_id':'',
                        'acknowledgement_required':acknowledgement,'ack_timeout_seconds':5}
                token=store.admit({recipient:['VOICE/'+target['number']]},25,
                    {'complete':True,'started_tick':time.monotonic(),'generation':generation,'boot_id':store.boot_id,'channels':[]},service='announcement',
                    outbound={recipient:{'target':target,'proof':{'fingerprint':'a'*64,'trunk_endpoints':['fixturetrunk']}}})
                previous=evidence.read_text() if evidence.exists() else ''
                call=root/'pending.call'; call.write_text('Channel: Local/'+recipient+'@sls-outbound-voice/n\nMaxRetries: 0\nWaitTime: 10\n'
                    +'Setvar: __SLS_PHONE_TOKEN='+token+'\nSetvar: __SLS_PHONE_RECIPIENT='+recipient+'\nSetvar: SLS_SOUND=SLS_Mass_Notifications_Plugin/tts/fixture\n'
                    +'Setvar: SLS_FIXTURE_ANSWER='+str(int(answered))+'\nSetvar: SLS_FIXTURE_DIGIT='+keypad+'\nContext: sls-outbound-playback\nExtension: s\nPriority: 1\n')
                call.rename(root/'spool/outgoing/fixture.call')
                for _ in range(140):
                    recent=evidence.read_text()[len(previous):] if evidence.exists() else ''
                    if 'SLS_GATE_RESULT' in recent: break
                    time.sleep(.1)
                else: raise RuntimeError('No playback result from isolated Asterisk: '+recent[-4000:])
                expected='SLS_GATE_RESULT '+('1:SUCCESS' if answered else '0:')
                assert expected in recent, recent[-4000:]
                if not answered: assert 'Playing ' not in recent, 'Local answer started audio without trunk evidence'
                state=json.loads((root/'state/phone-admission.json').read_text())
                result=state['batches'][token]['targets'][recipient].get('playback',{})
                if answered:
                    expected_ack=('acknowledged' if keypad=='1' else 'other_key' if keypad else 'timeout') if acknowledgement else 'not_requested'
                    assert result.get('completed_at') is not None and result.get('ack_status')==expected_ack, (result,recent[-4000:])
                else: assert not result
            print('PASS: real Local call-file/AGI answer gating, completed playback, DTMF1 acknowledgement, wrong digit and timeout. Carrier answer events were synthetic; no external call was made.')
        finally:
            process.terminate()
            try: process.wait(timeout=5)
            except subprocess.TimeoutExpired: process.kill(); process.wait(timeout=5)
