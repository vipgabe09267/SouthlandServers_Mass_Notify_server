#!/usr/bin/python3
"""Native voicemail, caller-only recording and MWI in a disposable Asterisk."""
import json
import math
import os
from pathlib import Path
import pwd
import re
import shutil
import struct
import subprocess
import tempfile
import time
import wave

repo = Path(__file__).resolve().parents[1]
isolated = os.environ.get('SLS_TEST_NAMESPACE') == 'entered' and all(
    os.environ.get('SLS_TEST_PARENT_' + label + '_NS') not in (None, '', os.readlink('/proc/self/ns/' + kind))
    for label, kind in [('MOUNT', 'mnt'), ('NET', 'net'), ('PID', 'pid')]
)
if not isolated or not shutil.which('asterisk'):
    raise SystemExit('Use the private fixture runner for native voicemail. No production PBX was contacted.')
data = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
library = repo / 'slsmassnotifyserver/bin/sls_mass_notify/sls_live_paging.php'

with tempfile.TemporaryDirectory(prefix='sls-native-voicemail-') as folder:
    root = Path(folder)
    for name in ('etc', 'run', 'log', 'spool', 'data/documentation', 'agi', 'cache', 'state', 'results'):
        (root / name).mkdir(parents=True, exist_ok=True)
    shutil.copyfile('/tmp/sls-asterisk-vendor-docs/core-en_US.xml', root / 'data/documentation/core-en_US.xml')
    modules = next((Path(path) for path in ('/usr/lib/asterisk/modules', '/usr/lib64/asterisk/modules',
                    '/usr/lib/x86_64-linux-gnu/asterisk/modules') if (Path(path) / 'app_voicemail.so').is_file()), None)
    if modules is None:
        raise SystemExit('Native voicemail fixture requires app_voicemail.so. Install the asterisk22-voicemail package '
                         'matching the pinned Asterisk core version in the disposable test runner. No production PBX was contacted.')
    paths = {'astetcdir': root / 'etc', 'astmoddir': modules, 'astvarlibdir': root / 'data', 'astdbdir': root / 'data',
             'astdatadir': root / 'data', 'astagidir': root / 'agi', 'astspooldir': root / 'spool',
             'astrundir': root / 'run', 'astlogdir': root / 'log', 'astcachedir': root / 'cache'}
    config = root / 'etc/asterisk.conf'
    config.write_text('[directories]\n' + ''.join(key + '=' + str(value) + '\n' for key, value in paths.items())
                      + '[options]\nverbose=3\n')
    (root / 'etc/modules.conf').write_text('[modules]\nautoload=no\n' + ''.join('load=' + name + '.so\n' for name in (
        'res_timing_timerfd', 'res_speech', 'res_agi', 'res_clioriginate', 'res_adsi', 'res_smdi', 'app_playback', 'app_read', 'app_senddtmf', 'app_voicemail',
        'app_mixmonitor', 'app_stack', 'app_exec', 'app_verbose', 'pbx_config', 'func_channel', 'func_callerid',
        'func_db', 'func_vmcount', 'format_wav', 'codec_ulaw', 'codec_alaw', 'func_dialplan', 'func_strings')))
    (root / 'etc/logger.conf').write_text('[general]\n[logfiles]\nevidence => notice,warning,error,verbose\n')
    voicemail = '[general]\nformat=wav\nmaxsecs=20\nminsecs=1\nmaxsilence=0\nreview=no\n[default]\n1001=1234,Fixture,,,attach=no|delete=no\n'
    (root / 'etc/voicemail.conf').write_text(voicemail)
    # The helper's supported static policy and the private PBX use identical
    # files; both paths are hidden from the host by the isolation wrapper.
    static_policy = Path('/etc/asterisk/voicemail.conf')
    old_policy = static_policy.read_bytes() if static_policy.exists() else None
    static_policy.write_text(voicemail); static_policy.chmod(0o644)
    (root / 'etc/manager.conf').write_text('[general]\nenabled=yes\nport=5038\nbindaddr=127.0.0.1\n'
        '[fixture]\nsecret=isolated-fixture-only\nread=all\nwrite=all\n')
    # Bind only disposable state to the canonical private runtime path. This
    # gives quota tests adequate space without weakening the configured reserve.
    subprocess.run(['mount', '--bind', str(root / 'state'), str(data)], check=True)
    account = pwd.getpwnam('asterisk')
    (root / 'state').chmod(0o750)
    worker_lock = data / 'enterprise-worker.lock'
    worker_lock.write_bytes(b''); worker_lock.chmod(0o640)
    os.chown(worker_lock, account.pw_uid, account.pw_gid)
    helper = root / 'agi/settings.php'
    helper.write_text('''<?php
require $argv[1];
$settings=['phone_device_limit'=>25,'generated_media_min_free_mib'=>64,
 'ami'=>['host'=>'127.0.0.1','port'=>5038,'username'=>'fixture','password'=>'isolated-fixture-only'],
 'announcement_groups'=>[], 'live_paging'=>['enabled'=>'1','voicemail_enabled'=>'1','extension'=>'799',
 'max_duration_seconds'=>30,'external_access'=>'1','external_ivr_ids'=>['1'],'groups'=>[
 ['group_id'=>'paging_fixture','name'=>'Fixture','menu_number'=>1,'extensions'=>['1001'],
 'allowed_callers'=>['1000'],'allow_external'=>'1','external_callers'=>['+15125550123'],
 'require_pin'=>'1','pin_hash'=>password_hash('0123',PASSWORD_DEFAULT)]]]];
$settings['live_paging']=\\SLS\\MassNotify\\LivePagingConfig::normalize($settings['live_paging']);
file_put_contents($argv[2],json_encode($settings));echo json_encode(\\SLS\\MassNotify\\LivePagingConfig::promptFiles($settings));
''')
    prompts = json.loads(subprocess.check_output(['php', str(helper), str(library), str(data / 'mass-notifications.config')]))
    os.chown(data / 'mass-notifications.config', account.pw_uid, account.pw_gid); (data / 'mass-notifications.config').chmod(0o640)
    for sound in set(prompts.values()):
        path = root / 'data/sounds' / (sound + '.wav'); path.parent.mkdir(parents=True, exist_ok=True)
        with wave.open(str(path), 'wb') as handle:
            handle.setnchannels(1); handle.setsampwidth(2); handle.setframerate(8000); handle.writeframes(b'\0\0' * 400)
    speech = root / 'data/sounds/speech.wav'
    with wave.open(str(speech), 'wb') as handle:
        handle.setnchannels(1); handle.setsampwidth(2); handle.setframerate(8000)
        handle.writeframes(b''.join(struct.pack('<h', int(9000 * math.sin(index * math.tau * 700 / 8000))) for index in range(32000)))
    (root / 'data/sounds/SLS_Mass_Notifications_Plugin/voicemail').symlink_to(data / 'sounds/voicemail')
    agi = root / 'agi/live.php'
    agi.write_text('''#!/usr/bin/php
<?php
require LIBRARY;
class NativeFixtureAgi extends \\SLS\\MassNotify\\LivePagingAgi {
 public function notifyGroup(array $group,string $caller,array $settings):array{return ['status'=>'not_requested'];}
 protected function rejectAdmission($result):array {file_put_contents(REJECT,json_encode($result));return parent::rejectAdmission($result);}
}
$agi=new NativeFixtureAgi(STDIN,STDOUT);$env=$agi->environment();
try {
 $session=new \\SLS\\MassNotify\\LivePagingSession($agi,new \\SLS\\MassNotify\\LivePagingState(DATA),
   static fn()=>\\SLS\\MassNotify\\LivePagingSession::loadSettings(DATA.'/mass-notifications.config'),static fn()=>true,$env);
 $result=$session->run();
} catch(Throwable $e){$result=['status'=>'exception','error'=>$e->getMessage()];}
file_put_contents(RESULT,json_encode($result));
'''.replace('LIBRARY', json.dumps(str(library))).replace('DATA', json.dumps(str(data)))
       .replace('RESULT', json.dumps(str(root / 'results/live.json')))
       .replace('REJECT', json.dumps(str(root / 'results/rejected.json'))))
    agi.chmod(0o755)
    source = (repo / 'slsmassnotifyserver/Slsmassnotifyserver.class.php').read_text()
    start = source.index('. "[sls-alert-voicemail]')
    end = source.index("\n\t\t$this->writeManagedBlock", start)
    native = ''.join(json.loads(match.replace(r'\$', '$')) for match in re.findall(r'\.\s*("(?:[^"\\]|\\.)*")', source[start:end]))
    (root / 'etc/extensions.conf').write_text('''[general]
static=yes
[fixture-caller]
exten => s,1,Answer()
 same => n,Wait(0.4)
 same => n,SendDTMF(1#,100,100)
 same => n,Wait(0.3)
 same => n,SendDTMF(0123#,100,100)
 same => n,Wait(0.8)
 same => n,Playback(speech)
 same => n,Hangup()
[sls-live-paging-external]
exten => s,1,Set(AGISIGHUP=no)
 same => n,Set(CALLERID(num)=+15125550123)
 same => n,Set(IVR_CONTEXT=ivr-1)
 same => n,AGI(''' + str(agi) + ''')
 same => n,Hangup()
''' + native)
    process = collector = observer = None
    with (root / 'console.log').open('wb') as console:
        try:
            process = subprocess.Popen(['asterisk', '-C', str(config), '-f', '-g', '-n'], stdout=console, stderr=console)
            def cli(command):
                return subprocess.check_output(['asterisk', '-C', str(config), '-rx', command], text=True, timeout=8)
            for _ in range(100):
                if (root / 'run/asterisk.ctl').exists(): break
                if process.poll() is not None: raise RuntimeError('Private Asterisk startup failed')
                time.sleep(.05)
            cli('core waitfullybooted')
            cli('database put AMPUSER/1001 voicemail default')
            collector = subprocess.Popen(['/usr/bin/python3', '-I', '/usr/local/bin/sls_mass_notify/sls_phone_events.py'], stdout=console, stderr=console)
            for _ in range(100):
                try:
                    state = json.loads((data / 'phone-admission.json').read_bytes())
                    if (state.get('collector') or {}).get('heartbeat_tick'): break
                except (OSError, ValueError): pass
                if collector.poll() is not None: raise RuntimeError('Private collector failed')
                time.sleep(.05)
            from importlib.util import spec_from_file_location, module_from_spec
            spec = spec_from_file_location('private_vm_module', repo / 'slsmassnotifyserver/bin/sls_mass_notify/sls_voicemail.py')
            vm = module_from_spec(spec); spec.loader.exec_module(vm)
            waiting_events = []
            observer = vm.PhoneAmi(vm.read_settings(), events=True, callback=waiting_events.append)
            observer.__enter__()
            cli('channel originate Local/s@fixture-caller/n extension s@sls-live-paging-external')
            inbox = root / 'spool/voicemail/default/1001/INBOX'
            for _ in range(500):
                state = json.loads((data / 'phone-admission.json').read_bytes())
                deposits = [batch for batch in state['batches'].values() if batch.get('purpose') == 'voicemail']
                if deposits and deposits[0]['voicemail'].get('result'): break
                result_file = root / 'results/live.json'
                if result_file.exists() and json.loads(result_file.read_text()).get('status') != 'page_ended':
                    raise RuntimeError('Private live paging failed: ' + result_file.read_text()
                                       + '\n' + (root / 'results/rejected.json').read_text() + '\n' + (root / 'log/evidence').read_text()[-5000:])
                time.sleep(.1)
            else:
                raise RuntimeError('Native voicemail did not complete: ' + (root / 'log/evidence').read_text()[-10000:]
                                   + '\n' + json.dumps(state) + '\n' + (root / 'results/live.json').read_text())
            proof = deposits[0]['voicemail']
            assert proof['playback_completed'] and proof['result']['status'] == 'SUCCESS', proof
            assert len(deposits) == 1, 'Fallback made more than one native deposit attempt'
            messages = list(inbox.glob('msg*.wav'))
            assert len(messages) == 1, 'Asterisk did not store exactly one native voicemail'
            with wave.open(str(messages[0]), 'rb') as handle:
                duration = handle.getnframes() / handle.getframerate(); samples = handle.readframes(handle.getnframes())
            assert 3.9 <= duration <= 6.5, 'Full caller speech was not stored: ' + str(duration)
            assert max(abs(value[0]) for value in struct.iter_unpack('<h', samples)) > 3000
            vm_info = cli('voicemail show users for default')
            assert re.search(r'1001\s+Fixture\s+(?:[A-Za-z0-9_-]+\s+)?1\s*$', vm_info, re.M), vm_info
            observer.action({'Action': 'Ping'})
            assert any(event.get('Event') == 'MessageWaiting' and event.get('Mailbox') == '1001@default'
                       and event.get('New') == '1' for event in waiting_events), waiting_events[-10:]
            with vm.PhoneAmi(vm.read_settings()) as ami:
                assert ami.variable('VM_INFO(1001@default,count)') == '1'
            # A second drain may read the source but must not originate again.
            assert vm.drain_voicemail() == 0
            assert len(list(inbox.glob('msg*.wav'))) == 1
            # Opt-out follows the original no-receivers behavior and performs
            # no recording, new admission or native mailbox operation.
            existing_leases = set((data / 'sounds/voicemail').glob('*.json'))
            settings_file = data / 'mass-notifications.config'
            opted_out = json.loads(settings_file.read_text()); opted_out['live_paging']['voicemail_enabled'] = '0'
            settings_file.write_text(json.dumps(opted_out))
            (root / 'results/live.json').unlink()
            cli('channel originate Local/s@fixture-caller/n extension s@sls-live-paging-external')
            for _ in range(100):
                if (root / 'results/live.json').exists(): break
                time.sleep(.1)
            result = json.loads((root / 'results/live.json').read_text())
            assert result['status'] == 'no_reachable_recipients', result
            assert set((data / 'sounds/voicemail').glob('*.json')) == existing_leases
            assert len(list(inbox.glob('msg*.wav'))) == 1
            print('PASS: actual offline live paging menu/PIN, caller-only MixMonitor, own-ID stop, finalized immutable audio, one native VoiceMail deposit, full source playback, native AMI MessageWaiting and opt-out without recording/deposit. No production endpoint was contacted.')
        finally:
            if observer is not None: observer.close()
            for child in (collector, process):
                if child is not None:
                    child.terminate()
                    try: child.wait(timeout=5)
                    except subprocess.TimeoutExpired: child.kill(); child.wait(timeout=5)
            subprocess.run(['umount', str(data)], check=True)
            if old_policy is None: static_policy.unlink(missing_ok=True)
            else: static_policy.write_bytes(old_policy)
