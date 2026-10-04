#!/usr/bin/python3
"""Read-only quiescence checks using private state/process/spool/channel fixtures."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('idle_fixture',ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_install_idle.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class Idle(unittest.TestCase):
    def setUp(self):
        temp=tempfile.TemporaryDirectory(prefix='sls-install-idle-');self.addCleanup(temp.cleanup)
        self.base=Path(temp.name);self.data=self.base/'data';self.data.mkdir(mode=0o750)
        self.proc=self.base/'proc';self.proc.mkdir()
        self.spools=[self.base/'outgoing',self.base/'spool-tmp']
        for directory in self.spools:directory.mkdir()
        self.module=self.base/'module/module.xml';self.module.parent.mkdir();self.module.write_bytes(b'<module/>')
        self.subsystem=self.module.with_name('PhoneEventService.php');self.subsystem.write_bytes(b'<?php')
        self.channels='';self.command_calls=0
        def channels():self.command_calls+=1;return self.channels
        self.check=m.IdleCheck(self.data,module=self.module,proc=self.proc,spools=self.spools,channels=channels,boot_id='fixture-boot',monotonic=lambda:100,clock=lambda:1000,subsystem_paths=(self.subsystem,self.base/'collector-unit',self.base/'runtime-collector'))
        self.token='a'*32
        self.state={'schema':1,'collector':{'generation':'b'*32,'boot_id':'fixture-boot','heartbeat_tick':99},'batches':{self.token:{'status':'closed','created_at':1,'closed_at':2,'detail':'private destination'}}}
        self.save('phone-admission.json',self.state)
    def save(self,name,value):
        path=self.data/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_text(json.dumps(value));path.chmod(0o640);return path
    def baseline(self):
        path=self.base/'baseline.json';path.write_text(json.dumps(self.check.inspect()));path.chmod(0o600);return path
    def process(self,args):
        directory=self.proc/'999999';directory.mkdir(exist_ok=True);(directory/'cmdline').write_bytes(b'\0'.join(value.encode() for value in args)+b'\0')
    def test_history_inspection_is_read_only_and_never_prints_tokens_or_destinations(self):
        path=self.data/'phone-admission.json';before=path.read_bytes();info=path.stat()
        result=self.check.inspect();encoded=json.dumps(result)
        self.assertEqual(result['closed_phone_batches'],1)
        self.assertNotIn(self.token,encoded);self.assertNotIn('private destination',encoded)
        self.assertEqual(path.read_bytes(),before)
        self.assertEqual((path.stat().st_mtime_ns,path.stat().st_ino),(info.st_mtime_ns,info.st_ino))
        self.assertEqual(self.command_calls,1)
    def test_heartbeat_change_allowed_but_closed_record_mutation_or_removal_rejected(self):
        before=self.baseline()
        self.state['collector']['generation']='c'*32;self.state['collector']['heartbeat_tick']=100;self.save('phone-admission.json',self.state)
        self.check.inspect(before)
        self.state['batches'][self.token]['closed_at']=3;self.save('phone-admission.json',self.state)
        with self.assertRaisesRegex(m.IdleError,'changed or disappeared'):self.check.inspect(before)
        self.state['batches']={};self.save('phone-admission.json',self.state)
        with self.assertRaisesRegex(m.IdleError,'changed or disappeared'):self.check.inspect(before)
    def test_new_closed_history_is_allowed(self):
        before=self.baseline();self.state['batches']['d'*32]={'status':'closed','closed_at':3};self.save('phone-admission.json',self.state)
        self.assertEqual(self.check.inspect(before)['closed_phone_batches'],2)
    def test_active_reserved_and_corrupt_phone_batches_stop_installation(self):
        for status in ('active','reserved',None):
            self.state['batches'][self.token]['status']=status;self.save('phone-admission.json',self.state)
            with self.assertRaisesRegex(m.IdleError,'unclosed'):self.check.inspect()
    def test_enabled_idle_paging_is_supported_without_reading_configuration(self):
        self.save('mass-notifications.config',{'live_paging':{'enabled':'1'},'secret':'do not read'})
        self.assertTrue(self.check.inspect()['ok'])
    def test_fresh_missing_state_allowed_but_installed_missing_ledger_refused(self):
        (self.data/'phone-admission.json').unlink()
        with self.assertRaisesRegex(m.IdleError,'missing'):self.check.inspect()
        self.module.unlink();(self.data/'audio-reservations.lock').unlink();self.data.rmdir()
        report=self.check.inspect()
        self.assertFalse(report['phone_collector_required']);self.assertEqual(report['phone_history'],{})
    def test_genuine_legacy_installation_without_any_collector_marker_can_upgrade(self):
        self.subsystem.unlink();(self.data/'phone-admission.json').unlink()
        report=self.check.inspect()
        self.assertTrue(report['installed']);self.assertFalse(report['phone_collector_required'])
        for marker in (self.base/'collector-unit',self.base/'runtime-collector'):
            marker.write_bytes(b'present modern subsystem')
            with self.assertRaisesRegex(m.IdleError,'missing'):self.check.inspect()
            marker.unlink()
    def test_installed_collector_requires_matching_boot_and_fresh_finite_heartbeat(self):
        for tick in (80,101,True,float('nan')):
            self.state['collector']['heartbeat_tick']=tick;self.save('phone-admission.json',self.state)
            with self.assertRaises(m.IdleError):self.check.inspect()
        self.state['collector']['heartbeat_tick']=99;self.state['collector']['boot_id']='old-boot';self.save('phone-admission.json',self.state)
        with self.assertRaisesRegex(m.IdleError,'identity'):self.check.inspect()
    def test_manual_test_live_paging_phone_agi_and_supervisor_stop_installation(self):
        for args in (['bash','/usr/local/bin/sls_mass_notify/sls_mass_notify_test.sh'],['php','sls_mass_notify_live_paging.php'],['python3','sls_mass_notify_phone_agi.py'],['php','sls_mass_notify_announcement_worker.php','--supervise'],['php','sls_mass_notify_announcement_worker.php','job_'+'e'*32]):
            with self.subTest(args=args):
                self.process(args)
                with self.assertRaises(m.IdleError):self.check.inspect()
        self.process(['php','sls_mass_notify_announcement_worker.php','--health-check'])
        self.assertTrue(self.check.inspect()['ok'])
    def test_enterprise_supervisors_and_response_helpers_stop_installation_without_killing_them(self):
        executions = [
            ['php', '/usr/local/bin/sls_mass_notify/sls_mass_notify_cluster_worker.php', mode]
            for mode in ('--watch', '--edge-watch', '--once', '--edge-once', '--initialize', '--status')
        ]
        executions += [
            ['php', '/usr/local/bin/sls_mass_notify/sls_mass_notify_cluster_effect.php'],
            ['php', '/usr/local/bin/sls_mass_notify/sls_mass_notify_incident_response.php'],
        ]
        state = (self.data / 'phone-admission.json').read_bytes()
        for args in executions:
            with self.subTest(args=args):
                self.process(args)
                command = self.proc / '999999/cmdline'
                original = command.read_bytes()
                with self.assertRaisesRegex(m.IdleError, 'stop its external supervisor'):
                    self.check.inspect()
                self.assertEqual(command.read_bytes(), original)
                self.assertEqual((self.data / 'phone-admission.json').read_bytes(), state)
        self.process(['php', '/tmp/unrelated.php', '--watch'])
        self.assertTrue(self.check.inspect()['ok'])
    def test_pending_jobs_and_call_spools_are_not_deleted_to_make_idle(self):
        jobs=self.data/'announcement-jobs';jobs.mkdir();marker=jobs/('pending_job_'+'e'*32+'.mark');marker.touch()
        with self.assertRaisesRegex(m.IdleError,'queued'):self.check.inspect()
        self.assertTrue(marker.exists());marker.unlink()
        for spool in self.spools:
            call=spool/'sls_fixture.call';call.write_bytes(b'private call')
            with self.assertRaisesRegex(m.IdleError,'call files'):self.check.inspect()
            self.assertEqual(call.read_bytes(),b'private call');call.unlink()
    def test_reservations_require_expiry_and_malformed_timestamps_fail_closed(self):
        for state in ({'recipients':{'1000':1001},'media':{}}, {'recipients':{},'media':{},'waiting':{'a'*32:{'recipients':['1000'],'priority':1,'created':999,'expires':1001,'heartbeat':1001,'media_name':'fixture.wav','duration':1}}}, {'recipients':{'1000':'malformed'},'media':{}}, {'recipients':{},'media':{},'waiting':{'ticket':{}}}):
            self.save('audio-reservations.json',state)
            with self.assertRaises(m.IdleError):self.check.inspect()
        self.save('audio-reservations.json',{'recipients':{'1000':999},'media':{},'waiting':{}})
        self.assertTrue(self.check.inspect()['ok'])
    def test_active_sls_channels_refused_and_unrelated_calls_left_alone(self):
        self.channels='PJSIP/ordinary!'+'!'.join(['ordinary']*12)
        self.assertTrue(self.check.inspect()['ok'])
        self.channels='Local/1000@sls-phone-origin!'+'!'.join(['ordinary']*12)
        with self.assertRaisesRegex(m.IdleError,'channel is active'):self.check.inspect()
        self.channels='Asterisk failed to provide channel inventory'
        with self.assertRaisesRegex(m.IdleError,'unrecognized'):self.check.inspect()
    def test_links_special_files_and_unprotected_baseline_rejected_without_mutation(self):
        path=self.data/'phone-admission.json';body=path.read_bytes();path.unlink()
        victim=self.base/'victim';victim.write_bytes(body);victim.chmod(0o600)
        for kind in ('symlink','hardlink','fifo'):
            if kind=='symlink':path.symlink_to(victim)
            elif kind=='hardlink':os.link(victim,path)
            else:os.mkfifo(path)
            with self.assertRaises((m.IdleError,OSError)):self.check.inspect()
            self.assertEqual(victim.read_bytes(),body);self.assertEqual(victim.stat().st_mode & 0o777,0o600);path.unlink()
        path.write_bytes(body);path.chmod(0o640)
        baseline=self.baseline();baseline.chmod(0o644)
        with self.assertRaisesRegex(m.IdleError,'baseline mode'):self.check.inspect(baseline)
    def test_parent_symlink_and_oversized_state_rejected(self):
        alias=self.base/'alias';alias.symlink_to(self.data);self.check.data=alias
        with self.assertRaises(OSError):self.check.inspect()
        self.check.data=self.data
        path=self.data/'phone-admission.json'
        with path.open('wb') as handle:handle.truncate(m.MAX_STATE+1)
        with self.assertRaisesRegex(m.IdleError,'oversized'):self.check.inspect()
    def test_busy_state_and_inplace_change_fail_without_rewrites(self):
        import fcntl
        path=self.data/'phone-admission.json'
        with path.open('rb') as handle:
            fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
            with self.assertRaisesRegex(m.IdleError,'busy'):self.check.inspect()
        original=m.os.read;changed=False;inode=path.stat().st_ino
        def mutate(fd,size):
            nonlocal changed
            body=original(fd,size)
            if not changed and os.fstat(fd).st_ino==inode:
                changed=True
                with path.open('ab') as output:output.write(b' ')
            return body
        with mock.patch.object(m.os,'read',side_effect=mutate):
            with self.assertRaisesRegex(m.IdleError,'changed'):self.check.inspect()

if __name__=='__main__':unittest.main()
