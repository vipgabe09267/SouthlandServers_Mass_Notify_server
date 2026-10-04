#!/usr/bin/python3
"""Bounded operational recovery evidence. Never activates queues or sends alerts."""
import argparse
import base64
from contextlib import contextmanager
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import sqlite3
import sys
import time
import tempfile

MAX_ARCHIVE = 64 * 1024 * 1024
MAX_FILE = 16 * 1024 * 1024
MAX_FILES = 25000
MAX_ARCHIVES = 5
FORMAT = 'sls-operational-recovery-v1'
ENTERPRISE_FENCES = {
    '.enterprise-state-required': b'SLS_ENTERPRISE_STATE_V1\n',
    '.enterprise-operations-required': b'SLS_ENTERPRISE_OPERATIONS_V1\n',
    '.enterprise-integrations-required': b'SLS_ENTERPRISE_INTEGRATIONS_V1\n',
}
EXACT = {
    'seen_alerts.txt', 'processed_alert_keys.txt', 'audio_delivered_alert_keys.txt', 'event_cooldowns.txt',
    'nws-local-dispatch-intents.json', 'nws-cross-zone-delivery-claims.json', 'nws-external-deliveries.json',
    'xweather-external-deliveries.json', 'xweather-lightning-state.json', 'xweather-quota-state.json',
    'weather-delivery.json', 'phone-admission.json', 'desktop-last-seen.json', 'schedule-executions.json',
    # Audit history and recovery evidence are captured verbatim. Archive restore
    # keeps them private evidence; it never replaces a live audit or health file.
    'control-api-audit.jsonl', 'security-audit.jsonl',
    'control-api-audit-fault.json', 'security-audit-fault.json',
    'control-api-audit-forwarding.json', 'security-audit-forwarding.json',
    '.sls-retention-control-api-audit.jsonl.backup', '.sls-retention-control-api-audit.jsonl.kept',
    '.sls-retention-security-audit.jsonl.backup', '.sls-retention-security-audit.jsonl.kept',
    '.audit-separation-required', 'audit-separation.json',
    'audit-separation-original.jsonl', 'audit-separation-security-before.jsonl',
    'audit-separation-control-after.jsonl', 'audit-separation-security-after.jsonl',
    'sipnotify/sipnotify_events.jsonl',
    'incidents/.archive-required', 'incidents/archive/initialized', 'incidents/archive/reports.sqlite',
    '.incident-replay-blocked.json',
    '.incident-replay-required',
    '.enterprise-state-required',
    '.enterprise-operations-required',
    '.enterprise-integrations-required',
    '.enterprise-cluster-required.json',
    'enterprise-cluster/worker-state.json',
    'enterprise-cluster/cluster-required.json',
    'enterprise-cluster/announcement-send.lock',
    'enterprise-operations/worker-state.json',
    'enterprise-operations/announcement-send.lock',
    'enterprise-integrations/worker-state.json',
    'enterprise-integrations/announcement-send.lock',
}
PATTERNS = [
    re.compile(r'(?:seen-alerts|processed-alerts|audio-delivered|event-cooldowns)-[A-Za-z0-9_-]{1,64}\.txt'),
    re.compile(r'(?:xweather-lightning-state|nws-lightning-gate)-[A-Za-z0-9_-]{1,64}\.json'),
    re.compile(r'announcement-jobs/job_[a-f0-9]{32}\.json'),
    re.compile(r'incidents/inc_[a-f0-9]{32}\.json'),
    re.compile(r'sipnotify/acknowledgements/[a-f0-9]{64}\.json'),
    re.compile(r'enterprise-state/(?:login|replay|directory_plans|subscriber_tokens|identity_audit)\.(?:json|required)'),
    re.compile(r'enterprise-floorplans/img_[a-f0-9]{64}'),
]
DIRECTORIES = ['', 'announcement-jobs', 'incidents', 'incidents/archive', 'sipnotify', 'sipnotify/acknowledgements',
               'enterprise-state', 'enterprise-cluster', 'enterprise-operations', 'enterprise-integrations', 'enterprise-floorplans']


