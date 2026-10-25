#!/usr/bin/python3
"""Connected-handset-only paging text; private state and inert transports."""
import configparser
import hashlib
import json
import os
from pathlib import Path
import select
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from unittest import mock

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_live_paging_visual as visual
import sls_phone_admission as phone


class VisualTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-page-visual-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.settings = {'ami': {}, 'announcement_timeout_mode': 'custom', 'announcement_timeout_seconds': 45,
            'live_paging': {'enabled': '1', 'extension': '799', 'max_duration_seconds': 300,
                'groups': [{'group_id': 'paging_fixture', 'name': 'Staff', 'extensions': ['1000','1001','1002'],
                    'allowed_callers': ['1000'], 'notify_extensions': ['1001','1002'],
                    'text_message': 'Live paging in progress.'}]}, 'announcement_groups': []}
        path = self.root / 'mass-notifications.config'
        path.write_text(json.dumps(self.settings));path.chmod(0o640);os.chown(path, *phone._account_ids())
        self.store = phone.PhoneAdmissionStore(self.root)
        self.generation = 'a' * 32
        self.store.collector_heartbeat(self.generation)
        self.dials = ['PJSIP/1001/sip:one@192.0.2.1:5060;transport=UDP',
                      'PJSIP/1001/sips:two@[2001:db8::1]:5061;transport=TLS',
                      'PJSIP/1002/sip:three@192.0.2.2:5060;transport=TCP']
        inventory = {'complete': True, 'started_tick': time.monotonic(), 'generation': self.generation,
                     'boot_id': self.store.boot_id, 'channels': []}
        self.token = self.store.admit({'1000': self.dials}, 25, inventory, service='live')
        self.origin = 'PJSIP/1000-00000001'
        self.store.bind_origin(self.token, '1000', '10.1', self.origin)
        for n, dial in enumerate(self.dials, 2):
            self.store.bind_contact(self.token, '1000', '10.'+str(n), 'PJSIP/contact-'+str(n), dial)
        self.request = {'token': self.token, 'group': 'paging_fixture', 'caller': '1000', 'external': False,
            'external_number': '', 'channel': self.origin, 'uniqueid': '10.1', 'owner': 'b'*32,
            'config_sha256': hashlib.sha256(path.read_bytes()).hexdigest()}
        self.sender = mock.Mock()
        config = configparser.ConfigParser();config.read_dict({'ami': {'host':'127.0.0.1','port':'5038','username':'fixture','password':'fixture'}})
        self.config = lambda: config
        self.monitor = visual.VisualMonitor(self.request, self.root, sender=self.sender, config_loader=self.config)

    def join(self, number):
        self.assertTrue(self.store.record_event({'Event':'ConfbridgeJoin','Uniqueid':'10.'+str(number),
            'Channel':'PJSIP/contact-'+str(number),'AccountCode':phone.accountcode(self.token)}))

    def join_origin(self):
        self.assertTrue(self.store.record_event({'Event':'ConfbridgeJoin','Uniqueid':'10.1',
            'Channel':self.origin,'AccountCode':phone.accountcode(self.token)}))

    def test_ready_cue_waits_for_every_admitted_contact_to_settle(self):
        self.monitor.request.update(ready_cue=True, expires_at=int(time.time())+300)
        self.monitor.origin_actions=mock.Mock()
        self.join_origin();self.join(2)
        self.monitor.tick();self.monitor.origin_actions.assert_not_called();self.sender.assert_not_called()
        self.join(3);self.monitor.tick();self.monitor.origin_actions.assert_not_called();self.sender.assert_not_called()
        self.store.ended(self.token,'10.4')
        self.monitor.tick()
        actions=self.monitor.origin_actions.call_args.args[0]
        self.assertEqual(actions,[{'Action':'Setvar','Variable':'SLS_PAGING_CUE_ARM','Value':self.request['owner']},
            {'Action':'PlayDTMF','Digit':'A','Duration':'100','Receive':'true'}])
        self.monitor.tick();self.monitor.origin_actions.assert_called_once()

    def test_slow_optional_visual_sender_cannot_delay_caller_control(self):
        self.monitor.request.update(ready_cue=True,expires_at=int(time.time())+300)
        controlled=threading.Event();sending=threading.Event();release=threading.Event()
        self.monitor.origin_actions=lambda actions:controlled.set()
        self.monitor.sender=lambda *args,**kwargs:(sending.set(),release.wait(2))
        self.join_origin();self.join(2);self.join(3);self.join(4)
        worker=threading.Thread(target=self.monitor.tick)
        worker.start()
        try:
            self.assertTrue(sending.wait(1));self.assertTrue(controlled.is_set())
        finally:
            release.set();worker.join(timeout=3)
        self.assertFalse(worker.is_alive())

    def test_voicemail_fallback_requires_exact_active_recording_lease(self):
        from sls_voicemail import VoicemailMedia, scope_fingerprint
        self.monitor.request['voicemail_ready']=True
        self.join_origin()
        for number in (2,3,4):self.store.ended(self.token,'10.'+str(number))
        batch=self.monitor.snapshot()
        for row in batch['channels'].values():
            if row['kind']=='contact':row['dial_status']='NOANSWER'
        batch['vm_fallback']={'scope':'paging_fixture','scope_hash':scope_fingerprint(self.settings,'live','paging_fixture'),
            'expires_at':time.time()+300,'expires_tick':time.monotonic()+300,
            'targets':{'1001':{'dials':self.dials[:2]},'1002':{'dials':self.dials[2:]}}}
        media=VoicemailMedia(self.root);parent=media.open_directory()
        value={'schema':1,'token':self.token,'kind':'live','state':'recording','channel':self.origin,
            'max_seconds':300,'created_at':time.time(),'expires_at':time.time()+300}
        try:
            media.write_lease(parent,self.token,value)
            self.assertEqual(self.monitor.ready_decision(batch),'recording')
            for row in batch['channels'].values():
                if row['kind']=='contact':row['answered_at']=time.time();row['joined_at']=time.time()
            self.assertEqual(self.monitor.ready_decision(batch),'unavailable')
            for row in batch['channels'].values():
                if row['kind']=='contact':row['answered_at']=None;row['joined_at']=None
            media.write_lease(parent,self.token,dict(value,channel='PJSIP/another'),replace=True)
            self.assertEqual(self.monitor.ready_decision(batch),'unavailable')
        finally:os.close(parent)

    def test_all_unanswered_cohort_never_reports_ready(self):
        self.join_origin()
        for number in (2,3,4):self.store.ended(self.token,'10.'+str(number))
        self.assertEqual(self.monitor.ready_decision(self.monitor.snapshot()),'unavailable')
        self.sender.assert_not_called()

    def test_duplicate_or_foreign_contact_cannot_complete_admitted_cohort(self):
        self.join_origin();self.join(2);self.join(3);self.join(4)
        original=self.monitor.snapshot()
        self.assertEqual(self.monitor.ready_decision(original),'ready')
        duplicate=json.loads(json.dumps(original))
        duplicate['channels']['10.99']=dict(duplicate['channels']['10.2'])
        self.assertEqual(self.monitor.ready_decision(duplicate),'pending')
        foreign=json.loads(json.dumps(original))
        foreign['channels']['10.4']['dial']='PJSIP/9999/sip:foreign@192.0.2.99'
        self.assertEqual(self.monitor.ready_decision(foreign),'pending')

    def test_elapsed_wait_never_changes_unsettled_cohort_into_ready(self):
        self.monitor.request.update(ready_cue=True,expires_at=int(time.time())+300)
        self.monitor.origin_actions=mock.Mock()
        self.join_origin();self.join(2)
        self.monitor.ready_started=self.monitor.clock()-20
        self.monitor.signal_ready_gate(self.monitor.snapshot())
        self.monitor.origin_actions.assert_called_once()
        # The callback independently reads this verdict and fails closed;
        # signaling the bounded failure callback does not authorize speech.
        self.assertEqual(self.monitor.ready_decision(self.monitor.snapshot()),'pending')

    def test_ready_gate_rechecks_exact_origin_uid_before_control(self):
        client=mock.MagicMock();client.__enter__.return_value=client
        client.action.return_value=({'Response':'Success','Value':'another-origin'},[])
        with mock.patch.object(visual.notify,'AmiClient',return_value=client),self.assertRaisesRegex(RuntimeError,'origin_changed'):
            self.monitor.arm_ready_gate()
        self.assertEqual(client.action.call_count,1)

    def test_ready_request_expires_and_rejects_nonboolean_flags(self):
        for patch in [{'ready_cue':True,'expires_at':int(time.time())-1},
                      {'ready_cue':True,'expires_at':int(time.time())+9000},
                      {'ready_cue':'true'},{'voicemail_ready':1}]:
            with self.subTest(patch=patch),self.assertRaises(ValueError):
                visual.VisualMonitor(dict(self.request,**patch),self.root,sender=self.sender,config_loader=self.config)

    def test_ready_control_uncertainty_is_not_retried(self):
        self.monitor.request.update(ready_cue=True,expires_at=int(time.time())+300)
        self.monitor.origin_actions=mock.Mock(side_effect=TimeoutError('synthetic'))
        self.join_origin();self.join(2);self.join(3);self.join(4)
        with self.assertRaises(TimeoutError):self.monitor.signal_ready_gate(self.monitor.snapshot())
        self.monitor.signal_ready_gate(self.monitor.snapshot())
        self.monitor.origin_actions.assert_called_once()

    def test_no_popup_for_dialing_answer_only_or_caller_bridge_join(self):
        self.monitor.tick();self.sender.assert_not_called()
        self.store.record_event({'Event':'DialEnd','DestUniqueid':'10.2','DestChannel':'PJSIP/contact-2',
            'DestAccountCode':phone.accountcode(self.token),'DialStatus':'ANSWER'})
        self.store.record_event({'Event':'ConfbridgeJoin','Uniqueid':'10.1','Channel':self.origin,
            'AccountCode':phone.accountcode(self.token)})
        self.monitor.tick();self.sender.assert_not_called()
        self.assertEqual(self.monitor.finish()['status'], 'not_requested')

    def test_exact_connected_handsets_then_owned_quiet_one_second_cleanup(self):
        self.join(2);self.monitor.tick()
        args, kwargs = self.sender.call_args
        self.assertEqual(args[1:3], ('Live paging in progress.', ['1001']))
        self.assertEqual(kwargs['contact_targets'], {'1001':[self.dials[0].split('/',2)[2]]})
        self.assertEqual(kwargs['visual_owner'], self.request['owner'])
        self.assertEqual(kwargs['timeout_seconds'],45);self.assertFalse(kwargs['api_publish'])
        self.assertEqual(kwargs['continuation_extensions'],set())
        self.monitor.tick();self.assertEqual(self.sender.call_count,1)
        self.join(3);self.monitor.tick()
        self.assertEqual(self.sender.call_args.kwargs['contact_targets'], {'1001':[self.dials[1].split('/',2)[2]]})
        self.assertEqual(self.sender.call_args.kwargs['continuation_extensions'],{'1001'})
        result = self.monitor.finish();args,kwargs=self.sender.call_args
        self.assertEqual(result, {'status':'submitted','reason':'completed','connected_contacts':2,'attempted_contacts':2,'failures':0})
        self.assertEqual(args[1], 'Paging ended.')
        self.assertEqual(kwargs['timeout_seconds'],1);self.assertEqual(kwargs['visual_owner'],'')
        self.assertEqual(kwargs['clear_visual_owner'],self.request['owner'])
        self.assertEqual(kwargs['contact_targets'],{'1001':sorted(d.split('/',2)[2] for d in self.dials[:2])})

    def test_stop_during_inventory_prevents_later_submission(self):
        self.join(2);stopped=[False]
        def sender(*args, **kwargs):
            self.assertTrue(kwargs['submission_guard']())
            stopped[0]=True
            self.assertFalse(kwargs['submission_guard']())
        self.monitor.sender=sender
        self.monitor.tick(lambda:stopped[0])
        with self.assertRaises(visual.VisualStopped):self.monitor.tick(lambda:True)

    def test_origin_hangup_or_revoked_config_prevents_new_popups(self):
        self.join(2);self.store.ended(self.token,'10.1')
        with self.assertRaises(visual.VisualStopped):self.monitor.tick()
        self.sender.assert_not_called()
        path=self.root/'mass-notifications.config';path.write_text(path.read_text()+'\n')
        with self.assertRaises(visual.VisualStopped):self.monitor.tick()
        self.assertEqual(self.monitor.finish()['status'],'configuration_changed');self.sender.assert_not_called()

    def test_uncertain_evidence_is_distinct_from_confirmed_origin_end(self):
        self.join(2)
        with self.store.locked() as state:state['batches'][self.token]['outcome_uncertain']=True
        with self.assertRaises(phone.PhoneAdmissionError):self.monitor.tick()
        self.sender.assert_not_called()

    def test_no_join_reports_why_no_popup_was_requested(self):
        self.monitor.tick()
        self.assertEqual(self.monitor.finish()['reason'],'no_audio_recipient_joined')

    def test_unknown_origin_token_or_config_hash_is_refused(self):
        for patch in [{'token':'c'*32},{'uniqueid':'10.99'},{'channel':'PJSIP/other-00000001'},
                      {'config_sha256':'0'*64},{'external':True,'external_number':'+15125550123'}]:
            with self.subTest(patch=patch),self.assertRaises((ValueError,visual.VisualStopped,phone.PhoneAdmissionError)):
                visual.VisualMonitor(dict(self.request,**patch),self.root,sender=self.sender,config_loader=self.config)
        self.sender.assert_not_called()

    def test_stale_or_gapped_collector_never_releases_text(self):
        self.join(2);self.store.mark_event_gap()
        with self.assertRaises(phone.PhoneAdmissionError):self.monitor.tick()
        self.sender.assert_not_called()

    def test_uncertain_start_is_not_retried_but_retains_cleanup_cohort(self):
        self.join(2);self.sender.side_effect=RuntimeError('synthetic uncertainty')
        self.monitor.tick();self.monitor.tick();self.assertEqual(self.sender.call_count,1)
        result=self.monitor.finish();self.assertEqual(self.sender.call_count,2)
        self.assertEqual(result['status'],'failed');self.assertEqual(result['attempted_contacts'],1)

    def test_text_only_recipients_wait_for_real_audio_join(self):
        self.monitor.targets.add('1003')
        self.monitor.tick();self.sender.assert_not_called()
        fake=mock.MagicMock()
        fake.__enter__.return_value=fake
        with mock.patch.object(visual.notify,'AmiClient',return_value=fake),mock.patch.object(visual.notify,'get_registered_endpoint_info',return_value={
                '1003':{'contacts':[{'contact':'sip:display@192.0.2.3:5060','format':'yealink'}]}}):
            self.join(2);self.monitor.tick()
        self.assertEqual(set(self.sender.call_args.kwargs['contact_targets']),{'1001','1003'})

    def exercise_parent_pipe_revocation(self,kind):
        self.join(2)
        source=Path(visual.__file__).parent
        events=self.root/(kind+'.jsonl');script=self.root/(kind+'.py')
        program=[
            'import configparser,json,sys', 'from pathlib import Path',
            'sys.dont_write_bytecode=True', 'sys.path.insert(0,'+repr(str(source))+')',
            'import sls_live_paging_visual as module',
            "config=configparser.ConfigParser();config.read_dict({'ami':{'host':'127.0.0.1','port':'5038'}})",
            'def sender(*args,**kwargs):',
            "    with Path("+repr(str(events))+").open('a') as output:output.write(json.dumps({'message':args[1],'timeout':kwargs['timeout_seconds']})+chr(10))",
            'factory=module.VisualMonitor',
            'module.VisualMonitor=lambda request:factory(request,'+repr(str(self.root))+',sender=sender,config_loader=lambda:config)',
            'raise SystemExit(module.main())',
        ]
        script.write_text('\n'.join(program)+'\n')
        child=subprocess.Popen([sys.executable,'-I',str(script),'--request-stdin'],stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        try:
            request=dict(self.request,config_sha256=hashlib.sha256((self.root/'mass-notifications.config').read_bytes()).hexdigest())
            child.stdin.write(json.dumps(request)+'\n');child.stdin.flush()
            self.assertTrue(select.select([child.stdout],[],[],3)[0],'Monitor did not report readiness')
            ready_line=child.stdout.readline()
            if not ready_line:self.fail(child.stderr.read())
            self.assertEqual(json.loads(ready_line),{'ready':True})
            end=time.monotonic()+3
            while not events.exists() and time.monotonic()<end:time.sleep(.01)
            self.assertTrue(events.exists(),'Connected popup was not observed')
            if kind=='collector_gap':self.store.mark_event_gap()
            else:
                path=self.root/'mass-notifications.config';path.write_text(path.read_text()+'\n')
            self.assertFalse(select.select([child.stdout],[],[],.5)[0],'Monitor claimed page completion before parent EOF')
            self.assertIsNone(child.poll());self.assertEqual(len(events.read_text().splitlines()),1)
            child.stdin.close();child.wait(timeout=3)
            result=json.loads(child.stdout.read())
            sent=[json.loads(line) for line in events.read_text().splitlines()]
            if kind=='collector_gap':
                self.assertEqual(result['status'],'failed')
                self.assertEqual(sent[-1],{'message':'Paging ended.','timeout':1})
            else:
                self.assertEqual(result['status'],'configuration_changed')
                self.assertEqual(result['reason'],'configuration_changed');self.assertEqual(len(sent),1)
        finally:
            if child.poll() is None:child.kill();child.wait(timeout=3)
            if not child.stdin.closed:child.stdin.close()
            child.stdout.close();child.stderr.close()

    def test_parent_pipe_defers_completion_after_collector_evidence_loss(self):
        self.exercise_parent_pipe_revocation('collector_gap')

    def test_parent_pipe_defers_and_reports_skipped_configuration_revoked_cleanup(self):
        self.exercise_parent_pipe_revocation('configuration_changed')


if __name__ == '__main__':
    unittest.main()
