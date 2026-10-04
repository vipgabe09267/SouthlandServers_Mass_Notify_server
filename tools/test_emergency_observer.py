#!/usr/bin/python3
import hashlib
import json
import os
from pathlib import Path
import pwd
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_emergency_observer as observer


class EmergencyObserver(unittest.TestCase):
    def setUp(self):
        self.rule={'id':'trg_'+'a'*24,'enabled':True,'kind':'emergency_call','callers':['1000'],'numbers':['911'],'max_age_seconds':300}
        self.settings={'enabled':'1','automations':{'rules':[self.rule]}}
        self.event={'Event':'DialBegin','Channel':'PJSIP/1000-0000001a','Uniqueid':'178000.5','CallerIDNum':'5125550100','DialString':'911@fixture-trunk'}

    def test_strict_channel_identity_and_destinations(self):
        rows=observer.observations(self.event,self.settings,'a'*64,1000)
        self.assertEqual(len(rows),1); self.assertEqual(rows[0]['caller'],'1000'); self.assertEqual(rows[0]['event'],'911')
        for field,value in [('Channel','PJSIP/trunk-0000001a'),('Channel','Local/1000@from-internal;2'),('Channel','PJSIP/1001-0000001a'),('DialString','912@trunk'),('DialString','911@trunk&other'),('Uniqueid','bad\n'),('Event','Hangup')]:
            self.assertEqual(observer.observations(dict(self.event,**{field:value}),self.settings,'a'*64,1000),[])
        for value in ['911@trunk','PJSIP/911@trunk','trunk/911','trunk/sip:911@192.0.2.1','trunk/sips:911@carrier.example:5061']:
            self.assertEqual(observer.dial_number(dict(self.event,DialString=value)),'911')
        self.rule['enabled']=False
        self.assertEqual(observer.observations(self.event,self.settings,'a'*64,1000),[])

    def test_dedup_uses_origin_call_not_trunk_leg(self):
        first=observer.observations(self.event,self.settings,'a'*64,1000)[0]
        second=observer.observations(dict(self.event,DestUniqueid='different-leg'),self.settings,'a'*64,1001)[0]
        self.assertEqual(first['id'],second['id'])
        self.assertNotIn('CallerIDNum',first); self.assertNotIn('Channel',first)

    def test_durable_spool_and_no_replacement(self):
        with tempfile.TemporaryDirectory(prefix='sls-emergency-') as directory:
            root=Path(directory); path=root/'mass-notifications.config'; raw=json.dumps(self.settings).encode(); path.write_bytes(raw)
            path.chmod(0o640); os.chown(path,0,pwd.getpwnam('asterisk').pw_gid)
            instance=observer.EmergencyObserver(root,clock=lambda:1000)
            with patch.object(observer.subprocess,'Popen') as start:
                instance.record(self.event); instance.record(self.event)
                self.assertEqual(start.call_count,1)
                self.assertEqual(start.call_args.args[0][-1],'--observe')
                self.assertNotIn('shell',start.call_args.kwargs)
            paths=list((root/'emergency-observations').glob('job_*.json')); self.assertEqual(len(paths),1)
            row=json.loads(paths[0].read_text()); self.assertEqual(row['settings_sha256'],hashlib.sha256(raw).hexdigest())
            self.assertEqual(row['state'],'observed'); self.assertFalse(row['is_test'])
            paths[0].write_text(json.dumps(dict(row,state='accepted')))
            with patch.object(observer.subprocess,'Popen'): instance.record(self.event)
            self.assertEqual(json.loads(paths[0].read_text())['state'],'accepted')

    def test_crowded_spool_scan_stops_at_capacity(self):
        with tempfile.TemporaryDirectory(prefix='sls-emergency-') as directory:
            root=Path(directory); path=root/'mass-notifications.config'
            path.write_text(json.dumps(self.settings)); path.chmod(0o640)
            os.chown(path,0,pwd.getpwnam('asterisk').pw_gid)
            spool=root/'emergency-observations'; spool.mkdir(mode=0o750)
            for index in range(700):
                (spool/f'retained-{index}').write_text('history')
            scan=observer.os.scandir; visited=[]

            class CountedDirectory:
                def __init__(self, fd):
                    self.entries=scan(fd)
                def __enter__(self):
                    def entries():
                        for entry in self.entries:
                            visited.append(entry.name)
                            yield entry
                    return entries()
                def __exit__(self, *args):
                    self.entries.close()

            instance=observer.EmergencyObserver(root,clock=lambda:1000)
            with patch.object(observer.os,'scandir',CountedDirectory), \
                    patch.object(observer.os,'listdir',side_effect=AssertionError('unbounded enumeration')), \
                    patch.object(observer.subprocess,'Popen') as start:
                with self.assertRaisesRegex(RuntimeError,'journal is full'):
                    instance.record(self.event)
                start.assert_not_called()
            self.assertEqual(len(visited),550)
            self.assertEqual(len(list(spool.iterdir())),700)


if __name__=='__main__': unittest.main()
