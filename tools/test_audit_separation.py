#!/usr/bin/python3
"""Bounded audit cutover, crash recovery and independent security capacity."""
import fcntl,importlib.util,json,os,subprocess,tempfile,unittest,sys
sys.dont_write_bytecode=True
from pathlib import Path
from unittest import mock
ROOT=Path(__file__).resolve().parents[1];HELPERS=ROOT/'slsmassnotifyserver/bin/sls_mass_notify'
def module(name,file):
 spec=importlib.util.spec_from_file_location(name,HELPERS/file);value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value);return value
migration=module('audit_migration_fixture','sls_audit_separation.py');storage=migration.storage;backup=module('audit_backup_fixture','sls_operational_backup.py')
def line(**extra):
 return (json.dumps(dict(created_at='2026-10-04T00:00:00Z',ip='192.0.2.1',method='POST',action='get_status',status=200,ok=True,**extra))+'\n').encode()
class AuditSeparationTests(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory(prefix='sls-audit-split-');self.addCleanup(self.temp.cleanup);self.root=Path(self.temp.name);self.root.chmod(0o700)
  self.control=self.root/'control-api-audit.jsonl';self.security=self.root/'security-audit.jsonl';self.human=json.loads(line(actor='operator:fixture',credential_id='api_'+'a'*24).decode());self.human['action']='operator_sign_in_completed'
 def seed(self):
  human=json.dumps(self.human).encode()+b'\r\n';admin=line(actor='web:fixture-owner').replace(b'get_status',b'config_export_encrypted')
  key=line(credential_id='api_'+'a'*24);unknown=line();no_key=line(actor='api:no-key',credentials_present=False)
  # Duplicated members, malformed rows and unfinished tails are never classified.
  ambiguous=b'{"action":"operator_sign_in_completed","actor":"operator:fixture","actor":"other"}\nmalformed\n{"unfinished":'
  self.original=key+human+unknown+admin+no_key+ambiguous;self.moved=human+admin;self.kept=key+unknown+no_key+ambiguous;self.prior=line(actor='operator:existing').replace(b'get_status',b'operator_login_password_accepted')
  self.control.write_bytes(self.original);self.security.write_bytes(self.prior);self.control.chmod(0o640);self.security.chmod(0o640)
 def test_byte_exact_positive_split_and_completed_rerun_preserves_new_records(self):
  self.seed();inode=(self.control.stat().st_ino,self.security.stat().st_ino)
  result=migration.split(self.root);self.assertEqual(result['moved_records'],2);self.assertEqual(self.control.read_bytes(),self.kept);self.assertEqual(self.security.read_bytes(),self.prior+self.moved)
  self.assertEqual(inode,(self.control.stat().st_ino,self.security.stat().st_ino));self.assertEqual((self.root/migration.SNAPSHOTS[0]).read_bytes(),self.original)
  self.assertTrue(storage.append_security_audit(self.human,self.root)['ok']);current=self.security.read_bytes();self.assertTrue(migration.split(self.root)['already_complete']);self.assertEqual(self.security.read_bytes(),current)
 def test_destination_commit_crash_blocks_all_operations_and_explicit_recovery_is_exact(self):
  self.seed();real=migration.rewrite_log;calls=[]
  def fail(fd,raw):
   calls.append(fd)
   if len(calls)==2:os.ftruncate(fd,0);os.lseek(fd,0,os.SEEK_SET);os.write(fd,raw[:7]);raise OSError('fixture interruption')
   real(fd,raw)
  with mock.patch.object(migration,'rewrite_log',side_effect=fail),self.assertRaises(OSError):migration.split(self.root)
  self.assertFalse(storage.append_security_audit(self.human,self.root)['ok']);self.assertFalse(storage.append_control_audit(json.loads(line().decode()),self.root)['ok'])
  with self.assertRaises(RuntimeError):storage.prune_audit(self.control)
  with self.assertRaises(RuntimeError):backup.snapshot(self.root,self.root/'pending-backup.jsonl')
  with self.assertRaises(RuntimeError):migration.split(self.root)
  self.assertEqual(migration.split(self.root,recover=True)['moved_records'],2);self.assertEqual(self.control.read_bytes(),self.kept);self.assertEqual(self.security.read_bytes(),self.prior+self.moved)
 def test_malformed_or_lost_established_ledger_never_resets_replay_guard(self):
  self.seed();migration.split(self.root);ledger=self.root/'audit-separation.json';original=ledger.read_bytes()
  for value in (b'{"schema":true,"state":"complete"}',b'{"schema":1,"state":"complete"}',b'{}',b'corrupt'):
   ledger.write_bytes(value);self.assertFalse(storage.append_security_audit(self.human,self.root)['ok'])
  ledger.write_bytes(original);ledger.unlink();self.assertFalse(storage.append_security_audit(self.human,self.root)['ok'])
 def test_no_key_api_capacity_cannot_consume_human_security_journal(self):
  no_key=json.loads(line(actor='api:no-key',credentials_present=False).decode())
  with mock.patch.object(storage,'AUDIT_MAX_BYTES',500):
   for _ in range(20):storage.append_control_audit(no_key,self.root)
   self.assertFalse(storage.append_control_audit(no_key,self.root)['ok']);self.assertFalse(self.security.exists());self.assertTrue(storage.append_security_audit(self.human,self.root)['ok'])
  self.assertNotIn(b'api:no-key',self.security.read_bytes());self.assertTrue((self.root/'control-api-audit-fault.json').exists());self.assertFalse((self.root/'security-audit-fault.json').exists())
 def test_outer_lock_blocks_append_retention_migration_and_backup_without_cutover(self):
  self.seed();lock=self.root/'.audit-separation.lock';lock.write_bytes(b'');lock.chmod(0o640)
  with lock.open('r+b') as held:
   fcntl.flock(held,fcntl.LOCK_EX)
   self.assertFalse(storage.append_security_audit(self.human,self.root)['ok'])
   with self.assertRaises(BlockingIOError):migration.split(self.root)
   with self.assertRaises(BlockingIOError):storage.prune_audit(self.control)
   with self.assertRaises(BlockingIOError):backup.snapshot(self.root,self.root/'blocked-backup.jsonl')
  self.assertEqual(self.control.read_bytes(),self.original);self.assertFalse((self.root/'.audit-separation-required').exists())
 def test_backup_lock_is_held_before_inventory_and_through_last_copy(self):
  self.seed();real_inventory=backup.inventory;real_read=backup.read;attempts=[]
  def attempt():
   with self.assertRaises(BlockingIOError):migration.split(self.root)
   attempts.append(True)
  def inventory(data):attempt();return real_inventory(data)
  def read(path,maximum):attempt();return real_read(path,maximum)
  with mock.patch.object(backup,'inventory',side_effect=inventory),mock.patch.object(backup,'read',side_effect=read):backup.snapshot(self.root,self.root/'snapshot.jsonl')
  self.assertGreaterEqual(len(attempts),3);self.assertEqual(self.control.read_bytes(),self.original)
 def test_oversize_and_unsafe_storage_leave_source_intact(self):
  self.seed();victim=self.root/'victim';victim.write_bytes(b'private');self.security.unlink();self.security.symlink_to(victim)
  with self.assertRaises((OSError,RuntimeError)):migration.split(self.root)
  self.assertEqual(victim.read_bytes(),b'private');self.assertEqual(self.control.read_bytes(),self.original)
  self.security.unlink();self.security.write_bytes(self.prior)
  with mock.patch.object(migration,'MAX_BYTES',100),self.assertRaises(RuntimeError):migration.split(self.root)
  self.assertFalse((self.root/'.audit-separation-required').exists())
 def test_short_reads_never_truncate_authoritative_capture(self):
  self.seed();real=os.read
  with mock.patch.object(migration.os,'read',side_effect=lambda fd,size:real(fd,min(size,7))):migration.split(self.root)
  self.assertEqual(self.control.read_bytes(),self.kept);self.assertEqual((self.root/migration.SNAPSHOTS[0]).read_bytes(),self.original)
 def test_bad_postwrite_readback_never_publishes_completion(self):
  self.seed();real=migration.rewrite_log
  def bad(fd,raw):real(fd,raw);os.lseek(fd,0,os.SEEK_SET);os.write(fd,b'!')
  with mock.patch.object(migration,'rewrite_log',side_effect=bad),self.assertRaises(RuntimeError):migration.split(self.root)
  self.assertEqual(json.loads((self.root/'audit-separation.json').read_text())['state'],'prepared');self.assertFalse(storage.append_security_audit(self.human,self.root)['ok'])
 def test_completed_noop_allows_normal_live_growth_above_migration_limit(self):
  self.seed();migration.split(self.root)
  with self.control.open('ab') as handle:handle.write(b'x'*(migration.MAX_BYTES+1))
  self.assertTrue(migration.split(self.root)['already_complete'])
 def test_unfinished_destination_tail_is_preserved_without_claim_or_cutover(self):
  self.seed();tail=b'{"unfinished":';self.security.write_bytes(tail)
  with self.assertRaises(RuntimeError):migration.split(self.root)
  self.assertEqual(self.security.read_bytes(),tail);self.assertEqual(self.control.read_bytes(),self.original);self.assertFalse((self.root/'.audit-separation-required').exists())
 def test_established_missing_log_is_not_recreated_by_rerun_or_appender(self):
  self.seed();migration.split(self.root);self.security.unlink()
  with self.assertRaises(FileNotFoundError):migration.split(self.root)
  self.assertFalse(storage.append_security_audit(self.human,self.root)['ok']);self.assertFalse(self.security.exists())
 def test_migrated_unfinished_control_tail_refuses_append_without_losing_evidence(self):
  self.seed();migration.split(self.root);before=self.control.read_bytes()
  key=json.loads(line(credential_id='legacy',credentials_present=True).decode())
  result=storage.append_control_audit(key,self.root)
  self.assertEqual(result,{'ok':False,'error_code':'audit_recovery_required','fault_recorded':True});self.assertEqual(self.control.read_bytes(),before)
  fault=json.loads((self.root/'control-api-audit-fault.json').read_text());self.assertTrue(fault['active']);self.assertEqual(fault['error_code'],'audit_recovery_required')
  self.assertTrue(storage.append_security_audit(self.human,self.root)['ok']);self.assertNotIn(b'credentials_present',self.security.read_bytes())
 def test_cli_python_isolation_and_noop_reentry(self):
  self.seed();command=['python3','-I',str(HELPERS/'sls_audit_separation.py'),'--directory',str(self.root),'--split']
  first=subprocess.run(command,text=True,capture_output=True,check=True);self.assertEqual(json.loads(first.stdout)['moved_records'],2)
  second=subprocess.run(command,text=True,capture_output=True,check=True);self.assertTrue(json.loads(second.stdout)['already_complete'])
if __name__=='__main__':unittest.main()
