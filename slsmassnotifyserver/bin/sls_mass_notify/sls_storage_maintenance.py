#!/usr/bin/python3
"""Bounded retention for audit, jobs and generated media. No configuration writes."""
import fcntl
import importlib.util
import json
import math
import os
import pwd
import re
import secrets
import socket
import stat
import sys
import tempfile
import time
from collections import deque
from datetime import datetime
from pathlib import Path

import sys as _config_sys
_config_sys.dont_write_bytecode = True
_config_crypto_spec = importlib.util.spec_from_file_location("sls_config_crypto", Path(__file__).resolve().with_name("sls_config_crypto.py"))
_config_crypto = importlib.util.module_from_spec(_config_crypto_spec)
_config_crypto_spec.loader.exec_module(_config_crypto)

# Load the authenticated sibling even in isolated Python or copied installer bootstraps.
sys.dont_write_bytecode = True
_audio_spec = importlib.util.spec_from_file_location('sls_audio_state', Path(__file__).resolve().with_name('sls_audio_state.py'))
_audio_state = importlib.util.module_from_spec(_audio_spec)
_audio_spec.loader.exec_module(_audio_state)

DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
WEB = Path('/var/www/html/sls_mass_notify')
OUTGOING = Path('/var/spool/asterisk/outgoing')
EVENT_LOGS = (Path('/var/log/sls_mass_notify_events.jsonl'), Path('/var/log/nws_weather_alert_events.jsonl'))
MAX_SCAN = 20000
DIRECTORY_SCAN_SECONDS = 3
MAX_DELETE = 250
MAX_REFERENCE_BYTES = 32 * 1024 * 1024
MAX_QUEUE_FILES = 256
AUDIT_MAX_BYTES = 8 * 1024 * 1024
SYSLOG_SOCKET = '/dev/log'
ACTIVE_JOBS = {'queued', 'worker_starting', 'running'}
TERMINAL_JOBS = {'complete', 'failed', 'partial_or_failed', 'expired'}
AUDIO_NAME = re.compile(r'(?:announcement_(?:tts|sequence|tone)_[A-Za-z0-9_-]+|nws_[A-Za-z0-9_-]+|test_(?:piper_tts|sequence)_[A-Za-z0-9_-]+|xweather_(?:tts|sequence)_[A-Za-z0-9_-]+)\.wav')
IMAGE_NAME = re.compile(r'(?:mms_[0-9]{14}_[a-f0-9]{32}\.png|alert_[a-f0-9]{12}(?:_[a-f0-9]{20})?\.png|announcement_[0-9]{14}_[a-f0-9]{6,32}\.png|phone_payload_[a-f0-9]{12}(?:_[a-f0-9]{20})?\.xml)')

def open_directory(path):
    """Anchor every path component; root cleanup never traverses a symlink."""
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise RuntimeError('Unsafe storage directory')
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    descriptor = os.open('/', flags)
    try:
        for component in path.parts[1:]:
            child = os.open(component, flags, dir_fd=descriptor)
            os.close(descriptor)
            descriptor = child
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise

def read_at(directory_fd, name, maximum):
    descriptor = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory_fd)
    with os.fdopen(descriptor, 'rb') as handle:
        metadata = os.fstat(handle.fileno())
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size > maximum:
            raise RuntimeError('Unsafe or oversized retention reference')
        raw = handle.read(maximum + 1)
        if len(raw) > maximum:
            raise RuntimeError('Retention reference exceeds its limit')
        return raw

def reference_names(value):
    names = set()
    stack = [value]
    count = 0
    while stack:
        count += 1
        if count > 200000:
            raise RuntimeError('Too many media references')
        item = stack.pop()
        if isinstance(item, dict):
            stack.extend(item.values())
        elif isinstance(item, list):
            stack.extend(item)
        elif isinstance(item, str):
            for match in re.finditer(r'(?:announcement_(?:tts|sequence|tone)_|nws_|test_(?:piper_tts|sequence)_|xweather_(?:tts|sequence)_)[A-Za-z0-9_-]{1,220}(?:\.wav)?', item):
                name = match.group()
                names.add(name if name.endswith('.wav') else name + '.wav')
            for match in re.finditer(r'(?:alert_|announcement_|phone_payload_)[A-Za-z0-9_]{1,220}\.(?:png|xml)', item):
                if IMAGE_NAME.fullmatch(match.group()):
                    names.add(match.group())
    return names

def media_references(directory, spool, now):
    """Protect current/pending settings, queued work and retained desktop records.

    Terminal Weather payloads are intentionally unnecessary: only queued/running
    records can still play media, and active playback has a separate lease.
    """
    names = set()
    remaining = MAX_REFERENCE_BYTES
    def read_object(fd, name, maximum=4 * 1024 * 1024, required=False):
        nonlocal remaining
        try:
            raw = read_at(fd, name, min(maximum, remaining))
        except FileNotFoundError:
            if required:
                raise RuntimeError('Required media reference is missing')
            return None
        remaining -= len(raw)
        value = _config_crypto.decode_config(raw) if name in {"mass-notifications.config", "mass-notifications.pending.config", "mass-notifications-settings.pending.json", "slsmassnotifyserver-settings.pending.json"} else json.loads(raw)
        if not isinstance(value, dict):
            raise RuntimeError('Invalid media reference object')
        return value
    descriptor = open_directory(directory)
    try:
        for filename in ('mass-notifications.config', 'mass-notifications.pending.config', 'mass-notifications-settings.pending.json'):
            value = read_object(descriptor, filename, required=filename == 'mass-notifications.config')
            if value is not None:
                names.update(reference_names(value))
        weather = read_object(descriptor, 'weather-delivery.json', 16 * 1024 * 1024)
        if weather is not None:
            jobs = weather.get('jobs')
            if not isinstance(jobs, dict):
                raise RuntimeError('Invalid Weather media references')
            for job in jobs.values():
                if not isinstance(job, dict):
                    raise RuntimeError('Invalid Weather job reference')
                if job.get('state') in ACTIVE_JOBS:
                    names.update(reference_names(job))
    finally:
        os.close(descriptor)
    # An older pending config can still be imported on a later settings save.
    parent = open_directory(Path(directory).parent)
    try:
        legacy = read_object(parent, 'slsmassnotifyserver-settings.pending.json')
        if legacy is not None:
            names.update(reference_names(legacy))
    finally:
        os.close(parent)
    for subdirectory in (Path(directory) / 'announcement-jobs', Path(directory) / 'sipnotify', Path(spool)):
        try:
            descriptor = open_directory(subdirectory)
        except FileNotFoundError:
            continue
        try:
            if subdirectory.name == 'sipnotify':
                try:
                    raw = read_at(descriptor, 'sipnotify_events.jsonl', min(16 * 1024 * 1024, remaining))
                except FileNotFoundError:
                    continue
                remaining -= len(raw)
                for index, line in enumerate(raw.splitlines()):
                    if index >= 1000 or len(line) > 262144:
                        raise RuntimeError('Desktop media references exceed their limit')
                    if line.strip():
                        names.update(reference_names(json.loads(line)))
                continue
            with os.scandir(descriptor) as entries:
                deadline = time.monotonic() + DIRECTORY_SCAN_SECONDS
                relevant = 0
                for entry in entries:
                    if time.monotonic() > deadline:
                        raise RuntimeError('Media reference directory exceeded its time limit; cleanup deferred')
                    if subdirectory.name == 'announcement-jobs':
                        if not re.fullmatch(r'job_[a-f0-9]{32}\.json', entry.name):
                            continue
                        # Completed history need not be parsed. A pending marker
                        # protects older running jobs; unindexed new jobs remain
                        # protected during their entire 15-minute admission age.
                        metadata = entry.stat(follow_symlinks=False)
                        marker = 'pending_' + entry.name[:-5] + '.mark'
                        try:
                            os.stat(marker, dir_fd=descriptor, follow_symlinks=False)
                            pending = True
                        except FileNotFoundError:
                            pending = False
                        if metadata.st_mtime < now - 900 and not pending:
                            continue
                        relevant += 1
                        if relevant > MAX_SCAN:
                            raise RuntimeError('Media reference records exceed their scan limit')
                        job = read_object(descriptor, entry.name, 2 * 1024 * 1024, required=True)
                        if job.get('state') in ACTIVE_JOBS:
                            names.update(reference_names(job))
                    elif entry.name.startswith(('sls_', 'nws_')):
                        relevant += 1
                        if relevant > MAX_SCAN:
                            raise RuntimeError('Spool media references exceed their scan limit')
                        raw = read_at(descriptor, entry.name, min(262144, remaining))
                        remaining -= len(raw)
                        for line in raw.decode('utf-8', errors='strict').splitlines():
                            if line.startswith('Setvar: SLS_SOUND='):
                                names.update(reference_names(line))
        finally:
            os.close(descriptor)
    return names

