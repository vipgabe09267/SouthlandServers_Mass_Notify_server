#!/usr/bin/python3
"""Run only fixed SLS cron jobs and retain bounded private diagnostics.

Cron entries also redirect this runner's output to /dev/null: interpreter and
logging failures must not create a second, uncontrolled email producer.
"""
from __future__ import annotations

import datetime
import fcntl
import json
import os
from pathlib import Path
import pwd
import selectors
import signal
import stat
import subprocess
import sys
import syslog
import time

sys.dont_write_bytecode = True
DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
RUNTIME = '/usr/local/bin/sls_mass_notify'
JOBS = {
    'maintenance': (True, 900, ['/bin/bash', RUNTIME + '/sls_mass_notify_maintenance.sh']),
    'update': (True, 1800, ['/bin/bash', RUNTIME + '/sls_mass_notify_update.sh']),
    'weather': (False, 5500, ['/bin/bash', RUNTIME + '/sls_mass_notify_weather_poll.sh']),
    'scheduling': (False, 1200, ['/usr/bin/php', RUNTIME + '/sls_mass_notify_schedule_worker.php']),
    'announcement_reconcile': (False, 900, ['/usr/bin/php', RUNTIME + '/sls_mass_notify_announcement_worker.php', '--reconcile']),
}
LOG_BYTES = 1024 * 1024
RECORD_OUTPUT_BYTES = 64 * 1024
MAX_OUTPUT_BYTES = 4 * 1024 * 1024


class CronError(RuntimeError):
    pass


def identity(metadata):
    return metadata.st_dev, metadata.st_ino, metadata.st_uid, metadata.st_gid, metadata.st_nlink


