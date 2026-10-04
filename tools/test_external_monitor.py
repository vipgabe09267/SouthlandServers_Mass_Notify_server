#!/usr/bin/python3
"""Real HTTPS monitor requests against a private disposable TLS server."""
import datetime as dt
import email.utils
import http.server
import importlib.util
import json
import os
from pathlib import Path
import ssl
import subprocess
import tempfile
import threading
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('monitor', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_external_monitor.py')
monitor = importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(monitor)


class ExternalMonitorTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-monitor-'); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name); self.token = 'fixture-read-only-token-1234567890'
        self.report = {'ok': True, 'resource': 'readiness', 'schema': 'sls-deployment-readiness-v1',
                       'version': '0.1.5-beta', 'generated_at': dt.datetime.now(dt.timezone.utc).isoformat(),
                       'operational_ready': True, 'attention_count': 0, 'unknown_count': 0,
                       'checks': [{'id':'fixture', 'label':'Fixture check', 'state':'ok', 'detail':'private evidence'}],
                       'device_acceptance': {'records':[{'note':'private note'}]}}
        self.headers = {'Date': email.utils.formatdate(usegmt=True), 'Cache-Control':'private, no-store'}

    def test_valid_report_and_redacted_faults(self):
        good = monitor.evaluate(json.dumps(self.report), self.headers)
        self.assertTrue(good['ok']); self.assertNotIn('private', json.dumps(good))
        self.report.update(operational_ready=False, unknown_count=1); self.report['checks'][0]['state']='unknown'
        fault = monitor.evaluate(json.dumps(self.report), self.headers)
        self.assertFalse(fault['ok']); self.assertEqual(fault['state'], 'attention_required')
        self.assertNotIn('private', json.dumps(fault))

    def test_invalid_stale_naive_and_inconsistent_reports(self):
        for change in ({'ok':False}, {'schema':'unknown'}, {'operational_ready':'true'}, {'attention_count':True},
                       {'checks':[]}, {'generated_at':'2026-01-01T00:00:00Z'}, {'generated_at':'2026-01-01T00:00:00'},
                       {'generated_at':dt.datetime.fromtimestamp(time.time()+60,dt.timezone.utc).isoformat()}, {'unknown_count':1}, {'operational_ready':False}):
            with self.subTest(change=change), self.assertRaises(monitor.MonitorError):
                monitor.evaluate(json.dumps(self.report | change), self.headers)
        for headers in ({}, self.headers | {'Cache-Control':'public,max-age=3600'}, self.headers | {'Date':'invalid'}):
            with self.assertRaises(monitor.MonitorError): monitor.evaluate(json.dumps(self.report), headers)

    def test_origin_and_secret_file(self):
        self.assertEqual(monitor.endpoint('https://pbx.example.test:8443'), ('pbx.example.test',8443))
        for bad in ('http://pbx.test','https://user:secret@pbx.test','https://pbx.test/api','https://pbx.test?token=secret',
                    'https://pbx.test:70000','https://pbx.test/#fragment','https://pbx.test\nInjected: secret'):
            with self.assertRaises(monitor.MonitorError): monitor.endpoint(bad)
        path=self.root/'token'; path.write_text(self.token); path.chmod(0o600)
        self.assertEqual(monitor.credential(str(path)),self.token)
        path.chmod(0o644)
        with self.assertRaises(monitor.MonitorError): monitor.credential(str(path))
        path.chmod(0o600); os.link(path,self.root/'hardlink')
        with self.assertRaises(monitor.MonitorError): monitor.credential(str(path))
        (self.root/'hardlink').unlink(); (self.root/'linked').symlink_to(path)
        with self.assertRaises(OSError): monitor.credential(str(self.root/'linked'))

    def test_real_https_authentication_date_cache_and_no_redirect(self):
        parent = os.environ.get('SLS_TEST_PARENT_NET_NS') or os.environ.get('SLS_BUILD_PARENT_NET')
        if parent and parent != os.readlink('/proc/self/ns/net'):
            subprocess.run(['ip','link','set','lo','up'],check=True,timeout=5,stdout=subprocess.DEVNULL)
        key=self.root/'key.pem'; cert=self.root/'cert.pem'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),
                        '-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost'],
                       check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=10)
        seen=[]; report=self.report; owner=self
        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self,*args): pass
            def do_GET(self):
                seen.append((self.path,self.headers.get('Authorization')))
                status=getattr(owner,'status',200); body=json.dumps(report).encode()
                self.send_response(status); self.send_header('Content-Type','application/json'); self.send_header('Cache-Control','no-store')
                self.send_header('Content-Length',str(len(body))); self.send_header('Retry-After','5')
                if status==302:self.send_header('Location','https://unapproved.invalid/')
                self.end_headers(); self.wfile.write(body)
        server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
        context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); context.load_cert_chain(cert,key); server.socket=context.wrap_socket(server.socket,server_side=True)
        thread=threading.Thread(target=server.serve_forever,daemon=True); thread.start()
        self.addCleanup(server.server_close); self.addCleanup(server.shutdown)
        origin='https://localhost:'+str(server.server_port)
        # Real certificate verification is retained; only this generated CA is
        # added to the fixture trust store. Untrusted TLS must fail first.
        with self.assertRaises(ssl.SSLError): monitor.probe(origin,self.token)
        original=os.environ.get('SSL_CERT_FILE'); os.environ['SSL_CERT_FILE']=str(cert)
        try:
            result=monitor.probe(origin,self.token); self.assertTrue(result['ok'])
            self.assertEqual(seen,[('/api/sls-mass-notify/?resource=readiness','Bearer '+self.token)])
            self.status=429
            with self.assertRaisesRegex(monitor.MonitorError,'Retry after 5'): monitor.probe(origin,self.token)
            self.status=302
            with self.assertRaisesRegex(monitor.MonitorError,'HTTP 302'): monitor.probe(origin,self.token)
            self.assertEqual(len(seen),3)
        finally:
            if original is None:os.environ.pop('SSL_CERT_FILE',None)
            else:os.environ['SSL_CERT_FILE']=original


if __name__=='__main__': unittest.main(verbosity=2)
