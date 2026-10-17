#!/usr/bin/python3
"""Read-only diagnostics, bundle authenticity and disposable VM boundaries."""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import pwd
import re
import socket
import subprocess
import tarfile
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from unittest.mock import patch

if os.environ.get('SLS_TEST_NAMESPACE') != 'entered' or os.environ.get(
    'SLS_TEST_PARENT_NET_NS'
) in (None, '', os.readlink('/proc/self/ns/net')):
    raise SystemExit('Use the private fixture runner; doctor network isolation verification failed.')

ROOT=Path(__file__).resolve().parents[1]
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module
DOCTOR=load('doctor_fixture',ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_install_doctor.py')
BUNDLE=load('bundle_fixture',ROOT/'tools/prepare_offline_install.py')
VM=load('vm_fixture',ROOT/'tools/qualify_installer_vm.py')


class Doctor(unittest.TestCase):
    def test_diagnostic_and_transaction_stages_share_the_installer_contract(self):
        transaction=load('transaction_stages',ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_install_transaction.py')
        self.assertEqual(DOCTOR.STAGES,transaction.STAGES)
        emitted=set(re.findall(r"""^\s*set_install_stage ['"]([^'"$]+)['"]""", (ROOT/'tools/install_release.sh').read_text(), re.M))
        self.assertLessEqual(emitted,DOCTOR.STAGES)
    def php_payload(self):
        return {'probe':'sls_served_php_v1','ok':True,'missing':[],'php_version':'8.2.29','php_sapi':'fpm-fcgi',
                'runtime_account_ok':True,'extensions':dict.fromkeys(DOCTOR.PHP_EXTENSIONS,True),
                'functions':dict.fromkeys(DOCTOR.PHP_FUNCTIONS,True),'api_key':'must-not-appear','uid':999}
    def php_server(self,payload,status=200,slow=False):
        class Handler(BaseHTTPRequestHandler):
            def do_GET(self):
                try:
                    if slow:
                        for unused in range(50):self.connection.sendall(b'x');time.sleep(.03)
                        return
                    self.send_response(status)
                    if status==302:self.send_header('Location','https://provider.example.test/secret')
                    self.end_headers();self.wfile.write(json.dumps(payload).encode())
                except OSError:pass
            def log_message(self,*args):pass
        server=ThreadingHTTPServer(('127.0.0.1',0),Handler);server.daemon_threads=True
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        self.addCleanup(server.server_close);self.addCleanup(server.shutdown)
        return [('http','127.0.0.1',server.server_port,'pbx.example.test')]
    def test_diagnostics_never_start_services_install_packages_or_load_php(self):
        commands=[]
        def command(args,timeout=5):
            commands.append(args)
            if args[0].endswith('systemctl'): return 0,'active\n'
            if args[0].endswith('apache2ctl'): return 0,'Syntax OK\n'
            return 1,'{}'
        with patch.object(DOCTOR,'command',side_effect=command),patch.object(DOCTOR,'served_php_local',return_value={'ok':False,'available':False}): value=DOCTOR.report()
        self.assertTrue(value['read_only'])
        self.assertNotIn('mass-notifications.config',json.dumps(value))
        for args in commands:
            self.assertNotIn('start',args);self.assertNotIn('restart',args);self.assertNotIn('reload',args)
            self.assertNotIn('php',Path(args[0]).name);self.assertNotIn('apt-get',args);self.assertNotIn('fwconsole',args)
        self.assertIn('physical endpoints',value['not_exercised'])
    def test_unsafe_runtime_helper_is_not_executed(self):
        with patch.object(DOCTOR,'protected',return_value=False),patch.object(DOCTOR,'command',return_value=(0,'active')) as command,patch.object(DOCTOR,'served_php_local',return_value={'ok':False,'available':False}):
            value=DOCTOR.report()
        self.assertFalse(any('sls_resource_capacity.py' in argument for call in command.call_args_list for argument in call.args[0]))
        self.assertIn('resource_helper_unsafe_or_missing',json.dumps(value))
    def test_invalid_capacity_rejected_without_probes(self):
        result=subprocess.run(['python3','-I',str(ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_install_doctor.py'),'--desktop-limit','1001'],capture_output=True,text=True,timeout=5)
        self.assertEqual(result.returncode,2)
    def test_served_runtime_uses_local_wire_evidence_and_redacts_extra_fields(self):
        value=DOCTOR.served_php_probe(self.php_server(self.php_payload()))
        self.assertTrue(value['ok']);self.assertEqual(value['php_version'],'8.2.29');self.assertEqual(value['php_sapi'],'fpm-fcgi')
        self.assertNotIn('must-not-appear',json.dumps(value));self.assertNotIn('uid',value)
        payload=self.php_payload();payload['ok']=False;payload['runtime_account_ok']=False;payload['functions']['exec']=False
        value=DOCTOR.served_php_probe(self.php_server(payload,503));self.assertTrue(value['available']);self.assertFalse(value['ok'])
        self.assertFalse(value['functions']['exec']);self.assertFalse(value['runtime_account_ok'])
    def test_php_redirects_external_targets_and_invalid_payloads_are_not_accepted(self):
        self.assertFalse(DOCTOR.served_php_probe(self.php_server(self.php_payload(),302))['available'])
        with patch.object(DOCTOR.http.client,'HTTPConnection',side_effect=AssertionError('External connection attempted')):
            self.assertFalse(DOCTOR.served_php_probe([('http','192.0.2.1',80,'pbx.example.test')])['available'])
        payload=self.php_payload();payload['functions']['exec']='yes'
        self.assertFalse(DOCTOR.served_php_probe(self.php_server(payload))['available'])
        payload=self.php_payload();payload['ok']=False
        self.assertFalse(DOCTOR.served_php_probe(self.php_server(payload,503))['available'])
    def test_slow_headers_obey_absolute_probe_deadline(self):
        started=time.monotonic();value=DOCTOR.served_php_probe(self.php_server({},slow=True),.15)
        self.assertFalse(value['available']);self.assertLess(time.monotonic()-started,.6)
    def test_each_probe_deadline_remains_bound_to_its_own_socket(self):
        payload=json.dumps(self.php_payload()).encode()
        class Socket:
            def __init__(self):self.shutdowns=0;self.closes=0
            def shutdown(self,unused):self.shutdowns+=1
            def close(self):self.closes+=1
        class Response:
            def __init__(self,status,body=b''):self.status=status;self.body=body
            def read(self,unused):return self.body
        class Connection:
            def __init__(self,response):self.sock=Socket();self.response=response
            def connect(self):pass
            def request(self,*unused,**ignored):pass
            def getresponse(self):return self.response
            def close(self):pass
        class Timer:
            instances=[]
            def __init__(self,unused,callback):self.callback=callback;self.daemon=False;self.cancelled=False;self.instances.append(self)
            def start(self):pass
            def cancel(self):self.cancelled=True
        connections=[Connection(Response(404)),Connection(Response(200,payload))]
        with patch.object(DOCTOR.http.client,'HTTPConnection',side_effect=connections),patch.object(DOCTOR.threading,'Timer',Timer):
            value=DOCTOR.served_php_probe([
                ('http','127.0.0.1',8001,'first.example.test'),
                ('http','127.0.0.1',8002,'second.example.test'),
            ])
        self.assertTrue(value['ok'])
        Timer.instances[0].callback()
        self.assertEqual((connections[0].sock.shutdowns,connections[0].sock.closes),(1,1))
        self.assertEqual((connections[1].sock.shutdowns,connections[1].sock.closes),(0,0))
    def test_parsed_vhosts_change_headers_and_ports_without_public_resolution(self):
        targets=DOCTOR.served_php_targets('*:8443 pbx.example.test (/etc/apache2/pbx.conf:1)\n port 9443 namevhost secondary.example.test (/etc/apache2/secondary:1)\n[::]:8080 ipv6.example.test (/etc/apache2/ipv6:1)')
        self.assertEqual(targets[0],('https','127.0.0.1',8443,'pbx.example.test'))
        self.assertTrue(any(row[1]=='::1' and row[2]==8080 for row in targets))
        self.assertTrue(all(row[1] in ('127.0.0.1','::1') for row in targets))
    def test_process_output_and_deadline_bounds_apply_before_capture_completes(self):
        status,output=DOCTOR.command(['/usr/bin/python3','-I','-c','import os\nwhile True:os.write(1,b"x"*65536)'],.5)
        self.assertEqual((status,output),(125,''))
        started=time.monotonic();status,output=DOCTOR.command(['/usr/bin/python3','-I','-c','import time;time.sleep(20)'],.1)
        self.assertEqual((status,output),(124,''));self.assertLess(time.monotonic()-started,.8)
    def test_exited_probe_parent_does_not_leave_its_stdout_holding_descendant(self):
        with tempfile.TemporaryDirectory() as name:
            path=Path(name)/'child-pid'
            code='import os,time\nif os.fork():os._exit(0)\nopen('+repr(str(path))+',"w").write(str(os.getpid()))\ntime.sleep(20)'
            self.assertEqual(DOCTOR.command(['/usr/bin/python3','-I','-c',code],.2),(124,''))
            child=int(path.read_text());state=Path('/proc')/str(child)/'stat'
            for unused in range(30):
                if not state.exists() or state.read_text().split()[2]=='Z':break
                time.sleep(.01)
            else:self.fail('Diagnostic descendant survived its deadline')
    def test_collector_probe_preserves_freshness_failures_and_excludes_arbitrary_detail(self):
        with patch.object(DOCTOR,'protected',return_value=True),patch.object(DOCTOR,'command',return_value=(0,json.dumps({'ok':True,'connected':True,'heartbeat_age_seconds':1.5,'active_batches':2,'reserved_contacts':3,'detail':'must-not-appear'}))):
            value=DOCTOR.collector_probe();self.assertTrue(value['ok']);self.assertNotIn('must-not-appear',json.dumps(value))
        for age in [16,-1,float('nan'),'1']:
            with patch.object(DOCTOR,'protected',return_value=True),patch.object(DOCTOR,'command',return_value=(0,json.dumps({'ok':True,'connected':True,'heartbeat_age_seconds':age,'active_batches':0,'reserved_contacts':0}))):
                self.assertFalse(DOCTOR.collector_probe()['ok'])
        with patch.object(DOCTOR,'protected',return_value=True),patch.object(DOCTOR,'command',return_value=(1,json.dumps({'ok':False,'failure_code':'phone_event_collector_stale','detail':'must-not-appear'}))):
            value=DOCTOR.collector_probe();self.assertEqual(value['failure_code'],'phone_event_collector_stale');self.assertNotIn('detail',value)
    def test_nonroot_trust_check_cannot_execute_protected_privileged_verification(self):
        with patch.object(DOCTOR.os,'geteuid',return_value=pwd.getpwnam('asterisk').pw_uid),patch.object(DOCTOR,'command',side_effect=AssertionError('Unexpected trust command')):
            self.assertEqual(DOCTOR.release_probe()['failure_code'],'release_trust_root_inspection_required')
    def test_real_publisher_and_installed_inventory_verification_is_read_only(self):
        with tempfile.TemporaryDirectory() as name:
            root=Path(name);runtime=root/'runtime';runtime.mkdir(mode=0o700);web=root/'web';web.mkdir(mode=0o700)
            key=root/'private-key'
            subprocess.run(['openssl','genpkey','-algorithm','Ed25519','-out',str(key)],check=True,capture_output=True)
            public=subprocess.run(['openssl','pkey','-in',str(key),'-pubout'],check=True,capture_output=True).stdout
            source=(ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_module_trust.py').read_text()
            source=re.sub(r'^PUBLIC_KEY = .*$',lambda unused:'PUBLIC_KEY = '+repr(public),source,flags=re.M)
            (runtime/'sls_module_trust.py').write_text(source)
            (runtime/'sls_release_trust.py').write_bytes((ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_release_trust.py').read_bytes())
            for path in runtime.iterdir():path.chmod(0o600)
            trust=load('doctor_signed_trust_fixture',runtime/'sls_module_trust.py');store=root/'trust'
            body=b'<module><rawname>slsmassnotifyserver</rawname><version>0.1.6-beta</version></module>'
            archive=root/'slsmassnotifyserver-0.1.6-beta.tgz'
            with tarfile.open(archive,'w:gz') as package:
                member=tarfile.TarInfo('slsmassnotifyserver/module.xml');member.size=len(body);package.addfile(member,io.BytesIO(body))
            manifest=root/'release-manifest.json';signature=root/'release-manifest.sig'
            archive_sha=hashlib.sha256(archive.read_bytes()).hexdigest()
            manifest.write_text(json.dumps({'schema':1,'version':'0.1.6-beta','tag':'slsmassnotifyserver-0.1.6-beta',
                'package':archive.name,'package_sha256':archive_sha,'installer_sha256':'a'*64}))
            subprocess.run(['openssl','pkeyutl','-sign','-rawin','-inkey',str(key),'-in',str(manifest),'-out',str(signature)],check=True,capture_output=True)
            generation=store/trust.enroll_sls(store,archive,manifest,signature)
            installed=web/'admin/modules/slsmassnotifyserver/module.xml';installed.parent.mkdir(mode=0o700,parents=True);installed.write_bytes(body)
            for module in ('framework','dashboard'):
                target=web/'admin/modules'/module/'module.xml';target.parent.mkdir(mode=0o700);target.write_text('<module>'+module+'</module>')
                inventory=root/(module+'-reviewed.json')
                inventory.write_text(json.dumps({'schema':1,'module':module,'version':'17.0.1','source':{'kind':'reviewed-upstream-and-overlays','archive_sha256':'b'*64},
                    'files':{'module.xml':{'sha256':hashlib.sha256(target.read_bytes()).hexdigest(),'target':'admin/modules/'+module+'/module.xml'}}}))
                inventory.chmod(0o600);trust.enroll_reviewed(store,inventory,hashlib.sha256(inventory.read_bytes()).hexdigest())
            def snapshot():
                return {str(path.relative_to(root)):(path.read_bytes(),path.stat().st_mode,path.stat().st_ino,path.stat().st_mtime_ns)
                        for tree in (runtime,web,store) for path in tree.rglob('*') if path.is_file()}
            with patch.object(DOCTOR,'RUNTIME',runtime),patch.object(DOCTOR,'TRUST_ROOT',store),patch.object(DOCTOR,'WEB_ROOT',web),patch.object(DOCTOR,'protected',return_value=True):
                before=snapshot();value=DOCTOR.release_probe()
                self.assertTrue(value['ok']);self.assertTrue(value['publisher_authenticated']);self.assertEqual(value['package_sha256'],archive_sha)
                self.assertEqual(snapshot(),before)
                stock=web/'admin/modules/dashboard/module.xml';stock.write_bytes(b'changed')
                value=DOCTOR.release_probe();self.assertFalse(value['ok']);self.assertTrue(value['publisher_authenticated']);self.assertFalse(value['stock_inventories']['dashboard'])
                installed.write_bytes(b'changed')
                self.assertEqual(DOCTOR.release_probe()['failure_code'],'release_trust_or_inventory_invalid')
                installed.write_bytes(body);(generation/'release-manifest.sig').write_bytes(b'0'*64)
                self.assertEqual(DOCTOR.release_probe()['failure_code'],'release_trust_or_inventory_invalid')
    def test_journal_stage_is_redacted_and_old_failure_remains_historical(self):
        with tempfile.TemporaryDirectory() as name:
            root=Path(name);journal=root/'journal';journal.mkdir();data=root/'data';data.mkdir()
            directory=journal/('transaction-'+'a'*24);directory.mkdir()
            now=int(time.time());path=directory/'transaction.json'
            path.write_text(json.dumps({'schema':1,'phase':'committed','updated_at':now,'stage':'post-install verification','stage_at':now,'settings':{'SECRET':'must-not-appear'}}))
            (data/'install-failure.json').write_text(json.dumps({'stage':'module activation','failed_at':'2001-01-01T00:00:00+00:00','error_category':'install_command_failed','solution':'must-not-appear','log':'must-not-appear'}))
            with patch.object(DOCTOR,'JOURNAL',journal),patch.object(DOCTOR,'DATA',data),patch.object(DOCTOR,'protected',return_value=True):
                value=DOCTOR.installation_state();self.assertTrue(value['ok']);self.assertEqual(value['latest_transaction']['stage'],'post-install verification')
                self.assertTrue(value['last_failure']['historical_only']);self.assertTrue(value['last_failure']['superseded_by_commit']);self.assertNotIn('must-not-appear',json.dumps(value))
                row=json.loads(path.read_text());row['stage']='must-not-appear';path.write_text(json.dumps(row))
                self.assertTrue(DOCTOR.installation_state()['unsafe_evidence'])
    def test_vm_requires_explicit_disposable_opt_in_before_loading_any_image(self):
        result=subprocess.run(['python3','-I',str(ROOT/'tools/qualify_installer_vm.py'),'--image','/nonexistent','--image-sha256','a'*64,'--bundle','/nonexistent','--baseline','fresh','--report','/nonexistent'],capture_output=True,text=True,timeout=5)
        self.assertEqual(result.returncode,2)
        self.assertIn('--allow-disposable-vm',result.stderr)


class OfflineBundle(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup);self.root=Path(self.temp.name)
        self.key=self.root/'key';subprocess.run(['openssl','genpkey','-algorithm','Ed25519','-out',str(self.key)],check=True,capture_output=True)
        public=subprocess.run(['openssl','pkey','-in',str(self.key),'-pubout'],check=True,capture_output=True).stdout
        self.patch=patch.object(BUNDLE,'ANCHOR',public);self.patch.start();self.addCleanup(self.patch.stop)
        self.directory=self.root/'bundle';self.directory.mkdir(mode=0o700)
        self.installer=self.directory/'install_release.sh';self.installer.write_bytes(b'#!/bin/bash\nexit 0\n')
        self.package=self.directory/'slsmassnotifyserver-0.1.6-beta.tgz'
        with tarfile.open(self.package,'w:gz') as archive:
            body=b'<module><rawname>slsmassnotifyserver</rawname><version>0.1.6-beta</version></module>'
            member=tarfile.TarInfo('slsmassnotifyserver/module.xml');member.size=len(body);archive.addfile(member,io.BytesIO(body))
        self.sign()
    def sign(self):
        manifest={'schema':1,'version':'0.1.6-beta','package':self.package.name,
            'installer_sha256':hashlib.sha256(self.installer.read_bytes()).hexdigest(),
            'package_sha256':hashlib.sha256(self.package.read_bytes()).hexdigest()}
        path=self.directory/'release-manifest.json';path.write_text(json.dumps(manifest))
        signature=self.directory/'release-manifest.sig'
        subprocess.run(['openssl','pkeyutl','-sign','-rawin','-inkey',str(self.key),'-in',str(path),'-out',str(signature)],check=True,capture_output=True)
        for entry in self.directory.iterdir():entry.chmod(0o600)
    def test_installer_package_signature_and_tampering(self):
        self.assertEqual(BUNDLE.release(self.directory)['version'],'0.1.6-beta')
        self.installer.write_bytes(b'altered')
        with self.assertRaisesRegex(ValueError,'differs'):BUNDLE.release(self.directory)
        self.installer.write_bytes(b'#!/bin/bash\nexit 0\n');self.package.write_bytes(b'altered')
        with self.assertRaisesRegex(ValueError,'differs'):BUNDLE.release(self.directory)
    def test_offline_environment_retains_the_authenticated_digest_and_clears_remote_url(self):
        values=BUNDLE.installer_environment(self.directory)
        self.assertEqual(values['SLS_MASS_NOTIFY_SHA256'],hashlib.sha256(self.package.read_bytes()).hexdigest())
        self.assertEqual(values['SLS_MASS_NOTIFY_TGZ_URL'],'')
        self.assertEqual(values['SLS_MASS_NOTIFY_TGZ'],str(self.package))
        self.installer.write_bytes(b'altered')
        with self.assertRaisesRegex(ValueError,'differs'):BUNDLE.installer_environment(self.directory)
    def test_unsigned_and_publicly_writable_inputs_are_rejected(self):
        (self.directory/'release-manifest.sig').write_bytes(b'0'*64)
        with self.assertRaises(subprocess.CalledProcessError):BUNDLE.release(self.directory)
        self.sign();self.installer.chmod(0o666)
        with self.assertRaisesRegex(ValueError,'Unsafe'):BUNDLE.release(self.directory)
    def test_symlinks_and_archive_traversal_are_rejected(self):
        self.installer.unlink();self.installer.symlink_to(self.key)
        with self.assertRaises(OSError):BUNDLE.release(self.directory)
        data=io.BytesIO()
        with tarfile.open(fileobj=data,mode='w:gz') as archive:
            member=tarfile.TarInfo('slsmassnotifyserver/../../etc/passwd');member.size=1;archive.addfile(member,io.BytesIO(b'x'))
        with self.assertRaisesRegex(ValueError,'Unsafe'):BUNDLE.members(data.getvalue(),'slsmassnotifyserver')


if __name__=='__main__':unittest.main()
