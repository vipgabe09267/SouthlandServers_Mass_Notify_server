#!/usr/bin/env python3
"""Fixture-only SIP submission, route, fallback, and safe logging regressions."""

import contextlib
import configparser
import importlib.util
import io
import json
import logging
import os
from pathlib import Path
import tempfile
import threading
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]
SOURCE = Path(os.environ.get(
    "SLS_NOTIFY_TEST_SOURCE",
    str(ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py"),
))
SPEC = importlib.util.spec_from_file_location("sls_notify_status_test", SOURCE)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class FixtureAmi:
    def __init__(self, responses=None):
        self.responses = list(responses or [])
        self.actions = []

    def action(self, fields, complete_event=None):
        self.actions.append(fields)
        response = self.responses.pop(0) if self.responses else {"Response": "Success", "Message": "NOTIFY sent"}
        if isinstance(response, Exception):
            raise response
        return response, []


def endpoint(contacts):
    formats = list(dict.fromkeys(contact["format"] for contact in contacts))
    return {"format": formats[0] if formats else "yealink", "formats": formats, "contacts": contacts}


def contact(fmt="yealink", uri="sip:1000@192.0.2.10:5061;transport=TLS"):
    return {"format": fmt, "contact": uri, "user_agent": "fixture"}


class SipSubmissionTests(unittest.TestCase):
    def setUp(self):
        # Any accidental live transport/capability probe fails this fixture suite.
        self.socket_guard = mock.patch.object(MODULE.socket, "create_connection", side_effect=AssertionError("live socket forbidden"))
        self.cli_guard = mock.patch.object(MODULE.subprocess, "run", side_effect=AssertionError("live CLI forbidden"))
        self.socket_guard.start()
        self.cli_guard.start()
        self.visual_state = tempfile.TemporaryDirectory(prefix='sls-notify-visual-state-')
        self.visual_guard = mock.patch.object(MODULE, 'PHONE_VISUAL_STATE', Path(self.visual_state.name) / 'state')
        self.visual_guard.start()
        self.addCleanup(self.socket_guard.stop)
        self.addCleanup(self.cli_guard.stop)
        self.addCleanup(self.visual_guard.stop)
        self.addCleanup(self.visual_state.cleanup)

    def batch(self, ami, info, capable=True, builder=None, unavailable_targets=None, **extra):
        output = io.StringIO()
        error = None
        legacy_count = None
        with mock.patch.object(MODULE, "pjsip_notify_capabilities", return_value={
            "contact_uri_usable": capable, "routing_mode": "contact_uri" if capable else "endpoint_fanout",
        }) as capabilities, contextlib.redirect_stdout(output), self.assertLogs(level=logging.INFO) as logs:
            try:
                legacy_count = MODULE.send_notify_batch(
                    ami, info, builder or (lambda fmt: f"<payload vendor='{fmt}'/>"),
                    "fixture-alert-123", print_results=True,
                    unavailable_targets=unavailable_targets,
                    **extra,
                )
            except MODULE.NotifyBatchError as exc:
                error = exc
        if extra.get('strict_contacts'):
            self.assertEqual(capabilities.call_count,0,'Strict live paging ran unbounded capability CLI probes')
        lines = output.getvalue().splitlines()
        summaries = [json.loads(line.split(" ", 1)[1]) for line in lines if line.startswith("SLS_NOTIFY_RESULT ")]
        self.assertEqual(len(summaries), 1)
        summary = summaries[0]
        self.assertEqual(summary["alert_id"], "fixture-alert-123")
        self.assertFalse(summary["handset_delivery_confirmed"])
        if error is not None:
            self.assertEqual(error.result, summary)
        return summary, error, legacy_count, output.getvalue() + "\n".join(logs.output)

    def test_homogeneous_contacts_log_endpoint_even_when_uri_capable(self):
        ami = FixtureAmi()
        summary, error, count, _ = self.batch(ami, {"1000": endpoint([
            contact(), contact(uri="sip:1000@192.0.2.11:5061;transport=TLS"),
        ])})
        self.assertIsNone(error)
        self.assertEqual(count, 2)  # Backwards-compatible coverage, not delivered phones.
        self.assertEqual(len(ami.actions), 1)
        self.assertEqual(ami.actions[0]["Endpoint"], "1000")
        self.assertNotIn("URI", ami.actions[0])
        self.assertEqual(summary["status"], "submitted_to_asterisk")
        self.assertEqual(summary["submitted_endpoint_targets"], 1)
        self.assertEqual(summary["submitted_contact_targets"], 0)
        target = summary["targets"][0]
        self.assertEqual(target["actual_route"], "endpoint")
        self.assertEqual(target["target_field"], "Endpoint")
        self.assertEqual(target["registered_contacts"], 2)

    def test_connected_scope_always_uses_exact_uri_without_endpoint_fallback(self):
        for capable in (True,False):
            with self.subTest(capable=capable):
                ami=FixtureAmi(None if capable else [{'Response':'Error','Message':'URI target unavailable'}])
                summary,error,count,_=self.batch(ami,{'1000':dict(endpoint([contact()]),all_registered_contacts=2)},capable=capable,strict_contacts=True)
                if capable:
                    self.assertIsNone(error);self.assertEqual(ami.actions[0]['URI'],contact()['contact'])
                    self.assertNotIn('Endpoint',ami.actions[0])
                else:
                    self.assertIsNotNone(error);self.assertEqual(len(ami.actions),1)
                    self.assertIn('URI',ami.actions[0]);self.assertNotIn('Endpoint',ami.actions[0])
                    self.assertEqual(summary['status'],'failed')

    def test_later_join_cannot_reclaim_a_newer_popup_owner(self):
        owner='a'*32
        with MODULE.phone_visual_owner('1000',owner) as lease:lease.commit()
        with MODULE.phone_visual_owner('1000') as lease:lease.commit()
        ami=FixtureAmi()
        summary,error,count,_=self.batch(ami,{'1000':endpoint([contact()])},strict_contacts=True,
            visual_owner=owner,continuation_extensions={'1000'})
        self.assertIsNone(error);self.assertEqual(ami.actions,[])
        self.assertEqual(summary['status'],'no_targets')

    def test_live_submission_guard_stops_before_notify_action(self):
        ami=FixtureAmi()
        with mock.patch.object(MODULE,'pjsip_notify_capabilities',return_value={'contact_uri_usable':True}):
            with self.assertRaisesRegex(RuntimeError,'no longer permits'):
                MODULE.send_notify_batch(ami,{'1000':endpoint([contact()])},lambda fmt:'<fixture/>','fixture',
                    strict_contacts=True,submission_guard=lambda:False)
        self.assertEqual(ami.actions,[])

    def test_live_contact_cli_is_cumulative_guarded_and_one_second_per_probe(self):
        now=[100.0]
        def stalled(argv,**kwargs):
            self.assertLessEqual(kwargs['timeout'],1)
            now[0]+=kwargs['timeout']
            raise MODULE.subprocess.TimeoutExpired('private-fixture',kwargs['timeout'])
        with mock.patch.object(MODULE.time,'monotonic',side_effect=lambda:now[0]),mock.patch.object(MODULE.subprocess,'run',side_effect=stalled) as cli:
            with self.assertRaisesRegex(RuntimeError,'inventory lease expired'):
                MODULE.asterisk_contact_uri_inventory(['1000','1001'],max_seconds=8,submission_guard=lambda:now[0]<100.5)
        self.assertEqual(cli.call_count,1);self.assertEqual(now[0],101)

    def test_live_coordination_uses_remaining_budget_and_preserves_uncertain_ami_result(self):
        ami=FixtureAmi();now=[100.0]
        def begin(*args,**kwargs):
            self.assertAlmostEqual(kwargs['timeout'],.1)
            now[0]=101.0
            return {'id':'private-claim'}
        with mock.patch.object(MODULE.time,'monotonic',side_effect=lambda:now[0]),mock.patch.object(MODULE._cluster_guard,'begin',side_effect=begin),mock.patch.object(MODULE._cluster_guard,'finish',side_effect=[MODULE.subprocess.TimeoutExpired('private-helper',.05),None]) as finish:
            with self.assertRaises(MODULE.NotifySubmissionError) as error:
                MODULE.send_notify(ami,contact()['contact'],'<fixture/>','yealink','URI',coordination_deadline=100.1)
        self.assertEqual(len(ami.actions),1,'Uncertain receipt caused a repeated SIP submission')
        self.assertEqual(finish.call_count,2)
        self.assertFalse(finish.call_args_list[0].kwargs['uncertain'])
        self.assertTrue(finish.call_args_list[1].kwargs['uncertain'])
        self.assertTrue(all(call.kwargs['timeout']==.05 for call in finish.call_args_list))
        self.assertEqual(error.exception.details['status'],'failed')

    def test_cluster_helper_preserves_default_timeout_and_accepts_bounded_override(self):
        guard=MODULE._cluster_guard
        result=mock.Mock(returncode=0,stdout=b'{"ok":true}')
        with mock.patch.object(guard.subprocess,'run',return_value=result) as process:
            guard._call({'action':'fixture'});self.assertEqual(process.call_args.kwargs['timeout'],14)
            guard._call({'action':'fixture'},timeout=2);self.assertEqual(process.call_args.kwargs['timeout'],2)
            for invalid in [True,0,-1,15,float('nan')]:
                with self.assertRaises(guard.ClusterFenced):guard._call({'action':'fixture'},timeout=invalid)
            self.assertEqual(process.call_count,2)

    def test_page_cleanup_and_queued_new_alert_share_a_permanent_mutex(self):
        owner='c'*32
        with MODULE.phone_visual_owner('1000',owner) as lease:lease.commit()
        state=MODULE.PHONE_VISUAL_STATE
        mutex=state/'1000.json.lock';inode=mutex.stat().st_ino
        waiter_opened=threading.Event();results=[];errors=[]
        real_open=MODULE.os.open
        def tracked_open(name,*args,**kwargs):
            fd=real_open(name,*args,**kwargs)
            if threading.current_thread().name=='queued-alert' and str(name) in ('1000.json','1000.json.lock'):
                waiter_opened.set()
            return fd
        def newer_alert():
            try:
                with MODULE.phone_visual_owner('1000') as lease:
                    results.append(bool(lease));lease.commit()
            except Exception as error:errors.append(type(error).__name__)
        with mock.patch.object(MODULE.os,'open',side_effect=tracked_open):
            with MODULE.phone_visual_owner('1000',expected_owner=owner) as lease:
                worker=threading.Thread(target=newer_alert,name='queued-alert');worker.start()
                self.assertTrue(waiter_opened.wait(1),'Concurrent alert did not reach the mutex')
                self.assertEqual(results,[]);lease.commit()
            worker.join(2)
        self.assertFalse(worker.is_alive());self.assertEqual(errors,[]);self.assertEqual(results,[True])
        self.assertEqual(mutex.stat().st_ino,inode);self.assertEqual(mutex.read_bytes(),b'')
        with MODULE.phone_visual_owner('1000',expected_owner=owner) as lease:self.assertFalse(lease)

    def test_visual_mutex_rejects_links_and_public_permissions(self):
        state=MODULE.PHONE_VISUAL_STATE;state.mkdir(mode=0o750)
        victim=state/'victim';victim.write_bytes(b'');victim.chmod(0o640)
        lock=state/'1000.json.lock'
        for kind in ('symlink','hardlink','public'):
            with self.subTest(kind=kind):
                if kind=='symlink':lock.symlink_to(victim)
                elif kind=='hardlink':os.link(victim,lock)
                else:lock.write_bytes(b'');lock.chmod(0o644)
                try:
                    with self.assertRaises(OSError):
                        with MODULE.phone_visual_owner('1000'):pass
                    self.assertEqual(victim.read_bytes(),b'')
                finally:lock.unlink()

    def test_mixed_contacts_preserve_exact_uri_transports_and_vendor_payloads(self):
        contacts = [contact(), contact("poly", "sip:1000@192.0.2.11:5060;transport=TCP"),
                    contact("generic", "sips:1000@[2001:db8::10]:5061;transport=TLS")]
        ami = FixtureAmi()
        summary, error, count, _ = self.batch(ami, {"1000": endpoint(contacts)})
        self.assertIsNone(error)
        self.assertEqual(count, 3)
        self.assertEqual(summary["submitted_contact_targets"], 3)
        for action, source, target in zip(ami.actions, contacts, summary["targets"]):
            self.assertEqual(action["URI"], source["contact"])
            self.assertNotIn("Endpoint", action)
            self.assertIn(f"Content=<payload vendor='{source['format']}'/>", action["Variable"])
            self.assertEqual(target["actual_route"], "contact_uri")
            self.assertEqual(target["target_field"], "URI")

    def test_same_extension_routes_yealink_acrobits_and_grandstream_individually(self):
        agents = ['Yealink SIP-T48G', 'Acrobits Groundwire', 'Grandstream GXP2170']
        formats = [MODULE.detect_phone_format(agent, '6767') for agent in agents]
        self.assertEqual(formats, ['yealink', 'unknown', 'grandstream'])
        contacts = [dict(contact(fmt, 'sip:6767@192.0.2.'+str(10+i)+':5060'), user_agent=agent)
                    for i, (fmt, agent) in enumerate(zip(formats, agents))]
        ami = FixtureAmi()
        summary, error, count, _ = self.batch(ami, {'6767': endpoint(contacts)})
        self.assertIsNone(error)
        self.assertEqual(count, 3)
        self.assertEqual(summary['submitted_contact_targets'], 3)
        for action, original in zip(ami.actions, contacts):
            self.assertEqual(action['URI'], original['contact'])
            self.assertNotIn('Endpoint', action)
            self.assertIn("Content=<payload vendor='"+original['format']+"'/>", action['Variable'])

    def test_mixed_unsupported_uri_uses_original_generic_fallback(self):
        ami = FixtureAmi()
        summary, error, count, _ = self.batch(ami, {"1000": endpoint([
            contact(), contact("poly", "sip:1000@192.0.2.11:5060;transport=UDP"),
        ])}, capable=False)
        self.assertIsNone(error)
        self.assertEqual(count, 2)
        self.assertEqual(len(ami.actions), 1)
        self.assertIn("Content=<payload vendor='generic'/>", ami.actions[0]["Variable"])
        target = summary["targets"][0]
        self.assertEqual(target["requested_route"], "contact_uri")
        self.assertEqual(target["actual_route"], "endpoint")
        self.assertEqual(target["requested_formats"], ["poly", "yealink"])
        self.assertEqual(target["format"], "generic")
        self.assertEqual(target["fallback_reason"], "mixed_formats_uri_route_unavailable")
        self.assertEqual(target["fallback_outcome"], "submitted_to_asterisk")

    def test_incomplete_uri_inventory_is_not_logged_as_contact_route(self):
        summary, error, _, _ = self.batch(FixtureAmi(), {"1000": endpoint([contact(), contact("poly", "")])})
        self.assertIsNone(error)
        target = summary["targets"][0]
        self.assertEqual(target["actual_route"], "endpoint")
        self.assertEqual(target["fallback_reason"], "mixed_formats_incomplete_contact_uris")
        self.assertEqual(target["resolved_contact_uris"], 1)

    def test_partial_contacts_are_not_all_failed_or_confirmed_delivered(self):
        ami = FixtureAmi([{"Response": "Success"}, {"Response": "Error"}, {"Response": "Error"}])
        summary, error, _, output = self.batch(ami, {"1000": endpoint([
            contact(), contact("poly", "sip:1000@192.0.2.11:5060;transport=TCP"),
        ])})
        self.assertIsNotNone(error)
        self.assertIn("partial submission", str(error))
        self.assertEqual(summary["status"], "partial")
        self.assertEqual(summary["submitted_contact_targets"], 1)
        self.assertEqual(summary["failed_contact_targets"], 1)
        self.assertEqual(summary["ami_attempts"], 3)
        self.assertTrue(all("URI" in action and "Endpoint" not in action for action in ami.actions))
        self.assertNotIn("delivered", output)

    def test_ami_messages_and_extra_credentials_never_reach_logs(self):
        response = {"Response": "Error", "Message": "Permission denied password=ami-secret token=private-token\nINJECTED",
                    "Secret": "other-secret", "Authorization": "Bearer another-secret"}
        summary, error, _, output = self.batch(FixtureAmi([response]), {"1000": endpoint([contact()])})
        self.assertIsNotNone(error)
        self.assertEqual(summary["status"], "failed")
        self.assertEqual(summary["targets"][0]["ami_response"]["message_category"], "authorization_failed")
        for secret in ("ami-secret", "private-token", "INJECTED", "other-secret", "another-secret"):
            self.assertNotIn(secret, output + str(error))

    def test_uri_credentials_are_redacted_only_in_observability(self):
        secret_uri = "sip:1000:contact-password@192.0.2.10:5061;transport=TLS"
        ami = FixtureAmi([{"Response": "Error"}, {"Response": "Success"}])
        summary, error, _, output = self.batch(ami, {"1000": endpoint([
            contact(uri=secret_uri), contact("generic", "sip:1000@192.0.2.11:5060"),
        ])})
        self.assertIsNotNone(error)
        self.assertEqual(ami.actions[0]["URI"], secret_uri)
        self.assertNotIn("contact-password", output + str(error))
        self.assertIn("[redacted]", summary["targets"][0]["target"])

    def test_vendor_event_fallback_is_recorded_without_changing_event_order(self):
        ami = FixtureAmi([{"Response": "Error"}, {"Response": "Success"}])
        summary, error, _, _ = self.batch(ami, {"1000": endpoint([contact("poly")])})
        self.assertIsNone(error)
        self.assertEqual([action["Variable"][0] for action in ami.actions], ["Event=polycom-push", "Event=xml"])
        self.assertEqual(summary["targets"][0]["event_fallback_outcome"], "submitted_to_asterisk")
        self.assertEqual(summary["ami_attempts"], 2)

    def test_transport_failure_does_not_retry_or_expose_exception(self):
        ami = FixtureAmi([OSError("socket exception private-password")])
        summary, error, _, output = self.batch(ami, {"1000": endpoint([contact("poly")])})
        self.assertIsNotNone(error)
        self.assertEqual(len(ami.actions), 1)
        self.assertEqual(summary["targets"][0]["ami_response"]["message_category"], "ami_action_error")
        self.assertNotIn("private-password", output + str(error))

    def test_no_explicit_success_is_failed(self):
        summary, error, _, _ = self.batch(FixtureAmi([{"Message": "request accepted"}]), {"1000": endpoint([contact()])})
        self.assertIsNotNone(error)
        self.assertEqual(summary["status"], "failed")
        self.assertEqual(summary["targets"][0]["ami_response"]["message_category"], "no_explicit_success")

    def test_payload_failure_is_safe_and_does_not_send(self):
        ami = FixtureAmi()
        def broken_builder(fmt):
            raise RuntimeError("private-payload-content")
        summary, error, _, output = self.batch(ami, {"1000": endpoint([contact()])}, builder=broken_builder)
        self.assertIsNotNone(error)
        self.assertFalse(ami.actions)
        self.assertEqual(summary["targets"][0]["error_category"], "payload_build_failed")
        self.assertIsNone(summary["targets"][0]["ami_response"])
        self.assertEqual(summary["targets"][0]["actual_route"], "none")
        self.assertIsNone(summary["targets"][0]["target_field"])
        self.assertNotIn("private-payload-content", output + str(error))

    def test_result_target_details_are_bounded_without_losing_totals(self):
        ami = FixtureAmi()
        summary, error, _, _ = self.batch(ami, {str(index): endpoint([]) for index in range(1001)})
        self.assertIsNone(error)
        self.assertEqual(summary["submitted_targets"], 1001)
        self.assertEqual(summary["ami_attempts"], 1001)
        self.assertEqual(len(summary["targets"]), 1000)
        self.assertTrue(summary["targets_truncated"])

    def test_empty_batch_has_no_delivery_claim(self):
        ami = FixtureAmi()
        summary, error, count, _ = self.batch(ami, {})
        self.assertIsNone(error)
        self.assertEqual(summary["status"], "no_targets")
        self.assertEqual(count, 0)
        self.assertEqual(ami.actions, [])

    def test_unavailable_requested_subset_is_partial_without_rerouting(self):
        ami = FixtureAmi()
        summary, error, _, _ = self.batch(ami, {"1000": endpoint([contact()])}, unavailable_targets=["1001"])
        self.assertIsNotNone(error)
        self.assertEqual(summary["status"], "partial")
        self.assertEqual(summary["unavailable_targets"], 1)
        self.assertEqual(summary["submitted_targets"], 1)
        self.assertEqual(summary["failed_targets"], 1)
        self.assertEqual(len(ami.actions), 1)
        self.assertEqual(ami.actions[0]["Endpoint"], "1000")
        unavailable = summary["targets"][1]
        self.assertEqual(unavailable["extension"], "1001")
        self.assertEqual(unavailable["actual_route"], "none")
        self.assertIsNone(unavailable["target_field"])
        self.assertIsNone(unavailable["ami_response"])

    def push_context(self, ami):
        stack = contextlib.ExitStack()
        self.addCleanup(stack.close)
        client = stack.enter_context(mock.patch.object(MODULE, "AmiClient"))
        client.return_value.__enter__.return_value = ami
        stack.enter_context(mock.patch.object(MODULE, "get_registered_endpoint_info", return_value={"1000": endpoint([contact()])}))
        stack.enter_context(mock.patch.object(MODULE, "endpoint_format_overrides", return_value={}))
        stack.enter_context(mock.patch.object(MODULE, "pjsip_notify_capabilities", return_value={"contact_uri_usable": True}))
        stack.enter_context(mock.patch.object(MODULE, "build_xml", return_value="<YealinkIPPhoneTextScreen/>"))
        stack.enter_context(mock.patch.object(MODULE, "alert_api_record", return_value={"id": "fixture-alert"}))
        stack.enter_context(mock.patch.object(MODULE, "announcement_api_record", return_value={"id": "fixture-announcement"}))
        publish = stack.enter_context(mock.patch.object(MODULE, "append_sipnotify_event"))
        stack.enter_context(self.assertLogs(level=logging.INFO))
        config = configparser.ConfigParser(interpolation=None)
        config.read_dict({"ami": {}, "visual": {"retry_delays": "1,2"}})
        return config, publish

    def test_missing_alert_subset_preserves_retries_and_desktop_publication(self):
        ami = FixtureAmi()
        config, publish = self.push_context(ami)
        with mock.patch.object(MODULE.time, "sleep") as sleep:
            with self.assertRaisesRegex(RuntimeError, "partial submission"):
                MODULE.push_alert(config, {"id": "fixture-alert", "properties": {}},
                                  targets=["1000", "1001"], desktop_targets=["fixture-desktop"])
        self.assertEqual(len(ami.actions), 3)
        self.assertEqual([action["Endpoint"] for action in ami.actions], ["1000"] * 3)
        self.assertEqual(sleep.call_args_list, [mock.call(1), mock.call(2)])
        publish.assert_called_once()

    def test_missing_announcement_subset_preserves_desktop_publication(self):
        ami = FixtureAmi()
        config, publish = self.push_context(ami)
        with self.assertRaisesRegex(RuntimeError, "partial submission"):
            MODULE.push_announcement(config, "fixture", ["1000", "1001"], print_results=False,
                                     desktop_targets=["fixture-desktop"])
        self.assertEqual(len(ami.actions), 1)
        publish.assert_called_once()

    def test_manual_require_all_targets_still_rejects_before_any_send(self):
        ami = FixtureAmi()
        config, publish = self.push_context(ami)
        with self.assertRaisesRegex(RuntimeError, "Requested phone endpoints are not registered/reachable"):
            MODULE.push_alert(config, {"id": "fixture-alert", "properties": {}},
                              targets=["1000", "1001"], desktop_targets=["fixture-desktop"],
                              require_all_targets=True)
        self.assertEqual(ami.actions, [])
        publish.assert_not_called()

    def test_radio_profiles_remain_audio_only_for_alert_and_announcement_producers(self):
        settings={'enterprise_labs':{'enabled':True},'enterprise_integrations':{'enabled':'1','radios':{'enabled':'1','devices':[
            {'extension':'1000','enabled':'1'}, {'extension':'1001','enabled':'0'}]}}}
        self.assertEqual(MODULE.radio_gateway_extensions(settings),['1000'])
        for enabled in [False,'1',1,None]:
            with self.subTest(labs=enabled):
                self.assertEqual(MODULE.radio_gateway_extensions(dict(settings,enterprise_labs={'enabled':enabled})),[])
        for method in ('alert','announcement'):
            with self.subTest(method=method):
                ami=FixtureAmi(); config,publish=self.push_context(ami)
                config['visual']['audio_only_extensions']='1000'
                if method=='alert':
                    MODULE.push_alert(config,{'id':'fixture-radio-alert','properties':{}},targets=['1000'],desktop_targets=['fixture-desktop'],retries=False)
                else:
                    self.assertEqual(MODULE.push_announcement(config,'Fixture',['1000'],print_results=False,desktop_targets=['fixture-desktop']),[])
                self.assertEqual(ami.actions,[])
                publish.assert_called_once()
        config['visual']['audio_only_extensions']='../1000'
        with self.assertRaises(ValueError):MODULE.audio_only_visual_exclusions(config)

    def test_ami_login_error_does_not_echo_secret(self):
        ami = MODULE.AmiClient("127.0.0.1", 5038, "fixture", "not-a-real-password")
        sock = mock.Mock()
        sock.recv.return_value = b'Asterisk Call Manager/5.0\r\n'
        ami.action = mock.Mock(return_value=({"Response": "Error", "Message": "Authentication failed secret=do-not-log"}, []))
        with mock.patch.object(MODULE.socket, "create_connection", return_value=sock):
            with self.assertRaisesRegex(RuntimeError, "AMI login failed: authorization_failed") as caught:
                ami.connect()
        self.assertNotIn("do-not-log", str(caught.exception))


if __name__ == "__main__":
    unittest.main()