def generated_inventory(directory, web_dir):
    """Inspect both caches completely before making a size-based decision."""
    rows = []
    deadline = time.monotonic() + DIRECTORY_SCAN_SECONDS
    for kind, path, pattern, age in (
            ('audio', Path(directory) / 'sounds/tts', AUDIO_NAME, 900),
            ('images', Path(web_dir), IMAGE_NAME, 3 * 86400)):
        try: descriptor = open_directory(path)
        except FileNotFoundError: continue
        try:
            with os.scandir(descriptor) as entries:
                for index, entry in enumerate(entries):
                    if index >= MAX_SCAN or time.monotonic() > deadline:
                        raise RuntimeError('Generated-media inventory exceeded its scan limit; size-based cleanup was deferred.')
                    if not pattern.fullmatch(entry.name): continue
                    try: info = entry.stat(follow_symlinks=False)
                    except FileNotFoundError: continue
                    if stat.S_ISREG(info.st_mode) and info.st_nlink == 1:
                        rows.append((kind, path, entry.name, info, age))
        finally: os.close(descriptor)
    return rows

def generated_cache_limit(settings):
    value = settings.get('generated_media_cache_mib', 512)
    if type(value) is not int or not 64 <= value <= 4096:
        raise RuntimeError('Generated-media cache target must be an integer from 64 through 4096 MiB in the central configuration.')
    return value * 1024 * 1024

