#!/usr/bin/python3
"""Actual SIP dispatcher, ownership races and quiet page completion; no phones."""
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('visual_sender',ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py')
sender=importlib.util.module_from_spec(spec); spec.loader.exec_module(sender)

class VisualOwnerTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.state=Path(self.temp.name)/'visual'
        self.token='a'*32; self.next_token='b'*32

    def lease(self, owner='', expected=''):
        return sender.phone_visual_owner('1000',owner,expected,self.state)

    def test_completion_and_duplicate(self):
        with self.lease(self.token) as permitted: self.assertTrue(permitted)
        with self.lease(expected=self.token) as permitted: self.assertTrue(permitted)
        with self.lease(expected=self.token) as permitted: self.assertFalse(permitted)

    def test_new_alert_and_new_page_survive_old_cleanup(self):
        for replacement in ('',self.next_token):
            with self.lease(self.token): pass
            with self.lease(replacement): pass
            before=(self.state/'1000.json').read_bytes()
            with self.lease(expected=self.token) as permitted: self.assertFalse(permitted)
            self.assertEqual((self.state/'1000.json').read_bytes(),before)

    def test_reject_symlinks_hardlinks_and_unsafe_modes(self):
        with self.lease(self.token): pass
        marker=self.state/'1000.json'; other=self.state/'other'
        os.link(marker,other)
        with self.assertRaises(OSError):
            with self.lease(expected=self.token): self.fail('Hard link accepted')
        other.unlink(); marker.chmod(0o666)
        with self.assertRaises(OSError):
            with self.lease(): self.fail('Writable marker accepted')
        marker.chmod(0o640); marker.rename(other); marker.symlink_to(other)
        with self.assertRaises(OSError):
            with self.lease(expected=self.token): self.fail('Symlink accepted')

    def test_unknown_marker_never_authorizes_cleanup(self):
        with self.lease(self.token): pass
        (self.state/'1000.json').write_text('{broken')
        with self.lease(expected=self.token) as permitted: self.assertFalse(permitted)
        with self.lease(self.next_token) as permitted: self.assertTrue(permitted)

    def test_real_dispatcher_preserves_newer_push(self):
        info={'1000':{'format':'yealink','contacts':[]}}
        calls=[]
        def submit(*args):
            calls.append(args)
            return sender.NotifySubmissionResult('accepted',{'status':'submitted_to_asterisk','event_attempts':[{'response':'success'}],'event_fallback_outcome':'not_used'})
        with patch.object(sender,'PHONE_VISUAL_STATE',self.state), patch.object(sender,'pjsip_notify_capabilities',return_value={}), patch.object(sender,'send_notify',side_effect=submit):
            push=lambda **kw:sender.send_notify_batch(None,info,lambda _:sender.build_announcement_xml('Fixture'), 'fixture', **kw)
            push(visual_owner=self.token)
            push() # An ordinary alert supersedes the page.
            self.assertEqual(push(clear_visual_owner=self.token),0)
            self.assertEqual(len(calls),2)
            push(visual_owner=self.next_token)
            self.assertEqual(push(clear_visual_owner=self.token),0)
            push(clear_visual_owner=self.next_token)
            self.assertEqual(len(calls),4)

    def test_lock_serializes_another_sender(self):
        with self.lease(self.token):
            pid=os.fork()
            if pid==0:
                try:
                    for entry in Path('/proc/self/fd').iterdir():
                        try:
                            if os.readlink(entry)==str(self.state/'1000.json'): os.close(int(entry.name))
                        except OSError: pass
                    with self.lease(self.next_token): pass
                    os._exit(0)
                except BaseException: os._exit(1)
            try:
                self.assertEqual(os.waitpid(pid,os.WNOHANG),(0,0))
            finally:
                # Release in the parent before waiting; inherited lock fd is
                # closed by process exit once the child completes its push.
                pass
        self.assertEqual(os.waitpid(pid,0)[1],0)
        with self.lease(expected=self.token) as permitted: self.assertFalse(permitted)

    def test_xml_completion_seconds(self):
        xml=sender.build_announcement_xml('Paging ended.',1).replace("Beep='yes'","Beep='no'")
        root=ET.fromstring(xml)
        self.assertEqual(root.attrib,{'Beep':'no','Timeout':'1','LockIn':'no'})

if __name__=='__main__': unittest.main()
