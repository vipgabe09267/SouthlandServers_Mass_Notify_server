#!/usr/bin/python3
"""Real private subprocess/ownership fixtures; never execute installed cron jobs."""
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import pwd
import signal
import stat
import subprocess
import sys
import tempfile
import time
import unittest
from unittest import mock

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_cron_runner.py'
spec = importlib.util.spec_from_file_location('cron_fixture', SOURCE)
runner = importlib.util.module_from_spec(spec); spec.loader.exec_module(runner)


@unittest.skipUnless(os.geteuid() == 0, 'Actual runtime ownership fixtures require root isolation')
class CronRunnerTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='sls-cron-fixture-'); self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name); self.root.chmod(0o755)
        self.data = self.root / 'data'; self.data.mkdir(mode=0o750)
        self.account = pwd.getpwnam('asterisk'); os.chown(self.data, self.account.pw_uid, self.account.pw_gid)
        self.emergency = mock.patch.object(runner, 'emergency'); self.notice = self.emergency.start(); self.addCleanup(self.emergency.stop)

    def run_job(self, code, timeout=5):
        with mock.patch.dict(runner.JOBS, {'maintenance': (True, timeout, ['/usr/bin/python3', '-c', code])}):
            return runner.execute('maintenance', self.data)

    def records(self, name='maintenance'):
        return [json.loads(line) for line in (self.data / 'cron-diagnostics' / (name + '.jsonl')).read_text().splitlines()]

    def assert_child_stopped(self, marker):
        # killpg delivery is asynchronous when the already-reaped group leader
        # cannot be waited on for its orphaned child. Allow a bounded scheduler
        # tick for SIGKILL to take effect; never weaken the live-process check.
        path = Path('/proc') / marker.read_text() / 'stat'
        deadline = time.monotonic() + .5
        while time.monotonic() < deadline:
            try: state = path.read_text().split()[2]
            except (FileNotFoundError, ProcessLookupError): return
            if state == 'Z': return
            time.sleep(.005)
        self.fail('Stopped job left a running descendant')

    def test_fixed_jobs_and_unknown_arguments_never_execute(self):
        self.assertEqual(set(runner.JOBS), {'maintenance', 'update', 'weather', 'scheduling', 'announcement_reconcile'})
        self.assertEqual([runner.JOBS[name][1] for name in runner.JOBS], [7200, 1800, 5500, 1200, 900])
        with mock.patch.object(runner.subprocess, 'Popen') as start:
            self.assertEqual(runner.execute('weather; touch /tmp/unsafe', self.data), 64)
            self.assertEqual(runner.execute('weather', self.data), 77)
            start.assert_not_called()

    def test_actual_stdout_and_stderr_retained_privately_and_exit_preserved(self):
        code = 'import sys;print("normal worker diagnostic");print("actionable fixture failure",file=sys.stderr);sys.exit(9)'
        self.assertEqual(self.run_job(code), 9)
        rows = self.records(); self.assertEqual(rows[0]['event'], 'started'); self.assertEqual(rows[-1]['exit_code'], 9)
        self.assertIn('normal worker diagnostic', rows[-1]['output']); self.assertIn('actionable fixture failure', rows[-1]['output'])
        for path in (self.data / 'cron-diagnostics').iterdir():
            info = path.stat(); self.assertEqual((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)), (self.account.pw_uid, self.account.pw_gid, 0o640))
        self.assertEqual(stat.S_IMODE((self.data / 'cron-diagnostics').stat().st_mode), 0o750)

    def test_tail_is_bounded_and_retains_final_diagnostic(self):
        self.assertEqual(self.run_job('import os;os.write(1,b"A"*120000+b"FINAL ERROR\\n")'), 0)
        row = self.records()[-1]
        self.assertTrue(row['output_truncated']); self.assertEqual(row['output_bytes'], 120012)
        self.assertLessEqual(len(row['output']), runner.RECORD_OUTPUT_BYTES); self.assertTrue(row['output'].endswith('FINAL ERROR\n'))

    def test_runaway_output_is_stopped_and_logged(self):
        self.assertEqual(self.run_job('import os,time;os.write(1,b"x"*(5*1024*1024));time.sleep(30)'), 65)
        self.assertEqual(self.records()[-1]['result'], 'output_limit_exceeded')
        self.assertLessEqual((self.data / 'cron-diagnostics/maintenance.jsonl').stat().st_size, runner.LOG_BYTES)

    def test_repeated_diagnostics_remain_bounded_on_same_inode(self):
        with mock.patch.object(runner, 'LOG_BYTES', 256 * 1024):
            for i in range(8):
                self.assertEqual(self.run_job('import os;os.write(1,b"x"*64000+b"RUN' + str(i) + '")'), 0)
                path = self.data / 'cron-diagnostics/maintenance.jsonl'
                if i == 0: inode = path.stat().st_ino
                self.assertEqual(path.stat().st_ino, inode); self.assertLessEqual(path.stat().st_size, 256 * 1024)
        self.assertTrue(self.records()[-1]['output'].endswith('RUN7'))
        self.assertLess(len(self.records()), 16)

    def test_final_record_refuses_a_log_made_public_while_the_worker_runs(self):
        path = self.data / 'cron-diagnostics/maintenance.jsonl'
        code = 'import os;os.chmod(' + repr(str(path)) + ',0o644);print("must not be appended")'
        self.assertEqual(self.run_job(code), 1)
        self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o644)
        rows = self.records()
        self.assertEqual([row['event'] for row in rows], ['started'])
        self.assertNotIn('must not be appended', path.read_text())

    def test_permanent_lock_prevents_overlapping_jobs_without_output(self):
        self.assertEqual(self.run_job('pass'), 0)
        lock = os.open(self.data / 'cron-diagnostics/maintenance.lock', os.O_RDWR)
        before = self.records()
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with mock.patch.object(runner.subprocess, 'Popen') as start:
                self.assertEqual(self.run_job('raise Exception("must not execute")'), 0); start.assert_not_called()
            self.assertEqual(self.records(), before)
        finally: os.close(lock)

    def test_unsafe_logs_refuse_without_following_or_starting_worker(self):
        self.assertEqual(self.run_job('pass'), 0)
        path = self.data / 'cron-diagnostics/maintenance.jsonl'; victim = self.root / 'outside'; victim.write_bytes(b'preserved'); victim.chmod(0o600)
        for kind in ('symlink', 'hardlink', 'fifo', 'wrong_owner', 'public', 'oversized'):
            with self.subTest(kind=kind):
                path.unlink()
                if kind == 'symlink': path.symlink_to(victim)
                elif kind == 'hardlink': os.link(victim, path)
                elif kind == 'fifo': os.mkfifo(path, 0o640)
                else:
                    path.touch(); os.chown(path, self.account.pw_uid, self.account.pw_gid); path.chmod(0o640)
                    if kind == 'wrong_owner': os.chown(path, 0, 0)
                    elif kind == 'public': path.chmod(0o644)
                    else:
                        with path.open('wb') as handle: handle.truncate(runner.LOG_BYTES + 1)
                with mock.patch.object(runner.subprocess, 'Popen') as start:
                    self.assertEqual(self.run_job('pass'), 1); start.assert_not_called()
                self.assertEqual(victim.read_bytes(), b'preserved'); self.assertEqual(stat.S_IMODE(victim.stat().st_mode), 0o600)

    def test_timeout_kills_descendant_even_after_its_parent_exits(self):
        marker = self.root / 'child.pid'
        child = 'import os,signal,time,pathlib;signal.signal(signal.SIGTERM,signal.SIG_IGN);pathlib.Path(' + repr(str(marker)) + ').write_text(str(os.getpid()));time.sleep(30)'
        script = 'import subprocess,sys;subprocess.Popen([sys.executable,"-c",' + repr(child) + '])'
        started = time.monotonic(); self.assertEqual(self.run_job(script, .3), 124)
        self.assertLess(time.monotonic() - started, 4)
        self.assertEqual(self.records()[-1]['result'], 'timeout')
        self.assert_child_stopped(marker)

    def test_user_jobs_run_as_actual_asterisk_without_root_log_ownership(self):
        os.chown(self.root,0,self.account.pw_gid);self.root.chmod(0o2775)
        private_source = self.root / 'runner.py'; private_source.write_bytes(SOURCE.read_bytes()); private_source.chmod(0o644)
        script = 'import importlib.util,sys;sys.dont_write_bytecode=True;s=importlib.util.spec_from_file_location("fixture",sys.argv[1]);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);m.JOBS["weather"]=(False,5,["/usr/bin/python3","-c","import os;print(os.geteuid())"]);raise SystemExit(m.execute("weather",sys.argv[2]))'
        result = subprocess.run(['/usr/sbin/runuser', '-u', 'asterisk', '--', '/usr/bin/python3', '-I', '-c', script, str(private_source), str(self.data)], capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr); self.assertEqual(result.stdout, '')
        self.assertEqual(self.records('weather')[-1]['output'].strip(), str(self.account.pw_uid))
        self.assertEqual(stat.S_IMODE(self.root.stat().st_mode),0o2775)

    def test_trusted_pbx_ancestor_preserved_but_unsafe_groups_stop_before_job(self):
        os.chown(self.root,0,self.account.pw_gid);self.root.chmod(0o2775)
        self.assertEqual(self.run_job('print("private qualified ancestor")'),0)
        path=self.data/'cron-diagnostics/maintenance.jsonl';saved=path.read_bytes()
        self.data.chmod(0o2770)
        with mock.patch.object(runner.subprocess,'Popen') as start:
            self.assertEqual(self.run_job('raise RuntimeError("must not run")'),1);start.assert_not_called()
        self.data.chmod(0o750);os.chown(self.root,0,65534)
        with mock.patch.object(runner.subprocess,'Popen') as start:
            self.assertEqual(self.run_job('raise RuntimeError("must not run")'),1);start.assert_not_called()
        self.assertEqual(path.read_bytes(),saved);self.assertEqual(stat.S_IMODE(self.root.stat().st_mode),0o2775)

    def test_cron_redirection_prevents_mail_when_interpreter_cannot_start_helper(self):
        result = subprocess.run(['/bin/sh', '-c', '/usr/bin/python3 -I /definitely-missing-sls-cron-fixture.py weather >/dev/null 2>&1'], capture_output=True, timeout=5)
        self.assertNotEqual(result.returncode, 0); self.assertEqual(result.stdout + result.stderr, b'')

    def test_runner_signal_stops_child_and_records_interruption_without_output(self):
        marker = self.root / 'signal-child.pid'
        code = 'import os,signal,time,pathlib;signal.signal(signal.SIGTERM,signal.SIG_IGN);pathlib.Path(' + repr(str(marker)) + ').write_text(str(os.getpid()));time.sleep(30)'
        driver = ('import importlib.util,sys;sys.dont_write_bytecode=True;'
                  's=importlib.util.spec_from_file_location("fixture",sys.argv[1]);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);'
                  'm.emergency=lambda *args:None;m.JOBS["maintenance"]=(True,30,["/usr/bin/python3","-c",sys.argv[3]]);'
                  'raise SystemExit(m.execute("maintenance",sys.argv[2]))')
        process = subprocess.Popen(['/usr/bin/python3', '-I', '-c', driver, str(SOURCE), str(self.data), code], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        try:
            deadline = time.monotonic() + 5
            while not marker.exists() and time.monotonic() < deadline: time.sleep(.02)
            self.assertTrue(marker.exists(), 'Fixture child never started')
            process.send_signal(signal.SIGTERM)
            stdout, stderr = process.communicate(timeout=6)
            self.assertEqual(process.returncode, 143); self.assertEqual(stdout + stderr, b'')
            row = self.records()[-1]; self.assertEqual(row['result'], 'interrupted'); self.assertEqual(row['exit_code'], 143)
            self.assert_child_stopped(marker)
        finally:
            if process.poll() is None: process.kill(); process.communicate()
            if marker.exists():
                try: os.kill(int(marker.read_text()), signal.SIGKILL)
                except ProcessLookupError: pass


class InstallerCronVerificationTests(unittest.TestCase):
    def setUp(self):
        runner_path = '/usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_cron_runner.py'
        self.lines = {job: ('17 * * * * ' if job == 'update' else '* * * * * ') + runner_path + ' ' + job + ' >/dev/null 2>&1' for job in runner.JOBS}
        self.root_cron = '\n'.join(self.lines[job] for job in ('maintenance', 'update'))
        self.user_cron = '\n'.join(self.lines[job] for job in ('weather', 'scheduling', 'announcement_reconcile'))

    def verify(self, root_cron, user_cron):
        return subprocess.run(['bash', '-c', 'source "$1"; log() { printf "%s\\n" "$*"; }; verify_install_cron "$2" "$3"', 'fixture', str(ROOT / 'tools/install_release.sh'), root_cron, user_cron], capture_output=True, text=True, timeout=10)

    def test_exact_five_jobs_preserve_unrelated_jobs_and_ignore_comments(self):
        unrelated = '\nMAILTO=private-fixture@example.invalid\n0 2 * * * /usr/local/sbin/admin-backup --password private-fixture-value\n# old sls_mass_notify_schedule_worker.php disabled\n'
        result = self.verify(self.root_cron + unrelated, self.user_cron + unrelated)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(result.stdout + result.stderr, '')

    def test_missing_duplicate_wrong_account_or_unsafe_variants_refused(self):
        cases = [
            (self.root_cron, self.user_cron.replace(self.lines['announcement_reconcile'], '')),
            (self.root_cron + '\n' + self.lines['update'], self.user_cron),
            (self.root_cron + '\n' + self.lines['weather'], self.user_cron),
            (self.root_cron, self.user_cron.replace(' >/dev/null 2>&1', '')),
            (self.root_cron, self.user_cron + '\n* * * * * /usr/bin/timeout 1200 /usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php'),
            (self.root_cron, self.user_cron + '\n* * * * * /usr/local/bin/nws_weather_alert.sh'),
            (self.root_cron, self.user_cron + '\n' + self.lines['weather'] + ' --extra'),
            (self.root_cron.replace('17 * * * *', '17 */6 * * *'), self.user_cron),
        ]
        for root_cron, user_cron in cases:
            with self.subTest(root_cron=root_cron, user_cron=user_cron):
                result = self.verify(root_cron + '\nMAILTO=private-fixture@example.invalid', user_cron)
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn('private-fixture@example.invalid', result.stdout + result.stderr)


if __name__ == '__main__': unittest.main()