def prune_generated_cache(directory, web_dir, protected, now, limit):
    rows = generated_inventory(directory, web_dir)
    total = sum(row[3].st_size for row in rows)
    removed = {'audio_removed': 0, 'images_removed': 0}
    # Age-expired files first, then oldest unreferenced files under pressure.
    # A 15-minute grace always protects newly rendered/in-flight media.
    rows.sort(key=lambda row: (row[3].st_mtime >= now - row[4], row[3].st_mtime, row[2]))
    for kind, path, name, before, age in rows:
        counter = kind + '_removed'
        if name in protected or before.st_mtime >= now - (86400 if name.startswith('mms_') else 900) or removed[counter] >= MAX_DELETE:
            continue
        if before.st_mtime >= now - age and total <= limit: continue
        descriptor = open_directory(path)
        try:
            try: current = os.stat(name, dir_fd=descriptor, follow_symlinks=False)
            except FileNotFoundError: continue
            if (current.st_dev, current.st_ino, current.st_nlink, current.st_mtime_ns, current.st_size, current.st_mode) != (
                    before.st_dev, before.st_ino, 1, before.st_mtime_ns, before.st_size, before.st_mode): continue
            os.unlink(name, dir_fd=descriptor); os.fsync(descriptor)
            removed[counter] += 1; total -= before.st_size
        finally: os.close(descriptor)
    if total > limit:
        raise RuntimeError('Generated media still uses %d MiB above its %d MiB cache target. Active/referenced/recent files were preserved. Check queued alerts and free space; bounded cleanup retries on the next maintenance run.'
                           % ((total + 1048575) // 1048576, limit // 1048576))
    return removed

def prune_generated_media(directory=None, web_dir=None, spool=None, now=None):
    directory = DATA if directory is None else Path(directory)
    web_dir = WEB if web_dir is None else Path(web_dir)
    spool = OUTGOING if spool is None else Path(spool)
    now = time.time() if now is None else now
    parent = open_directory(directory)
    activity = None
    try:
        # Announcements may be reading a cached composite/tone before acquiring
        # its playback lease. The existing activity lock covers that interval
        # and also prevents configuration replacement during reference reads.
        activity = os.open('announcement-activity.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o640, dir_fd=parent)
        activity_meta = os.fstat(activity)
        if not stat.S_ISREG(activity_meta.st_mode) or activity_meta.st_nlink != 1:
            raise RuntimeError('Unsafe announcement activity lock')
        if os.geteuid() == 0:
            account = pwd.getpwnam('asterisk')
            os.fchown(activity, account.pw_uid, account.pw_gid)
        fcntl.flock(activity, fcntl.LOCK_EX | fcntl.LOCK_NB)
        # The permanent shared lock spans reference inspection and deletion.
        # Atomic JSON replacement cannot strand this reader on an old inode.
        with _audio_state.read_audio_state(directory, nonblocking=True) as state:
            leases = state.get('media')
            waiting = state.get('waiting', {})
            if not isinstance(leases, dict) or not isinstance(waiting, dict):
                raise RuntimeError('Invalid media reservations')
            protected = media_references(directory, spool, now)
            for name, expires in leases.items():
                if not isinstance(expires, (int, float)) or not math.isfinite(expires):
                    raise RuntimeError('Invalid media lease timestamp')
                if expires > now:
                    protected.add(name)
            for row in waiting.values():
                if not isinstance(row, dict) or not isinstance(row.get('media_name'), str):
                    raise RuntimeError('Invalid waiting media reference')
                if any(not isinstance(row.get(key), (int, float)) or not math.isfinite(row[key]) for key in ('expires', 'heartbeat')):
                    raise RuntimeError('Invalid waiting media timestamp')
                if row['expires'] > now and row['heartbeat'] > now:
                    protected.add(row['media_name'])
            settings = _config_crypto.decode_config(read_at(parent, 'mass-notifications.config', _config_crypto.MAX_FILE_BYTES))
            return prune_generated_cache(directory, web_dir, protected, now, generated_cache_limit(settings))
    finally:
        if activity is not None:
            os.close(activity)
        os.close(parent)

def lock_reclamation_ready(now):
    # Give pre-upgrade PHP requests an hour to drain. Never infer readiness from
    # the web-owned module copy; the installed runtime writer is root protected.
    writer = Path(__file__).with_name('sls_announcement_jobs.php')
    try:
        metadata = writer.lstat()
        return (stat.S_ISREG(metadata.st_mode) and metadata.st_nlink == 1 and metadata.st_uid == 0
                and not metadata.st_mode & 0o022 and max(metadata.st_mtime, metadata.st_ctime) <= now - 3600)
    except OSError:
        return False


def prune_announcement_jobs(directory, now=None):
    now = time.time() if now is None else now
    try:
        descriptor = open_directory(directory)
    except FileNotFoundError:
        return 0
    removed = 0
    retired_locks = 0
    reclaim_locks = lock_reclamation_ready(now)
    try:
        # Examine records before orphan locks. A large historical lock backlog
        # must not consume the record scan or deletion budget and starve jobs.
        for orphan in (False, True) if reclaim_locks else (False,):
            with os.scandir(descriptor) as entries:
                deadline = time.monotonic() + DIRECTORY_SCAN_SECONDS
                relevant = 0
                for entry in entries:
                    if time.monotonic() > deadline:
                        print('Announcement history scan reached its time limit; additional jobs were preserved.')
                        break
                    if removed + retired_locks >= MAX_DELETE:
                        break
                    pattern = r'job_[a-f0-9]{32}\.json\.lock' if orphan else r'job_[a-f0-9]{32}\.json'
                    if not re.fullmatch(pattern, entry.name):
                        continue
                    lock = None
                    try:
                        metadata = entry.stat(follow_symlinks=False)
                        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_mtime >= now - 30 * 86400:
                            continue
                        relevant += 1
                        if relevant > MAX_SCAN:
                            print('Announcement history records reached their scan limit; additional jobs were preserved.')
                            break
                        job_name = entry.name[:-5] if orphan else entry.name
                        lock_name = job_name + '.lock'
                        # Never create missing locks during retention. Current
                        # writers recheck inode identity after acquiring a lock.
                        lock = os.open(lock_name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=descriptor)
                        lock_meta = os.fstat(lock)
                        if (not stat.S_ISREG(lock_meta.st_mode) or lock_meta.st_nlink != 1 or lock_meta.st_mode & 0o022
                                or lock_meta.st_uid not in (0, os.fstat(descriptor).st_uid)):
                            continue
                        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                        current_lock = os.stat(lock_name, dir_fd=descriptor, follow_symlinks=False)
                        if (current_lock.st_dev, current_lock.st_ino, current_lock.st_nlink) != (lock_meta.st_dev, lock_meta.st_ino, 1):
                            continue
                        try:
                            os.stat('pending_' + job_name[:-5] + '.mark', dir_fd=descriptor, follow_symlinks=False)
                            continue
                        except FileNotFoundError:
                            pass
                        if orphan:
                            try:
                                os.stat(job_name, dir_fd=descriptor, follow_symlinks=False)
                                continue
                            except FileNotFoundError:
                                pass
                            # The original lock must still be old and unchanged.
                            if current_lock.st_mtime_ns != metadata.st_mtime_ns:
                                continue
                        else:
                            job = json.loads(read_at(descriptor, job_name, 2 * 1024 * 1024))
                            if not isinstance(job, dict) or job.get('id') != job_name[:-5] or job.get('state') not in TERMINAL_JOBS:
                                continue
                            current = os.stat(job_name, dir_fd=descriptor, follow_symlinks=False)
                            if (current.st_dev, current.st_ino, current.st_mtime_ns) != (metadata.st_dev, metadata.st_ino, metadata.st_mtime_ns):
                                continue
                            os.unlink(job_name, dir_fd=descriptor)
                            removed += 1
                        if reclaim_locks:
                            os.unlink(lock_name, dir_fd=descriptor)
                            retired_locks += 1
                    except (OSError, ValueError, RuntimeError):
                        continue
                    finally:
                        if lock is not None:
                            os.close(lock)
        if removed or retired_locks:
            os.fsync(descriptor)
    finally:
        os.close(descriptor)
    return removed

def copy_event_stream(source, target):
    source.seek(0)
    target.seek(0)
    total = 0
    while True:
        chunk = source.read(65536)
        if not chunk:
            break
        if target.write(chunk) != len(chunk):
            raise OSError('Incomplete event-log write')
        total += len(chunk)
    target.flush()
    os.ftruncate(target.fileno(), total)
    os.fsync(target.fileno())

def prune_event_log(path, retention_days=90, now=None, maximum_bytes=64 * 1024 * 1024, scan_seconds=3,
                    maximum_records=None, discard_invalid=False, recovery_directory=None):
    """Bounded, same-inode compaction compatible with existing flock appenders.

    An original backup is durable before the first overwrite. Ordinary failures
    restore it; abrupt termination leaves it for operator recovery, and another
    cleanup refuses to overwrite that recovery evidence.
    """
    now = time.time() if now is None else now
    try:
        days = int(retention_days)
    except (TypeError, ValueError):
        days = 90
    days = min(365, days if days >= 1 else 90)
    cutoff = now - days * 86400
    parent = open_directory(Path(path).parent)
    recovery = parent
    original = kept = None
    created = []
    preserve_backup = False
    try:
        if recovery_directory is not None:
            recovery = open_directory(Path(recovery_directory))
            info = os.fstat(recovery)
            if info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
                raise RuntimeError('event_log_retention_recovery_directory_unsafe')
        try:
            descriptor = os.open(Path(path).name, os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        except FileNotFoundError:
            return {'removed': 0, 'preserved_invalid': 0}
        with os.fdopen(descriptor, 'r+b') as handle:
            metadata = os.fstat(handle.fileno())
            if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size > maximum_bytes:
                raise RuntimeError('event_log_retention_unsafe_or_oversized')
            try:
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                return {'removed': 0, 'preserved_invalid': 0, 'busy': True}
            # Capture size only after the appender lock is ours.
            metadata = os.fstat(handle.fileno())
            if metadata.st_size > maximum_bytes:
                raise RuntimeError('event_log_retention_oversized')
            for suffix in ('backup', 'kept'):
                name = '.sls-retention-' + Path(path).name + '.' + suffix
                if recovery_directory is not None:
                    try:
                        os.stat(name, dir_fd=parent, follow_symlinks=False)
                    except FileNotFoundError:
                        pass
                    else:
                        raise RuntimeError('event_log_retention_recovery_required: Legacy recovery evidence exists at %s. '
                                           'Preserve the live log and recovery files; review them before retrying cleanup.' % (Path(path).parent / name))
                try:
                    fd = os.open(name, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=recovery)
                except FileExistsError:
                    backup_path = (Path(recovery_directory) if recovery_directory is not None else Path(path).parent) / name
                    raise RuntimeError('event_log_retention_recovery_required: A previous cleanup did not finish. '
                                       'The original backup location is %s. Preserve the live log and recovery files; '
                                       'review them before retrying cleanup.' % backup_path)
                created.append(name)
                stream = os.fdopen(fd, 'w+b')
                if suffix == 'backup':
                    original = stream
                else:
                    kept = stream
            removed = invalid = scanned = 0
            retained = deque() if maximum_records is not None else None
            deadline = time.monotonic() + scan_seconds
            while True:
                if time.monotonic() > deadline:
                    raise RuntimeError('event_log_retention_scan_timeout')
                line = handle.readline(262145)
                if not line:
                    break
                scanned += len(line)
                if len(line) > 262144 or scanned > maximum_bytes:
                    raise RuntimeError('event_log_retention_record_or_file_oversized')
                if original.write(line) != len(line):
                    raise OSError('event_log_retention_backup_failed')
                expired = False
                try:
                    value = json.loads(line)
                    if not isinstance(value, dict):
                        raise ValueError('Invalid log record')
                    timestamp = value.get('logged_at', value.get('created_at'))
                    parsed = datetime.fromisoformat(str(timestamp).replace('Z', '+00:00')).timestamp()
                    expired = math.isfinite(parsed) and parsed < cutoff
                except (ValueError, TypeError, OverflowError):
                    invalid += 1
                    expired = discard_invalid
                if expired:
                    removed += 1
                elif retained is not None:
                    retained.append(line)
                    if len(retained) > maximum_records:
                        retained.popleft()
                        removed += 1
                elif kept.write(line) != len(line):
                    raise OSError('event_log_retention_output_failed')
            if retained is not None:
                for line in retained:
                    if kept.write(line) != len(line):
                        raise OSError('event_log_retention_output_failed')
            if removed:
                for stream in (original, kept):
                    stream.flush(); os.fsync(stream.fileno())
                os.fsync(recovery)
                current = os.stat(Path(path).name, dir_fd=parent, follow_symlinks=False)
                if (current.st_dev, current.st_ino, current.st_nlink, current.st_size) != (metadata.st_dev, metadata.st_ino, 1, metadata.st_size):
                    raise RuntimeError('event_log_retention_file_changed')
                preserve_backup = True
                try:
                    copy_event_stream(kept, handle)
                except BaseException:
                    copy_event_stream(original, handle)
                    preserve_backup = False
                    raise
                preserve_backup = False
            return {'removed': removed, 'preserved_invalid': invalid}
    finally:
        for stream in (original, kept):
            if stream is not None:
                stream.close()
        if not preserve_backup:
            for name in created:
                os.unlink(name, dir_fd=recovery)
            if created:
                os.fsync(recovery)
        if recovery != parent:
            os.close(recovery)
        os.close(parent)

def prune_configured_event_logs(directory=None, paths=None):
    directory = DATA if directory is None else Path(directory)
    paths = EVENT_LOGS if paths is None else paths
    parent = open_directory(directory)
    try:
        settings = _config_crypto.decode_config(read_at(parent, 'mass-notifications.config', _config_crypto.MAX_FILE_BYTES))
        if not isinstance(settings, dict):
            raise RuntimeError('event_log_retention_config_invalid')
        try:
            os.mkdir('event-log-recovery', 0o700, dir_fd=parent)
            os.fsync(parent)
        except FileExistsError:
            pass
        # No chown/chmod repair through mutable paths: a bad existing directory
        # requires the authenticated installer, never broader /var/log access.
        recovery = os.open('event-log-recovery', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
        try:
            info = os.fstat(recovery)
            if info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
                raise RuntimeError('event_log_retention_recovery_directory_unsafe')
        finally:
            os.close(recovery)
    finally:
        os.close(parent)
    for path in paths:
        try:
            result = prune_event_log(path, settings.get('log_retention_days', 90), recovery_directory=directory / 'event-log-recovery')
        except PermissionError as error:
            raise RuntimeError('Event retention cannot access %s or its private recovery directory %s. '
                               'Use the verified installer to repair these SLS paths; do not grant write access to /var/log.'
                               % (path, directory / 'event-log-recovery')) from error
        if result.get('preserved_invalid'):
            print('Event retention preserved %d record(s) with malformed or unknown timestamps.' % result['preserved_invalid'])

def read_locked_object(path, limit=4 * 1024 * 1024):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as handle:
        fcntl.flock(handle, fcntl.LOCK_SH | fcntl.LOCK_NB)
        metadata = os.fstat(handle.fileno())
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size > limit:
            raise ValueError('Invalid state file type or size')
        raw = handle.read(limit + 1)
        if len(raw) > limit:
            raise ValueError('State file exceeds its size limit')
        value = json.loads(raw)
        if not isinstance(value, dict):
            raise ValueError('Invalid state object')
        return value

def audit_regular(parent, name, create=False):
    """Open without following links or blocking on substituted special files."""
    flags = os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
    created = False
    try:
        descriptor = os.open(name, flags, dir_fd=parent)
    except FileNotFoundError:
        if not create:
            raise
        descriptor = os.open(name, flags | os.O_CREAT | os.O_EXCL, 0o640, dir_fd=parent)
        created = True
    try:
        audit_identity(parent, name, descriptor)
        return descriptor, created
    except BaseException:
        os.close(descriptor)
        raise

def audit_identity(parent, name, descriptor):
    opened = os.fstat(descriptor)
    current = os.stat(name, dir_fd=parent, follow_symlinks=False)
    if (not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1
            or opened.st_uid not in (0, os.geteuid()) or opened.st_mode & 0o002
            or (opened.st_dev, opened.st_ino, opened.st_mode, opened.st_nlink)
            != (current.st_dev, current.st_ino, current.st_mode, current.st_nlink)):
        raise RuntimeError('audit_storage_unsafe')
    return opened

def write_all(descriptor, data):
    offset = 0
    while offset < len(data):
        written = os.write(descriptor, data[offset:])
        if written <= 0:
            raise OSError('Incomplete audit write')
        offset += written

def record_audit_health(directory, error_code='', forwarding=False, security=False):
    """Retain failure counts; a verified write clears only the current fault."""
    parent = lock = temporary_fd = None
    temporary = None
    stem = ('security-audit' if security else 'control-api-audit') + ('-forwarding' if forwarding else '-fault')
    try:
        parent = open_directory(directory)
        # Successful local writes need no extra file when no failure has occurred.
        if not error_code and not forwarding:
            try:
                os.stat(stem + '.json', dir_fd=parent, follow_symlinks=False)
            except FileNotFoundError:
                return True
        lock, _ = audit_regular(parent, stem + '.lock', create=True)
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        audit_identity(parent, stem + '.lock', lock)
        value = {}
        try:
            descriptor, _ = audit_regular(parent, stem + '.json')
            with os.fdopen(descriptor, 'rb') as handle:
                if os.fstat(handle.fileno()).st_size > 4096:
                    raise RuntimeError('audit_fault_state_oversized')
                value = json.loads(handle.read(4097))
                if not isinstance(value, dict):
                    raise RuntimeError('audit_fault_state_invalid')
        except FileNotFoundError:
            pass
        now = int(time.time())
        value = {'failed_records': min(2147483647, max(0, int(value.get('failed_records', 0))) + bool(error_code)),
                 'last_failure_at': now if error_code else max(0, int(value.get('last_failure_at', 0))),
                 'last_success_at': max(0, int(value.get('last_success_at', 0))) if error_code else now,
                 'error_code': error_code or str(value.get('error_code', ''))[:64], 'active': bool(error_code)}
        data = json.dumps(value, separators=(',', ':')).encode() + b'\n'
        temporary = '.' + stem + '-' + secrets.token_hex(16)
        temporary_fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                               0o640, dir_fd=parent)
        write_all(temporary_fd, data)
        os.fsync(temporary_fd)
        os.replace(temporary, stem + '.json', src_dir_fd=parent, dst_dir_fd=parent)
        temporary = None
        os.fsync(parent)
        return True
    except (OSError, ValueError, TypeError, RuntimeError):
        return False
    finally:
        if temporary_fd is not None:
            os.close(temporary_fd)
        if temporary is not None:
            try:
                os.unlink(temporary, dir_fd=parent)
            except OSError:
                pass
        if lock is not None:
            os.close(lock)
        if parent is not None:
            os.close(parent)

def record_audit_failure(directory, error_code, security=False):
    return record_audit_health(directory, error_code, security=security)


OPERATOR_AUDIT_ACTIONS = frozenset([
    'operator_login_unknown_or_disabled', 'operator_login_failed_password', 'operator_login_password_accepted',
    'operator_sign_in_completed', 'operator_authenticator_failed', 'operator_reset_link_issued', 'operator_reset_link_revoked',
    'operator_reset_link_issue_authorized', 'operator_reset_link_revoke_authorized', 'operator_password_reset_authorized',
    'operator_reset_email_reserved', 'operator_reset_email_accepted', 'operator_reset_email_failed',
    'operator_password_reset_failed', 'operator_password_reset_completed', 'operator_reset_confirmation_failed'])
EXPORT_AUDIT_ACTIONS = frozenset(['config_export_plain', 'config_export_encrypted', 'config_export_native'])


def human_audit_record(record):
    action, actor = record.get('action'), record.get('actor')
    return isinstance(actor, str) and ((action in OPERATOR_AUDIT_ACTIONS and actor.startswith('operator:'))
        or (action in EXPORT_AUDIT_ACTIONS and (actor == 'cli' or actor.startswith('web:'))))


def encode_audit(record, security=False):
    required = {'created_at', 'ip', 'method', 'action', 'status', 'ok'}
    if (not isinstance(record, dict) or not required <= set(record)
            or set(record) - required - {'credential_id', 'event_id', 'actor', 'credentials_present'} - ({'operator_id', 'operator_username'} if security else set())
            or not all(isinstance(record[key], str) for key in ('created_at', 'ip', 'method', 'action'))
            or not isinstance(record['ok'], bool) or type(record['status']) is not int
            or not 100 <= record['status'] <= 599
            or len(record['created_at']) > 40 or len(record['ip']) > 64
            or len(record['method']) > 12 or len(record['action']) > 80
            or ('actor' in record and (not isinstance(record['actor'], str)
                or re.fullmatch(r'[\x20-\x7e]{1,160}', record['actor']) is None))
            or ('credential_id' in record and (not isinstance(record['credential_id'], str)
                or re.fullmatch(r'(?:legacy|api_[0-9a-f]{24})', record['credential_id']) is None))
            or ('credentials_present' in record and type(record['credentials_present']) is not bool)
            or ('operator_id' in record and (not isinstance(record['operator_id'], str)
                or re.fullmatch(r'api_[0-9a-f]{24}', record['operator_id']) is None))
            or ('operator_username' in record and (not isinstance(record['operator_username'], str)
                or re.fullmatch(r'[\x20-\x7e]{1,140}', record['operator_username']) is None))
            or ('event_id' in record and (not isinstance(record['event_id'], str)
                or re.fullmatch(r'audit_[0-9a-f]{32}', record['event_id']) is None))):
        raise ValueError('Invalid audit record')
    human = human_audit_record(record)
    no_key = record.get('actor') == 'api:no-key' and record.get('credentials_present') is False
    if (security and not human) or (not security and human):
        raise ValueError('Audit channel does not match its producer')
    if no_key and ('credential_id' in record or 'operator_id' in record or 'operator_username' in record):
        raise ValueError('Unauthenticated API record has an attributed credential')
    data = json.dumps(record, separators=(',', ':'), ensure_ascii=True).encode() + b'\n'
    if len(data) > 2048:
        raise ValueError('Oversized audit record')
    return data


def encode_control_audit(record):
    return encode_audit(record)


def encode_security_audit(record):
    return encode_audit(record, security=True)


def forward_audit(record, directory=DATA, security=False):
    """Bounded local syslog handoff. The OS agent owns remote TLS and queuing."""
    error_code = ''
    try:
        data = (encode_security_audit(record) if security else encode_control_audit(record)).rstrip(b'\n')
        # Local syslog format understood by both journald and rsyslog. Keep the
        # JSON's timezone-aware timestamp and event ID unchanged at collectors.
        stamp = time.localtime()
        month = ('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec')[stamp.tm_mon - 1]
        header = '<174>%s %2d %02d:%02d:%02d sls-mass-notify[%d]: ' % (
            month, stamp.tm_mday, stamp.tm_hour, stamp.tm_min, stamp.tm_sec, os.getpid())
        payload = header.encode('ascii') + data
        with socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM) as transport:
            transport.settimeout(0.05)
            if transport.sendto(payload, SYSLOG_SOCKET) != len(payload):
                raise OSError('Incomplete local syslog handoff')
    except (OSError, ValueError, TypeError):
        error_code = 'audit_syslog_unavailable'
    recorded = record_audit_health(directory, error_code, forwarding=True, security=security)
    return {'ok': not error_code and recorded,
            'error_code': error_code or ('' if recorded else 'audit_syslog_health_unavailable')}


def forward_control_audit(record, directory=DATA):
    return forward_audit(record, directory)


def forward_security_audit(record, directory=DATA):
    return forward_audit(record, directory, security=True)


def reject_duplicate_json(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate JSON member')
        result[key] = value
    return result


def validate_audit_separation(value, complete=True):
    names = {'audit-separation-original.jsonl', 'audit-separation-security-before.jsonl',
             'audit-separation-control-after.jsonl', 'audit-separation-security-after.jsonl'}
    required = {'schema', 'state', 'predicate', 'created_at', 'completed_at', 'moved_records', 'retained_records', 'snapshots'}
    if (not isinstance(value, dict) or set(value) != required or type(value['schema']) is not int or value['schema'] != 1
            or value['state'] not in ('preparing', 'prepared', 'complete') or (complete and value['state'] != 'complete')
            or value['predicate'] != 'positive-human-audit-v1'
            or any(type(value[key]) is not int or not 0 <= value[key] <= 32503680000 for key in ('created_at', 'completed_at'))
            or value['created_at'] <= 0 or (value['state'] == 'complete' and value['completed_at'] < value['created_at'])
            or any(type(value[key]) is not int or not 0 <= value[key] <= 100000 for key in ('moved_records', 'retained_records'))
            or not isinstance(value['snapshots'], dict) or set(value['snapshots']) != names):
        raise RuntimeError('audit_separation_recovery_required')
    for evidence in value['snapshots'].values():
        if (not isinstance(evidence, dict) or set(evidence) != {'sha256', 'bytes'}
                or not isinstance(evidence['sha256'], str) or re.fullmatch('[0-9a-f]{64}', evidence['sha256']) is None
                or type(evidence['bytes']) is not int or not 0 <= evidence['bytes'] <= AUDIT_MAX_BYTES):
            raise RuntimeError('audit_separation_recovery_required')


def audit_separation_ready(parent):
    """A permanent marker makes interrupted two-log migration fail closed."""
    exists = []
    for name in ('.audit-separation-required', 'audit-separation.json'):
        try:
            os.stat(name, dir_fd=parent, follow_symlinks=False)
            exists.append(True)
        except FileNotFoundError:
            exists.append(False)
    if not any(exists):
        return
    if not all(exists):
        raise RuntimeError('audit_separation_recovery_required')
    for name, maximum in (('.audit-separation-required', 128), ('audit-separation.json', 65536)):
        descriptor, _ = audit_regular(parent, name)
        with os.fdopen(descriptor, 'rb') as handle:
            fcntl.flock(handle.fileno(), fcntl.LOCK_SH | fcntl.LOCK_NB)
            if os.fstat(handle.fileno()).st_size > maximum:
                raise RuntimeError('audit_separation_recovery_required')
            raw = handle.read(maximum + 1)
        if name == '.audit-separation-required':
            if raw != b'SLS_AUDIT_SEPARATION_V1\n':
                raise RuntimeError('audit_separation_recovery_required')
        else:
            value = json.loads(raw, object_pairs_hook=reject_duplicate_json)
            validate_audit_separation(value)
            for required in ('control-api-audit.jsonl','security-audit.jsonl'):
                existing,_=audit_regular(parent,required)
                os.close(existing)


def append_audit(record, directory=DATA, security=False):
    """Audit an already-decided HTTP result; failure never retries that action."""
    parent = descriptor = separation_lock = None
    error_code = 'audit_storage_unavailable'
    try:
        data = encode_security_audit(record) if security else encode_control_audit(record)
        parent = open_directory(directory)
        error_code = 'audit_storage_busy'
        separation_lock, _ = audit_regular(parent, '.audit-separation.lock', create=True)
        fcntl.flock(separation_lock, fcntl.LOCK_SH | fcntl.LOCK_NB)
        audit_identity(parent, '.audit-separation.lock', separation_lock)
        error_code = 'audit_separation_recovery_required'
        audit_separation_ready(parent)
        name = 'security-audit.jsonl' if security else 'control-api-audit.jsonl'
        error_code = 'audit_storage_unavailable'
        descriptor, created = audit_regular(parent, name, create=True)
        error_code = 'audit_storage_busy'
        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        error_code = 'audit_storage_unsafe'
        metadata = audit_identity(parent, name, descriptor)
        for suffix in ('backup', 'kept'):
            try:
                os.stat('.sls-retention-' + name + '.' + suffix,
                        dir_fd=parent, follow_symlinks=False)
            except FileNotFoundError:
                continue
            error_code = 'audit_recovery_required'
            raise RuntimeError(error_code)
        error_code = 'audit_storage_at_capacity'
        if metadata.st_size + len(data) > AUDIT_MAX_BYTES:
            raise RuntimeError(error_code)
        # Preserve unfinished evidence. Concatenating a new JSON event would
        # hide the event inside that old tail while claiming a durable append.
        error_code = 'audit_recovery_required'
        if metadata.st_size and os.pread(descriptor, 1, metadata.st_size - 1) != b'\n':
            raise RuntimeError(error_code)
        error_code = 'audit_storage_write_failed'
        os.lseek(descriptor, 0, os.SEEK_END)
        try:
            write_all(descriptor, data)
            os.fsync(descriptor)
            if created:
                os.fsync(parent)
        except OSError:
            # A failed write must not leave a corrupt tail before the next append.
            # If rollback also fails, retain the file and visible fault evidence.
            try:
                os.ftruncate(descriptor, metadata.st_size)
                os.fsync(descriptor)
            except OSError:
                pass
            raise
        if not record_audit_health(directory, security=security):
            return {'ok': False, 'error_code': 'audit_health_unavailable', 'stored': True}
        return {'ok': True}
    except (OSError, ValueError, TypeError, RuntimeError):
        return {'ok': False, 'error_code': error_code,
                'fault_recorded': record_audit_failure(directory, error_code, security=security)}
    finally:
        if descriptor is not None:
            os.close(descriptor)
        if separation_lock is not None:
            os.close(separation_lock)
        if parent is not None:
            os.close(parent)

def append_control_audit(record, directory=DATA):
    return append_audit(record, directory)


def append_security_audit(record, directory=DATA):
    return append_audit(record, directory, security=True)


def append_control_audit_stdin(forwarding=False, security=False):
    try:
        raw = sys.stdin.buffer.read(4097)
        if len(raw) > 4096:
            raise ValueError('Oversized audit input')
        record = json.loads(raw)
        (encode_security_audit(record) if security else encode_control_audit(record))
        result = append_security_audit(record) if security else append_control_audit(record)
        if forwarding:
            forwarded = forward_security_audit(record) if security else forward_control_audit(record)
            result['forwarding'] = forwarded
            if not forwarded['ok']:
                result['ok'] = False
                result.setdefault('error_code', forwarded['error_code'])
    except (ValueError, TypeError, OSError):
        result = {'ok': False, 'error_code': 'audit_record_invalid'}
    print(json.dumps(result, separators=(',', ':')))
    return 0 if result['ok'] else 1

def storage_summary(directory=DATA, now=None):
    now = time.time() if now is None else now
    filesystem = os.statvfs(directory)
    free = filesystem.f_bavail * filesystem.f_frsize
    total = filesystem.f_blocks * filesystem.f_frsize
    summary = {'checked_at': int(now), 'free_bytes': free, 'total_bytes': total,
               'pending_external': 0, 'expired_external': 0, 'recent_expired_external': 0, 'oldest_pending_at': 0, 'queue_errors': 0,
               'queue_scan_incomplete': False, 'queue_files_scanned': 0, 'queue_files_seen': 0}
    deadline = time.monotonic() + DIRECTORY_SCAN_SECONDS
    remaining = MAX_REFERENCE_BYTES
    parent = open_directory(directory)
    try:
        with os.scandir(parent) as entries:
            for index, entry in enumerate(entries):
                if index >= MAX_SCAN or time.monotonic() > deadline:
                    summary['queue_scan_incomplete'] = True
                    break
                name = entry.name
                if not (name.startswith('external-deliveries-') and name.endswith('.json')
                        or name.endswith('-external-deliveries.json')):
                    continue
                summary['queue_files_seen'] += 1
                if summary['queue_files_scanned'] >= MAX_QUEUE_FILES or remaining <= 0:
                    summary['queue_scan_incomplete'] = True
                    break
                summary['queue_files_scanned'] += 1
                try:
                    size = entry.stat(follow_symlinks=False).st_size
                    if size > remaining:
                        summary['queue_scan_incomplete'] = True
                        break
                    remaining -= max(0, size)
                    records = read_locked_object(Path('/proc/self/fd') / str(parent) / name).get('deliveries', {})
                    if not isinstance(records, dict):
                        raise ValueError('Invalid delivery queue')
                    for record in records.values():
                        if not isinstance(record, dict):
                            raise ValueError('Invalid delivery record')
                        if record.get('terminal_status') == 'expired':
                            summary['expired_external'] += 1
                            if float(record.get('completed_at') or 0) >= now - 900:
                                summary['recent_expired_external'] += 1
                        elif not record.get('completed_at') and (record.get('email_pending') or record.get('webhook_pending')):
                            summary['pending_external'] += 1
                            created = int(record.get('created_at') or 0)
                            previous = summary['oldest_pending_at']
                            summary['oldest_pending_at'] = min(previous, created) if previous else created
                except (OSError, ValueError, TypeError):
                    summary['queue_errors'] += 1
                    summary['queue_scan_incomplete'] = True
    finally:
        os.close(parent)
    audit = directory / 'control-api-audit.jsonl'
    summary.update(pending_weather=0, failed_weather=0, uncertain_weather=0, expired_weather=0,
                   recent_failed_weather=0, recent_uncertain_weather=0, recent_expired_weather=0,
                   recent_weather_deadline_misses=0,
                   weather_running=0, weather_max_workers=0, weather_oldest_queued_age_seconds=0,
                   weather_oldest_running_age_seconds=0, weather_deadline_misses=0,
                   weather_dispatch_updated_at=0)
    try:
        weather = read_locked_object(directory / 'weather-delivery.json', limit=16 * 1024 * 1024)
        metrics = weather.get('dispatch_metrics', {})
        if isinstance(metrics, dict):
            summary['weather_max_workers'] = max(0, min(2, int(metrics.get('max_workers', 0))))
            summary['weather_dispatch_updated_at'] = max(0, min(int(now), int(metrics.get('updated_at', 0))))
        for job in weather.get('jobs', {}).values():
            state = job.get('state')
            if state in ('queued', 'running'):
                summary['pending_weather'] += 1
                age_field = 'weather_oldest_' + state + '_age_seconds'
                since = job.get('started_at', job.get('updated_at', now)) if state == 'running' else job.get('created_at', now)
                summary[age_field] = max(summary[age_field], max(0, min(7 * 86400, int(now - float(since)))))
                if state == 'running':
                    summary['weather_running'] += 1
            elif state in ('failed', 'uncertain', 'expired') and float(job.get('updated_at', 0)) > now - 7 * 86400:
                summary[state + '_weather'] += 1
                if float(job.get('updated_at', 0)) >= now - 900:
                    summary['recent_' + state + '_weather'] += 1
            if job.get('deadline_missed') is True and float(job.get('updated_at', 0)) > now - 7 * 86400:
                summary['weather_deadline_misses'] += 1
                if float(job.get('updated_at', 0)) >= now - 900:
                    summary['recent_weather_deadline_misses'] += 1
    except FileNotFoundError:
        pass
    except (OSError, ValueError, TypeError, AttributeError, OverflowError):
        summary['queue_errors'] += 1
    summary['audit_at_capacity'] = audit.is_file() and audit.stat().st_size >= 8 * 1024 * 1024 - 4096
    summary.update(audit_failed_records=0, audit_last_failure_at=0, audit_failure_code='', audit_fault_active=False)
    try:
        fault = read_locked_object(directory / 'control-api-audit-fault.json', limit=4096)
        summary['audit_failed_records'] = max(0, min(2147483647, int(fault.get('failed_records', 0))))
        summary['audit_last_failure_at'] = max(0, int(fault.get('last_failure_at', 0)))
        summary['audit_failure_code'] = re.sub('[^a-z_]', '', str(fault.get('error_code', '')))[:64]
        active = fault.get('active', bool(summary['audit_failed_records'] or summary['audit_failure_code']))
        if type(active) is not bool:
            raise ValueError('Invalid audit health flag')
        summary['audit_fault_active'] = active
    except FileNotFoundError:
        pass
    except (OSError, ValueError, TypeError):
        summary['audit_failure_code'] = 'audit_fault_state_unavailable'
        summary['audit_fault_active'] = True
        summary['queue_errors'] += 1
    summary.update(media_bytes=0, media_cache_limit_bytes=0, media_over_budget=False, media_scan_incomplete=False)
    try:
        settings = _config_crypto.read_config(directory / 'mass-notifications.config')
        summary['media_cache_limit_bytes'] = generated_cache_limit(settings)
        summary['media_bytes'] = sum(row[3].st_size for row in generated_inventory(directory, WEB))
        summary['media_over_budget'] = summary['media_bytes'] > summary['media_cache_limit_bytes']
    except (OSError, ValueError, RuntimeError, TypeError, AttributeError):
        summary['media_scan_incomplete'] = True
    summary.update(audit_forwarding_enabled=False, audit_forwarding_active=False,
                   audit_forwarding_failed_records=0, audit_forwarding_last_success_at=0,
                   audit_forwarding_error='')
    try:
        settings = _config_crypto.read_config(directory / 'mass-notifications.config')
        summary['audit_forwarding_enabled'] = settings.get('control_api', {}).get('audit_syslog', '0') == '1'
    except FileNotFoundError:
        pass
    except (OSError, ValueError, TypeError, AttributeError):
        summary['audit_forwarding_active'] = True
        summary['audit_forwarding_error'] = 'audit_forwarding_config_unavailable'
    if summary['audit_forwarding_enabled']:
        try:
            health = read_locked_object(directory / 'control-api-audit-forwarding.json', limit=4096)
            if type(health.get('active')) is not bool:
                raise ValueError('Invalid forwarding health flag')
            summary['audit_forwarding_active'] = health['active']
            summary['audit_forwarding_failed_records'] = max(0, min(2147483647, int(health.get('failed_records', 0))))
            summary['audit_forwarding_last_success_at'] = max(0, int(health.get('last_success_at', 0)))
            summary['audit_forwarding_error'] = re.sub('[^a-z_]', '', str(health.get('error_code', '')))[:64]
        except FileNotFoundError:
            pass
        except (OSError, ValueError, TypeError):
            summary['audit_forwarding_active'] = True
            summary['audit_forwarding_error'] = 'audit_forwarding_health_unavailable'
    security = directory / 'security-audit.jsonl'
    summary.update(security_audit_at_capacity=security.is_file() and security.stat().st_size >= AUDIT_MAX_BYTES - 4096,
                   security_audit_failed_records=0, security_audit_last_failure_at=0,
                   security_audit_failure_code='', security_audit_fault_active=False,
                   security_audit_forwarding_enabled=summary['audit_forwarding_enabled'],
                   security_audit_forwarding_active=False, security_audit_forwarding_failed_records=0,
                   security_audit_forwarding_last_success_at=0, security_audit_forwarding_error='')
    for forwarding in (False, True):
        if forwarding and not summary['security_audit_forwarding_enabled']:
            continue
        stem = 'security-audit-forwarding' if forwarding else 'security-audit-fault'
        prefix = 'security_audit_forwarding_' if forwarding else 'security_audit_'
        try:
            health = read_locked_object(directory / (stem + '.json'), limit=4096)
            active = health.get('active', bool(health.get('failed_records') or health.get('error_code')))
            if type(active) is not bool:
                raise ValueError('Invalid security audit health flag')
            summary[prefix + ('active' if forwarding else 'fault_active')] = active
            summary[prefix + 'failed_records'] = max(0, min(2147483647, int(health.get('failed_records', 0))))
            summary[prefix + ('last_success_at' if forwarding else 'last_failure_at')] = max(0, int(health.get('last_success_at' if forwarding else 'last_failure_at', 0)))
            summary[prefix + ('error' if forwarding else 'failure_code')] = re.sub('[^a-z_]', '', str(health.get('error_code', '')))[:64]
        except FileNotFoundError:
            pass
        except (OSError, ValueError, TypeError):
            summary[prefix + ('active' if forwarding else 'fault_active')] = True
            summary[prefix + ('error' if forwarding else 'failure_code')] = 'audit_health_unavailable'
    parent = open_directory(directory)
    try:
        audit_separation_ready(parent)
        summary['audit_separation_recovery_required'] = False
    except (OSError, ValueError, TypeError, RuntimeError):
        summary['audit_separation_recovery_required'] = True
    finally:
        os.close(parent)
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
    parent = open_directory(path.parent)
    separation_lock = None
    try:
        separation_lock, _ = audit_regular(parent, '.audit-separation.lock', create=True)
        fcntl.flock(separation_lock, fcntl.LOCK_SH | fcntl.LOCK_NB)
        audit_identity(parent, '.audit-separation.lock', separation_lock)
        audit_separation_ready(parent)
        result = prune_event_log(path, retention_days=30, now=now, maximum_bytes=AUDIT_MAX_BYTES,
                                 maximum_records=10000, discard_invalid=True)
        if result.get('busy'):
            raise BlockingIOError('Audit retention deferred while an appender holds the log')
        return result
    finally:
        if separation_lock is not None:
            os.close(separation_lock)
        os.close(parent)


def main():
    failed = False
    # A full event log must not disable independent generated-media retention.
    for stage, action in (
        ('audit', lambda: prune_audit(DATA / 'control-api-audit.jsonl')),
        ('security_audit', lambda: prune_audit(DATA / 'security-audit.jsonl')),
        ('announcement_jobs', lambda: prune_announcement_jobs(DATA / 'announcement-jobs')),
        ('event_logs', prune_configured_event_logs),
        ('generated_media', prune_generated_media),
        ('summary', lambda: storage_summary(directory=DATA)),
    ):
        try:
            action()
        except (OSError, ValueError, RuntimeError, TypeError, AttributeError, OverflowError) as error:
            detail = str(error) if isinstance(error, RuntimeError) else type(error).__name__
            print('Storage retention %s deferred: %s' % (stage, detail))
            failed = True
    return 1 if failed else 0

if __name__ == '__main__':
    if sys.argv[1:] in (['--append-control-audit'], ['--append-control-audit', '--syslog']):
        raise SystemExit(append_control_audit_stdin('--syslog' in sys.argv[1:]))
    if sys.argv[1:] in (['--append-security-audit'], ['--append-security-audit', '--syslog']):
        raise SystemExit(append_control_audit_stdin('--syslog' in sys.argv[1:], security=True))
    if sys.argv[1:]:
        print('Unsupported storage maintenance option.', file=sys.stderr)
        raise SystemExit(2)
    raise SystemExit(main())
