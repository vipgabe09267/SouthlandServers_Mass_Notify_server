#!/usr/bin/python3
"""Qualify notifier storage with inert private files; never invoke a mailer."""
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode=True
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('system_notification_storage_fixture',ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_system_notifications.py')
notifier=importlib.util.module_from_spec(spec);spec.loader.exec_module(notifier)


class NotificationStorage(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='sls-notifier-storage-');self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.data=self.root/'data';self.data.mkdir(mode=0o750)

    def test_ambiguous_snapshots_are_refused_or_deferred_without_rewriting(self):
        path=self.data/'snapshot.json'
        for raw in ('{"ok":false,"ok":true}','{"version":1,"active":{"fault":"first"},"active":{}}','{"ok":true,"unused":1e309}','{"ok":true,"unused":NaN}'):
            with self.subTest(raw=raw):
                path.write_text(raw);path.chmod(0o640);before=path.stat()
                with self.assertRaises(RuntimeError):notifier._read_json(path)
                self.assertIsNone(notifier._read_json(path,tolerate_corrupt=True))
                self.assertIsNone(notifier._read_health_snapshot(path)[0])
                self.assertEqual((path.stat().st_ino,path.read_text()),(before.st_ino,raw))

    def test_replaced_snapshot_inode_is_never_accepted(self):
        path=self.data/'snapshot.json';path.write_text('{"ok":true}');path.chmod(0o640)
        real_stat=notifier.os.stat
        def exercise(reader, expected):
            calls=0
            def replace_on_second_named_stat(name,*args,**kwargs):
                nonlocal calls
                if name==path.name and kwargs.get('dir_fd') is not None:
                    calls+=1
                    if calls==2:
                        replacement=self.data/'replacement.json'
                        replacement.write_text('{"ok":false}');replacement.chmod(0o640)
                        os.replace(replacement,path)
                return real_stat(name,*args,**kwargs)
            with mock.patch.object(notifier.os,'stat',side_effect=replace_on_second_named_stat):
                result=reader(path)
            self.assertEqual(result,expected)
            self.assertEqual(calls,2)
            path.write_text('{"ok":true}');path.chmod(0o640)
        exercise(lambda candidate:notifier._read_json(candidate,tolerate_corrupt=True),None)
        exercise(notifier._read_health_snapshot,(None,'changed'))

    def test_state_writer_does_not_follow_parent_replacement(self):
        path=self.data/'system-notification-email-state.json'
        outside=self.root/'outside';outside.mkdir();sentinel=outside/path.name;sentinel.write_text('outside remains unchanged')
        moved=self.root/'original-data';real_open=notifier.os.open;replaced=False
        def replace_parent(name,flags,*args,**kwargs):
            nonlocal replaced
            if not replaced and flags&os.O_CREAT and not flags&os.O_DIRECTORY:
                self.data.rename(moved);self.data.symlink_to(outside,target_is_directory=True);replaced=True
            return real_open(name,flags,*args,**kwargs)
        with mock.patch.object(notifier.os,'open',side_effect=replace_parent):
            try:notifier._write_state(path,{'version':1,'active':{},'attempts':{},'history':{}})
            except (OSError,RuntimeError,ValueError):pass
        self.assertTrue(replaced)
        self.assertEqual(sentinel.read_text(),'outside remains unchanged')

    def test_unknown_notifier_lock_metadata_is_preserved(self):
        path=self.data/'system-notification-email-state.lock'
        for case in ('group','mode','contents'):
            with self.subTest(case=case):
                path.unlink(missing_ok=True);path.write_bytes(b'unknown' if case=='contents' else b'');path.chmod(0o777 if case=='mode' else 0o640)
                if case=='group':os.chown(path,0,65000)
                before=path.stat();raw=path.read_bytes()
                with self.assertRaises(RuntimeError):
                    descriptor=notifier._open_notification_lock(path);os.close(descriptor)
                after=path.stat()
                self.assertEqual((after.st_ino,after.st_uid,after.st_gid,stat.S_IMODE(after.st_mode),path.read_bytes()),
                    (before.st_ino,before.st_uid,before.st_gid,stat.S_IMODE(before.st_mode),raw))

    def test_weather_acknowledgement_works_under_isolated_python(self):
        path=self.data/'status.json';path.write_text('{"last_fault_stage":"api","last_fault_at":"2026-10-13T00:00:00Z"}');path.chmod(0o640)
        source='''import importlib.util,pathlib,sys
sys.dont_write_bytecode=True
spec=importlib.util.spec_from_file_location('notifier_fixture',sys.argv[1]);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
module._mark_weather_fault_email_sent(pathlib.Path(sys.argv[2]),{'stage':'api','occurred_at':'2026-10-13T00:00:00Z'},'2026-10-13T00:01:00Z')
'''
        result=subprocess.run(['/usr/bin/python3','-I','-c',source,str(ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_system_notifications.py'),str(path)],capture_output=True,text=True,timeout=5)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertIn('fault_email_sent_at',path.read_text())

    def test_invalid_suppression_state_never_restarts_delivery(self):
        path=self.data/'state.json';faults={'weather':{'fingerprint':'f'*64,'stage':'api','message':'Private fixture','occurred_at':''}}
        invalid=[{}, {'version':True,'active':{},'attempts':{},'history':{}},
                 {'version':1,'active':[],'attempts':{},'history':{}},
                 {'version':1,'active':{'weather':'invalid'},'attempts':{},'history':{}},
                 {'version':1,'active':{},'attempts':{},'history':{'f'*64:'invalid'}}]
        for value in invalid:
            with self.subTest(value=value):
                raw=json.dumps(value);path.write_text(raw);path.chmod(0o640);before=path.stat();calls=[]
                with self.assertRaises(RuntimeError):
                    notifier.process_faults({'system_notification_emails':'fixture@example.test'},faults,path,
                        sender=lambda *args,**kwargs:calls.append(True) or True,now=1000000)
                self.assertFalse(calls)
                self.assertEqual((path.stat().st_ino,path.read_text()),(before.st_ino,raw))

    def test_missing_established_history_does_not_submit_the_fault_again(self):
        path=self.data/'state.json';lock=self.data/'system-notification-email-state.lock';calls=[]
        config={'system_notification_emails':'fixture@example.test'}
        faults={'weather':{'fingerprint':'f'*64,'stage':'api','message':'Private fixture','occurred_at':''}}
        original_state=notifier.STATE_FILE;original_lock=notifier.LOCK_FILE
        try:
            notifier.STATE_FILE=path;notifier.LOCK_FILE=lock
            descriptor=notifier._open_notification_lock(lock)
            try:notifier.process_faults(config,faults,path,sender=lambda *args,**kwargs:calls.append(True) or True,now=1000000,journal_lock=descriptor)
            finally:os.close(descriptor)
            self.assertEqual(calls,[True]);inode=lock.stat().st_ino;path.unlink()
            with self.assertRaises(RuntimeError):
                descriptor=notifier._open_notification_lock(lock)
                try:notifier.process_faults(config,faults,path,sender=lambda *args,**kwargs:calls.append(True) or True,now=1000001,journal_lock=descriptor)
                finally:os.close(descriptor)
            self.assertEqual(calls,[True]);self.assertEqual(lock.stat().st_ino,inode)
            self.assertFalse(path.exists())
        finally:notifier.STATE_FILE=original_state;notifier.LOCK_FILE=original_lock

    def test_interrupted_marker_commit_resumes_without_replacing_the_lock(self):
        path=self.data/'state.json';lock=self.data/'system-notification-email-state.lock';calls=[]
        config={'system_notification_emails':'fixture@example.test'}
        faults={'weather':{'fingerprint':'f'*64,'stage':'api','message':'Private fixture','occurred_at':''}}
        original_state=notifier.STATE_FILE;original_lock=notifier.LOCK_FILE;real_write=notifier.os.pwrite
        try:
            notifier.STATE_FILE=path;notifier.LOCK_FILE=lock
            descriptor=notifier._open_notification_lock(lock);inode=lock.stat().st_ino
            def short_write(descriptor,data,offset):return real_write(descriptor,data[:5],offset)
            try:
                with mock.patch.object(notifier.os,'pwrite',side_effect=short_write):
                    with self.assertRaises(OSError):notifier.process_faults(config,faults,path,sender=lambda *args,**kwargs:calls.append(True) or True,now=1000000,journal_lock=descriptor)
            finally:os.close(descriptor)
            self.assertFalse(calls);self.assertTrue(path.exists());self.assertEqual(lock.read_bytes(),notifier.STATE_REQUIRED_MARKER[:5])
            descriptor=notifier._open_notification_lock(lock)
            try:notifier.process_faults(config,faults,path,sender=lambda *args,**kwargs:calls.append(True) or True,now=1000001,journal_lock=descriptor)
            finally:os.close(descriptor)
            self.assertEqual(calls,[True]);self.assertEqual(lock.stat().st_ino,inode)
            self.assertEqual(lock.read_bytes(),notifier.STATE_REQUIRED_MARKER)
        finally:notifier.STATE_FILE=original_state;notifier.LOCK_FILE=original_lock


if __name__=='__main__':unittest.main()
