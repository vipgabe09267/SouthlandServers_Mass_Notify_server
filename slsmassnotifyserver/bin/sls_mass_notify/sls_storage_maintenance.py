#!/usr/bin/python3
"""Bounded retention for audit, jobs and generated media. No configuration writes."""
import fcntl
import json
import os
import pwd
import stat
import tempfile
import time
from datetime import datetime
from pathlib import Path

DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')

def read_locked_object(path, limit=4 * 1024 * 1024):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'r', encoding='utf-8') as handle:
        fcntl.flock(handle, fcntl.LOCK_SH)
        metadata = os.fstat(handle.fileno())
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_size > limit:
            raise ValueError('Invalid state file type or size')
        value = json.load(handle)
        if not isinstance(value, dict):
            raise ValueError('Invalid state object')
        return value

def storage_summary(directory=DATA, now=None):
    now = time.time() if now is None else now
    filesystem = os.statvfs(directory)
    free = filesystem.f_bavail * filesystem.f_frsize
    total = filesystem.f_blocks * filesystem.f_frsize
    summary = {'checked_at': int(now), 'free_bytes': free, 'total_bytes': total,
               'pending_external': 0, 'expired_external': 0, 'oldest_pending_at': 0, 'queue_errors': 0}
    paths = set(directory.glob('external-deliveries-*.json')) | set(directory.glob('*-external-deliveries.json'))
    for path in sorted(paths)[:16]:
        try:
            records = read_locked_object(path).get('deliveries', {})
            if not isinstance(records, dict):
                raise ValueError('Invalid delivery queue')
            for record in records.values():
                if not isinstance(record, dict):
                    raise ValueError('Invalid delivery record')
                if record.get('terminal_status') == 'expired':
                    summary['expired_external'] += 1
                elif not record.get('completed_at') and (record.get('email_pending') or record.get('webhook_pending')):
                    summary['pending_external'] += 1
                    created = int(record.get('created_at') or 0)
                    previous = summary['oldest_pending_at']
                    summary['oldest_pending_at'] = min(previous, created) if previous else created
        except (OSError, ValueError, TypeError):
            summary['queue_errors'] += 1
    audit = directory / 'control-api-audit.jsonl'
    summary.update(pending_weather=0, failed_weather=0, uncertain_weather=0, expired_weather=0)
    try:
        weather = read_locked_object(directory / 'weather-delivery.json', limit=16 * 1024 * 1024)
        for job in weather.get('jobs', {}).values():
            state = job.get('state')
            if state in ('queued', 'running'):
                summary['pending_weather'] += 1
            elif state in ('failed', 'uncertain', 'expired') and float(job.get('updated_at', 0)) > now - 7 * 86400:
                summary[state + '_weather'] += 1
    except FileNotFoundError:
        pass
    except (OSError, ValueError, TypeError, AttributeError):
        summary['queue_errors'] += 1
    summary['audit_at_capacity'] = audit.is_file() and audit.stat().st_size >= 8 * 1024 * 1024 - 4096
    fd, temporary = tempfile.mkstemp(prefix='.storage-summary-', dir=directory)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as handle:
            os.fchmod(handle.fileno(), 0o640)
            if os.geteuid() == 0:
                account = pwd.getpwnam('asterisk')
                os.fchown(handle.fileno(), account.pw_uid, account.pw_gid)
            json.dump(summary, handle, separators=(',', ':'))
        os.replace(temporary, directory / 'storage-summary.json')
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
    return summary

def prune_audit(path, now=None):
    now = time.time() if now is None else now
    try:
        fd = os.open(path, os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK)
    except FileNotFoundError:
        return
    with os.fdopen(fd, 'r+', encoding='utf-8', errors='replace') as handle:
        metadata = os.fstat(handle.fileno())
        if not stat.S_ISREG(metadata.st_mode):
            raise RuntimeError('audit log is not a regular file')
        fcntl.flock(handle, fcntl.LOCK_EX)
        handle.seek(max(0, metadata.st_size - 8 * 1024 * 1024))
        if metadata.st_size > 8 * 1024 * 1024:
            handle.readline()
        lines = handle.readlines()
        kept = []
        for line in lines:
            try:
                row = json.loads(line)
                created = datetime.fromisoformat(row['created_at']).timestamp()
                if created >= now - 30 * 86400:
                    kept.append(line)
            except (ValueError, KeyError, TypeError):
                continue
        kept = kept[-10000:]
        result = ''.join(kept)
        if result != ''.join(lines) or metadata.st_size > 8 * 1024 * 1024:
            handle.seek(0)
            handle.write(result)
            handle.truncate()
            handle.flush()

