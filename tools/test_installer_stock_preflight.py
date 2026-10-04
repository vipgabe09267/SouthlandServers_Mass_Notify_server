#!/usr/bin/env python3
"""Exercise exact stock/candidate transition checks without enrolling live trust."""
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
SOURCE=(ROOT/'tools/install_release.sh').read_text()
BLOCK=SOURCE.split('prepare_authenticated_installer() {',1)[1].split("<<'PY' || return 1\n",1)[1].split('\nPY\n',1)[0]
TRUST=ROOT/'slsmassnotifyserver/bin/sls_mass_notify/sls_module_trust.py'

def digest(body):return hashlib.sha256(body).hexdigest()
class StockPreflightTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory(prefix='sls-stock-preflight-');self.addCleanup(self.tmp.cleanup)
  self.root=Path(self.tmp.name);self.web=self.root/'web';self.candidate=self.root/'candidate';self.approvals=self.root/'approvals.json'
  self.data={'prior':{},'approved':{}}
  for module in ('dashboard','framework'):
   modulepath=self.web/'admin/modules'/module;modulepath.mkdir(parents=True)
   (modulepath/'module.xml').write_bytes(b'reviewed upstream')
   self.data['approved'][module]={'files':{'module.xml':{'target':'admin/modules/'+module+'/module.xml','sha256':digest(b'reviewed upstream')}},'uninstall':{'replace':{}}}
  for name in ('sections/SlsMassNotifyAnnouncement.class.php','views/sections/sls-mass-notify-announcement.php'):
   candidate=self.candidate/'dashboard'/name;candidate.parent.mkdir(parents=True,exist_ok=True);candidate.write_bytes(b'authenticated candidate')
   target=self.web/'admin/modules/dashboard'/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(b'prior approved widget')
   entry={'target':'admin/modules/dashboard/'+name,'sha256':digest(candidate.read_bytes())}
   self.data['approved']['dashboard']['files'][name]=entry
   self.data['prior'].setdefault('dashboard',{'files':{}})['files'][name]={**entry,'sha256':digest(target.read_bytes())}
  menu=self.web/'admin/views/menu_items.php';menu.parent.mkdir(parents=True);menu.write_bytes(b'reviewed uninstalled menu')
  name='amp_conf/htdocs/admin/views/menu_items.php'
  self.data['approved']['framework']['files'][name]={'target':'admin/views/menu_items.php','sha256':digest(b'reviewed SLS menu')}
  self.data['approved']['framework']['uninstall']['replace'][name]=digest(menu.read_bytes())
 def check(self):
  self.approvals.write_text(json.dumps(self.data));self.approvals.chmod(0o600)
  code=BLOCK.replace("Path('/var/www/html')",'Path('+repr(str(self.web))+')')
  return subprocess.run(['python3','-I','-',str(TRUST),str(self.root/'unusedtrust'),str(self.candidate),str(self.approvals)],input=code,text=True,capture_output=True,timeout=10)
 def test_prior_approved_overlays_and_reviewed_menu_transition_are_allowed(self):
  result=self.check();self.assertEqual(result.returncode,0,result.stderr)
 def test_fresh_missing_widgets_allowed_only_when_candidate_matches_approval(self):
  for name in self.data['prior']['dashboard']['files']:(self.web/'admin/modules/dashboard'/name).unlink()
  self.data['prior']={};self.assertEqual(self.check().returncode,0)
  (self.candidate/'dashboard/sections/SlsMassNotifyAnnouncement.class.php').write_bytes(b'wrong candidate')
  self.assertNotEqual(self.check().returncode,0)
 def test_unreviewed_current_widget_or_stock_file_rejected(self):
  target=self.web/'admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php'
  target.write_bytes(b'unknown widget');self.assertNotEqual(self.check().returncode,0)
  target.write_bytes(b'prior approved widget')
  (self.web/'admin/modules/framework/module.xml').write_bytes(b'unreviewed upstream');self.assertNotEqual(self.check().returncode,0)
 def test_extra_stock_file_and_unreviewed_menu_rejected(self):
  extra=self.web/'admin/modules/framework/unapproved.php';extra.write_bytes(b'extra');self.assertNotEqual(self.check().returncode,0)
  extra.unlink();(self.web/'admin/views/menu_items.php').write_bytes(b'unreviewed menu');self.assertNotEqual(self.check().returncode,0)
 def test_preflight_precedes_all_active_trust_pointer_mutations(self):
  prepare=SOURCE.split('prepare_authenticated_installer() {',1)[1].split('\n# The protected log opener',1)[0]
  self.assertLess(prepare.index('Stock file differs from independently approved inventory'),prepare.index('STATIC_MUTATION_STARTED=1'))
  self.assertLess(prepare.index('STATIC_MUTATION_STARTED=1'),prepare.index('enroll-sls'))
  self.assertIn('sys.dont_write_bytecode = True',prepare)

if __name__=='__main__':unittest.main()
