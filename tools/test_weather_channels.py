#!/usr/bin/python3
"""Weather audiences freeze consent/provider identity and recheck source policy."""
import copy
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode = True
BIN = Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'
sys.path.insert(0, str(BIN))
import sls_weather_channels as channels
import sls_notification_destinations as destinations

class WeatherChannels(unittest.TestCase):
    def setUp(self):
        self.voice = 'voice_' + 'a' * 24
        self.sms = 'sms_' + 'b' * 24
        self.group = {'voice_recipient_ids': [self.voice], 'sms_recipient_ids': [self.sms]}
        self.config = {'outbound_voice': {'enabled':'1', 'trunk':'fixture', 'recipients':[{'id':self.voice,'enabled':'1','number':'+15551230000'}]},
                       'announcement_sms': {'enabled':'1','provider':'bulkvs','recipients':[{'id':self.sms,'enabled':'1','consent':'1','number':'+15551230001'}]}}
        self.context = {'source_validity':{},'event':'Tornado Warning','severity':'Extreme','deadline_at':1600,'channels':channels.snapshot(self.config,self.group)}
        self.guard = mock.patch.object(destinations,'validate_external_weather',return_value=('eligible','',self.group))
        self.guard.start();self.addCleanup(self.guard.stop)

    def authorize(self): return channels.authorize(self.config,self.context,now=1000)

    def test_original_recipients_remain_eligible(self):
        result=self.authorize();self.assertEqual(result['status'],'eligible')
        self.assertEqual(result['voice_recipient_ids'],[self.voice]);self.assertEqual(result['sms_recipient_ids'],[self.sms])

    def test_newly_selected_recipient_never_receives_old_alert(self):
        new='sms_'+'c'*24;self.group['sms_recipient_ids'].append(new)
        self.config['announcement_sms']['recipients'].append({'id':new,'enabled':'1','number':'+15551230002'})
        self.assertEqual(self.authorize()['sms_recipient_ids'],[self.sms])

    def test_changed_number_provider_consent_and_disabled_service_excluded(self):
        for section,field,value in [('outbound_voice','trunk','changed'),('announcement_sms','provider','telnyx'),('announcement_sms','enabled','0')]:
            saved=copy.deepcopy(self.config);self.config[section][field]=value
            self.assertFalse(self.authorize()['voice_recipient_ids' if section=='outbound_voice' else 'sms_recipient_ids']);self.config=saved
        self.config['announcement_sms']['recipients'][0]['consent']='0';self.assertFalse(self.authorize()['sms_recipient_ids'])

    def test_removed_and_disabled_original_recipient_excluded(self):
        self.group['voice_recipient_ids']=[];self.config['announcement_sms']['recipients'][0]['enabled']='0'
        result=self.authorize();self.assertFalse(result['voice_recipient_ids']);self.assertFalse(result['sms_recipient_ids'])

    def test_quiet_hours_and_stale_observations_defer_without_sending(self):
        for reason in ('quiet_hours','source_observation_stale'):
            with mock.patch.object(destinations,'validate_external_weather',return_value=('deferred',reason,self.group)):
                result=self.authorize();self.assertEqual(result['status'],'deferred');self.assertFalse(result['sms_recipient_ids'])

    def test_expiry_and_superseded_event_cancel(self):
        self.context['deadline_at']=1000;self.assertEqual(self.authorize()['status'],'cancelled')
        self.context['deadline_at']=1600
        with mock.patch.object(destinations,'validate_external_weather',return_value=('cancelled','source_superseded',self.group)):
            self.assertEqual(self.authorize()['reason'],'source_superseded')

    def test_missing_snapshot_malformed_id_and_unexpected_context_rejected(self):
        for field,value in [('channels',{}),('unexpected',True)]:
            bad=copy.deepcopy(self.context);bad[field]=value
            with self.assertRaises(ValueError):channels.authorize(self.config,bad,now=1000)
        self.context['channels']['sms_recipient_ids']=['sms_../../x']
        with self.assertRaises(ValueError):self.authorize()

    def test_external_outbox_retains_channel_job_without_resending_phones(self):
        with tempfile.TemporaryDirectory() as directory:
            state=Path(directory)/'retry.json'
            with mock.patch.object(destinations,'_source_group',return_value=self.group):
                key=destinations.queue_external_delivery(state,self.config,'alert-chain','Weather','Full instructions',source='nws',source_validity={})
            runner=mock.Mock(returncode=0,stdout=json.dumps({'success':True,'status':'queued','job_id':'job_'+'d'*32}))
            with mock.patch.object(destinations.subprocess,'run',return_value=runner) as run:
                destinations.retry_external_deliveries(state,self.config,'nws',live=True,validity_checker=lambda *args:('eligible','',self.group))
                destinations.retry_external_deliveries(state,self.config,'nws',live=True,validity_checker=lambda *args:('eligible','',self.group))
                self.assertEqual(run.call_count,1)
                self.assertIn('sls_mass_notify_weather_channels.php',run.call_args.args[0][-1])
            record=json.loads(state.read_text())['deliveries'][key]
            self.assertFalse(record['channels_pending']);self.assertEqual(record['channel_job_id'],'job_'+'d'*32)
            self.assertEqual(record['destination_receipts']['channels:sms_external_voice']['status'],'queued')

if __name__ == '__main__': unittest.main()
