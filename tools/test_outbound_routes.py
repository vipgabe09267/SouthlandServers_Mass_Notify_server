#!/usr/bin/python3
"""Isolated route proofs using minimal and sanitized FreePBX core fixtures.

The core fixture retains real stock control flow. Unsupported add-on callbacks
are tested separately; this does not claim universal FreePBX compatibility.
"""
import copy
import importlib.util
import json
import hashlib
import os
import pwd
from pathlib import Path
import sys
import tempfile
import time
import unittest
from unittest.mock import patch
sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_outbound_routes as routes
import sls_phone_admission as phone


def row(context, app, data, priority=1, extension='s'):
    return {'Context': context, 'Extension': extension, 'Priority': str(priority), 'Application': app, 'AppData': data}


class Inventory:
    def __init__(self):
        self.contexts = {
            'outbound-allroutes': [{'Context': 'outbound-allroutes', 'IncludeContext': 'outrt-1'}],
            'outrt-1': [row('outrt-1', 'Gosub', 'macro-dialout-trunk,s,1(1,${EXTEN},,off)', extension='_+X.')],
            'macro-dialout-trunk': [
                row('macro-dialout-trunk', 'Set', 'DIAL_TRUNK=${ARG1}'),
                row('macro-dialout-trunk', 'Set', 'DIAL_NUMBER=${ARG2}', 2),
                row('macro-dialout-trunk', 'Set', 'DIAL_TRUNK_OPTIONS=' + routes.TRUNK_OPTIONS_ASSIGNMENT, 3),
                row('macro-dialout-trunk', 'Set', 'OUTNUM=${OUTPREFIX_${DIAL_TRUNK}}${DIAL_NUMBER}', 4),
                row('macro-dialout-trunk', 'Dial', routes.DIAL_DESTINATION + ',${TRUNK_RING_TIMER},${DIAL_TRUNK_OPTIONS}', 5),
                row('macro-dialout-trunk', 'Return', '', 6)],
        }
        self.values = {'OUT_1': 'PJSIP', 'OUTDISABLE_1': 'off', 'OUT_1_SUFFIX': '@carrier',
            'PJSIP_ENDPOINT(carrier,aors)': 'carrier', 'TRUNK_OPTIONS': 'Ti', 'DB_EXISTS(TRUNK/1/dialopts)': '0'}
    def dialplan(self, context): return copy.deepcopy(self.contexts[context])
    def variable(self, key):
        if key.startswith('DIALPLAN_EXISTS('): return '1' if key[16:-1] in self.contexts else '0'
        return self.values.get(key, '')