def directory(path, account):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise CronError('unsafe_directory')
    descriptor = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        for index, name in enumerate(path.parts[1:]):
            child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=descriptor)
            os.close(descriptor); descriptor = child
            info = os.fstat(descriptor)
            sticky = info.st_uid == 0 and info.st_mode & stat.S_ISVTX and index < len(path.parts) - 2
            if info.st_uid not in (0, account.pw_uid) or info.st_mode & 0o022 and not sticky:
                raise CronError('unsafe_directory')
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def log_directory(data, account):
    parent = directory(data, account)
    try:
        if os.fstat(parent).st_uid != account.pw_uid:
            raise CronError('unsafe_data_owner')
        created = False
        try:
            os.mkdir('cron-diagnostics', 0o750, dir_fd=parent); created = True
        except FileExistsError:
            pass
        child = os.open('cron-diagnostics', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
        try:
            if created:
                os.fchown(child, account.pw_uid, account.pw_gid); os.fchmod(child, 0o750); os.fsync(parent)
            info = os.fstat(child)
            if info.st_uid != account.pw_uid or info.st_gid != account.pw_gid or stat.S_IMODE(info.st_mode) not in (0o700, 0o750):
                raise CronError('unsafe_log_directory')
            result, child = child, -1
            return result
        finally:
            if child >= 0: os.close(child)
    finally:
        os.close(parent)


def open_file(parent, name, account, maximum):
    flags = os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
    created = False
    try:
        descriptor = os.open(name, flags | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=parent); created = True
    except FileExistsError:
        descriptor = os.open(name, flags, dir_fd=parent)
    try:
        if created:
            os.fchown(descriptor, account.pw_uid, account.pw_gid); os.fchmod(descriptor, 0o640); os.fsync(parent)
        opened = os.fstat(descriptor)
        at = os.stat(name, dir_fd=parent, follow_symlinks=False)
        if (not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1 or identity(opened) != identity(at)
                or opened.st_uid != account.pw_uid or opened.st_gid != account.pw_gid
                or stat.S_IMODE(opened.st_mode) != 0o640 or opened.st_size > maximum):
            raise CronError('unsafe_diagnostic_file')
        result, descriptor = descriptor, -1
        return result
    finally:
        if descriptor >= 0: os.close(descriptor)


def write_all(descriptor, body):
    while body:
        written = os.write(descriptor, body)
        if written <= 0: raise CronError('diagnostic_write_failed')
        body = body[written:]


def record(parent, descriptor, job, values):
    opened = os.fstat(descriptor)
    at = os.stat(job + '.jsonl', dir_fd=parent, follow_symlinks=False)
    if identity(opened) != identity(at) or opened.st_size > LOG_BYTES:
        raise CronError('diagnostic_file_changed')
    row = {'at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'job': job, **values}
    body = (json.dumps(row, ensure_ascii=True, separators=(',', ':')) + '\n').encode()
    if len(body) > LOG_BYTES // 2: raise CronError('diagnostic_record_oversized')
    if opened.st_size + len(body) > LOG_BYTES:
        # Compact the same held inode; permanent job locks serialize all runs.
        # These are diagnostics, never delivery or configuration journals.
        keep = LOG_BYTES - len(body)
        os.lseek(descriptor, max(0, opened.st_size - keep), os.SEEK_SET)
        tail = os.read(descriptor, keep)
        boundary = tail.find(b'\n')
        tail = tail[boundary + 1:] if boundary >= 0 else b''
        os.lseek(descriptor, 0, os.SEEK_SET); write_all(descriptor, tail + body); os.ftruncate(descriptor, len(tail) + len(body))
    else:
        os.lseek(descriptor, 0, os.SEEK_END); write_all(descriptor, body)
    os.fsync(descriptor)


def emergency(job, category):
    # Fixed metadata only. Do not leak captured child output to system logs.
    try:
        syslog.openlog('sls-cron', syslog.LOG_PID, syslog.LOG_DAEMON)
        syslog.syslog(syslog.LOG_ERR, 'SLS cron ' + job + ': ' + category + '; review private cron diagnostics and directory permissions.')
    except Exception:
        pass


def terminate(process):
    try: os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError: pass
    deadline = time.monotonic() + 2
    while time.monotonic() < deadline:
        process.poll()
        try: os.killpg(process.pid, 0)
        except ProcessLookupError: break
        time.sleep(.02)
    try: os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError: pass
    process.wait(timeout=2)


def execute(job, data=DATA):
    if job not in JOBS: return 64
    account = pwd.getpwnam('asterisk')
    root_job, seconds, command = JOBS[job]
    if account.pw_uid == 0 or os.geteuid() != (0 if root_job else account.pw_uid):
        emergency(job, 'incorrect_runtime_account'); return 77
    parent = lock = output = -1
    process = None; selector = None
    previous_handlers = {}; interrupted = []
    try:
        parent = log_directory(data, account)
        lock = open_file(parent, job + '.lock', account, 0)
        try: fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError: return 0
        output = open_file(parent, job + '.jsonl', account, LOG_BYTES)
        record(parent, output, job, {'event': 'started'})
        def interrupted_by(signum, _frame): interrupted.append(signum)
        for signum in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
            previous_handlers[signum] = signal.signal(signum, interrupted_by)
        process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            cwd='/', env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C.UTF-8',
                          'HOME': '/root' if root_job else '/var/lib/asterisk'},
            close_fds=True, start_new_session=True)
        os.set_blocking(process.stdout.fileno(), False)
        selector = selectors.DefaultSelector(); selector.register(process.stdout, selectors.EVENT_READ)
        deadline = time.monotonic() + seconds
        tail = bytearray(); total = 0; reason = 'completed'
        while selector.get_map() or process.poll() is None:
            if interrupted or time.monotonic() >= deadline or total > MAX_OUTPUT_BYTES:
                reason = 'interrupted' if interrupted else ('timeout' if time.monotonic() >= deadline else 'output_limit_exceeded')
                terminate(process); break
            for key, _ in selector.select(.2):
                try: block = os.read(key.fileobj.fileno(), 65536)
                except BlockingIOError: continue
                if not block: selector.unregister(key.fileobj); continue
                total += len(block); tail.extend(block)
                if len(tail) > RECORD_OUTPUT_BYTES: del tail[:-RECORD_OUTPUT_BYTES]
        code = process.wait(timeout=2)
        if reason != 'completed': code = 128 + interrupted[0] if interrupted else (124 if reason == 'timeout' else 65)
        record(parent, output, job, {'event': 'finished', 'result': reason, 'exit_code': code,
            'output_bytes': total, 'output_truncated': total > len(tail), 'output': tail.decode('utf-8', 'replace')})
        if code != 0: emergency(job, reason if reason != 'completed' else 'worker_failed')
        return code if 0 <= code <= 255 else 1
    except Exception:
        if process is not None and process.poll() is None: terminate(process)
        if parent >= 0 and output >= 0:
            try: record(parent, output, job, {'event': 'failed', 'result': 'runner_or_diagnostic_failure'})
            except Exception: pass
        emergency(job, 'runner_or_diagnostic_failure')
        return 1
    finally:
        for signum, handler in previous_handlers.items(): signal.signal(signum, handler)
        if selector is not None: selector.close()
        if process is not None and process.stdout is not None: process.stdout.close()
        for descriptor in (output, lock, parent):
            if descriptor >= 0: os.close(descriptor)


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in JOBS: return 64
    try: return execute(sys.argv[1])
    except Exception:
        emergency(sys.argv[1], 'runner_initialization_failed'); return 1


if __name__ == '__main__':
    raise SystemExit(main())
