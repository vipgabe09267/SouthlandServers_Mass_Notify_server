#!/usr/bin/python3
"""Actual MIME and local process tests; sendmail is always a private executable."""
import copy
from email import policy
from email.parser import BytesParser
import importlib.util
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch
sys.dont_write_bytecode = True
RUNTIME = Path(__file__).resolve().parents[1]/'slsmassnotifyserver/bin/sls_mass_notify'
spec = importlib.util.spec_from_file_location('announcement_email', RUNTIME/'sls_announcement_email.py')
m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)

class AnnouncementEmail(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-email-fixture-'); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        # Installed runtimes must fence missing policy. Supply explicit disabled
        # policy only in this private transport fixture, preserving that guard.
        self.config=self.root/'disabled.config';self.config.write_text(json.dumps({'enterprise_cluster':{'enabled':False}}));self.config.chmod(0o600)
        cluster_policy=patch.object(m._cluster_guard,'CONFIG',self.config);cluster_policy.start();self.addCleanup(cluster_policy.stop)
        self.payload = dict(recipient_id='email_'+'a'*24, address='person@example.com',
            sender={'name':'SLS notification system','address':'notify@example.com'}, title='Reading test schedules',
            message='This is an ordinary message, not classified from words.\n'+''.join(f'Field {i}: value {i} <unsafe>&\n' for i in range(20)),
            is_test=False, severity='info', message_id='<sls-'+'b'*32+'-'+'a'*24+'@example.com>', created_at='2026-09-23T12:34:56-05:00')
        self.fake = self.root/'sendmail'; self.wire = self.root/'message'; self.args = self.root/'args.json'
        self.program("import json,pathlib,sys\npathlib.Path(%r).write_text(json.dumps(sys.argv[1:]))\npathlib.Path(%r).write_bytes(sys.stdin.buffer.read())\n"%(str(self.args),str(self.wire)))
    def program(self, body):
        self.fake.write_text('#!/usr/bin/python3\n'+body); self.fake.chmod(0o700)
    def send(self, payload=None, **kwargs):
        return m.submit(self.payload if payload is None else payload, sendmail=self.fake, logo_paths=(), **kwargs)
    def test_accepted_fixed_arguments_full_multipart_and_stable_identity(self):
        result=self.send(); self.assertEqual(result['state'],'accepted'); self.assertFalse(result['retryable'])
        self.assertEqual(json.loads(self.args.read_text()),['-oi','-f','notify@example.com','--','person@example.com'])
        mail=BytesParser(policy=policy.default).parsebytes(self.wire.read_bytes())
        self.assertEqual(mail['Message-ID'],self.payload['message_id']); self.assertEqual(mail['X-SLS-Is-Test'],'false')
        self.assertNotIn('Bcc',mail); self.assertEqual(mail['To'],'person@example.com')
        self.assertEqual(mail['Date'].datetime.isoformat(),'2026-09-23T12:34:56-05:00')
        self.assertEqual(mail.get_body(('plain',)).get_content().replace('\r\n','\n').rstrip('\n'),self.payload['message'].rstrip('\n'))
        html=mail.get_body(('html',)).get_content()
        self.assertIn('Field 19: value 19 &lt;unsafe&gt;&amp;',html)
        self.assertNotIn('SYSTEM TEST — NOT AN ACTUAL ALERT',html)
        self.assertNotIn('<unsafe>',html)
    def test_explicit_test_and_critical_metadata_no_text_heuristics(self):
        self.payload.update(title='Ordinary heading',is_test=True,severity='critical')
        mail=BytesParser(policy=policy.default).parsebytes(m.build_message(self.payload,logo_paths=()))
        self.assertEqual(str(mail['Subject']),'[TEST] Ordinary heading')
        self.assertIn('SYSTEM TEST — NOT AN ACTUAL ALERT',mail.get_body(('html',)).get_content())
        self.payload['is_test']=False
        mail=BytesParser(policy=policy.default).parsebytes(m.build_message(self.payload,logo_paths=()))
        self.assertIn('CRITICAL ALERT',mail.get_body(('html',)).get_content())
    def test_malformed_and_injection_inputs_never_start_process(self):
        changes=[{'address':'person@example.com\nBcc: victim@example.com'}, {'address':'-X/tmp/output'},
                 {'sender':{'name':'hello\r\nX: bad','address':'notify@example.com'}}, {'title':'line\nBcc:bad'},
                 {'message':'nul\x00'}, {'message':'x'*(m.MAX_BODY+1)}, {'message':'\ud800'},
                 {'recipient_id':'email_wrong'}, {'message_id':self.payload['message_id'].replace('a'*24,'c'*24)},
                 {'created_at':'2026-09-23T12:00:00'}, {'created_at':'2026-99-23T12:00:00Z'},
                 {'is_test':'false'}, {'severity':'made_up'}, {'unknown':True}, {'address':['a@example.com']}]
        with patch.object(m.subprocess,'Popen',side_effect=AssertionError('invalid input executed sendmail')):
            for change in changes:
                with self.subTest(change=repr(change)[:80]),self.assertRaises((m.InputError,ValueError)):
                    self.send({**self.payload,**change})
    def test_rejected_exit_is_per_recipient_and_temporary_only_retryable(self):
        for code,retry in ((75,True),(67,False),(1,False)):
            self.program(f'import sys\nsys.stdin.buffer.read()\nsys.stderr.write("private address / secret details")\nsys.exit({code})\n')
            result=self.send(); self.assertEqual(result['state'],'rejected'); self.assertEqual(result['retryable'],retry)
            self.assertNotIn('private address',json.dumps(result))
        result=m.submit(self.payload,sendmail=self.root/'absent',logo_paths=())
        self.assertEqual(result['error_code'],'sendmail_unavailable')
    def test_timeout_kills_submission_group_and_does_not_retry(self):
        counter=self.root/'counter'; child_pid=self.root/'child.pid'
        self.program('import os,pathlib,subprocess,sys,time\n'
                     f'pathlib.Path({str(counter)!r}).write_text("one")\n'
                     'child=subprocess.Popen([sys.executable,"-c","import time;time.sleep(30)"])\n'
                     f'pathlib.Path({str(child_pid)!r}).write_text(str(child.pid))\n'
                     'sys.stdin.buffer.read()\ntime.sleep(30)\n')
        start=time.monotonic(); result=self.send(timeout=.2)
        self.assertLess(time.monotonic()-start,3); self.assertEqual(result['state'],'uncertain'); self.assertFalse(result['retryable'])
        self.assertEqual(counter.read_text(),'one')
        pid=int(child_pid.read_text())
        for _ in range(50):
            proc=Path('/proc')/str(pid)/'stat'
            if not proc.exists() or proc.read_text().split()[2]=='Z':break
            time.sleep(.02)
        else:
            os.kill(pid,signal.SIGKILL); self.fail('timeout left sendmail child running')
    def test_signal_is_uncertain_not_failed(self):
        self.program('import os,signal,sys\nsys.stdin.buffer.read()\nos.kill(os.getpid(),signal.SIGTERM)\n')
        self.assertEqual(self.send()['state'],'uncertain')
    def test_logo_bounds_links_and_render_size(self):
        logo=self.root/'logo.png'; logo.write_bytes(b'\x89PNG\r\n\x1a\nfixture')
        mail=BytesParser(policy=policy.default).parsebytes(m.build_message(self.payload,logo_paths=(logo,)))
        self.assertEqual(len([p for p in mail.walk() if p.get_content_type()=='image/png']),1)
        link=self.root/'link';link.symlink_to(logo)
        self.assertIsNone(m.read_logo((link,)))
        os.link(logo,self.root/'hard'); self.assertIsNone(m.read_logo((logo,)))
        (self.root/'hard').unlink();logo.write_bytes(b'\x89PNG\r\n\x1a\n'+b'x'*m.MAX_LOGO)
        self.assertIsNone(m.read_logo((logo,)))
        with patch.object(m,'MAX_MIME',100),self.assertRaises(m.InputError):self.send()
        self.assertFalse(self.wire.exists())
    def test_real_cli_rejects_bad_input_without_any_submission(self):
        for raw in (b'{"address":"secret-token"}',b'{"a":1,"a":2}',b'{'*(10000),b' '* (m.MAX_INPUT+1)):
            result=subprocess.run([sys.executable,'-I',str(RUNTIME/'sls_announcement_email.py')],input=raw,capture_output=True,timeout=3)
            self.assertEqual(result.returncode,2,result.stderr)
            output=json.loads(result.stdout);self.assertEqual(output['state'],'rejected');self.assertFalse(output['retryable'])
            self.assertNotIn(b'secret-token',result.stdout+result.stderr)
        self.assertFalse(self.wire.exists())
    def test_real_cli_valid_envelope_and_fixed_program_with_private_copy(self):
        # Change only the test copy's constant, never the production executable.
        helper=self.root/'sls_announcement_email.py'
        source=(RUNTIME/'sls_announcement_email.py').read_text()
        self.assertIn("SENDMAIL = '/usr/sbin/sendmail'",source)
        helper.write_text(source.replace("SENDMAIL = '/usr/sbin/sendmail'",'SENDMAIL = '+repr(str(self.fake))))
        (self.root/'sls_branded_email.py').write_bytes((RUNTIME/'sls_branded_email.py').read_bytes())
        (self.root/'sls_config_crypto.py').write_bytes((RUNTIME/'sls_config_crypto.py').read_bytes())
        guard=(RUNTIME/'sls_cluster_guard.py').read_text()
        guard_constant="CONFIG = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config')"
        self.assertIn(guard_constant,guard)
        (self.root/'sls_cluster_guard.py').write_text(guard.replace(guard_constant,'CONFIG = Path('+repr(str(self.config))+')'))
        result=subprocess.run([sys.executable,'-I',str(helper)],input=json.dumps(self.payload).encode(),
                    capture_output=True,timeout=3,env={**os.environ,'SLS_SENDMAIL':'/invalid/override'})
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(json.loads(result.stdout)['state'],'accepted')
        self.assertTrue(self.wire.exists())
        self.assertFalse((self.root/'__pycache__').exists())

    def test_weather_profile_and_signature_remain_compatible(self):
        import sls_branded_email as branded
        self.assertIn('EXTREME WEATHER',branded.build_html('Tornado warning','Stay indoors'))
        self.assertIn('SYSTEM TEST',branded.build_html('System test','Test only'))

if __name__=='__main__':unittest.main()