class RouteTests(unittest.TestCase):
    def setUp(self):
        self.ami = Inventory()
        self.target = {'id': 'voice_' + 'a' * 24, 'number': '+15551234567', 'route_mode': 'pbx_routes', 'trunk_id': '', 'caller_id': ''}
    def proof(self): return routes.validate_route(self.ami, self.target)
    def test_supported_subset_remains_in_freepbx_and_fingerprints_changes(self):
        before = self.proof()
        self.assertEqual(before['trunk_endpoints'], ['carrier'])
        self.assertEqual(before['route_mode'], 'pbx_routes')
        self.ami.values['OUTPREFIX_1'] = '9'
        self.assertNotEqual(before['fingerprint'], self.proof()['fingerprint'])
        self.target.update(route_mode='trunk', trunk_id='1', caller_id='+15557654321')
        self.ami.contexts.pop('outbound-allroutes')
        self.assertEqual(self.proof()['caller_id'], '+15557654321')
    def test_forwarding_transfer_and_callbacks_require_review(self):
        self.ami.values['TRUNK_OPTIONS'] = 'TL(21600000)'
        proof = self.proof()
        self.assertEqual(proof['channel_variables'], {'TRUNK_OPTIONS': 'TL(21600000)i'})
        self.assertEqual(self.ami.values['TRUNK_OPTIONS'], 'TL(21600000)')
        for value, code in [('Tit', 'dial_options_unsupported'), ('TiU(custom)', 'dial_options_unsupported'),
                            ('Tb(custom^s^1)', 'dial_options_unsupported'), ('T${EVIL}', 'dial_options_unsupported')]:
            self.ami.values['TRUNK_OPTIONS'] = value
            with self.assertRaisesRegex(routes.OutboundRouteError, code): self.proof()
        self.ami.values['TRUNK_OPTIONS'] = 'Ti'
        self.ami.values.update({'DB_EXISTS(TRUNK/1/dialopts)': '1', 'DB(TRUNK/1/dialopts)': ''})
        with self.assertRaisesRegex(routes.OutboundRouteError, 'trunk_forwarding_override'): self.proof()
        self.ami.values['DB(TRUNK/1/dialopts)'] = 'TiS(600)'
        self.assertEqual(self.proof()['channel_variables']['TRUNK_OPTIONS'], 'Ti')
    def test_numeric_duration_options_preserve_limits_without_code_expansion(self):
        for value in ('TL(21600000)', 'TiL(60000:30000:10000)', 'rS(600)', 'L(60000::10000)'):
            self.ami.values['TRUNK_OPTIONS'] = value
            self.assertEqual(routes.channel_trunk_options(self.proof()), value + ('' if 'i' in value else 'i'))
        for value in ('TL(0)', 'S(-1)', 'L(999999999999)', 'L(60000:${SHELL(command)})', 'TTi', 'iL(60000)U(extra)', 'i\n'):
            with self.subTest(value=value), self.assertRaises(routes.OutboundRouteError): routes._dial_options(value)
    def test_pin_and_custom_fanout_are_rejected(self):
        self.ami.contexts['outrt-1'][0]['AppData'] = 'macro-dialout-trunk,s,1(1,${EXTEN},secret,off)'
        with self.assertRaisesRegex(routes.OutboundRouteError, 'pin_unsupported'): self.proof()
        self.ami = Inventory()
        self.ami.contexts['macro-dialout-trunk'][4]['AppData'] = 'PJSIP/a&PJSIP/b,30'
        with self.assertRaisesRegex(routes.OutboundRouteError, 'parallel_or_custom'): self.proof()
        self.ami = Inventory()
        self.ami.contexts['macro-dialout-trunk-predial-hook'] = [row('macro-dialout-trunk-predial-hook', 'Dial', 'PJSIP/extra')]
        with self.assertRaisesRegex(routes.OutboundRouteError, 'predial_hook'): self.proof()
    def test_nonmatching_routes_do_not_authorize_arbitrary_destinations(self):
        self.target['number'] = '+442012345678'
        self.ami.contexts['outrt-1'][0]['Extension'] = '_+1NXXNXXXXXX'
        with self.assertRaisesRegex(routes.OutboundRouteError, 'route_not_found'): self.proof()
    def test_unknown_context_and_dynamic_execution_fail_closed(self):
        for app, data in [('AGI', 'custom.php'), ('System', 'anything'), ('Set', 'OUTNUM=${SHELL(command)}'), ('Goto', 'custom,s,1')]:
            self.ami = Inventory()
            self.ami.contexts['outrt-1'].append(row('outrt-1', app, data, 2, '_+X.'))
            with self.subTest(app=app), self.assertRaises(routes.OutboundRouteError): self.proof()
    def test_incomplete_oversized_inventory_and_deadline_are_rejected(self):
        self.ami.contexts['macro-dialout-trunk'][0].pop('Priority')
        with self.assertRaisesRegex(routes.OutboundRouteError, 'malformed'): self.proof()
        self.ami = Inventory()
        with patch.object(routes, 'MAX_ROWS', 1), self.assertRaisesRegex(routes.OutboundRouteError, 'incomplete'): self.proof()
        with patch.object(routes, 'MAX_SECONDS', -1), self.assertRaisesRegex(routes.OutboundRouteError, 'timeout'): self.proof()

    def core(self):
        self.ami.contexts = json.loads((Path(__file__).parent / 'fixtures/freepbx_outbound_core.json').read_text())['contexts']
        self.ami.values.update(TRUNK_OPTIONS='TL(21600000)', PREFIX_TRUNK_1='16')

    def test_real_core_control_flow_cid_rules_headers_language_and_hook(self):
        self.core()
        proof = self.proof()
        self.assertEqual(proof['trunk_endpoints'], ['carrier'])
        self.assertEqual(proof['channel_variables']['TRUNK_OPTIONS'], 'TL(21600000)i')
        # Explicit mode reuses the same stock trunk CID/rules/header contexts.
        self.target.update(route_mode='trunk', trunk_id='1')
        self.ami.contexts.pop('outbound-allroutes')
        self.ami.contexts.pop('outrt-1')
        self.assertEqual(self.proof()['route_mode'], 'trunk')

    def test_route_order_and_timed_include_change_the_proof(self):
        self.core()
        self.ami.contexts['outrt-2'] = [row('outrt-2', 'Gosub', 'macro-dialout-trunk,s,1(1,${EXTEN},,off)', extension='_+X.')]
        self.ami.contexts['outbound-allroutes'].append({'Context':'outbound-allroutes','IncludeContext':'outrt-2,09:00-17:00,mon-fri,*,*'})
        before = self.proof()['fingerprint']
        self.ami.contexts['outbound-allroutes'][-2:] = self.ami.contexts['outbound-allroutes'][-2:][::-1]
        self.assertNotEqual(self.proof()['fingerprint'], before)
        self.ami.contexts['outbound-allroutes'][-2]['IncludeContext'] = 'outrt-2,10:00-17:00,mon-fri,*,*'
        self.assertNotEqual(self.proof()['fingerprint'], before)

    def test_branch_cannot_skip_safe_setup_or_enter_custom_trunk(self):
        self.ami.contexts['macro-dialout-trunk'][2].update(Application='Goto',AppData='5')
        with self.assertRaisesRegex(routes.OutboundRouteError, 'trunk_setup'): self.proof()
        self.core()
        first = next(r for r in self.ami.contexts['macro-dialout-trunk'] if r.get('Extension') == 's' and r['Priority'] == '1')
        first.update(Application='Goto', AppData='customtrunk')
        with self.assertRaisesRegex(routes.OutboundRouteError, 'custom_trunk_path'): self.proof()
        self.core()
        first = next(r for r in self.ami.contexts['macro-dialout-trunk'] if r.get('Extension') == 's' and r['Priority'] == '1')
        first.update(Application='ExecIf', AppData='1?Set(DIAL_TRUNK_OPTIONS=${DIAL_OPTIONS})')
        with self.assertRaisesRegex(routes.OutboundRouteError, 'assignment'): self.proof()

    def test_complete_inventory_and_ambiguous_cid_bodies_are_required(self):
        self.core()
        self.ami.contexts['macro-outbound-callerid'].pop(4)
        with self.assertRaisesRegex(routes.OutboundRouteError, 'incomplete'): self.proof()
        self.core()
        self.ami.contexts['outrt-1'].append(copy.deepcopy(self.ami.contexts['outrt-1'][0]))
        with self.assertRaisesRegex(routes.OutboundRouteError, 'ambiguous'): self.proof()

    def test_nested_helper_cannot_dial_outside_the_trunk_setup_proof(self):
        self.core()
        self.ami.contexts['macro-outbound-callerid'][0].update(Application='Gosub',AppData='trunk-dial-with-exten,${DIAL_NUMBER},1()')
        with self.assertRaisesRegex(routes.OutboundRouteError, 'trunk_setup'): self.proof()

    def test_real_connected_line_hook_does_not_authorize_arbitrary_hook_changes(self):
        self.core(); self.proof()
        hook = self.ami.contexts['macro-dialout-trunk-predial-hook']
        hook[1]['AppData'] = 'TRUNK_OPTIONS=TiU(extra)'
        with self.assertRaisesRegex(routes.OutboundRouteError, 'predial_hook'): self.proof()
        self.core()
        hook = self.ami.contexts['macro-dialout-trunk-predial-hook']
        hook[5]['AppData'] = 'DB(TRUNK/1/dialopts)=t'
        with self.assertRaisesRegex(routes.OutboundRouteError, 'predial_hook'): self.proof()

    def test_unknown_addon_callbacks_remain_explicitly_unsupported(self):
        self.core()
        self.ami.contexts['outrt-1'][0].update(Application='Gosub', AppData='sub-record-check,s,1(out,${EXTEN},dontcare)')
        with self.assertRaisesRegex(routes.OutboundRouteError, 'subroutine_unsupported'): self.proof()
        self.core()
        r = next(r for r in self.ami.contexts['macro-dialout-trunk'] if r.get('Extension') == 's')
        r.update(Application='AGI', AppData='agi://127.0.0.1/unreviewed.agi')
        with self.assertRaisesRegex(routes.OutboundRouteError, 'agi_callback_unsupported'): self.proof()
        self.core()
        r = next(r for r in self.ami.contexts['trunk-dial-with-exten'] if r.get('Application') == 'Dial')
        r['AppData'] += 'U(sub-send-obroute-email^${DIAL_NUMBER})'
        with self.assertRaisesRegex(routes.OutboundRouteError, 'dial_options_unsupported'): self.proof()

    def test_side_effects_and_confirmation_cannot_evade_proof_in_expressions(self):
        for app, data in [('NoOp', '${DB_DELETE(TRUNK/1/dialopts)}'), ('NoOp', '${${EVIL}(argument)}'),
                          ('Set', 'FORCE_CONFIRM=1'), ('NoOp', '${SHIFT(DIAL_TRUNK_OPTIONS)}')]:
            self.ami = Inventory()
            self.ami.contexts['outrt-1'].append(row('outrt-1', app, data, 2, '_+X.'))
            with self.subTest(data=data), self.assertRaises(routes.OutboundRouteError): self.proof()
        self.core(); self.ami.values['FORCE_CONFIRM'] = '1'
        with self.assertRaisesRegex(routes.OutboundRouteError, 'confirmation_callback'): self.proof()

    def callbacks(self):
        self.core()
        fixture = json.loads((Path(__file__).parent / 'fixtures/freepbx_outbound_callbacks.json').read_text())
        self.ami.contexts.update(fixture['contexts'])
        self.target.update(route_mode='trunk', trunk_id='1')
        self.addCleanup(patch.stopall)
        self.callback_reader = patch.object(routes, 'callback_file_fingerprint', side_effect=lambda name: routes.CALLBACK_FILES[name]).start()

    def test_reviewed_vendor_callbacks_keep_freepbx_execution_and_single_trunk(self):
        self.callbacks()
        value = self.proof()
        self.assertEqual(value['trunk_endpoints'], ['carrier'])
        self.assertEqual(value['channel_variables'], {'TRUNK_OPTIONS':'TL(21600000)i'})
        self.assertEqual({call.args[0] for call in self.callback_reader.call_args_list}, set(routes.CALLBACK_FILES))
        self.assertIn('AGI', {r.get('Application') for r in self.ami.contexts['macro-dialout-trunk']})

    def test_callback_name_cannot_authorize_changed_control_flow_or_custom_include(self):
        self.callbacks()
        original = copy.deepcopy(self.ami.contexts)
        for context in routes.CALLBACK_CONTEXTS:
            self.ami.contexts = copy.deepcopy(original)
            body = next(r for r in self.ami.contexts[context] if 'Application' in r)
            body.update(Application='Dial', AppData='PJSIP/extra')
            with self.subTest(context=context), self.assertRaisesRegex(routes.OutboundRouteError, 'callback_context_changed'):
                self.proof()
        self.ami.contexts = copy.deepcopy(original)
        self.ami.contexts['crm-hangup-custom'] = [row('crm-hangup-custom','NoOp','custom')]
        with self.assertRaisesRegex(routes.OutboundRouteError, 'custom_context'): self.proof()

    def test_callback_code_must_be_reviewed_at_every_admission(self):
        self.callbacks(); self.proof()
        self.callback_reader.side_effect = routes.OutboundRouteError('outbound_callback_version_unsupported')
        with self.assertRaisesRegex(routes.OutboundRouteError, 'callback_version'): self.proof()

    def test_extra_agi_arguments_handlers_and_active_confirmation_are_rejected(self):
        self.callbacks(); original = copy.deepcopy(self.ami.contexts)
        for application, text in [('AGI','agi://127.0.0.1/sangomacrm.agi,true'),
                                  ('AGI','agi://external.invalid/sangomacrm.agi'),
                                  ('Set','CHANNEL(hangup_handler_push)=custom,s,1'),
                                  ('Set','PBXMFA=1')]:
            self.ami.contexts=copy.deepcopy(original)
            self.ami.contexts['macro-dialout-trunk'][0].update(Application=application,AppData=text)
            with self.subTest(text=text),self.assertRaises(routes.OutboundRouteError):self.proof()
        self.ami.contexts=copy.deepcopy(original)
        for name in ['PBXMFA','FORCE_CONFIRM']:
            self.ami.values[name]='1'
            with self.subTest(name=name),self.assertRaises(routes.OutboundRouteError):self.proof()
            self.ami.values[name]=''


