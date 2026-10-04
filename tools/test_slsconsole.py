#!/usr/bin/env python3
"""Encrypted runtime control and transport fencing in disposable fixtures."""
import copy
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import pwd
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT/'slsmassnotifyserver/bin/sls_mass_notify'


def load(name):
    spec = importlib.util.spec_from_file_location(name, RUNTIME/(name+'.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


CONSOLE, CRYPTO, GUARD = (load(name) for name in ('sls_console','sls_config_crypto','sls_cluster_guard'))


class ConsoleTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='sls-console-')
        self.root = Path(self.directory.name)
        account = pwd.getpwnam('asterisk')
        self.uid,self.gid = account.pw_uid,account.pw_gid
        self.data=self.root/'data';self.data.mkdir(mode=0o750);os.chown(self.data,self.uid,self.gid)
        self.keyring=self.root/'keys/config-keys.json'
        CRYPTO.initialize_keyring(self.keyring,self.gid)
        self.original={'secret':'fixture-key-only','enabled':'0','enterprise_cluster':{'enabled':False},'outbound_voice':{'enabled':False},'operator_portal':{'enabled':False},'desktop_clients':[{'username':'fixture'}]}
        self.write('mass-notifications.config', self.original)
        self.pending=copy.deepcopy(self.original);self.pending['pending_only']='unsaved unrelated setting'
        self.write('mass-notifications.pending.config', self.pending)
        self.calls=[];self.failure=None
        self.console=CONSOLE.RuntimeConsole(CRYPTO,self.run_command,data=self.data,keyring=self.keyring)
        self.key_bytes=self.keyring.read_bytes()

    def tearDown(self):
        self.directory.cleanup()

    def write(self,name,value):
        CRYPTO._atomic_write(self.data/name,CRYPTO.encode_config(value,self.keyring),self.uid,self.gid)

    def read(self,name='mass-notifications.config'):
        return CRYPTO.read_config(self.data/name,self.keyring,allow_legacy=False)

    def run_command(self,args,**kwargs):
        self.assertEqual(args[0],'/usr/bin/systemctl');self.assertEqual(args[-1],CONSOLE.SERVICE)
        self.assertIn(args[1],['start','restart','show']);self.assertLessEqual(kwargs['timeout'],25)
        self.calls.append(args)
        if args[1]==self.failure:raise RuntimeError('fixture collector failed')
        return subprocess.CompletedProcess(args,0,b'ActiveState=active\nSubState=running\n',b'')

    def test_all_commands_preserve_settings_pending_and_keys(self):
        before=(self.data/'mass-notifications.config').read_bytes()
        self.assertEqual(self.console.execute('status')['runtime'],'running')
        self.assertEqual(self.console.execute('start')['runtime'],'running')
        self.assertEqual((self.data/'mass-notifications.config').read_bytes(),before)
        self.assertEqual(self.console.execute('stop')['runtime'],'stopped')
        self.assertEqual(self.read(),self.original|{'runtime_enabled':False})
        self.assertEqual(self.read('mass-notifications.pending.config'),self.pending|{'runtime_enabled':False})
        self.assertEqual(self.console.execute('reboot')['runtime'],'stopped')
        self.assertEqual(self.console.execute('start')['runtime'],'running')
        self.assertEqual(self.console.execute('reboot')['runtime'],'running')
        self.assertEqual(self.read(),self.original|{'runtime_enabled':True})
        self.assertEqual(self.keyring.read_bytes(),self.key_bytes)
        for path in self.data.glob('*.config'):
            self.assertEqual(path.stat().st_mode&0o777,0o640)
            self.assertEqual(path.stat().st_uid,self.uid)
            self.assertNotIn(b'fixture-key-only',path.read_bytes())
        self.assertEqual([c[1] for c in self.calls if c[1]!='show'],['start','restart','start','restart'])

    def test_failed_reboot_remains_stopped_and_never_restarts_pbx(self):
        self.failure='restart'
        with self.assertRaisesRegex(RuntimeError,'collector failed'):self.console.execute('reboot')
        self.assertFalse(self.read()['runtime_enabled'])
        self.assertFalse(self.read('mass-notifications.pending.config')['runtime_enabled'])
        self.assertEqual([c[1] for c in self.calls],['restart'])

    def test_existing_activity_continues_but_maintenance_blocks_control(self):
        with self.console.activity():
            self.assertEqual(self.console.execute('stop')['runtime'],'stopped')
        fd=os.open(self.data/'announcement-activity.lock',os.O_RDWR)
        try:
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            with self.assertRaises(BlockingIOError):self.console.execute('start')
            self.assertFalse(self.read()['runtime_enabled'])
        finally:os.close(fd)

    def test_start_never_creates_missing_pending_configuration(self):
        (self.data/'mass-notifications.pending.config').unlink()
        self.console.execute('stop');self.console.execute('start')
        self.assertFalse((self.data/'mass-notifications.pending.config').exists())

    def test_rejected_config_and_locks_do_not_trigger_system_commands(self):
        for value in [None,'0',0,1,[],{}]:
            self.write('mass-notifications.config',self.original|{'runtime_enabled':value})
            with self.assertRaises(RuntimeError):self.console.execute('start')
        self.assertEqual(self.calls,[])
        self.write('mass-notifications.config',self.original)
        lock=self.data/'console-control.lock';lock.unlink();lock.symlink_to(self.data/'mass-notifications.config')
        with self.assertRaises(OSError):self.console.execute('stop')
        self.assertEqual(self.read(),self.original)

    def test_corrupt_pending_fails_without_overwriting_active(self):
        before=(self.data/'mass-notifications.config').read_bytes()
        (self.data/'mass-notifications.pending.config').write_text('{}')
        with self.assertRaises(CRYPTO.ConfigCryptoError):self.console.execute('stop')
        self.assertEqual((self.data/'mass-notifications.config').read_bytes(),before)

    def test_partial_write_rolls_pending_back(self):
        writer=CRYPTO._atomic_write
        def fail_active(path,*args):
            if Path(path).name=='mass-notifications.config':raise OSError('fixture write refused')
            writer(path,*args)
        with patch.object(CRYPTO,'_atomic_write',side_effect=fail_active):
            with self.assertRaises(OSError):self.console.execute('stop')
        self.assertEqual(self.read(),self.original)
        self.assertEqual(self.read('mass-notifications.pending.config'),self.pending)

    def test_guard_checks_fresh_policy_even_when_cluster_is_disabled(self):
        with patch.object(GUARD,'CONFIG',self.data/'mass-notifications.config'),patch.object(GUARD._crypto,'read_config',side_effect=lambda p:CRYPTO.read_config(p,self.keyring)):
            self.assertIsNone(GUARD.begin('webhook','fixture',{},settings=self.original))
            self.console.execute('stop')
            with self.assertRaisesRegex(GUARD.ClusterFenced,'stopped'):GUARD.begin('webhook','fixture',{},settings=self.original)
            with self.assertRaises(GUARD.ClusterFenced):GUARD.fence_legacy(self.original)
            GUARD.finish(None)
            self.console.execute('start')
            self.assertIsNone(GUARD.begin('desktop','fixture',{},settings=self.original))

    def test_php_python_policy_parity_and_php_effect_boundary(self):
        integration=ROOT/'slsmassnotifyserver/EnterpriseClusterIntegration.php'
        for settings in [{},{'runtime_enabled':True},{'runtime_enabled':False},{'runtime_enabled':'0'},{'runtime_enabled':None}]:
            code='require '+json.dumps(str(integration))+'; $s=json_decode(stream_get_contents(STDIN),true);try {$r=\\SLS\\MassNotify\\RuntimeState::running($s);echo json_encode(["running"=>$r]);}catch(Throwable $e){echo "{}";}'
            output=subprocess.check_output(['php','-r',code],input=json.dumps(settings).encode())
            try:expected={'running':CONSOLE.running(settings)}
            except RuntimeError:expected={}
            self.assertEqual(json.loads(output),expected)
        code='require '+json.dumps(str(integration))+';try {\\SLS\\MassNotify\\EnterpriseClusterIntegration::effect(["runtime_enabled"=>false],[],"sms","fixture",function(){echo "SENT";});exit(1);}catch(DomainException $e){echo "blocked";}'
        self.assertEqual(subprocess.check_output(['php','-r',code]),b'blocked')

    def test_root_execution_rejects_untrusted_files_and_nonroot_control(self):
        path=self.root/'helper.py';path.write_text('fixture');os.chmod(path,0o666)
        with self.assertRaises(RuntimeError):CONSOLE.protected_file(path)
        os.chmod(path,0o600);alias=self.root/'alias';alias.symlink_to(path)
        with self.assertRaises(RuntimeError):CONSOLE.protected_file(alias)
        with patch.object(CONSOLE.os,'geteuid',return_value=self.uid),patch('sys.argv',['slsconsole','stop']):
            with self.assertRaisesRegex(RuntimeError,'requires root'):CONSOLE.main()


if __name__=='__main__':unittest.main()
