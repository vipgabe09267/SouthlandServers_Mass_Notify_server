#!/usr/bin/env python3
"""Execute repair control flow with recorded operations; no PBX command runs."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_maintenance.sh').read_text()
REPAIR = SOURCE[SOURCE.index('repair_ok=1\n'):]

class ProtectedMaintenance(unittest.TestCase):
    def run_repair(self, failure=''):
        with tempfile.TemporaryDirectory(prefix='sls-protected-repair-') as name:
            base=Path(name)
            modules=base/'modules'
            for module in ('slsmassnotifyserver','dashboard','framework'): (modules/module).mkdir(parents=True)
            script='''set -euo pipefail
LOG_FILE="$1/log"
PRIVILEGED_HELPER=/usr/local/bin/sls_mass_notify/sls_privileged_install.py
SIGNER=/usr/local/sbin/sign_sls_mass_notify_local_sig.sh
run_without_maintenance_lock() {
  /usr/bin/python3 -I - "$@" <<'RECORD'
import json,os,subprocess,sys
args=sys.argv[1:]
with open(os.environ['CALLS'],'a') as output: output.write(json.dumps(args)+'\\n')
failure=os.environ.get('FAILURE')
if failure and any(failure == item or failure in item for item in args): raise SystemExit(29)
if '/usr/sbin/fwconsole' in args:
    # Execute the real env prefix with an inert child in place of fwconsole.
    # This proves the flag reaches the child, without invoking a PBX command.
    command=args[4:]
    index=command.index('/usr/sbin/fwconsole')
    command[index:]=[sys.executable, '-c', "import os; print('RELOAD_PENDING=' + os.environ.get('SLS_MASS_NOTIFY_PRESERVE_PENDING', 'missing'))"]
    subprocess.run(command, check=True)
RECORD
}
log() { printf '%s\\n' "$*" >>"$LOG_FILE"; }
# No disk/signature/status operations are performed by these completion stubs.
verify_mutation_idle() { return 0; }
clear_install_failure() { echo cleared >>"$LOG_FILE"; }
write_maintenance_progress() { log "$*"; }
write_install_failure() { log "$*"; }
fail_maintenance() { log "$*"; exit "$3"; }
'''+REPAIR.replace('/var/www/html/admin/modules/',str(modules)+'/')
            path=base/'fixture.sh';path.write_text(script)
            result=subprocess.run(['/bin/bash',str(path),str(base)],env={**os.environ,'CALLS':str(base/'calls'),'FAILURE':failure},capture_output=True,text=True,timeout=5)
            calls=[json.loads(line) for line in (base/'calls').read_text().splitlines()]
            return result,calls,(base/'log').read_text()
    def test_status_writer_refuses_links_and_does_not_change_their_victims(self):
        definitions=SOURCE[:SOURCE.index('[ "${EUID:-$(id -u)}" -eq 0 ] || exit 1')]
        for kind in ('symlink','hardlink','fifo','parent'):
            with self.subTest(kind=kind), tempfile.TemporaryDirectory(prefix='sls-status-safe-') as name:
                base=Path(name); victim=base/'victim';victim.write_text('preserved');victim.chmod(0o600)
                target=base/'status.json'
                if kind=='symlink': target.symlink_to(victim)
                elif kind=='hardlink': os.link(victim,target)
                elif kind=='fifo': os.mkfifo(target)
                else:
                    (base/'redirect').symlink_to(base);target=base/'redirect/status.json'
                script=definitions+'\nwrite_status_json update "$1" complete "safe message" "" "" 0'
                result=subprocess.run(['/bin/bash','-c',script,'_',str(target)],capture_output=True,text=True,timeout=5)
                self.assertNotEqual(result.returncode,0,result.stderr)
                self.assertEqual(victim.read_text(),'preserved')
                self.assertEqual(victim.stat().st_mode & 0o777,0o600)
    def test_status_reader_does_not_block_on_fifo_or_follow_link(self):
        definitions=SOURCE[:SOURCE.index('[ "${EUID:-$(id -u)}" -eq 0 ] || exit 1')]
        for kind in ('fifo','symlink','oversized'):
            with self.subTest(kind=kind), tempfile.TemporaryDirectory(prefix='sls-status-read-') as name:
                base=Path(name);target=base/'status.json'
                if kind=='fifo': os.mkfifo(target)
                elif kind=='symlink':
                    victim=base/'victim';victim.write_text('{"state":"complete"}');target.symlink_to(victim)
                else: target.write_bytes(b' ' * 65537)
                script=definitions+'\nUPDATE_PROGRESS_FILE="$1"; update_progress_is complete'
                result=subprocess.run(['/bin/bash','-c',script,'_',str(target)],capture_output=True,text=True,timeout=5)
                self.assertNotEqual(result.returncode,0,result.stderr)
    def test_success_separates_every_php_and_reload_from_root(self):
        result,calls,log=self.run_repair()
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertIn('cleared',log)
        self.assertIn('RELOAD_PENDING=1',log)
        reload_calls=[args for args in calls if '/usr/sbin/fwconsole' in args]
        self.assertEqual(len(reload_calls),1)
        self.assertEqual(reload_calls[0][4:],['/usr/bin/env','SLS_MASS_NOTIFY_PRESERVE_PENDING=1','/usr/sbin/fwconsole','reload'])
        for args in calls:
            if '/usr/bin/php' in args or '/usr/sbin/fwconsole' in args:
                self.assertEqual(args[:4],['/usr/sbin/runuser','-u','asterisk','--'])
        root_ops=[args for args in calls if args[0]=='/usr/bin/python3']
        self.assertEqual([args[3] for args in root_ops],['prepare','dependencies','activate','verify'])
        self.assertFalse(any('chown' in args for args in calls))
        self.assertTrue(any('finalizeVerifiedUnprivilegedRestore' in arg for args in calls for arg in args))
    def test_every_required_stage_failure_preserves_failure_and_stops_following_stages(self):
        for stage in ('prepare','dependencies','installUnprivilegedPhase','/usr/sbin/fwconsole','activate','sign_sls_mass_notify_local_sig.sh','verify','verifyUnprivilegedIntegration'):
            with self.subTest(stage=stage):
                result,calls,log=self.run_repair(stage)
                self.assertEqual(result.returncode,29,result.stderr)
                self.assertNotIn('cleared',log)
                self.assertNotIn('completed successfully',log)
                last = next(index for index,args in enumerate(calls) if any(stage in item for item in args))
                remaining=calls[last+1:]
                self.assertTrue(not remaining or stage=='sign_sls_mass_notify_local_sig.sh')

if __name__=='__main__': unittest.main()
