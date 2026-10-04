#!/usr/bin/python3
"""Read-only installation idle/history checks while the installer owns worker guards.

Never invokes installed Python/PHP, changes a ledger, clears a reservation, sends
an alert, hangs up a channel, or rewinds live state. The only PBX command is the
fixed read-only Asterisk channel inventory. Worker and paging-slot locks belong
to the installer and must remain held across the before/after inspections.
"""
from __future__ import annotations
import argparse
import fcntl
import importlib.util
import hashlib
import json
import math
import os
from pathlib import Path
import pwd
import re
import selectors
import stat
import subprocess
import sys
import time
from datetime import datetime, timezone

# Load the authenticated sibling even in isolated Python or copied installer bootstraps.
sys.dont_write_bytecode = True
_audio_spec = importlib.util.spec_from_file_location('sls_audio_state', Path(__file__).resolve().with_name('sls_audio_state.py'))
_audio_state = importlib.util.module_from_spec(_audio_spec)
_audio_spec.loader.exec_module(_audio_state)

DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
MODULE = Path('/var/www/html/admin/modules/slsmassnotifyserver/module.xml')
SPOOLS = (Path('/var/spool/asterisk/outgoing'), Path('/var/spool/asterisk/tmp'))
MAX_STATE = 16 * 1024 * 1024
MAX_HISTORY = 10000
TOKEN = re.compile(r'[a-f0-9]{32}')
HASH = re.compile(r'[a-f0-9]{64}')
ACTIVE_SCRIPTS = {'sls_mass_notify_test.sh', 'sls_mass_notify_live_paging.php', 'sls_mass_notify_phone_agi.py'}
ENTERPRISE_SCRIPTS = {'sls_mass_notify_cluster_worker.php', 'sls_mass_notify_cluster_effect.php',
                      'sls_mass_notify_incident_response.php'}

class IdleError(RuntimeError): pass


def directory(path, *, protected=False):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise IdleError('Inspection path must be absolute and canonical')
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        for name in path.parts[1:]:
            child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
            os.close(fd); fd = child
            info = os.fstat(fd)
            if protected and (info.st_uid != 0 or info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX):
                raise IdleError('Inspection baseline has an unprotected ancestor')
        result, fd = fd, -1
        return result
    finally:
        if fd >= 0: os.close(fd)


def entries(fd, limit=20000, seconds=2):
    deadline = time.monotonic() + seconds
    with os.scandir(fd) as names:
        for index, entry in enumerate(names):
            if index >= limit or time.monotonic() > deadline:
                raise IdleError('Idle directory inspection exceeded its bounded limit; no state was changed')
            yield entry.name


