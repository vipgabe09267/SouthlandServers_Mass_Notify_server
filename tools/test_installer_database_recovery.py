#!/usr/bin/env python3
"""Execute the installer's actual PHP recovery code against a private DB adapter."""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
SOURCE=(ROOT/'tools/install_release.sh').read_text()
SNAPSHOT=SOURCE.split('prepare_install_recovery() {',1)[1].split("php -r '\n",1)[1].split("\n' >",1)[0]
RESTORE=SOURCE.split('restore_module_registration() {',1)[1].split("php -r '\n",1)[1].split("\n' <",1)[0]
HARNESS=r'''<?php
$GLOBALS['fixture']=json_decode(file_get_contents(getenv('FIXTURE_STATE')),true,64,JSON_THROW_ON_ERROR);
function saveFixture(){file_put_contents(getenv('FIXTURE_STATE'),json_encode($GLOBALS['fixture'],JSON_THROW_ON_ERROR));}
class FakeStatement {
 private $sql; private $rows=[];
 function __construct($sql){$this->sql=$sql;}
 function execute($args){
  $s=&$GLOBALS['fixture']; $sql=$this->sql;
  if(preg_match('/^SELECT \* FROM (modules|recordings) WHERE (modulename|filename) = \?/', $sql,$m)){
   $this->rows=array_values(array_filter($s[$m[1]],static fn($r)=>$r[$m[2]]===$args[0])); return true;
  }
  if(preg_match('/^DELETE FROM (modules|recordings) WHERE (modulename|filename) = \?$/',$sql,$m)){
   $s[$m[1]]=array_values(array_filter($s[$m[1]],static fn($r)=>$r[$m[2]]!==$args[0])); return true;
  }
  if(preg_match('/^INSERT INTO (modules|recordings) \(([^)]+)\) VALUES /',$sql,$m)){
   $columns=array_map(static fn($v)=>trim($v,'`'),explode(',',$m[2]));
   $s[$m[1]][]=array_combine($columns,$args); return true;
  }
  throw new RuntimeException('Unexpected query: '.$sql);
 }
 function fetch($mode=null){return $this->rows[0]??false;}
 function fetchAll($mode=null){return $this->rows;}
}
class FakeDatabase {
 private $saved;
 function prepare($sql){return new FakeStatement($sql);}
 function beginTransaction(){$this->saved=$GLOBALS['fixture'];}
 function commit(){saveFixture();$this->saved=null;}
 function rollBack(){$GLOBALS['fixture']=$this->saved;$this->saved=null;}
 function inTransaction(){return $this->saved!==null;}
}
class FakeBackup {
 function getAll($key){return $GLOBALS['fixture']['backup'][$key]??[];}
 function setConfig($key,$value,$id){$GLOBALS['fixture']['backup'][$id][$key]=$value;saveFixture();}
 function delConfig($key,$id){unset($GLOBALS['fixture']['backup'][$id][$key]);saveFixture();}
}
class FakeDashboard {function getConfig($key){return [];} function setConfig($key,$value){}}
class DashboardHooks {static function genHooks($order){return [];}}
class FreePBX {
 static function Database(){static $db;return $db??($db=new FakeDatabase());}
 static function Create(){return (object)['Backup'=>new FakeBackup()];}
 static function Dashboard(){return new FakeDashboard();}
}
'''

class DatabaseRecoveryTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory(prefix='sls-db-recovery-');self.addCleanup(self.tmp.cleanup)
  self.root=Path(self.tmp.name);self.state=self.root/'state.json';self.harness=self.root/'freepbx.php';self.harness.write_text(HARNESS)
  self.original={'modules':[{'modulename':'slsmassnotifyserver','version':'old','enabled':'1'},{'modulename':'other','version':'untouched'}],
   'recordings':[{'id':'8','filename':'custom/NWS_alert','displayname':'NWS Alert','description':'Default Southland Servers NWS alert opening tone.'},{'id':'9','filename':'custom/user-message','displayname':'User message'}],
   'backup':{'backupList':{'job1':{},'job2':{},'files':{}},'modules_job1':{'core':True},'modules_job2':{'core':True,'slsmassnotifyserver':'0'},'modules_files':{}},
   'receipts':['keep-new-receipt']}
  self.state.write_text(json.dumps(self.original))
 def run_php(self,code,stdin=''):
  import os
  code=code.replace('/etc/freepbx.conf',str(self.harness)).replace('/var/www/html/admin/modules/dashboard/classes/DashboardHooks.class.php',str(self.harness))
  return subprocess.run(['php','-r',code],input=stdin,text=True,capture_output=True,timeout=10,env={**os.environ,'FIXTURE_STATE':str(self.state)})
 def snapshot(self):
  result=self.run_php(SNAPSHOT);self.assertEqual(result.returncode,0,result.stderr);return json.loads(result.stdout)
 def test_scoped_restore_preserves_unrelated_jobs_and_new_operational_records(self):
  snapshot=self.snapshot();current=json.loads(self.state.read_text())
  current['modules'][0]['version']='candidate'
  current['recordings']=[current['recordings'][1],{'id':'10','filename':'custom/SLS_Mass_Notify_NWS_Alert','displayname':'SLS Mass Notify - NWS Alert','description':'Default Southland Servers NWS alert opening tone.'}]
  current['backup']['modules_job1'].update({'slsmassnotifyserver':True,'new-module':True})
  current['backup']['modules_job2']['slsmassnotifyserver']=True
  current['backup']['backupList']['new-job']={};current['backup']['modules_new-job']={'core':True,'slsmassnotifyserver':True}
  current['receipts'].append('receipt-during-install');self.state.write_text(json.dumps(current))
  result=self.run_php(RESTORE,json.dumps(snapshot));self.assertEqual(result.returncode,0,result.stderr)
  restored=json.loads(self.state.read_text())
  self.assertEqual(next(x for x in restored['modules'] if x['modulename']=='slsmassnotifyserver')['version'],'old')
  self.assertEqual(sorted(restored['recordings'],key=lambda r:r['id']),self.original['recordings'])
  self.assertEqual(restored['backup']['modules_job1'],{'core':True,'new-module':True})
  self.assertEqual(restored['backup']['modules_job2']['slsmassnotifyserver'],'0')
  self.assertTrue(restored['backup']['modules_new-job']['slsmassnotifyserver'])
  self.assertEqual(restored['receipts'],['keep-new-receipt','receipt-during-install'])
 def test_independent_existing_recording_edit_refuses_transaction(self):
  snapshot=self.snapshot();current=json.loads(self.state.read_text());current['recordings'][0]['displayname']='Administrator changed';self.state.write_text(json.dumps(current))
  result=self.run_php(RESTORE,json.dumps(snapshot));self.assertNotEqual(result.returncode,0)
  self.assertEqual(json.loads(self.state.read_text()),current)
 def test_removed_job_and_independent_enrollment_edit_are_preserved(self):
  snapshot=self.snapshot();current=json.loads(self.state.read_text());del current['backup']['backupList']['job1'];current['backup']['modules_job2']['slsmassnotifyserver']='administrator-edit';self.state.write_text(json.dumps(current))
  result=self.run_php(RESTORE,json.dumps(snapshot));self.assertEqual(result.returncode,0,result.stderr)
  restored=json.loads(self.state.read_text());self.assertNotIn('job1',restored['backup']['backupList']);self.assertEqual(restored['backup']['modules_job2']['slsmassnotifyserver'],'administrator-edit')
 def test_unscoped_or_invalid_identifier_snapshot_is_rejected(self):
  for change in ('filename','column'):
   snapshot=self.snapshot()
   if change=='filename':snapshot['recordings']['custom/user-message']=[]
   else:snapshot['row']['evil`']=1
   result=self.run_php(RESTORE,json.dumps(snapshot));self.assertNotEqual(result.returncode,0)
   self.assertEqual(json.loads(self.state.read_text()),self.original)

if __name__=='__main__':unittest.main()
