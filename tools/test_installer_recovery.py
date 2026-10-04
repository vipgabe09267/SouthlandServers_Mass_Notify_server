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
        if args[1]=='show': return 'User=asterisk\nGroup=asterisk\nFragmentPath=/etc/systemd/system/'+r.SERVICE+'\nDropInPaths='
        if args[1] in ('enable','disable'): self.service['enabled']='enabled' if args[1]=='enable' else 'disabled'
        if args[1] in ('restart','stop'): self.service['active']='active' if args[1]=='restart' else 'inactive'
        return ''
    def tearDown(self): self.tmp.cleanup()
    def file(self,path,body,mode=0o644):
        dest=self.helper.dest(path); dest.parent.mkdir(parents=True,exist_ok=True); dest.write_bytes(body); dest.chmod(mode); return dest
    def snapshot(self): return self.helper.snapshot_create()
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

if __name__=='__main__': unittest.main()