class CallbackFileTests(unittest.TestCase):
    def test_exact_bytes_and_safe_files_required_without_execution(self):
        with tempfile.TemporaryDirectory(prefix='sls-callback-') as directory:
            base=Path(directory); path=base/'fixture.agi'; body=b'fixture-only; never execute\n'
            path.write_bytes(body); path.chmod(0o640)
            digest=hashlib.sha256(body).hexdigest()
            with patch.object(routes,'AGI_DIRECTORY',base),patch.object(routes,'CALLBACK_FILES',{'fixture.agi':digest}):
                self.assertEqual(routes.callback_file_fingerprint('fixture.agi'),digest)
                if os.geteuid() == 0:
                    group=pwd.getpwnam('asterisk').pw_gid
                    os.chown(base,-1,group);base.chmod(0o775)
                    self.assertEqual(routes.callback_file_fingerprint('fixture.agi'),digest)
                    os.chown(base,-1,group+1)
                    with self.assertRaisesRegex(routes.OutboundRouteError,'unsafe'):routes.callback_file_fingerprint('fixture.agi')
                    base.chmod(0o700)
                path.write_bytes(body+b'changed')
                with self.assertRaisesRegex(routes.OutboundRouteError,'version'):routes.callback_file_fingerprint('fixture.agi')
                path.write_bytes(body);path.chmod(0o666)
                with self.assertRaisesRegex(routes.OutboundRouteError,'unsafe'):routes.callback_file_fingerprint('fixture.agi')
                path.chmod(0o640);linked=base/'link';os.link(path,linked)
                with self.assertRaisesRegex(routes.OutboundRouteError,'unsafe'):routes.callback_file_fingerprint('fixture.agi')
                path.unlink();path.symlink_to(linked)
                with self.assertRaises(routes.OutboundRouteError):routes.callback_file_fingerprint('fixture.agi')
                path.unlink();os.mkfifo(path)
                with self.assertRaisesRegex(routes.OutboundRouteError,'unsafe'):routes.callback_file_fingerprint('fixture.agi')


class AgiRoutePolicyTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-outbound-agi-')
        self.addCleanup(self.temp.cleanup)
        self.store = phone.PhoneAdmissionStore(Path(self.temp.name), clock=lambda: 1000.0)
        self.store.collector_heartbeat('a' * 32)
        self.target = {'id':'voice_'+'a'*24,'number':'+15551234567','route_mode':'pbx_routes','trunk_id':'','caller_id':''}
        self.ami = Inventory(); self.ami.values['TRUNK_OPTIONS'] = 'TL(21600000)'
        proof = routes.validate_route(self.ami, self.target)
        self.token = self.store.admit({self.target['id']:['VOICE/'+self.target['number']]},25,
            {'complete':True,'started_tick':1000.0,'generation':'a'*32,'boot_id':self.store.boot_id,'channels':[]},service='announcement',
            outbound={self.target['id']:{'target':self.target,'proof':proof}})
        spec = importlib.util.spec_from_file_location('outbound_agi_fixture', Path(__file__).resolve().parents[1]/'slsmassnotifyserver/bin/sls_mass_notify_phone_agi.py')
        self.module = importlib.util.module_from_spec(spec); spec.loader.exec_module(self.module)
        target = self.target; token = self.token
        class FakeAgi:
            environment = {'agi_uniqueid':'1.1','agi_channel':'Local/'+target['id']+'@sls-outbound-voice-fixture;2'}
            def __init__(self): self.writes = {}
            def variable(self,name): return {'${SLS_PHONE_TOKEN}':token,'${SLS_PHONE_RECIPIENT}':target['id']}.get(name,'')
            def set(self,name,value): self.writes[name] = value
        self.agi = FakeAgi()
        settings = {'outbound_voice':{'enabled':True,'route_mode':'pbx_routes','trunk_id':'','caller_id':'',
                    'recipients':[{'id':target['id'],'number':target['number'],'enabled':True}]}}
        self.addCleanup(patch.stopall)
        patch.object(self.module,'read_settings',return_value=settings).start()
        fake = patch.object(self.module,'PhoneAmi').start()
        fake.return_value.__enter__.return_value = self.ami

    def test_call_local_policy_applied_after_current_proof_without_global_mutation(self):
        self.assertTrue(self.module.handle('outbound',self.agi,self.store))
        self.assertEqual(self.agi.writes['TRUNK_OPTIONS'],'TL(21600000)i')
        self.assertEqual(self.ami.values['TRUNK_OPTIONS'],'TL(21600000)')
        self.assertEqual(set(self.agi.writes), {'SLS_PHONE_ALLOW','CHANNEL(accountcode)','TRUNK_OPTIONS',
            'SLS_OUTBOUND_ROUTE_MODE','SLS_OUTBOUND_TRUNK_ID','SLS_OUTBOUND_NUMBER','SLS_OUTBOUND_CALLER_ID'})

    def test_changed_route_refused_before_any_options_or_number_write(self):
        self.ami.values['OUTPREFIX_1'] = '9'
        with self.assertRaisesRegex(phone.PhoneAdmissionError,'outbound_route_changed'):
            self.module.handle('outbound',self.agi,self.store)
        self.assertEqual(self.agi.writes['SLS_PHONE_ALLOW'],'0')
        self.assertNotIn('TRUNK_OPTIONS',self.agi.writes)
        self.assertNotIn('SLS_OUTBOUND_NUMBER',self.agi.writes)

    def test_policy_schema_never_accepts_global_or_database_setters(self):
        for values in ({'GLOBAL(TRUNK_OPTIONS)':'Ti'}, {'DB(TRUNK/1/dialopts)':'Ti'},
                       {'TRUNK_OPTIONS':'Ti','EXTRA':'value'}, {'TRUNK_OPTIONS':'Tit'}, {'TRUNK_OPTIONS':'T'}):
            with self.subTest(values=values), self.assertRaises(routes.OutboundRouteError):
                routes.channel_trunk_options({'channel_variables':values})