def pending_audio_names():
    names = set()
    for directory in (Path('/var/spool/asterisk/outgoing'), DATA / 'announcement-jobs'):
        if directory.is_symlink() or not directory.is_dir():
            continue
        for path in directory.iterdir():
            if path.is_symlink() or not path.is_file() or path.stat().st_size > 262144:
                continue
            if directory.name == 'outgoing' and path.name.startswith(('sls_', 'nws_')):
                for line in path.read_text(errors='replace').splitlines():
                    if line.startswith('Setvar: SLS_SOUND='):
                        names.add(line.split('=', 1)[1].rsplit('/', 1)[-1] + '.wav')
    return names

def main():
    storage_summary()
    prune_audit(DATA / 'control-api-audit.jsonl')
    pending = pending_audio_names()
    now = time.time()
    # A malformed reservation journal stops media deletion, never playback.
    leases = {}
    try:
        fd = os.open(DATA / 'audio-reservations.json', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o640)
    except FileNotFoundError:
        fd = None
    if fd is not None:
        # Keep the original descriptor (and its shared flock) until deletion
        # finishes. A new reservation cannot race a stale cleanup snapshot.
        with os.fdopen(os.dup(fd), 'r', encoding='utf-8') as handle:
            fcntl.flock(handle, fcntl.LOCK_SH)
            if not stat.S_ISREG(os.fstat(handle.fileno()).st_mode):
                raise RuntimeError('Unsafe audio reservation journal')
            if os.geteuid() == 0:
                account = pwd.getpwnam('asterisk')
                os.fchown(fd, account.pw_uid, account.pw_gid)
            raw = handle.read(1048577)
            state = json.loads(raw) if raw else {'media': {}}
            leases = state['media']
            if not isinstance(leases, dict):
                raise RuntimeError('Invalid media reservations')
            waiting = state.get('waiting', {})
            if not isinstance(waiting, dict):
                raise RuntimeError('Invalid waiting media reservations')
            for row in waiting.values():
                if not isinstance(row, dict) or not isinstance(row.get('media_name'), str):
                    raise RuntimeError('Invalid waiting media reference')
                if float(row.get('expires', 0)) > now and float(row.get('heartbeat', 0)) > now:
                    pending.add(row['media_name'])
    descriptors = []
    try:
        descriptors.append(os.open(DATA, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW))
        for component in ('sounds', 'tts'):
            descriptors.append(os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=descriptors[-1]))
        directory_fd = descriptors[-1]
        for name in os.listdir(directory_fd):
            if not name.endswith('.wav') or name in pending:
                continue
            try:
                metadata = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
                protected_until = float(leases.get(name, 0))
                if stat.S_ISREG(metadata.st_mode) and metadata.st_mtime < now - 900 and protected_until <= now:
                    os.unlink(name, dir_fd=directory_fd)
            except FileNotFoundError:
                continue
    except FileNotFoundError:
        pass
    finally:
        for descriptor in reversed(descriptors):
            os.close(descriptor)
        if fd is not None:
            os.close(fd)
    jobs = DATA / 'announcement-jobs'
    if jobs.is_dir() and not jobs.is_symlink():
        for path in jobs.glob('job_*.json'):
            if path.is_symlink() or path.stat().st_size > 2 * 1024 * 1024:
                continue
            try:
                job = json.loads(path.read_text())
            except (OSError, ValueError):
                continue
            if job.get('state') not in ('queued', 'running') and path.stat().st_mtime < now - 30 * 86400:
                path.unlink(missing_ok=True)

if __name__ == '__main__':
    main()
