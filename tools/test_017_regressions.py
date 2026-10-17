#!/usr/bin/python3
"""Isolated regressions for the 0.1.7 audit fixes; no provider or PBX access."""
import importlib.util
import json
import os
from pathlib import Path
import re
import struct
import subprocess
import tempfile
import time
import unittest
import wave

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'
MAIN = (ROOT / 'slsmassnotifyserver/Slsmassnotifyserver.class.php').read_text()


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def method(name):
    start = re.search(r'(?m)^\t(?:private|protected|public) function ' + re.escape(name) + r'\(', MAIN)
    if not start:
        raise AssertionError('Missing module method: ' + name)
    following = re.search(r'(?m)^\t(?:private|protected|public) function ', MAIN[start.end():])
    end = start.end() + following.start() if following else MAIN.rfind('\n}')
    return MAIN[start.start():end]


def php(code):
    result = subprocess.run(['php'], input=code, text=True, capture_output=True, timeout=10)
    if result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return result.stdout


class RegressionTests(unittest.TestCase):
    def test_child_stdout_and_stderr_are_drained_concurrently(self):
        code = '<?php class F {' + method('collectBoundedProcessOutput') + '}'
        code += r'''$f=new F();$m=new ReflectionMethod($f,"collectBoundedProcessOutput");
        $script='fwrite(STDERR,str_repeat("e",70000));fwrite(STDOUT,"{\\"ok\\":true}");';
        $p=proc_open([PHP_BINARY,'-r',$script],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($p))exit(2);echo json_encode($m->invoke($f,$p,$pipes,65536,80000,5));'''
        result = json.loads(php(code))
        self.assertEqual(result['stdout'], '{"ok":true}')
        self.assertEqual(len(result['stderr']), 70000)
        self.assertFalse(result['output_too_large'])
        self.assertFalse(result['read_failed'])
        self.assertFalse(result['timed_out'])
        self.assertEqual(result['exit_code'], 0)

    def test_repeated_auxiliary_ami_headers_and_case_are_supported(self):
        phone = load('phone_017', RUNTIME / 'sls_phone_admission.py')
        ami = phone.PhoneAmi({})
        ami.buffer = b'event: Newchannel\r\nUniqueid: 1.2\r\nCHANVARIABLE: FOO=one\r\nChanVariable: BAR=two\r\n\r\n'
        actual = ami.receive(time.monotonic() + 1)
        self.assertEqual(actual['Event'], 'Newchannel')
        self.assertEqual(actual['ChanVariable'], ['FOO=one', 'BAR=two'])
        for frame in (b'Event: First\r\nevent: Second\r\n\r\n',
                      b'Uniqueid: 1.2\r\nUNIQUEID: 1.3\r\n\r\n',
                      b'Bad Key: value\r\n\r\n'):
            ami.buffer = frame
            with self.assertRaisesRegex(phone.PhoneAdmissionError, 'message_invalid'):
                ami.receive(time.monotonic() + 1)
        ami.buffer = (b'ChanVariable: a=b\r\n' * 129) + b'\r\n'
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'oversized'):
            ami.receive(time.monotonic() + 1)

    def test_shared_proxy_paging_preserves_distinct_sip_users(self):
        phone = load('phone_proxy_017', RUNTIME / 'sls_phone_admission.py')
        class Ami:
            def variable(self, expression, channel=None):
                return {'UNIQUEID':'fixture.1', 'CHANNEL(endpoint)':'1000',
                        'DB(DEVICE/1000/dial)':'PJSIP/1000', 'DB(DEVICE/1000/user)':'1000',
                        'CHANNEL(pjsip,remote_addr)':'192.0.2.10:5060'}[expression]
            def contacts(self, extensions):
                return {'1000':['PJSIP/1000/sip:1000@192.0.2.10:5060']}
        targets = {'1000':['PJSIP/1000/sip:1000@192.0.2.10:5060'],
                   '1001':['PJSIP/1001/sip:1001@192.0.2.10:5060']}
        actual = phone.exclude_live_contact(Ami(), targets, '1000', 'PJSIP/1000-00000001', 'fixture.1')
        self.assertEqual(actual['1000'], [])
        self.assertEqual(actual['1001'], targets['1001'])

    def test_incident_context_is_additive_and_strict(self):
        destinations = load('destinations_017', RUNTIME / 'sls_notification_destinations.py')
        core = {'schema':1, 'incident_id':'inc_'+'a'*32, 'sequence':1, 'kind':'initial',
                'severity':'critical', 'is_test':False}
        extended = {**core, 'operation_request_id':'b'*32, 'language_variant':'es',
                    'language_batch':1, 'language_batches':2, 'expires_at':'2030-01-01T00:00:00+00:00'}
        for value in (core, extended):
            self.assertEqual(destinations.collaboration_incident_context(value), value)
        for change in ({'language_batch':True}, {'language_batches':8},
                       {'expires_at':'2030-02-30T00:00:00+00:00'}, {'language_variant':'../path'},
                       {'extra':True}):
            with self.assertRaises(destinations.DestinationError):
                destinations.collaboration_incident_context({**extended, **change})
        with self.assertRaises(destinations.DestinationError):
            destinations.collaboration_incident_context({**core, 'language_variant':'es'})

    def test_real_weather_words_do_not_set_test_status(self):
        mail = load('mail_017', RUNTIME / 'sls_branded_email.py')
        for text in ('Democratic Road', 'Contest Road', 'Latest instructions', 'Simulation Avenue'):
            result = mail.build_html('Tornado Warning', text, 'Tornado Warning', 'Extreme', is_test=False)
            self.assertIn('EXTREME WEATHER', result)
            self.assertNotIn('SYSTEM TEST', result)
        self.assertIn('SYSTEM TEST', mail.build_html('Tornado Warning', 'Fixture', is_test=True))
        with self.assertRaises(ValueError):
            mail.alert_profile('Title', 'Body', is_test='false')

    def test_legacy_managed_blocks_replace_and_remove(self):
        for markers in ((';-- BEGIN Audit Block --',';-- END Audit Block --'),
                        ('; BEGIN Audit Block','; END Audit Block')):
            with tempfile.TemporaryDirectory() as name:
                path = Path(name) / 'extensions.conf'
                path.write_text('; unrelated\n'+markers[0]+'\nold-content\n'+markers[1]+'\n')
                code = '<?php class F {private function readManagedTextFile($path){return is_file($path)?file_get_contents($path):"";}'
                code += 'private function writeManagedTextFile($path,$contents,$mode=0644,$limit=16777216){return file_put_contents($path,$contents)===strlen($contents);}'
                code += method('writeManagedBlock')+method('removeManagedBlock')+'}'
                code += '$f=new F();$write=new ReflectionMethod($f,"writeManagedBlock");$remove=new ReflectionMethod($f,"removeManagedBlock");'
                code += '$write->invoke($f,'+repr(str(path))+',"Audit Block","new-content");'
                code += '$s=file_get_contents('+repr(str(path))+');if(str_contains($s,"old-content")||substr_count($s,"new-content")!==1)exit(2);'
                code += '$remove->invoke($f,'+repr(str(path))+',"Audit Block");echo file_get_contents('+repr(str(path))+');'
                self.assertEqual(php(code), '; unrelated\n')

        with tempfile.TemporaryDirectory() as name:
            path = Path(name) / 'extensions.conf'
            path.write_text(
                '; unrelated\n; BEGIN Audit Block\nold-one\n; END Audit Block\n'
                ';-- BEGIN Audit Block --\nold-two\n;-- END Audit Block --\n'
                '; BEGIN Audit Block\nold-three\n; END Audit Block\n'
            )
            code = '<?php class F {private function readManagedTextFile($path){return file_get_contents($path);}'
            code += 'private function writeManagedTextFile($path,$contents,$mode=0644,$limit=16777216){return file_put_contents($path,$contents)===strlen($contents);}'
            code += method('writeManagedBlock') + '}'
            code += '$f=new F();$write=new ReflectionMethod($f,"writeManagedBlock");'
            code += '$write->invoke($f,' + repr(str(path)) + ',"Audit Block","new-content");echo file_get_contents(' + repr(str(path)) + ');'
            rendered = php(code)
            self.assertEqual(rendered.count('; BEGIN Audit Block'), 1)
            self.assertEqual(rendered.count('; END Audit Block'), 1)
            self.assertEqual(rendered.count('new-content'), 1)
            self.assertNotIn('old-', rendered)

    def test_tone_cache_retains_volume_and_source_identity(self):
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            (root/'tones').mkdir(); (root/'tts').mkdir()
            tone = 'a'*60
            source = root/'tones'/(tone+'.wav')
            def audio(sample):
                with wave.open(str(source), 'wb') as output:
                    output.setparams((1,2,8000,8000,'NONE','not compressed'))
                    output.writeframes(struct.pack('<h',sample)*8000)
            audio(12000)
            names = ['createQuietAnnouncementTone','normalizeToneName','normalizeTtsVolume',
                     'volumePercentToScalar','announcementAudioFileMetadata',
                     'isValidAnnouncementAudioCache','readNativeBackupFile']
            code = '<?php class F {const TONES_DIR='+repr(str(root/'tones'))+';const TTS_DIR='+repr(str(root/'tts'))+';'
            code += 'private function reserveGeneratedMedia(int $bytes):void{}'+''.join(method(n) for n in names)+'}'
            invoke = '$f=new F();$m=new ReflectionMethod($f,"createQuietAnnouncementTone");'
            values = json.loads(php(code+invoke+'echo json_encode([$m->invoke($f,'+repr(tone)+',25),$m->invoke($f,'+repr(tone)+',100)]);'))
            self.assertNotEqual(*values)
            for filename, peak in zip(values, (3000,12000)):
                with wave.open(str(root/'tts'/(filename+'.wav')), 'rb') as output:
                    samples = struct.unpack('<'+'h'*output.getnframes(), output.readframes(output.getnframes()))
                self.assertAlmostEqual(max(samples), peak, delta=2)
            audio(6000)
            changed = php(code+invoke+'echo $m->invoke($f,'+repr(tone)+',100);')
            self.assertNotIn(changed, values)

    def test_runtime_flag_and_provider_credentials_match_schema(self):
        code = '<?php interface BMO{};require '+repr(str(ROOT/'slsmassnotifyserver/Slsmassnotifyserver.class.php'))+';'
        code += r'''$r=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);$m=$r->newInstanceWithoutConstructor();
        $d=$r->getMethod('getDefaultSettings')->invoke($m);$d['desktop_clients']=[];
        foreach([false,true] as $flag){$d['runtime_enabled']=$flag;$s=$r->getMethod('validateNativeBackupConfig')->invoke($m,json_encode($d));if($s['runtime_enabled']!==$flag)exit(2);}
        foreach([0,'0',null,[]] as $flag){$d['runtime_enabled']=$flag;$e=$r->getMethod('validateConfigValueTypes')->invoke($m,$d);if(!in_array('Runtime enabled must be a boolean.',$e,true))exit(3);}
        $check=$r->getMethod('lightningCredentialsComplete');
        if(!$check->invoke($m,['provider'=>'tempest','client_id'=>'fixture','client_secret'=>'']))exit(4);
        foreach(['xweather','meteomatics'] as $p){if($check->invoke($m,['provider'=>$p,'client_id'=>'fixture','client_secret'=>'']))exit(5);}
        echo 'ok';'''
        self.assertEqual(php(code), 'ok')

    def test_progress_reads_reject_unsafe_files_without_waiting(self):
        with tempfile.TemporaryDirectory() as name:
            root = Path(name); path = root/'state.json'
            code = '<?php class F {'+method('readOperationalState')+method('readNativeBackupFile')+'}'
            invoke = '$f=new F();$m=new ReflectionMethod($f,"readOperationalState");echo json_encode($m->invoke($f,'+repr(str(path))+'));'
            path.write_text('{"state":"complete"}')
            self.assertEqual(json.loads(php(code+invoke))['state'], 'complete')
            path.unlink(); os.mkfifo(path)
            started = time.monotonic()
            self.assertEqual(json.loads(php(code+invoke))['state'], 'failed')
            self.assertLess(time.monotonic()-started, 1)
            path.unlink(); (root/'outside').write_text('{"state":"complete"}'); path.symlink_to(root/'outside')
            self.assertEqual(json.loads(php(code+invoke))['state'], 'failed')
            path.unlink(); os.link(root/'outside', path)
            self.assertEqual(json.loads(php(code+invoke))['state'], 'failed')
            path.unlink(); path.write_bytes(b'x'*65537)
            self.assertEqual(json.loads(php(code+invoke))['state'], 'failed')

    def test_damaged_schedule_state_is_preserved_and_blocks_delivery(self):
        with tempfile.TemporaryDirectory() as name:
            path = Path(name)/'schedule.json'
            raw = '{"version":1,"occurrences":"damaged","worker":{}}'
            path.write_text(raw)
            names = ['loadScheduleExecutionStore','readNativeBackupFile','validateNativeScheduleLedger','parseScheduleUtcTimestamp']
            code = '<?php class F {const SCHEDULE_STATE_JSON='+repr(str(path))+';const NATIVE_BACKUP_MAX_LEDGER_BYTES=16777216;const MAX_SCHEDULES=50;const MAX_SCHEDULE_OCCURRENCES=500;'
            code += ''.join(method(n) for n in names)+'}$f=new F();$m=new ReflectionMethod($f,"loadScheduleExecutionStore");'
            self.assertEqual(php(code+'try{$m->invoke($f,true);exit(2);}catch(RuntimeException $e){echo "blocked";}'), 'blocked')
            self.assertEqual(path.read_text(), raw)
            self.assertEqual(json.loads(php(code+'echo json_encode($m->invoke($f,false));'))['worker']['status'], 'fault')

    def test_worker_startup_is_bounded_and_deduplicated_without_replacing_locks(self):
        with tempfile.TemporaryDirectory() as name:
            code = '<?php require '+repr(str(RUNTIME/'sls_announcement_jobs.php'))+';'
            code += '$store=new SlsAnnouncementJobStore('+repr(name)+');$leases=[];'
            code += r'''
            for($i=0;$i<4;$i++){$leases[]=$store->workerLease(hash('sha256','worker-'.$i));if(!is_resource($leases[$i]))exit(2);}
            $before=[];foreach(glob($store->directory().'/*.lock') as $p){$before[$p]=fileinode($p);}
            if($store->workerLease(hash('sha256','fifth'))!==null||$store->workerLease(hash('sha256','worker-0'))!==null)exit(3);
            SlsAnnouncementJobStore::unlock($leases[0]);$leases[0]=$store->workerLease(hash('sha256','replacement'));if(!is_resource($leases[0]))exit(4);
            foreach($before as $p=>$inode){clearstatcache(true,$p);if(fileinode($p)!==$inode)exit(5);}
            foreach($leases as $lease){SlsAnnouncementJobStore::unlock($lease);}echo 'ok';'''
            self.assertEqual(php(code), 'ok')

    def test_email_readiness_covers_current_enabled_channels(self):
        code = '<?php interface BMO{};require '+repr(str(ROOT/'slsmassnotifyserver/Slsmassnotifyserver.class.php'))+';'
        code += r'''$r=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);$m=$r->newInstanceWithoutConstructor();
        $check=$r->getMethod('hasConfiguredNotificationEmailRecipients');
        if($check->invoke($m,[]))exit(2);
        if(!$check->invoke($m,['system_notification_emails'=>'admin@example.test']))exit(3);
        $mail=['enabled'=>'1','recipients'=>[['id'=>'email_'.str_repeat('a',24),'name'=>'Fixture','address'=>'notify@example.test','enabled'=>'1']]];
        if(!$check->invoke($m,['announcement_email'=>$mail]))exit(4);
        $mail['enabled']='0';if($check->invoke($m,['announcement_email'=>$mail]))exit(5);
        $mail['enabled']='1';$mail['recipients'][0]['enabled']='0';if($check->invoke($m,['announcement_email'=>$mail]))exit(6);
        echo 'ok';'''
        self.assertEqual(php(code), 'ok')

    def test_defaults_do_not_spawn_postfix_discovery(self):
        code = '<?php namespace FreePBX\\modules {function exec(...$args){throw new \\RuntimeException("Unexpected subprocess discovery");}} namespace {interface BMO{};require '+repr(str(ROOT/'slsmassnotifyserver/Slsmassnotifyserver.class.php'))+';'
        code += r'''$r=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);$m=$r->newInstanceWithoutConstructor();
        $defaults=$r->getMethod('getDefaultSettings')->invoke($m);if($defaults['system_notification_emails']!==''||$defaults['announcement_email']['enabled']!=='0')exit(2);echo 'ok';}'''
        self.assertEqual(php(code), 'ok')

    def test_cross_zone_voice_sms_counts_commit_release_and_reset(self):
        source = (ROOT/'slsmassnotifyserver/bin/sls_mass_notify_nws_poll.sh').read_text()
        body = re.search(r'(?ms)^finalize_cross_zone_destinations\(\) \{.*?^\}[ \t]*$', source).group()
        for action in ('commit','release'):
            for voice, sms in ((0,0),(1,0),(0,2),(1,2)):
                code = 'NWS_CLAIMED_PHONE_COUNT=1;NWS_CLAIMED_DESKTOP_COUNT=0;NWS_CLAIMED_EMAIL_COUNT=0;NWS_CLAIMED_DISCORD_COUNT=0;NWS_CLAIMED_GENERIC_COUNT=0;'
                code += f'NWS_CLAIMED_VOICE_COUNT={voice};NWS_CLAIMED_SMS_COUNT={sms};NWS_ALERTS_DRY_RUN=0;FORCE_REPLAY=0;'
                code += 'NWS_ZONE_GROUP_ID_OVERRIDE=fixture;LOG=/dev/null;nws_coordination_enabled(){ return 0; };'
                code += 'nws_coordination_call(){ /usr/bin/python3 -c \'import json,sys;x=json.loads(sys.argv[1]);assert x["expected_count"]==int(sys.argv[2]);print(json.dumps({"ok":True,"changed":x["expected_count"]}))\' "$1" '+str(1+voice+sms)+'; }\n'
                code += body+'\nfinalize_cross_zone_destinations fixture '+action+' phone desktop email discord generic voice sms\n'
                code += 'test "$NWS_CLAIMED_PHONE_COUNT:$NWS_CLAIMED_VOICE_COUNT:$NWS_CLAIMED_SMS_COUNT" = "0:0:0"'
                result = subprocess.run(['bash','-e','-u','-c',code],capture_output=True,text=True,timeout=5)
                self.assertEqual(result.returncode,0,result.stderr)

if __name__ == '__main__':
    unittest.main()