def read_json(path, limit=MAX_STATE, *, optional=False, protected=False):
    parent = fd = -1
    try:
        parent = directory(Path(path).parent, protected=protected)
        fd = os.open(Path(path).name, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > limit:
            raise IdleError('Inspection state is linked, special, or oversized: ' + Path(path).name)
        account = pwd.getpwnam('asterisk')
        if info.st_uid not in (0, account.pw_uid) or protected and (info.st_uid != 0 or info.st_mode & 0o077):
            raise IdleError('Inspection state ownership or private baseline mode is unsafe: ' + Path(path).name)
        fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
        body = bytearray()
        while len(body) <= limit:
            block = os.read(fd, min(65536, limit + 1 - len(body)))
            if not block: break
            body.extend(block)
        after = os.fstat(fd)
        current = os.stat(Path(path).name, dir_fd=parent, follow_symlinks=False)
        def identity(value): return value.st_dev, value.st_ino, value.st_size, value.st_mtime_ns, value.st_ctime_ns, value.st_nlink
        if len(body) > limit or identity(info) != identity(after) or identity(after) != identity(current):
            raise IdleError('Inspection state changed while being read; retry after the worker becomes idle')
        value = json.loads(body, parse_constant=lambda unused: (_ for _ in ()).throw(ValueError('nonfinite JSON')))
        if not isinstance(value, dict): raise IdleError('Inspection state is not an object: ' + Path(path).name)
        return value
    except FileNotFoundError:
        if optional: return None
        raise IdleError('Required inspection state is missing: ' + Path(path).name) from None
    except (ValueError, BlockingIOError) as error:
        raise IdleError('Inspection state is corrupt or busy: ' + Path(path).name) from None
    finally:
        if fd >= 0: os.close(fd)
        if parent >= 0: os.close(parent)


def channel_inventory():
    """Bound both output pipes; only this read-only CLI child is stopped on timeout."""
    command = ['/usr/sbin/asterisk', '-rx', 'core show channels concise']
    process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                               env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C'}, close_fds=True)
    output = {'stdout': bytearray(), 'stderr': bytearray()}
    deadline = time.monotonic() + 5
    try:
        with selectors.DefaultSelector() as selected:
            for name in output:
                stream = getattr(process, name); os.set_blocking(stream.fileno(), False)
                selected.register(stream, selectors.EVENT_READ, name)
            while selected.get_map():
                remaining = deadline - time.monotonic()
                if remaining <= 0: raise IdleError('Read-only Asterisk channel inspection timed out')
                for key, event in selected.select(min(remaining, 1)):
                    data = os.read(key.fd, 65536)
                    if not data:
                        selected.unregister(key.fileobj); key.fileobj.close(); continue
                    if len(output[key.data]) + len(data) > 1024 * 1024:
                        raise IdleError('Read-only Asterisk channel inventory exceeded its safe bound')
                    output[key.data].extend(data)
            code = process.wait(timeout=max(0.01, deadline - time.monotonic()))
        if code or output['stderr'].strip(): raise IdleError('Read-only Asterisk channel inspection failed; check local PBX health')
        return bytes(output['stdout']).decode('utf-8', errors='strict')
    finally:
        if process.poll() is None:
            process.kill(); process.wait()
        for stream in (process.stdout, process.stderr):
            if not stream.closed: stream.close()


class IdleCheck:
    def __init__(self, data=DATA, *, module=MODULE, proc=Path('/proc'), spools=SPOOLS,
                 channels=channel_inventory, boot_id=None, monotonic=time.monotonic, clock=time.time, subsystem_paths=None):
        self.data = Path(data); self.module = Path(module); self.proc = Path(proc)
        self.spools = tuple(map(Path, spools)); self.channels = channels
        self.boot_id = boot_id; self.monotonic = monotonic; self.clock = clock
        self.subsystem_paths = tuple(map(Path, subsystem_paths)) if subsystem_paths is not None else (
            self.module.with_name('PhoneEventService.php'),
            Path('/etc/systemd/system/sls-mass-notify-phone-events.service'),
            Path('/usr/local/bin/sls_mass_notify/sls_phone_events.py'))

    def installed(self):
        try:
            parent = directory(self.module.parent)
        except FileNotFoundError: return False
        try:
            try: info = os.stat(self.module.name, dir_fd=parent, follow_symlinks=False)
            except FileNotFoundError: return False
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
                raise IdleError('Installed module identity file is unsafe')
            return True
        finally: os.close(parent)

    def phone_subsystem_present(self):
        # Genuine pre-collector releases can upgrade while idle. A partially
        # missing modern subsystem must not disguise an unhealthy collector.
        found = False
        for path in self.subsystem_paths:
            try: parent = directory(path.parent)
            except FileNotFoundError: continue
            try:
                try: info = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
                except FileNotFoundError: continue
                if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
                    raise IdleError('Phone collector installation marker is unsafe')
                found = True
            finally: os.close(parent)
        return found

    def no_processes(self):
        root = directory(self.proc)
        try:
            for pid in entries(root, 32768, 3):
                if not pid.isdigit() or int(pid) == os.getpid(): continue
                child = fd = -1
                try:
                    child = os.open(pid, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=root)
                    fd = os.open('cmdline', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=child)
                    raw = os.read(fd, 65537)
                    if len(raw) > 65536: raise IdleError('A process command line exceeded the inspection bound')
                    argv = raw.split(b'\0')
                    names = {value.rsplit(b'/', 1)[-1].decode('utf-8', errors='replace') for value in argv}
                    if ACTIVE_SCRIPTS.intersection(names):
                        raise IdleError('A manual weather test, phone AGI, or dial-in page is active; wait for it to finish')
                    if ENTERPRISE_SCRIPTS.intersection(names):
                        raise IdleError('An enterprise worker or response helper is active; stop its external supervisor and wait for cluster, edge, and incident-response work to finish before installation')
                    if 'sls_mass_notify_announcement_worker.php' in names and (
                            b'--supervise' in argv or any(re.fullmatch(rb'job_[a-f0-9]{32}', value) for value in argv)):
                        raise IdleError('An announcement worker or supervisor is active; allow delivery to finish')
                except (FileNotFoundError, ProcessLookupError): pass
                finally:
                    if fd >= 0: os.close(fd)
                    if child >= 0: os.close(child)
        finally: os.close(root)

    def no_pending(self):
        try: parent = directory(self.data / 'announcement-jobs')
        except FileNotFoundError: return
        try:
            if any(re.fullmatch(r'pending_job_[a-f0-9]{32}\.mark', name) for name in entries(parent)):
                raise IdleError('An announcement remains queued or running; allow delivery to finish before installation')
        finally: os.close(parent)

    def no_playback(self):
        for path in self.spools:
            try: fd = directory(path)
            except FileNotFoundError: continue
            try:
                if any(name.lower().startswith(('sls_', 'nws_', 'xweather_')) for name in entries(fd, 5000)):
                    raise IdleError('Notification call files remain in the Asterisk spool; allow them to drain and review stale files separately')
            finally: os.close(fd)
        try:
            with _audio_state.read_audio_state(self.data, nonblocking=True) as state:
                recipients, waiting = state.get('recipients', {}), state.get('waiting', {})
                if not isinstance(recipients, dict) or not isinstance(waiting, dict): raise IdleError('Audio reservation state is malformed')
                times = list(recipients.values())
                for row in waiting.values():
                    if not isinstance(row, dict): raise IdleError('Queued audio reservation state is malformed')
                    times.append(row.get('expires'))
                if any(isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value) or value < 0 for value in times):
                    raise IdleError('Audio reservation timestamps are invalid')
                if any(value > self.clock() for value in times):
                    raise IdleError('Audio playback or a queued reservation is active; wait and retry')
        except FileNotFoundError as error:
            # A genuinely fresh installation can have no data directory yet.
            # Never turn a missing file inside an existing/installed tree into
            # an empty journal: that could discard established reservations.
            if self.installed() or self.data.exists() or self.data.is_symlink():
                raise IdleError('Audio reservation inspection state is missing') from error
        except (OSError, RuntimeError) as error:
            if isinstance(error, IdleError):
                raise
            raise IdleError('Audio reservation inspection failed: ' + str(error)) from error
        lines = [line for line in self.channels().splitlines() if line.strip()]
        if any(line.count('!') < 10 for line in lines): raise IdleError('Asterisk returned an unrecognized channel inventory')
        if any(re.search(r'sls[-_]|nws[-_]|xweather|SLS_Mass_Notifications_Plugin', line, re.I) for line in lines):
            raise IdleError('A notification channel is active; allow it to finish before installation')

    def phone_history(self, installed):
        state = read_json(self.data / 'phone-admission.json', optional=not installed)
        if state is None: return {}
        batches = state.get('batches')
        if state.get('schema') != 1 or not isinstance(batches, dict) or len(batches) > MAX_HISTORY:
            raise IdleError('Phone admission history cannot be inspected safely')
        history = {}
        for token, batch in batches.items():
            if not isinstance(token, str) or not TOKEN.fullmatch(token) or not isinstance(batch, dict) or batch.get('status') != 'closed':
                raise IdleError('Phone admission has an unclosed or invalid batch; allow it to drain')
            history[hashlib.sha256(token.encode()).hexdigest()] = hashlib.sha256(
                json.dumps(batch, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()).hexdigest()
        if installed:
            health = state.get('collector')
            if self.boot_id is None:
                self.boot_id = Path('/proc/sys/kernel/random/boot_id').read_text().strip()
            if not isinstance(health, dict) or health.get('boot_id') != self.boot_id or not TOKEN.fullmatch(str(health.get('generation', ''))):
                raise IdleError('Phone collector identity is unavailable; restore collector health before installation')
            tick = health.get('heartbeat_tick')
            if isinstance(tick, bool) or not isinstance(tick, (int, float)) or not math.isfinite(tick) or not 0 <= self.monotonic() - tick <= 15:
                raise IdleError('Phone collector heartbeat is stale or invalid; restore collector health before installation')
        return history

    def inspect(self, baseline=None):
        self.no_processes(); self.no_pending(); self.no_playback()
        installed = self.installed()
        collector_required = installed and self.phone_subsystem_present()
        history = self.phone_history(collector_required)
        if baseline is not None:
            before = read_json(Path(baseline), 2 * 1024 * 1024, protected=True)
            known = before.get('phone_history')
            if (before.get('schema') != 1 or before.get('ok') is not True or not isinstance(known, dict)
                    or len(known) > MAX_HISTORY or before.get('closed_phone_batches') != len(known)
                    or any(not isinstance(key, str) or not HASH.fullmatch(key) or not isinstance(value, str) or not HASH.fullmatch(value) for key, value in known.items())):
                raise IdleError('Installation phone-history baseline is invalid')
            if any(history.get(key) != value for key, value in known.items()):
                raise IdleError('Retained closed phone history changed or disappeared; preserve recovery evidence and never restore an old live ledger')
        return {'schema': 1, 'ok': True, 'checked_at': datetime.now(timezone.utc).isoformat(), 'installed': installed,
                'phone_collector_required': collector_required, 'closed_phone_batches': len(history), 'phone_history': history,
                'manual_test_active': False, 'notification_playback_active': False, 'pending_announcements': False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--data', type=Path, default=DATA)
    parser.add_argument('action', choices=('inspect',))
    parser.add_argument('--baseline', type=Path)
    args = parser.parse_args()
    if os.geteuid() != 0: raise IdleError('Complete installation idle inspection requires root visibility')
    print(json.dumps(IdleCheck(args.data).inspect(args.baseline), sort_keys=True))

if __name__ == '__main__':
    try: main()
    except (IdleError, OSError, ValueError, TypeError, subprocess.SubprocessError) as error:
        print('SLS installation idle check failed: ' + str(error), file=sys.stderr)
        raise SystemExit(1)