class RecoveryError(RuntimeError):
    pass


def allowed(name):
    return isinstance(name, str) and (name in EXACT or any(pattern.fullmatch(name) for pattern in PATTERNS))


def directory(path):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise RecoveryError('Recovery storage requires an absolute path without parent traversal.')
    descriptor = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        for part in path.parts[1:]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=descriptor)
            os.close(descriptor); descriptor = child
            meta = os.fstat(descriptor)
            if meta.st_uid not in (0, os.geteuid()) or (meta.st_mode & 0o022 and not meta.st_mode & stat.S_ISVTX):
                raise RecoveryError('Recovery storage has an unsafe parent owner or writable directory.')
        result, descriptor = descriptor, -1
        return result
    finally:
        if descriptor >= 0: os.close(descriptor)


def identity(meta):
    return meta.st_dev, meta.st_ino, meta.st_nlink, meta.st_size, meta.st_mtime_ns, meta.st_ctime_ns


def read(path, maximum):
    parent = descriptor = -1
    try:
        parent = directory(Path(path).parent)
        descriptor = os.open(Path(path).name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        meta = os.fstat(descriptor)
        if (not stat.S_ISREG(meta.st_mode) or meta.st_nlink != 1 or meta.st_size > maximum
                or meta.st_uid not in (0, os.geteuid()) or meta.st_mode & 0o022):
            raise RecoveryError('Operational state is linked, special, writable by another account, or oversized: ' + Path(path).name)
        fcntl.flock(descriptor, fcntl.LOCK_SH | fcntl.LOCK_NB)
        with os.fdopen(descriptor, 'rb', closefd=False) as stream:
            body = stream.read(maximum + 1)
        if (len(body) > maximum or identity(meta) != identity(os.fstat(descriptor))
                or identity(meta) != identity(os.stat(Path(path).name, dir_fd=parent, follow_symlinks=False))):
            raise RecoveryError('Operational state changed during backup; retry after current work settles: ' + Path(path).name)
        return body
    finally:
        if descriptor >= 0: os.close(descriptor)
        if parent >= 0: os.close(parent)


def write_new(path, body):
    if len(body) > MAX_ARCHIVE: raise RecoveryError('Operational recovery evidence exceeds 64 MiB. Review retention before backing up; no records were silently omitted.')
    parent = directory(Path(path).parent); descriptor = -1; created = False
    try:
        descriptor = os.open(Path(path).name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=parent)
        created = True
        with os.fdopen(descriptor, 'wb', closefd=False) as stream:
            stream.write(body); stream.flush(); os.fsync(descriptor)
        os.fsync(parent)
    except BaseException:
        if created: os.unlink(Path(path).name, dir_fd=parent)
        raise
    finally:
        if descriptor >= 0: os.close(descriptor)
        os.close(parent)


def inventory(data):
    selected = []; examined = 0; deadline = time.monotonic() + 5
    for folder in DIRECTORIES:
        try: parent = directory(Path(data) / folder)
        except FileNotFoundError: continue
        try:
            with os.scandir(parent) as entries:
                for entry in entries:
                    examined += 1
                    if examined > MAX_FILES or time.monotonic() > deadline:
                        raise RecoveryError('Operational inventory exceeds its file/time bound. Review retention and retry; no partial snapshot was accepted.')
                    relative = folder + '/' + entry.name if folder else entry.name
                    if allowed(relative): selected.append(relative)
        finally: os.close(parent)
    return sorted(selected)


def line(value):
    return (json.dumps(value, separators=(',', ':'), ensure_ascii=True, allow_nan=False) + '\n').encode()


@contextmanager
def incident_archive_capture(data):
    """Hold the permanent locks; SQLite and source retirement cannot race backup."""
    marker = Path(data) / 'incidents/.archive-required'
    folder = Path(data) / 'incidents/archive'
    if not os.path.lexists(marker):
        if os.path.lexists(folder):
            raise RecoveryError('Incident archive initialization is incomplete. Preserve its directory and retry archival before backing up.')
        yield
        return
    handles = []
    try:
        for path in (Path(data) / 'incidents/admission.lock', folder / 'storage.lock'):
            parent = directory(path.parent)
            try:
                handle = os.open(path.name, os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
                handles.append(handle)
                meta = os.fstat(handle)
                if (not stat.S_ISREG(meta.st_mode) or meta.st_nlink != 1 or meta.st_mode & 0o077 or meta.st_uid != os.geteuid()):
                    raise RecoveryError('Incident archive backup lock is unsafe.')
                fcntl.flock(handle, fcntl.LOCK_SH | fcntl.LOCK_NB)
                at = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
                if (at.st_ino, at.st_dev) != (meta.st_ino, meta.st_dev):
                    raise RecoveryError('Incident archive backup lock changed during capture.')
            finally:
                os.close(parent)
        expected = b'SLS_INCIDENT_ARCHIVE_V1\n'
        if read(marker, 24) != expected or read(folder / 'initialized', 24) != expected:
            raise RecoveryError('Incident archive backup markers are damaged.')
        for suffix in ('-journal', '-wal', '-shm'):
            if os.path.lexists(str(folder / 'reports.sqlite') + suffix):
                raise RecoveryError('Incident archive SQLite recovery is pending. Retry the archival worker before backing up.')
        yield
    finally:
        for handle in reversed(handles):os.close(handle)


@contextmanager
def audit_separation_capture(data):
    # Inventory and all audit copies belong to one side of a migration.
    parent = directory(data); lock = -1
    try:
        lock = os.open('.audit-separation.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, 0o640, dir_fd=parent)
        meta = os.fstat(lock)
        if not stat.S_ISREG(meta.st_mode) or meta.st_nlink != 1 or meta.st_mode & 0o022 or meta.st_uid not in (0, os.geteuid()):
            raise RecoveryError('Audit separation capture lock is unsafe.')
        fcntl.flock(lock, fcntl.LOCK_SH | fcntl.LOCK_NB)
        at = os.stat('.audit-separation.lock', dir_fd=parent, follow_symlinks=False)
        if (at.st_ino, at.st_dev) != (meta.st_ino, meta.st_dev):
            raise RecoveryError('Audit separation capture lock changed.')
        if any(os.path.lexists(Path(data)/name) for name in ('.audit-separation-required','audit-separation.json')):
            spec=importlib.util.spec_from_file_location('operational_audit_guard',Path(__file__).resolve().with_name('sls_storage_maintenance.py'))
            guard=importlib.util.module_from_spec(spec);spec.loader.exec_module(guard)
            try: guard.audit_separation_ready(parent)
            except (OSError,ValueError,TypeError,RuntimeError) as error: raise RecoveryError('Audit separation requires recovery before backup capture.') from error
        with incident_archive_capture(data):
            yield
    finally:
        if lock >= 0: os.close(lock)
        os.close(parent)


def snapshot(data, output):
    with audit_separation_capture(data):
        return captured_snapshot(data, output)


def captured_snapshot(data, output):
    start = int(time.time()); names = inventory(data)
    body = bytearray(line({'format': FORMAT, 'started_at': start, 'policy': 'evidence_only_no_replay'}))
    total = 0
    for name in names:
        raw = read(Path(data) / name, MAX_FILE)
        row = line({'path': name, 'captured_at': int(time.time()), 'bytes': len(raw),
                    'sha256': hashlib.sha256(raw).hexdigest(), 'base64': base64.b64encode(raw).decode('ascii')})
        if len(body) + len(row) + 256 > MAX_ARCHIVE:
            raise RecoveryError('Operational recovery evidence exceeds 64 MiB. Review retention before backing up; no records were silently omitted.')
        body.extend(row); total += len(raw)
    body.extend(line({'complete': True, 'files': len(names), 'source_bytes': total, 'finished_at': int(time.time())}))
    write_new(output, body)
    return {'ok': True, 'files': len(names), 'source_bytes': total, 'policy': 'evidence_only_no_replay'}


def inspect(body):
    if not body or len(body) > MAX_ARCHIVE or not body.endswith(b'\n'):
        raise RecoveryError('Operational evidence is truncated or exceeds 64 MiB.')
    rows = body.splitlines()
    if not 2 <= len(rows) <= MAX_FILES + 2:
        raise RecoveryError('Operational evidence has an invalid record count.')
    try:
        header, trailer = json.loads(rows[0]), json.loads(rows[-1])
        if (not isinstance(header, dict) or set(header) != {'format', 'started_at', 'policy'}
                or header['format'] != FORMAT or header['policy'] != 'evidence_only_no_replay'
                or type(header['started_at']) is not int or not 0 < header['started_at'] < 32503680000
                or not isinstance(trailer, dict) or set(trailer) != {'complete', 'files', 'source_bytes', 'finished_at'}
                or trailer['complete'] is not True or type(trailer['files']) is not int
                or trailer['files'] != len(rows) - 2 or type(trailer['source_bytes']) is not int
                or type(trailer['finished_at']) is not int or not header['started_at'] <= trailer['finished_at'] < 32503680000):
            raise ValueError('invalid envelope')
        seen = set(); total = 0
        for raw in rows[1:-1]:
            row = json.loads(raw)
            if (not isinstance(row, dict) or set(row) != {'path', 'captured_at', 'bytes', 'sha256', 'base64'}
                    or not allowed(row['path']) or row['path'] in seen or type(row['bytes']) is not int
                    or not 0 <= row['bytes'] <= MAX_FILE or type(row['captured_at']) is not int
                    or not header['started_at'] <= row['captured_at'] <= trailer['finished_at']
                    or not isinstance(row['base64'], str) or not isinstance(row['sha256'], str)):
                raise ValueError('invalid record')
            decoded = base64.b64decode(row['base64'], validate=True)
            if (len(decoded) != row['bytes'] or base64.b64encode(decoded).decode('ascii') != row['base64']
                    or hashlib.sha256(decoded).hexdigest() != row['sha256']):
                raise ValueError('changed record')
            if row['path'].startswith('enterprise-floorplans/'):
                image = Path(row['path']).name
                if (len(decoded) > 5 * 1024 * 1024 or len(decoded) < 8
                        or image != 'img_' + row['sha256']
                        or not (decoded.startswith(b'\x89PNG\r\n\x1a\n') or decoded.startswith(b'\xff\xd8\xff'))):
                    raise ValueError('invalid private image')
            if row['path'] in ENTERPRISE_FENCES and decoded != ENTERPRISE_FENCES[row['path']]:
                raise ValueError('invalid enterprise replay fence')
            if row['path'] == '.enterprise-cluster-required.json':
                marker = json.loads(decoded)
                if (not isinstance(marker, dict) or set(marker) != {'schema', 'cluster_id', 'node_id', 'witness_epoch'}
                        or type(marker['schema']) is not int or marker['schema'] != 1
                        or any(not isinstance(marker[key], str) or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}', marker[key]) for key in ('cluster_id', 'node_id'))
                        or not isinstance(marker['witness_epoch'], str) or not re.fullmatch(r'[a-f0-9]{64}', marker['witness_epoch'])):
                    raise ValueError('invalid cluster replay fence')
            seen.add(row['path']); total += len(decoded)
        if total != trailer['source_bytes']: raise ValueError('invalid total')
    except (ValueError, TypeError, KeyError, OverflowError) as error:
        raise RecoveryError('Operational evidence failed its format, path, completeness or content checks; no operational files were restored.') from error
    return {'ok': True, 'files': len(seen), 'source_bytes': total, 'started_at': header['started_at'],
            'finished_at': trailer['finished_at'], 'policy': 'evidence_only_no_replay'}


def verified(source, expected=None):
    body = read(source, MAX_ARCHIVE)
    if expected is not None and (not re.fullmatch(r'[a-f0-9]{64}', expected) or hashlib.sha256(body).hexdigest() != expected):
        raise RecoveryError('Operational evidence changed after manifest verification; preserve the original backup and retry.')
    return body, inspect(body)


def inspect_replay_guard(raw):
    try:
        guard = json.loads(raw)
        if (not isinstance(guard, dict) or set(guard) != {'schema', 'ids'} or guard['schema'] != 1
                or not isinstance(guard['ids'], list) or len(guard['ids']) > 100000
                or any(not isinstance(value, str) or not re.fullmatch(r'inc_[a-f0-9]{32}', value) for value in guard['ids'])
                or len(set(guard['ids'])) != len(guard['ids'])):
            raise ValueError()
        return set(guard['ids'])
    except (ValueError, TypeError, KeyError) as error:
        raise RecoveryError('The restored incident replay guard is damaged. Preserve it before accepting new incident requests.') from error


def incident_replay_ids(body):
    """Restore identities only, without activating any historical workflow."""
    ids = set()
    for line_body in body.splitlines()[1:-1]:
        row = json.loads(line_body); name = row['path']
        match = re.fullmatch(r'incidents/(inc_[a-f0-9]{32})\.json', name)
        if match:
            ids.add(match[1])
        elif name == '.incident-replay-blocked.json':
            ids.update(inspect_replay_guard(base64.b64decode(row['base64'], validate=True)))
        elif name == 'incidents/archive/reports.sqlite':
            # Only this bounded, private backup is opened, read-only. No live
            # database, queues or desktop journal is opened or imported.
            with tempfile.TemporaryDirectory(prefix='sls-incident-replay-') as temporary:
                path = Path(temporary) / 'archive.sqlite'; path.write_bytes(base64.b64decode(row['base64'], validate=True)); path.chmod(0o600)
                db = sqlite3.connect(path.as_uri() + '?mode=ro', uri=True, timeout=.25)
                try:
                    deadline = time.monotonic() + 2
                    db.set_progress_handler(lambda: int(time.monotonic() > deadline), 1000)
                    db.execute('PRAGMA trusted_schema=OFF')
                    if db.execute('PRAGMA user_version').fetchone()[0] != 1:
                        raise RecoveryError('The archived incident replay identities use an unsupported schema.')
                    rows = db.execute('SELECT id FROM reports LIMIT 20001').fetchall()
                    if len(rows) > 20000 or any(not isinstance(r[0], str) or not re.fullmatch(r'inc_[a-f0-9]{32}', r[0]) for r in rows):
                        raise RecoveryError('The archived incident replay identities are invalid or exceed their bound.')
                    ids.update(r[0] for r in rows)
                except sqlite3.Error as error:
                    raise RecoveryError('Archived incident replay identities could not be read. Preserve the backup before retrying restore.') from error
                finally: db.close()
    return ids


def retain_replay_guard(data, incoming):
    # Same permanent admission lock as IncidentStore.transaction(create=True).
    # A restore cannot race a new start past the newly merged replay identities.
    parent = directory(data); child = handle = -1
    try:
        try: os.mkdir('incidents', 0o750, dir_fd=parent); os.fsync(parent)
        except FileExistsError: pass
        child = directory(Path(data) / 'incidents')
        handle = os.open('admission.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, 0o600, dir_fd=child)
        meta = os.fstat(handle)
        if not stat.S_ISREG(meta.st_mode) or meta.st_nlink != 1 or meta.st_mode & 0o077 or meta.st_uid != os.geteuid():
            raise RecoveryError('Restored incident admission lock is unsafe.')
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        at = os.stat('admission.lock', dir_fd=child, follow_symlinks=False)
        if (meta.st_dev, meta.st_ino) != (at.st_dev, at.st_ino):
            raise RecoveryError('Restored incident admission lock changed during access.')
        merge_replay_guard(data, incoming)
    finally:
        if handle >= 0: os.close(handle)
        if child >= 0: os.close(child)
        os.close(parent)


def merge_replay_guard(data, incoming):
    destination = Path(data) / '.incident-replay-blocked.json'
    try: existing = inspect_replay_guard(read(destination, 4 * 1024 * 1024)); present = True
    except FileNotFoundError: existing = set(); present = False
    merged = existing | incoming
    if len(merged) > 100000:
        raise RecoveryError('The restored incident replay guard reached 100,000 identities. Plan an offline migration; no identities were discarded.')
    marker = Path(data) / '.incident-replay-required'
    expected = b'SLS_RESTORED_INCIDENT_REPLAY_V1\n'
    try:
        if read(marker, len(expected)) != expected:
            raise RecoveryError('The restored incident replay marker is damaged. Preserve it before retrying restore.')
    except FileNotFoundError:
        write_new(marker, expected)
    if merged == existing and present: return
    raw = line({'schema': 1, 'ids': sorted(merged)})
    parent = directory(data); temporary = '.incident-replay-' + os.urandom(16).hex()
    try:
        write_new(Path(data) / temporary, raw)
        os.replace(temporary, destination.name, src_dir_fd=parent, dst_dir_fd=parent); os.fsync(parent)
    finally:
        try: os.unlink(temporary, dir_fd=parent)
        except FileNotFoundError: pass
        os.close(parent)


def archive(source, data, expected=None):
    body, report = verified(source, expected)
    replay_ids = incident_replay_ids(body)
    parent = directory(data)
    try:
        try: os.mkdir('recovery-archives', 0o700, dir_fd=parent); os.fsync(parent)
        except FileExistsError: pass
        recovery = os.open('recovery-archives', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
    finally: os.close(parent)
    lock = -1
    try:
        meta = os.fstat(recovery)
        if meta.st_uid != os.geteuid() or stat.S_IMODE(meta.st_mode) != 0o700:
            raise RecoveryError('Operational recovery directory must be private and owned by the runtime account.')
        lock = os.open('.archive.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, 0o600, dir_fd=recovery)
        meta = os.fstat(lock)
        if not stat.S_ISREG(meta.st_mode) or meta.st_nlink != 1 or meta.st_uid != os.geteuid() or stat.S_IMODE(meta.st_mode) != 0o600:
            raise RecoveryError('Operational recovery lock is unsafe.')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        current = os.stat('.archive.lock', dir_fd=recovery, follow_symlinks=False)
        if (meta.st_dev, meta.st_ino) != (current.st_dev, current.st_ino): raise RecoveryError('Operational recovery lock changed.')
        name = hashlib.sha256(body).hexdigest() + '.jsonl'
        destination = Path(data) / 'recovery-archives' / name
        try:
            prior = read(destination, MAX_ARCHIVE)
            if prior != body: raise RecoveryError('Existing operational recovery evidence differs; preserve it before retrying.')
        except FileNotFoundError:
            count = 0; size = 0
            with os.scandir(recovery) as entries:
                for entry in entries:
                    if entry.name == '.archive.lock': continue
                    info = entry.stat(follow_symlinks=False)
                    if not re.fullmatch(r'[a-f0-9]{64}\.jsonl', entry.name) or not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
                        raise RecoveryError('Unexpected files are present in operational recovery storage; preserve and review them.')
                    count += 1; size += info.st_size
                    if count >= MAX_ARCHIVES or size + len(body) > 256 * 1024 * 1024:
                        raise RecoveryError('Operational recovery storage is full. Archive older evidence to protected storage before retrying; nothing was deleted.')
            write_new(destination, body)
        retain_replay_guard(data, replay_ids)
        report['enterprise_fences_retained'] = retain_enterprise_fences(body, data)
        report['private_images_restored'] = restore_private_images(body, data)
        report['archive_name'] = name
        return report
    finally:
        if lock >= 0: os.close(lock)
        os.close(recovery)


def retain_enterprise_fences(body, data):
    """Retain passive loss guards, never operational authority or bearer state."""
    names = set(ENTERPRISE_FENCES) | {'.enterprise-cluster-required.json'}
    rows = [json.loads(raw) for raw in body.splitlines()[1:-1]]
    fences = [row for row in rows if row['path'] in names]
    directory_fd = directory(data)
    try:
        for row in fences:
            path = Path(data) / row['path']; raw = base64.b64decode(row['base64'], validate=True)
            try:
                if read(path, 1024) != raw:
                    raise RecoveryError('A restored enterprise replay fence conflicts with current evidence. Preserve both for reviewed recovery.')
            except FileNotFoundError:
                write_new(path, raw)
        return len(fences)
    finally:
        os.close(directory_fd)


def restore_private_images(body, data):
    """Restore immutable passive images; never restore sessions, leases or jobs."""
    rows = [json.loads(raw) for raw in body.splitlines()[1:-1]]
    images = [row for row in rows if row['path'].startswith('enterprise-floorplans/')]
    if not images:
        return 0
    if len(images) > 100 or sum(row['bytes'] for row in images) > 32 * 1024 * 1024:
        raise RecoveryError('Private floor plans exceed their bounded restore allocation.')
    parent = directory(data)
    try:
        try: os.mkdir('enterprise-floorplans', 0o700, dir_fd=parent); os.fsync(parent)
        except FileExistsError: pass
        child = os.open('enterprise-floorplans', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
    finally: os.close(parent)
    try:
        meta = os.fstat(child)
        if meta.st_uid != os.geteuid() or stat.S_IMODE(meta.st_mode) != 0o700:
            raise RecoveryError('Private image restore directory has unsafe ownership or permissions.')
        lock = os.open('.storage.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, 0o600, dir_fd=child)
        locked = os.fstat(lock)
        if not stat.S_ISREG(locked.st_mode) or locked.st_nlink != 1 or locked.st_uid != os.geteuid() or stat.S_IMODE(locked.st_mode) != 0o600 or locked.st_size != 0:
            os.close(lock); lock = None
            raise RecoveryError('Private image restore lock is unsafe.')
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BaseException:
            os.close(lock); lock = None
            raise
        count = 0; size = 0
        with os.scandir(child) as entries:
            for entry in entries:
                item = entry.stat(follow_symlinks=False)
                if entry.name == '.storage.lock':
                    if (item.st_dev, item.st_ino) != (locked.st_dev, locked.st_ino):
                        raise RecoveryError('Private image restore lock changed.')
                    continue
                if not re.fullmatch(r'img_[a-f0-9]{64}', entry.name) or not stat.S_ISREG(item.st_mode) or item.st_nlink != 1:
                    raise RecoveryError('Private image restore found unexpected or linked files.')
                count += 1; size += item.st_size
        for row in images:
            name = Path(row['path']).name; target = Path(data) / 'enterprise-floorplans' / name
            raw = base64.b64decode(row['base64'], validate=True)
            try:
                if read(target, 5 * 1024 * 1024) != raw:
                    raise RecoveryError('A private image conflicts with restored content. Preserve it for recovery.')
            except FileNotFoundError:
                if count >= 100 or size + len(raw) > 32 * 1024 * 1024:
                    raise RecoveryError('Existing plus restored private images exceed their allocation. Nothing was deleted.')
                write_new(target, raw); count += 1; size += len(raw)
        return len(images)
    finally:
        if 'lock' in locals() and lock is not None:
            os.close(lock)
        os.close(child)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['snapshot', 'verify', 'archive'])
    parser.add_argument('--data', type=Path, default=Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin'))
    parser.add_argument('--file', required=True, type=Path)
    parser.add_argument('--sha256')
    args = parser.parse_args()
    try:
        if args.action == 'snapshot': result = snapshot(args.data, args.file)
        elif args.action == 'verify': result = verified(args.file, args.sha256)[1]
        else: result = archive(args.file, args.data, args.sha256)
    except (OSError, RecoveryError) as error:
        # Paths/content from operational records never enter an error response.
        detail = str(error) if isinstance(error, RecoveryError) else 'Protected operational evidence is busy or inaccessible. Check file ownership, available space and recovery storage; preserve existing data and retry.'
        print(json.dumps({'ok': False, 'message': detail})); return 1
    print(json.dumps(result)); return 0


if __name__ == '__main__':
    raise SystemExit(main())