class MixedAudienceTests(unittest.TestCase):
    def test_exhausted_budget_rejects_only_external_calls_without_rewinding_counter(self):
        with tempfile.TemporaryDirectory(prefix='sls-budget-admission-') as directory:
            root=Path(directory);store=phone.PhoneAdmissionStore(root);store.collector_heartbeat('a'*32)
            with store.locked() as state: store.reserve_outbound_budget(state,1,1,time.time())
            class Ami(Inventory):
                def __enter__(self): return self
                def __exit__(self,*args): pass
                def contacts(self,extensions): return {ext:['PJSIP/'+ext+'/sip:fixture@192.0.2.1'] for ext in extensions}
                def inventory(self,generation,boot): return {'complete':True,'started_tick':time.monotonic(),'generation':generation,'boot_id':boot,'channels':[]}
            target={'id':'voice_'+'a'*24,'number':'+15551234567','route_mode':'pbx_routes','trunk_id':'','caller_id':'',
                    'acknowledgement_required':True,'ack_timeout_seconds':10}
            settings={'phone_device_limit':25,'outbound_voice':{'enabled':True,'daily_call_limit':1,'route_mode':'pbx_routes','caller_id':'',
                        'recipients':[{'id':target['id'],'number':target['number'],'enabled':True}]}}
            result=phone.admit_audience(['1000'],outbound=[target],service='announcement',directory=root,
                                       settings=settings,ami_factory=lambda _:Ami(),wait_seconds=0)
            self.assertEqual(result['targets'],['1000']);self.assertEqual(result['outbound_targets'],[])
            self.assertEqual(result['outbound_failures'][0]['failure_code'],'outbound_call_budget_exceeded')
            state=json.loads((root/'phone-admission.json').read_text());self.assertEqual(state['outbound_budget']['used'],1)
            self.assertNotIn(target['number'],json.dumps(state))

    def test_refused_external_route_does_not_block_internal_or_valid_external_calls(self):
        with tempfile.TemporaryDirectory(prefix='sls-mixed-admission-') as directory:
            root = Path(directory)
            store = phone.PhoneAdmissionStore(root)
            store.collector_heartbeat('a' * 32)
            class Ami(Inventory):
                def __enter__(self): return self
                def __exit__(self, *args): pass
                def contacts(self, extensions):
                    return {extension: ['PJSIP/' + extension + '/sip:fixture@192.0.2.1'] for extension in extensions}
                def inventory(self, generation, boot):
                    return {'complete': True, 'started_tick': time.monotonic(), 'generation': generation, 'boot_id': boot, 'channels': []}
            ami = Ami()
            good = {'id': 'voice_' + 'a' * 24, 'number': '+15551234567', 'route_mode': 'pbx_routes', 'trunk_id': '', 'caller_id': ''}
            bad = {**good, 'id': 'voice_' + 'b' * 24, 'number': '+15557654321'}
            settings = {'phone_device_limit': 25, 'outbound_voice': {'enabled': True, 'route_mode': 'pbx_routes', 'caller_id': '',
                        'recipients': [{**row, 'enabled': True} for row in (good, bad)]}}
            actual = routes.validate_route
            def verify(ami, target):
                if target['id'] == bad['id']:
                    raise routes.OutboundRouteError('outbound_subroutine_unsupported')
                return actual(ami, target)
            with patch.object(routes, 'validate_route', side_effect=verify):
                result = phone.admit_audience(['1000'], outbound=[bad, good], service='announcement', directory=root,
                                              settings=settings, ami_factory=lambda settings: ami, wait_seconds=0)
            self.assertEqual(result['targets'], ['1000'])
            self.assertEqual(result['outbound_targets'], [good['id']])
            self.assertEqual([row['id'] for row in result['outbound_failures']], [bad['id']])
            state = json.loads((root / 'phone-admission.json').read_text())
            self.assertEqual(set(state['batches'][result['token']]['targets']), {'1000', good['id']})
            self.assertNotIn(bad['number'], json.dumps(state))
            with patch.object(routes, 'validate_route', side_effect=verify), self.assertRaisesRegex(phone.PhoneAdmissionError, 'outbound_subroutine_unsupported'):
                phone.admit_audience([], outbound=[bad], service='announcement', directory=root, settings=settings,
                                     ami_factory=lambda settings: ami, wait_seconds=0)

if __name__ == '__main__': unittest.main()
