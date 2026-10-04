#!/usr/bin/env python3
"""Run the actual installer transaction with private trees and inert PBX phases."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
SOURCE=(ROOT/'tools/install_release.sh').read_text()
HARNESS=r'''
source "$1"
fixture="$2"; scenario="$3"
DATA_DIR="$fixture/data"; CONFIG_FILE="$DATA_DIR/mass-notifications.config"
SETTINGS_LOCK="$DATA_DIR/mass-notifications.config.lock"
LOG_FILE="$fixture/install.log"; INSTALL_NOTIFICATION_SIDE_EFFECTS=0
trace() { printf '%s\n' "$*" >>"$fixture/trace"; }
mktemp() {
 case "$*" in
  '-d /tmp/sls-mass-notify-bootstrap.XXXXXX') command mktemp -d "$fixture/bootstrap.XXXXXX" ;;
  '-d /tmp/sls-mass-notify-module-backup.XXXXXX') command mktemp -d "$fixture/module-backup.XXXXXX" ;;
  '/tmp/slsmassnotifyserver-config.XXXXXX') command mktemp "$fixture/config-backup.XXXXXX" ;;
  '/tmp/slsmassnotifyserver-pending.XXXXXX') command mktemp "$fixture/pending-backup.XXXXXX" ;;
  *) command mktemp "$@" ;;
 esac
}
ensure_installer_log_prerequisites() { :; }
preflight_hardware_requirements() { :; }
require_freepbx() { :; }
acquire_update_coordination() { trace update-lock; }
acquire_maintenance_coordination() { trace maintenance-lock; }
install_dependencies() { :; }
preflight_platform() { :; }
preflight_python() { :; }
ensure_freepbx_prerequisites() { :; }
initialize_configuration_keyring() { trace config-keyring-init; }
download_tgz() { DOWNLOAD_DIR="$fixture/download"; mkdir "$DOWNLOAD_DIR"; }
verify_tgz() { trace signed-input; }
ensure_data_directory() { :; }
validate_preserved_config_prerequisites() { :; }
validate_staged_central_config() { :; }
verify_staged_hardware_requirements() { :; }
stage_module_directory() {
 STAGING_DIR="$fixture/stage"; mkdir -m700 "$STAGING_DIR"; mkdir "$STAGING_DIR/$MODULE"
 printf candidate >"$STAGING_DIR/$MODULE/module.xml"
 mkdir -p "$STAGING_DIR/$MODULE/bin/sls_mass_notify"
 cp "$FIXTURE_HELPERS"/sls_{module_trust,release_trust,privileged_install,installer_recovery,install_idle,install_guard,audio_state,config_crypto}.py "$STAGING_DIR/$MODULE/bin/sls_mass_notify/"
 HAD_EXISTING_MODULE=1; MODULE_WAS_ENABLED=1
}
prepare_authenticated_installer() {
 trace approval-preflight
 if [ "$scenario" = approval-rejected ]; then return 31; fi
 RECOVERY_DIR="$fixture/recovery"; mkdir -m700 "$RECOVERY_DIR"
 RECOVERY_TOOL="$fixture/recovery.py"; IDLE_TOOL="$fixture/idle.py"
 printf '{"schema":1,"ok":true,"phone_history":{},"closed_phone_batches":0}' >"$RECOVERY_DIR/idle-before.json"
 chmod 0600 "$RECOVERY_DIR/idle-before.json"
 STATIC_MUTATION_STARTED=1
}
configure_system_timezone() { trace timezone; }
protected_install_phase() {
 trace "protected-$*"
 if [ "$scenario" = prepare-failed ] && [ "$1" = prepare ]; then return 32; fi
}
install_module_with_autoenable() {
 trace module-install
 [ "${SLS_MASS_NOTIFY_DEFER_SIGNING:-}" = 1 ] || return 99
 if [ "$scenario" = install-failed ]; then return 33; fi
}
sync_module_version() { trace module-db; }
ensure_runtime_installed() { trace runtime-parity; }
secure_central_config() { :; }
fwconsole() { trace "asterisk-fwconsole-$*"; [ "$1" != reload ] || [ "${SLS_MASS_NOTIFY_PRESERVE_PENDING:-}" = 1 ]; }
sls_as_asterisk() { trace "asterisk-fixed-command"; }
verify_runtime_shell_syntax() { :; }
verify_announcement_worker_health() { :; }
verify_piper_voices() { :; }
verify_install() { trace verification; if [ "$scenario" = pending-drift ]; then printf modified >"$DATA_DIR/mass-notifications.pending.config"; fi; [ "$scenario" != final-failed ] || return 34; }
verify_confirmed_system_timezone() { :; }
clear_install_failure() { trace success; }
restore_module_registration() { trace db-restored; }
verify_local_api_route() { trace recovered-route; }
record_install_failure() { trace "failure-$INSTALL_EXIT_CODE-$INSTALL_ERROR_CATEGORY"; }
# The real worker locks, config snapshot/hash guard, stage swap, static recovery
# invocation order, rollback module rename and final cleanup remain in use.
main
'''

@unittest.skipUnless(os.geteuid()==0,'requires root for protected staging/worker lock fixtures')
class TransactionTests(unittest.TestCase):
 def run_case(self,scenario,expected):
  with tempfile.TemporaryDirectory(prefix='sls-installer-transaction-') as name:
   root=Path(name); data=root/'data';data.mkdir();config=data/'mass-notifications.config';config.write_text('{"preserve":"exact bytes"}\n')
   module=root/'modules/slsmassnotifyserver';module.mkdir(parents=True);(module/'module.xml').write_text('original')
   (data/'delivery-ledger').write_text('current receipt')
   pending=data/'mass-notifications.pending.config';pending.write_text('{"staged":"preserve exact bytes"}\n')
   installer=root/'installer.sh';installer.write_text(SOURCE.replace('/var/www/html/admin/modules',str(root/'modules')))
   (root/'recovery.py').write_text('import pathlib,sys\np=pathlib.Path(__file__).parent\nwith (p/"trace").open("a") as f:f.write("static-"+sys.argv[-1]+"\\n")\n')
   (root/'idle.py').write_text('import json\nprint(json.dumps({"ok":True}))\n')
   result=subprocess.run(['bash','-c',HARNESS,'fixture',str(installer),str(root),scenario],capture_output=True,text=True,timeout=20,env={**os.environ,'FIXTURE_HELPERS':str(ROOT/'slsmassnotifyserver/bin/sls_mass_notify')})
   self.assertEqual(result.returncode,expected,result.stdout+result.stderr)
   self.assertEqual(config.read_text(),'{"preserve":"exact bytes"}\n')
   self.assertEqual((data/'delivery-ledger').read_text(),'current receipt')
   self.assertEqual(pending.read_text(),'{"staged":"preserve exact bytes"}\n')
   trace=(root/'trace').read_text().splitlines()
   self.assertNotIn('asterisk-fwconsole-chown',trace)
   if scenario=='success':
    self.assertEqual((module/'module.xml').read_text(),'candidate')
    self.assertLess(trace.index('protected-activate --apply'),trace.index('asterisk-fwconsole-reload'))
    self.assertLess(trace.index('asterisk-fwconsole-reload'),trace.index('protected-verify'))
    self.assertEqual(trace[-1],'success')
    self.assertFalse((root/'recovery').exists())
   else:
    self.assertEqual((module/'module.xml').read_text(),'original')
    if scenario=='approval-rejected':
     self.assertFalse(any(x.startswith('static-') for x in trace))
     self.assertIn('failure-31-install_command_failed',trace)
    else:
     self.assertIn('static-restore',trace);self.assertIn('static-restore-services',trace);self.assertIn('db-restored',trace)
     self.assertTrue((root/'recovery/idle-before.json').exists())
     self.assertIn('failure-'+str(expected)+'-install_rollback_failed',trace)
   # The EXIT guard always releases worker slots, including every failure path.
   import fcntl
   with (data/'schedule-runner.lock').open('r+') as handle:fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
 def test_successful_candidate_transaction(self):self.run_case('success',0)
 def test_prerequisite_rejection_does_not_restore_or_disable_old_automation(self):self.run_case('approval-rejected',31)
 def test_failure_stages_preserve_old_module_config_and_operational_records(self):
  for scenario,code in [('prepare-failed',32),('install-failed',33),('final-failed',34),('pending-drift',1)]:
   with self.subTest(scenario=scenario):self.run_case(scenario,code)

if __name__=='__main__':unittest.main()
