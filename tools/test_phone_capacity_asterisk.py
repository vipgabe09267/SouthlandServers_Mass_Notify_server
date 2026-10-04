#!/usr/bin/python3
"""Measure real private Asterisk conference fan-out, never production calls."""
import json
import os
import re
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import wave

isolated = os.environ.get('SLS_TEST_NAMESPACE') == 'entered' and os.environ.get('SLS_TEST_PARENT_NET_NS') not in (None, os.readlink('/proc/self/ns/net'))
if not isolated:
    raise SystemExit('Run through tools/run_isolated_tests.sh; production Asterisk is never used.')
with tempfile.TemporaryDirectory(prefix='sls-capacity-') as folder:
    root = Path(folder)
    for name in ['etc','run','log','data/documentation','data/sounds','spool','cache']:
        (root/name).mkdir(parents=True, exist_ok=True)
    shutil.copyfile('/tmp/sls-asterisk-vendor-docs/core-en_US.xml', root/'data/documentation/core-en_US.xml')
    modules = next(Path(p) for p in ['/usr/lib/asterisk/modules','/usr/lib64/asterisk/modules','/usr/lib/x86_64-linux-gnu/asterisk/modules'] if (Path(p)/'app_confbridge.so').is_file())
    paths = {'astetcdir':root/'etc','astmoddir':modules,'astvarlibdir':root/'data','astdbdir':root/'data','astdatadir':root/'data','astspooldir':root/'spool','astrundir':root/'run','astlogdir':root/'log','astcachedir':root/'cache'}
    config = root/'etc/asterisk.conf'
    config.write_text('[directories]\n'+''.join(k+' = '+str(v)+'\n' for k,v in paths.items())+'[options]\nverbose=0\n')
    (root/'etc/modules.conf').write_text('[modules]\nautoload=no\n'+''.join('load='+m+'.so\n' for m in ['res_timing_timerfd','res_speech','res_musiconhold','res_clioriginate','app_playback','app_confbridge','app_page','app_originate','app_exec','pbx_config','bridge_softmix','bridge_simple','bridge_builtin_features','func_channel','func_callerid','format_wav','codec_ulaw','codec_alaw']))
    (root/'etc/logger.conf').write_text('[general]\n[logfiles]\nevidence => error,warning,notice\n')
    (root/'etc/confbridge.conf').write_text('[general]\n[default_bridge]\ntype=bridge\ninternal_sample_rate=8000\nmixing_interval=20\n[default_user]\ntype=user\nquiet=yes\n')
    with wave.open(str(root/'data/sounds/speech.wav'),'wb') as wav:
        import math, struct
        wav.setnchannels(1);wav.setsampwidth(2);wav.setframerate(8000)
        wav.writeframes(b''.join(struct.pack('<h', int(4000*math.sin(i*math.tau*440/8000))) for i in range(8000*30)))
    (root/'etc/extensions.conf').write_text('[general]\nstatic=yes\n[receive]\nexten => _X.,1,Answer()\n same => n,ConfBridge(capacity,default_bridge,default_user)\n[transmit]\nexten => s,1,Answer()\n same => n,Playback(speech)\n same => n,Hangup()\n')
    proc = subprocess.Popen(['asterisk','-C',str(config),'-f','-g','-n'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    def cli(command):
        return subprocess.check_output(['asterisk','-C',str(config),'-rx',command], text=True, timeout=10)
    def cpu():
        values=(Path('/proc')/str(proc.pid)/'stat').read_text().split(') ')[1].split()
        return (int(values[11])+int(values[12])) / os.sysconf('SC_CLK_TCK')
    try:
        for _ in range(80):
            if (root/'run/asterisk.ctl').exists(): break
            if proc.poll() is not None: raise RuntimeError('Private Asterisk did not start.')
            time.sleep(.05)
        for _ in range(100):
            if 'fully booted' in cli('core waitfullybooted'): break
            time.sleep(.05)
        report=[]
        for contacts in (25,50,100):
            for number in range(contacts): cli('channel originate Local/'+str(1000+number)+'@receive/n extension s@transmit')
            time.sleep(.6)
            channels=cli('core show channels count')
            count = re.search(r'(\d+) active channels', channels)
            if not count or not contacts*2 <= int(count[1]) <= contacts*2+4: raise RuntimeError('The measured fan-out was not active: '+channels+(root/'log/evidence').read_text()[-1400:])
            start=time.monotonic();before=cpu();time.sleep(1.2);elapsed=time.monotonic()-start
            rss=int(next(line.split()[1] for line in (Path('/proc')/str(proc.pid)/'status').read_text().splitlines() if line.startswith('VmRSS:')))*1024
            report.append({'contacts':contacts,'active_channels':int(count[1]),'cpu_cores_used':round((cpu()-before)/elapsed,4),'resident_bytes':rss,'mode':'Local PCM playback with 8 kHz conference mixing'})
            cli('channel request hangup all');time.sleep(.3)
        print(json.dumps({'private_asterisk_fanout':report,'limitations':'No SIP transport, encryption, external trunk or physical handset; engineering sizing includes additional margin.'}))
    finally:
        proc.terminate()
        try:proc.wait(timeout=8)
        except subprocess.TimeoutExpired:proc.kill();proc.wait()
