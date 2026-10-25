#!/usr/bin/python3
"""Disposable static recovery fixtures: no installed paths or subprocesses."""
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('recovery',ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_installer_recovery.py')
r=importlib.util.module_from_spec(spec); spec.loader.exec_module(r)

class Fixture(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='sls-recovery-test-')
        self.base=Path(self.tmp.name); self.fs=self.base/'fs'; self.fs.mkdir()
        self.private=self.base/'recovery'; self.private.mkdir(mode=0o700)
        self.cron=b'12 1 * * * /usr/bin/true\n* * * * * /usr/local/bin/sls_mass_notify/old.php\n'
        def cron(body=None):
            if body is not None: self.cron=body
            return self.cron
        self.user_cron=b'3 4 * * * /usr/bin/unrelated-job\n* * * * * /usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh\n'
        def user_cron(body=None):
            if body is not None: self.user_cron=body
            return self.user_cron
        self.commands=[]; self.service={'enabled':'not-found','active':'inactive'}
        self.helper=r.Recovery(self.private/'static',self.fs,cron,self.command,user_cron=user_cron)
        self.file(r.RUNTIME+'/worker.py',b'old worker\n',0o755)
        self.file('/var/www/html/api/sipnotify/index.php',b'old web\n')
        self.file('/etc/asterisk/extensions_custom.conf',b'original dialplan\n')
        self.file('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config',b'config-secret\n')
        self.file('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sipnotify/events.jsonl',b'original ledger\n')
        self.file('/var/www/html/sls_mass_notify/images/alert.png',b'current alert image')
    def command(self,args,check=True):
        self.commands.append(args)
        if args[1]=='is-enabled': return self.service['enabled']
        if args[1]=='is-active': return self.service['active']
        if args[1]=='show' and 'LoadState' in args[-1]: return 'LoadState='+('not-found' if self.service['enabled']=='not-found' else 'loaded')+'\nUnitFileState='+self.service['enabled']+'\nActiveState='+self.service['active']
        if args[1]=='show' and args[-1]=='--property=DropInPaths': return 'DropInPaths='
        if args[1]=='show': return 'User=asterisk\nGroup=asterisk\nFragmentPath=/etc/systemd/system/'+r.SERVICE+'\nDropInPaths='
        if args[1] in ('enable','disable'): self.service['enabled']='enabled' if args[1]=='enable' else 'disabled'
        if args[1] in ('restart','stop'): self.service['active']='active' if args[1]=='restart' else 'inactive'
        return ''
    def tearDown(self): self.tmp.cleanup()
    def file(self,path,body,mode=0o644):
        dest=self.helper.dest(path); dest.parent.mkdir(parents=True,exist_ok=True); dest.write_bytes(body); dest.chmod(mode); return dest
    def snapshot(self): return self.helper.snapshot_create()
    def test_current_service_template_matches_the_php_generator(self):
        import subprocess
        script=r'''require $argv[1];
class ServiceTemplateFixture { use \FreePBX\modules\SlsPhoneEventService;
public function contents(){return $this->phoneEventServiceContents();}}
echo (new ServiceTemplateFixture())->contents();'''
        body=subprocess.check_output(['php','-r',script,str(ROOT/'slsmassnotifyserver/PhoneEventService.php')])
        self.assertEqual(body,r.SAFE_UNIT)

    def test_exact_current_and_legacy_units_support_preflight_and_recovery(self):
        for unit in r.SUPPORTED_UNITS:
            with self.subTest(current=unit==r.SAFE_UNIT):
                self.service={'enabled':'enabled','active':'active'}
                path=self.file('/etc/systemd/system/'+r.SERVICE,unit)
                self.assertTrue(self.helper.preflight_services()['rollback_supported'])
                self.assertEqual(path.read_bytes(),unit)
                self.assertFalse(any(row[1] in ('stop','disable','restart') for row in self.commands))
                self.snapshot();self.helper.restore();self.helper.restore_services()
                self.assertEqual(path.read_bytes(),unit)
                self.assertEqual(self.service,{'enabled':'enabled','active':'active'})
                import shutil
                shutil.rmtree(self.private/'static');self.commands.clear()

    def test_noncanonical_resource_and_execution_changes_require_review(self):
        self.service={'enabled':'enabled','active':'active'}
        for unit in (r.SAFE_UNIT.replace(b'CPUWeight=50',b'CPUWeight=100'),
                     r.SAFE_UNIT+b'ExecStartPre=+/bin/true\n',
                     r.LEGACY_SAFE_UNIT.replace(b'User=asterisk',b'User=root')):
            self.file('/etc/systemd/system/'+r.SERVICE,unit)
            with self.assertRaisesRegex(r.RecoveryError,'differs'):self.helper.preflight_services()
        self.assertFalse(any(row[1] in ('stop','disable','restart') for row in self.commands))

    def test_unsupported_service_state_is_rejected_before_snapshot_or_changes(self):
        for enabled in ('masked', 'static', 'enabled-runtime', 'indirect'):
            self.service = {'enabled': enabled, 'active': 'inactive'}
            with self.assertRaisesRegex(r.RecoveryError, 'administrator-managed'):
                self.helper.preflight_services()
            self.assertFalse((self.private/'static').exists())
            self.assertFalse(any(row[1] in ('stop', 'disable', 'restart') for row in self.commands))

    def test_service_override_requires_review_before_mutation(self):
        self.service = {'enabled': 'enabled', 'active': 'active'}
        self.file('/etc/systemd/system/'+r.SERVICE, r.SAFE_UNIT)
        def command(args, check=True):
            if args[1] == 'show' and args[-1] == '--property=DropInPaths':
                return 'DropInPaths=/etc/systemd/system/sls-mass-notify-phone-events.service.d/custom.conf'
            return self.command(args, check)
        self.helper.command = command
        with self.assertRaisesRegex(r.RecoveryError, 'override'): self.helper.preflight_services()
        self.assertFalse((self.private/'static').exists())
    def test_roundtrip_preserves_data_config_images_and_concurrent_unrelated_cron(self):
        self.snapshot()
        self.file(r.RUNTIME+'/worker.py',b'new worker\n',0o755)
        self.file(r.RUNTIME+'/new.py',b'new only\n',0o755)
        self.file('/etc/asterisk/extensions_custom.conf',b'new dialplan\n')
        data='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sipnotify/events.jsonl'
        self.file(data,b'new operational ledger\n')
        self.cron+=b'13 2 * * * /usr/bin/true\n'
        result=self.helper.restore()
        self.assertFalse(result['automatic_activation_safe'])
        self.assertEqual(self.helper.dest(r.RUNTIME+'/worker.py').read_bytes(),b'old worker\n')
        self.assertFalse(self.helper.dest(r.RUNTIME+'/new.py').exists())
        self.assertEqual(self.helper.dest(data).read_bytes(),b'new operational ledger\n')
        self.assertEqual(self.helper.dest('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config').read_bytes(),b'config-secret\n')
        self.assertEqual(self.helper.dest('/var/www/html/sls_mass_notify/images/alert.png').read_bytes(),b'current alert image')
        self.assertIn(b'13 2',self.cron); self.assertNotIn(b'sls_mass_notify',self.cron)
        self.assertTrue(self.helper.verify()['restored_static_verified'])
    def test_prior_sls_user_jobs_restored_without_reverting_current_unrelated_jobs(self):
        old_sls=[line for line in self.user_cron.splitlines() if r.sls_user_job(line)]
        self.snapshot()
        self.user_cron=b'7 8 * * * /usr/bin/new-administrator-job\n# keep this current comment\n* * * * * /usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php\n'
        self.helper.restore()
        self.assertEqual([line for line in self.user_cron.splitlines() if r.sls_user_job(line)],old_sls)
        self.assertIn(b'new-administrator-job',self.user_cron)
        self.assertIn(b'# keep this current comment',self.user_cron)
        self.assertNotIn(b'/usr/bin/unrelated-job',self.user_cron)
        self.assertNotIn(b'sls_mass_notify',self.cron)
        self.assertTrue(self.helper.verify()['snapshot_verified'])
    def test_user_jobs_stay_disabled_when_static_restore_is_incomplete(self):
        self.snapshot()
        original=self.helper.write_at
        def fail(parent,name,*args,**kwargs):
            if name=='worker.py': raise OSError('fixture full disk')
            return original(parent,name,*args,**kwargs)
        with patch.object(self.helper,'write_at',side_effect=fail):
            with self.assertRaises(OSError): self.helper.restore()
        self.assertFalse(any(r.sls_user_job(line) for line in self.user_cron.splitlines()))
        self.assertIn(b'unrelated-job',self.user_cron)
        self.assertFalse(any(marker.encode() in self.cron for marker in r.CRON_MARKERS))

    def test_user_jobs_are_restored_only_after_static_reinventory(self):
        self.snapshot()
        self.file(r.RUNTIME+'/worker.py',b'candidate worker\n',0o755)
        expected=b'old worker\n'
        original=self.helper.user_cron
        observed=[]
        def checked(body=None):
            if body is not None and any(r.sls_user_job(line) for line in body.splitlines()):
                observed.append(self.helper.dest(r.RUNTIME+'/worker.py').read_bytes())
            return original(body)
        self.helper.user_cron=checked
        self.helper.restore()
        self.assertEqual(observed,[expected])
    def test_user_cron_snapshot_tampering_is_refused_before_static_changes(self):
        self.snapshot(); self.file(r.RUNTIME+'/worker.py',b'candidate worker')
        (self.private/'static/asterisk-cron.original').write_bytes(b'unapproved')
        with self.assertRaises(r.RecoveryError): self.helper.restore()
        self.assertEqual(self.helper.dest(r.RUNTIME+'/worker.py').read_bytes(),b'candidate worker')
    def test_fixed_recordings_and_legacy_aliases_restore_but_user_audio_is_untouched(self):
        for path in (*r.CUSTOM_RECORDINGS,*r.FACTORY_TONES):
            if path!=r.CUSTOM_RECORDINGS[0]: self.file(path,('prior '+path).encode())
        user_path='/var/lib/asterisk/sounds/en/custom/User_Recording.wav'
        self.file(user_path,b'prior user audio')
        self.snapshot()
        for path in (*r.CUSTOM_RECORDINGS,*r.FACTORY_TONES): self.file(path,b'candidate audio')
        self.file(user_path,b'concurrent user audio')
        self.helper.restore()
        self.assertFalse(self.helper.dest(r.CUSTOM_RECORDINGS[0]).exists())
        for path in (*r.CUSTOM_RECORDINGS[1:],*r.FACTORY_TONES):
            self.assertEqual(self.helper.dest(path).read_bytes(),('prior '+path).encode())
        self.assertEqual(self.helper.dest(user_path).read_bytes(),b'concurrent user audio')
    def test_original_snapshot_cannot_be_replaced(self):
        self.snapshot(); original=(self.private/'static/manifest.json').read_bytes()
        self.file(r.RUNTIME+'/worker.py',b'other')
        with self.assertRaises(r.RecoveryError): self.snapshot()
        self.assertEqual(original,(self.private/'static/manifest.json').read_bytes())
    def test_tampered_blob_refused_before_destination_changes(self):
        self.snapshot(); manifest=self.helper.load(); digest=manifest['records'][r.RUNTIME+'/worker.py']['sha256']
        (self.private/'static/blobs'/digest).write_bytes(b'tampered')
        self.file(r.RUNTIME+'/worker.py',b'new')
        with self.assertRaises(r.RecoveryError): self.helper.restore()
        self.assertEqual(self.helper.dest(r.RUNTIME+'/worker.py').read_bytes(),b'new')
    def test_manifest_cannot_add_operational_path(self):
        self.snapshot(); path=self.private/'static/manifest.json'; manifest=json.loads(path.read_text())
        manifest['records']['/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config']={'kind':'absent'}
        path.write_text(json.dumps(manifest))
        with self.assertRaises(r.RecoveryError): self.helper.restore()
    def test_snapshot_rejects_hardlink_and_retains_failed_stage(self):
        original=self.helper.dest(r.RUNTIME+'/worker.py'); os.link(original,original.parent/'linked.py')
        with self.assertRaises(r.RecoveryError): self.snapshot()
        self.assertTrue(list(self.private.glob('.static-building-*')))
        self.assertFalse((self.private/'static').exists())
    def test_snapshot_fifo_never_blocks(self):
        os.mkfifo(self.helper.dest(r.RUNTIME+'/fifo'))
        with self.assertRaises(r.RecoveryError): self.snapshot()
    def test_runtime_owned_by_service_account_rejected(self):
        runtime=self.helper.dest(r.RUNTIME); os.chown(runtime,self.helper.uid,-1)
        with self.assertRaises(r.RecoveryError): self.snapshot()

    def test_root_cli_and_piper_wrapper_owned_by_service_account_are_rejected(self):
        for path in ('/usr/local/bin/slsconsole', '/usr/local/bin/piper'):
            with self.subTest(path=path):
                fixture=Fixture(methodName='runTest'); fixture.setUp()
                try:
                    target=fixture.file(path,b'#!/bin/sh\nexit 0\n',0o755)
                    os.chown(target,fixture.helper.uid,-1)
                    with self.assertRaisesRegex(r.RecoveryError,'ownership/mode'):
                        fixture.snapshot()
                finally: fixture.tearDown()
    def test_writable_static_web_file_is_rejected(self):
        path=self.helper.dest('/var/www/html/api/sipnotify/index.php'); path.chmod(0o664)
        with self.assertRaisesRegex(r.RecoveryError,'ownership/mode'): self.snapshot()

    def test_freepbx_service_group_modes_roundtrip_without_permission_adoption(self):
        web=self.helper.dest('/var/www/html/api/sipnotify')
        source=web/'index.php'
        config=self.helper.dest('/etc/asterisk/extensions_custom.conf')
        for path,mode in [(web,0o775),(source,0o664),(config,0o660)]:
            os.chown(path,self.helper.uid,self.helper.gid);path.chmod(mode)
        root_web=self.file('/var/www/html/api/sipnotify/root-managed.php',b'root managed web\n',0o664)
        os.chown(root_web,0,self.helper.gid)
        before={str(path):(path.stat().st_ino,path.stat().st_uid,path.stat().st_gid,stat.S_IMODE(path.stat().st_mode))
                for path in (web,source,config,root_web)}
        self.snapshot()
        self.assertEqual(before,{str(path):(path.stat().st_ino,path.stat().st_uid,path.stat().st_gid,stat.S_IMODE(path.stat().st_mode))
                                for path in (web,source,config,root_web)})
        self.assertEqual(source.read_bytes(),b'old web\n')
        manifest=self.helper.load()
        blob=self.private/'static/blobs'/manifest['records']['/var/www/html/api/sipnotify/index.php']['sha256']
        self.assertEqual((blob.stat().st_uid,stat.S_IMODE(blob.stat().st_mode)),(0,0o600))
        source.write_bytes(b'candidate web\n');config.write_bytes(b'candidate dialplan\n')
        self.helper.restore()
        self.assertEqual(source.read_bytes(),b'old web\n')
        self.assertEqual(config.read_bytes(),b'original dialplan\n')
        for path in (web,source,config,root_web):
            self.assertEqual((path.stat().st_uid,path.stat().st_gid,stat.S_IMODE(path.stat().st_mode)),before[str(path)][1:])
        self.assertTrue(self.helper.verify()['restored_static_verified'])

    def test_static_inventory_preflight_creates_no_snapshot_or_operational_changes(self):
        source=self.helper.dest('/var/www/html/api/sipnotify/index.php')
        before=(source.stat().st_ino,source.read_bytes(),self.cron,self.user_cron)
        result=self.helper.preflight_inventory()
        self.assertTrue(result['ok'] and result['read_only'])
        self.assertGreater(result['static_entries'],len(r.TREES))
        self.assertGreater(result['static_bytes'],0)
        self.assertFalse((self.private/'static').exists())
        self.assertEqual(list(self.private.iterdir()),[])
        self.assertEqual((source.stat().st_ino,source.read_bytes(),self.cron,self.user_cron),before)
        self.assertEqual(self.commands,[])

    def test_static_inventory_preflight_rejects_unsafe_modes_without_artifacts(self):
        source=self.helper.dest('/var/www/html/api/sipnotify/index.php')
        os.chown(source,self.helper.uid,self.helper.gid);source.chmod(0o666)
        with self.assertRaisesRegex(r.RecoveryError,'ownership/mode'):self.helper.preflight_inventory()
        self.assertEqual(list(self.private.iterdir()),[])
        self.assertEqual(source.read_bytes(),b'old web\n')
        self.assertEqual(self.commands,[])

    def test_group_writable_service_files_with_an_unrelated_group_are_rejected(self):
        for directory in (False,True):
            with self.subTest(directory=directory):
                fixture=Fixture(methodName='runTest');fixture.setUp()
                try:
                    path=fixture.helper.dest('/var/www/html/api/sipnotify'+('' if directory else '/index.php'))
                    os.chown(path,fixture.helper.uid,0);path.chmod(0o775 if directory else 0o664)
                    with self.assertRaisesRegex(r.RecoveryError,'ownership/mode'):fixture.snapshot()
                finally:fixture.tearDown()

    def test_service_group_does_not_allow_world_write_or_writable_root_runtime(self):
        for path,uid,mode in [('/var/www/html/api/sipnotify',self.helper.uid,0o777),
                              ('/var/www/html/api/sipnotify/index.php',self.helper.uid,0o666),
                              (r.RUNTIME,0,0o775),(r.RUNTIME+'/worker.py',0,0o775),
                              (r.COMPAT,0,0o775)]:
            with self.subTest(path=path):
                fixture=Fixture(methodName='runTest');fixture.setUp()
                try:
                    target=fixture.helper.dest(path)
                    if path==r.COMPAT:target.mkdir(parents=True)
                    os.chown(target,uid,fixture.helper.gid);target.chmod(mode)
                    with self.assertRaisesRegex(r.RecoveryError,'ownership/mode'):fixture.snapshot()
                finally:fixture.tearDown()

    def test_recovery_manifest_rejects_unsafe_service_group_modes_before_restore(self):
        self.snapshot();path=self.private/'static/manifest.json';original=path.read_bytes()
        for item,changes in [('/var/www/html/api/sipnotify/index.php',{'uid':self.helper.uid,'gid':0,'mode':0o664}),
                             ('/var/www/html/api/sipnotify',{'uid':self.helper.uid,'gid':self.helper.gid,'mode':0o777}),
                             (r.RUNTIME+'/worker.py',{'uid':0,'gid':self.helper.gid,'mode':0o775})]:
            with self.subTest(path=item):
                manifest=json.loads(original);manifest['records'][item].update(changes)
                path.write_text(json.dumps(manifest))
                with self.assertRaisesRegex(r.RecoveryError,'Writable static recovery record'):self.helper.load()
        path.write_bytes(original)
    def test_web_parent_symlink_rejected_without_following(self):
        api=self.helper.dest('/var/www/html/api/sipnotify'); (api/'index.php').unlink(); api.rmdir()
        outside=self.base/'outside'; outside.mkdir(); (outside/'index.php').write_bytes(b'untouched')
        api.symlink_to(outside,target_is_directory=True)
        with self.assertRaises((r.RecoveryError,OSError)): self.snapshot()
        self.assertEqual((outside/'index.php').read_bytes(),b'untouched')
    def test_piper_links_preserved_without_dereference(self):
        venv=self.helper.dest(r.RUNTIME+'/piper/venv'); (venv/'bin').mkdir(parents=True); (venv/'lib').mkdir()
        (venv/'lib64').symlink_to('lib'); (venv/'bin/python3').symlink_to('/usr/bin/python3')
        self.snapshot(); (venv/'bin/python3').unlink()
        self.helper.restore()
        self.assertEqual(os.readlink(venv/'bin/python3'),'/usr/bin/python3')
    def test_unexpected_runtime_symlink_rejected(self):
        path=self.helper.dest(r.RUNTIME+'/piper/venv/bin/python3'); path.parent.mkdir(parents=True); path.symlink_to('/tmp/untrusted-python')
        with self.assertRaises(r.RecoveryError): self.snapshot()
    def test_unsafe_current_tree_rejected_before_any_restore(self):
        self.snapshot(); self.file(r.RUNTIME+'/worker.py',b'new')
        api=self.helper.dest('/var/www/html/api/sipnotify'); os.mkfifo(api/'fifo')
        with self.assertRaises(r.RecoveryError): self.helper.restore()
        self.assertEqual(self.helper.dest(r.RUNTIME+'/worker.py').read_bytes(),b'new')
    def test_fsync_failure_keeps_original_snapshot_and_evidence(self):
        self.snapshot(); self.file(r.RUNTIME+'/worker.py',b'new')
        with patch.object(r.os,'fsync',side_effect=OSError('fixture disk failure')):
            with self.assertRaises(OSError): self.helper.restore()
        self.assertTrue((self.private/'static/manifest.json').exists())
        self.assertTrue(self.helper.load()['records'])
    def test_unprotected_recovery_parent_rejected(self):
        self.private.chmod(0o755)
        with self.assertRaises(r.RecoveryError): self.snapshot()
    def test_snapshot_symlink_is_rejected(self):
        self.snapshot(); (self.private/'alias').symlink_to(self.private/'static',target_is_directory=True)
        unsafe=r.Recovery(self.private/'alias',self.fs,self.helper.cron)
        with self.assertRaises(OSError): unsafe.load()
    def test_manifest_json_and_scalar_types_are_strict(self):
        self.snapshot(); path=self.private/'static/manifest.json'; original=path.read_bytes()
        malformed=(
            original.replace(b'"schema":1',b'"schema":true',1),
            original.replace(b'"schema":1',b'"schema":1e309',1),
            original.replace(b'"schema":1',b'"schema":1,"schema":1',1),
        )
        for body in malformed:
            with self.subTest(body=body[-80:]):
                path.write_bytes(body)
                with self.assertRaises(r.RecoveryError): self.helper.load()
        manifest=json.loads(original)
        for key,value in (('uid',True),('gid',True),('mode',True),('size',True)):
            with self.subTest(key=key):
                changed=json.loads(original); changed['records'][r.RUNTIME+'/worker.py'][key]=value
                path.write_text(json.dumps(changed))
                with self.assertRaises(r.RecoveryError): self.helper.load()
        changed=json.loads(original); changed['records'][r.RUNTIME+'/worker.py']['ignored']='value'
        path.write_text(json.dumps(changed))
        with self.assertRaisesRegex(r.RecoveryError,'entry members'): self.helper.load()
        for key,value in (
            ('automatic_activation_safe',True),
            ('cron_sha256','A'*64),
            ('user_cron_sha256',False),
            ('unknown_member','unexpected'),
        ):
            with self.subTest(key=key):
                changed=json.loads(original); changed[key]=value
                path.write_text(json.dumps(changed))
                with self.assertRaisesRegex(r.RecoveryError,'static manifest'): self.helper.load()

    def test_read_only_static_group_is_preserved_without_group_allowlist(self):
        worker=self.helper.dest(r.RUNTIME+'/worker.py'); os.chown(worker,-1,12345)
        self.snapshot()
        self.assertEqual(self.helper.load()['records'][r.RUNTIME+'/worker.py']['gid'],12345)
        self.file(r.RUNTIME+'/worker.py',b'candidate',0o755)
        self.helper.restore()
        self.assertEqual(worker.stat().st_gid,12345)

    def test_invalid_posix_group_ids_are_rejected(self):
        self.snapshot(); path=self.private/'static/manifest.json'; original=json.loads(path.read_text())
        for gid in (-1,r.MAX_POSIX_ID+1,True):
            with self.subTest(gid=gid):
                changed=json.loads(json.dumps(original)); changed['records'][r.RUNTIME+'/worker.py']['gid']=gid
                path.write_text(json.dumps(changed))
                with self.assertRaisesRegex(r.RecoveryError,'ownership/mode'): self.helper.load()

    def test_descriptor_walk_rejects_relative_paths(self):
        with self.assertRaisesRegex(r.RecoveryError,'must be absolute'):
            self.helper.directory('relative/path')

    def test_failed_atomic_write_removes_private_temporary(self):
        parent=self.helper.directory(self.private)
        try:
            with patch.object(r.os,'fchown',side_effect=OSError('fixture ownership failure')):
                with self.assertRaises(OSError): self.helper.write_at(parent,'result',b'value')
            self.assertFalse((self.private/'result').exists())
            self.assertEqual(list(self.private.glob('.sls-recovery-*')),[])
        finally: os.close(parent)

    def test_non_object_manifest_is_rejected_cleanly(self):
        self.snapshot(); path=self.private/'static/manifest.json'; path.write_text('[]')
        with self.assertRaisesRegex(r.RecoveryError,'static manifest'): self.helper.load()
    def test_activation_approval_rejects_boolean_schema(self):
        approval={'schema':True,'source':'fresh-install','files':{},'root_jobs':[]}
        with self.assertRaises(r.RecoveryError): self.helper.validate_activation_approval(approval,{},b'')
    def test_static_removal_has_an_aggregate_bound(self):
        path=r.RUNTIME+'/worker.py'; self.helper.removed=r.MAX_FILES
        with self.assertRaisesRegex(r.RecoveryError,'entry bound'): self.helper.remove(path)
        self.assertEqual(self.helper.dest(path).read_bytes(),b'old worker\n')
    def test_inventory_paths_reject_terminal_controls_and_surrogates(self):
        self.assertFalse(r.allowed(r.RUNTIME+'/bad\x7fname'))
        self.assertFalse(r.allowed(r.RUNTIME+'/bad\udcffname'))
    def test_short_writes_complete_without_truncation(self):
        original=r.os.write
        def short(fd,body): return original(fd,body[:3])
        with patch.object(r.os,'write',side_effect=short): self.snapshot()
        self.file(r.RUNTIME+'/worker.py',b'new')
        with patch.object(r.os,'write',side_effect=short): self.helper.restore()
        self.assertEqual(self.helper.dest(r.RUNTIME+'/worker.py').read_bytes(),b'old worker\n')
    def test_source_changes_during_read_abort_snapshot(self):
        path=self.helper.dest(r.RUNTIME+'/worker.py'); inode=path.stat().st_ino
        original=r.os.read; changed=False
        def changing(fd,size):
            nonlocal changed
            result=original(fd,size)
            if not changed and os.fstat(fd).st_ino==inode:
                changed=True
                with path.open('ab') as stream: stream.write(b'race')
            return result
        with patch.object(r.os,'read',side_effect=changing):
            with self.assertRaises(r.RecoveryError): self.snapshot()
        self.assertFalse((self.private/'static').exists())
    def test_blob_hardlink_refused_before_restore(self):
        self.snapshot(); manifest=self.helper.load(); digest=manifest['records'][r.RUNTIME+'/worker.py']['sha256']
        os.link(self.private/'static/blobs'/digest,self.private/'external-link')
        with self.assertRaises(r.RecoveryError): self.helper.restore()

    def test_blob_directory_open_failure_closes_snapshot_descriptor(self):
        self.snapshot()
        opened=[]; blob_opens=0; original=r.os.open
        def fail_second_blob(name,flags,*args,**kwargs):
            nonlocal blob_opens
            if name=='blobs' and kwargs.get('dir_fd') is not None:
                blob_opens+=1
                if blob_opens==2:
                    opened.append(kwargs['dir_fd'])
                    raise OSError('fixture snapshot blob open failure')
            return original(name,flags,*args,**kwargs)
        with patch.object(r.os,'open',side_effect=fail_second_blob):
            with self.assertRaisesRegex(OSError,'blob open failure'): self.helper.restore()
        self.assertEqual(len(opened),1)
        with self.assertRaises(OSError): os.fstat(opened[0])

    def test_partial_restore_disables_sls_root_jobs_before_file_writes(self):
        self.snapshot()
        original=self.helper.write_at
        def failing(parent,name,*args,**kwargs):
            if name=='worker.py': raise OSError('fixture full destination')
            return original(parent,name,*args,**kwargs)
        with patch.object(self.helper,'write_at',side_effect=failing):
            with self.assertRaises(OSError): self.helper.restore()
        self.assertNotIn(b'sls_mass_notify',self.cron)
        self.assertTrue((self.private/'static/restore-started.json').exists())
        self.assertTrue(self.helper.load()['records'])
    def test_service_state_recovery_fixed_hardened_unit_only(self):
        self.file('/etc/systemd/system/'+r.SERVICE,r.SAFE_UNIT)
        self.service={'enabled':'enabled','active':'active'}
        self.snapshot(); self.service={'enabled':'disabled','active':'inactive'}
        self.helper.restore(); result=self.helper.restore_services()
        self.assertTrue(result['collector_state_restored'])
        self.assertEqual(self.service,{'enabled':'enabled','active':'active'})
        self.assertIn(['/usr/bin/systemctl','reload','apache2'],self.commands)
        self.assertNotIn(['/usr/bin/systemctl','restart','asterisk'],self.commands)
    def test_unsafe_old_unit_never_activated(self):
        self.file('/etc/systemd/system/'+r.SERVICE,r.SAFE_UNIT+b'ExecStartPre=+/bin/false\n')
        self.service={'enabled':'enabled','active':'active'}
        self.snapshot(); self.helper.restore(); self.commands.clear()
        with self.assertRaises(r.RecoveryError): self.helper.restore_services()
        self.assertEqual(self.commands,[])
    def test_loaded_systemd_override_blocks_activation(self):
        self.file('/etc/systemd/system/'+r.SERVICE,r.SAFE_UNIT)
        self.service={'enabled':'enabled','active':'active'}
        self.snapshot(); self.helper.restore()
        original=self.helper.command
        def overridden(args,check=True):
            if args[1]=='show': return 'User=root\nGroup=root\nFragmentPath=/etc/systemd/system/'+r.SERVICE+'\nDropInPaths=/etc/systemd/system/override.conf'
            return original(args,check)
        self.commands.clear(); self.helper.command=overridden
        with self.assertRaises(r.RecoveryError): self.helper.restore_services()
        self.assertNotIn(['/usr/bin/systemctl','restart',r.SERVICE],self.commands)
    def test_trust_pointers_restored_but_generation_left_intact(self):
        self.file(r.TRUST+'/slsmassnotifyserver.active.json',b'{"generation":"prior"}')
        self.file(r.TRUST+'/generations/new/inventory.json',b'new immutable generation')
        self.snapshot(); self.file(r.TRUST+'/slsmassnotifyserver.active.json',b'{"generation":"candidate"}')
        self.helper.restore()
        self.assertEqual(self.helper.dest(r.TRUST+'/slsmassnotifyserver.active.json').read_bytes(),b'{"generation":"prior"}')
        self.assertTrue(self.helper.dest(r.TRUST+'/generations/new/inventory.json').exists())

    def approved_snapshot(self):
        import hashlib
        code=b'authenticated prior maintenance fixture\n'
        self.file(r.RUNTIME+'/sls_mass_notify_maintenance.sh',code,0o755)
        job='* * * * * /usr/bin/timeout 900 '+r.RUNTIME+'/sls_mass_notify_maintenance.sh'
        self.cron=b'12 1 * * * /usr/bin/true\n'+job.encode()+b'\n'
        self.approval=self.private/'prior-approval.json'
        self.approval.write_text(json.dumps({'schema':1,'source':'publisher-verified-previous-release','version':'0.1.4-beta',
            'archive_sha256':'a'*64,'files':{r.RUNTIME+'/sls_mass_notify_maintenance.sh':hashlib.sha256(code).hexdigest()},'root_jobs':[job]}))
        self.approval.chmod(0o600);self.helper.approval=self.approval
        self.snapshot()
        return code,job

    def test_approved_previous_maintenance_restored_after_full_rollback_and_retry(self):
        code,job=self.approved_snapshot()
        self.file(r.RUNTIME+'/sls_mass_notify_maintenance.sh',b'candidate',0o755)
        self.cron+=b'13 2 * * * /usr/bin/true\n'
        self.helper.restore()
        self.assertNotIn(job.encode(),self.cron)
        self.assertTrue(self.helper.restore_maintenance()['previous_maintenance_restored'])
        self.assertIn(job.encode(),self.cron);self.assertIn(b'13 2',self.cron)
        self.assertTrue(self.helper.verify()['snapshot_verified'])
        self.assertTrue(self.helper.restore_maintenance()['previous_maintenance_restored'])
        self.assertEqual(self.cron.count(job.encode()),1)
        self.helper.restore()
        self.assertNotIn(job.encode(),self.cron)
        self.assertTrue(self.helper.restore_maintenance()['previous_maintenance_restored'])
        self.assertEqual(self.cron.count(job.encode()),1)

    def test_missing_or_changed_prior_execution_approval_does_not_revive_jobs(self):
        self.snapshot();self.helper.restore()
        with self.assertRaises(r.RecoveryError):self.helper.restore_maintenance()
        self.assertNotIn(b'sls_mass_notify',self.cron)

    def test_cron_runner_restoration_requires_runner_and_underlying_signed_job(self):
        import hashlib
        scripts={r.RUNTIME+'/sls_cron_runner.py':b'authenticated fixed runner',r.RUNTIME+'/sls_mass_notify_maintenance.sh':b'authenticated maintenance'}
        for path,code in scripts.items():self.file(path,code,0o755)
        job='* * * * * /usr/bin/python3 -I '+r.RUNTIME+'/sls_cron_runner.py maintenance >/dev/null 2>&1'
        self.cron=b'12 1 * * * /usr/bin/true\n'+job.encode()+b'\n'
        self.approval=self.private/'prior-approval.json'
        approval={'schema':1,'source':'publisher-verified-previous-release','version':'0.1.5-beta',
            'archive_sha256':'a'*64,'files':{path:hashlib.sha256(code).hexdigest() for path,code in scripts.items()},'root_jobs':[job]}
        self.approval.write_text(json.dumps(approval));self.approval.chmod(0o600);self.helper.approval=self.approval
        self.snapshot();self.helper.restore();self.assertNotIn(job.encode(),self.cron)
        self.assertTrue(self.helper.restore_maintenance()['previous_maintenance_restored']);self.assertIn(job.encode(),self.cron)
        manifest=self.helper.load()
        for removed in scripts:
            unsafe={**approval,'files':{path:digest for path,digest in approval['files'].items() if path!=removed}}
            with self.assertRaisesRegex(r.RecoveryError,'authenticated source approval'):
                self.helper.validate_activation_approval(unsafe,manifest['records'],self.cron)

    def test_user_cron_recognizes_new_runner_without_matching_unrelated_jobs(self):
        self.assertTrue(r.USER_CRON_JOB.search(b'* * * * * /usr/bin/python3 -I '+r.RUNTIME.encode()+b'/sls_cron_runner.py weather >/dev/null 2>&1'))
        self.assertFalse(r.USER_CRON_JOB.search(b'* * * * * /root/unrelated_sls_cron_runner.py_not_owned'))

    def test_prior_runtime_changed_after_restore_blocks_maintenance_before_cron_write(self):
        _,job=self.approved_snapshot();self.helper.restore()
        self.file(r.RUNTIME+'/sls_mass_notify_maintenance.sh',b'unapproved current code',0o755)
        with self.assertRaises(r.RecoveryError):self.helper.restore_maintenance()
        self.assertNotIn(job.encode(),self.cron)

    def test_prior_cron_changed_between_approval_and_snapshot_is_rejected(self):
        import hashlib
        code=b'reviewed prior fixture';path=r.RUNTIME+'/sls_mass_notify_maintenance.sh'
        self.file(path,code,0o755)
        approval=self.private/'approval.json';approval.write_text(json.dumps({'schema':1,'source':'publisher-verified-previous-release',
            'version':'0.1.4-beta','archive_sha256':'a'*64,'files':{path:hashlib.sha256(code).hexdigest()},'root_jobs':[]}));approval.chmod(0o600)
        self.helper.approval=approval
        with self.assertRaises(r.RecoveryError):self.snapshot()
        self.assertFalse((self.private/'static').exists())

if __name__=='__main__': unittest.main()
