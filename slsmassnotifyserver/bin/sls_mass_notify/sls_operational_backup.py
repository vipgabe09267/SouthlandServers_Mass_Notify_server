#!/usr/bin/python3
"""Bounded operational recovery evidence. Never activates queues or sends alerts."""
import argparse
import base64
from contextlib import contextmanager
import fcntl
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import re
import stat
import struct
import zlib
import sqlite3
import sys
import time
import tempfile

MAX_ARCHIVE = 128 * 1024 * 1024
MAX_FILE = 16 * 1024 * 1024
MAX_LARGE_FILE = 64 * 1024 * 1024
MAX_SOURCE_BYTES = 256 * 1024 * 1024
MAX_FILES = 25000
MAX_ARCHIVES = 5
FORMAT = 'sls-operational-recovery-v2'
LEGACY_FORMAT = 'sls-operational-recovery-v1'
ENTERPRISE_FENCES = {
    '.automations-required': b'SLS_AUTOMATION_STATE_V1\n',
    '.enterprise-state-required': b'SLS_ENTERPRISE_STATE_V1\n',
    '.control-idempotency-required': b'SLS_ENTERPRISE_STATE_V1\n',
    '.enterprise-operations-required': b'SLS_ENTERPRISE_OPERATIONS_V1\n',
    '.enterprise-integrations-required': b'SLS_ENTERPRISE_INTEGRATIONS_V1\n',
}
EXACT = {
    '.automations-required', 'automations/worker-state.json', 'automations/announcement-send.lock',
    'operator-login-failures.json',
    'system-notification-email-state.json', 'system-notification-email-state.lock',
    'mail-admission.json', 'mail-admission.lock', '.mail-admission-initializing',
    'mail-evidence.json', 'mail-evidence.lock',
    'seen_alerts.txt', 'processed_alert_keys.txt', 'audio_delivered_alert_keys.txt', 'event_cooldowns.txt',
    'seen_alerts.txt.lock', 'processed_alert_keys.txt.lock', 'audio_delivered_alert_keys.txt.lock',
    'nws-local-dispatch-intents.json', 'nws-cross-zone-delivery-claims.json', 'nws-cross-zone-delivery-claims.json.lock',
    'nws-external-deliveries.json',
    'nws-external-deliveries.json.lock', 'xweather-external-deliveries.json.lock',
    'xweather-external-deliveries.json', 'xweather-lightning-state.json', 'xweather-quota-state.json',
    'weather-delivery.json', 'weather-delivery.lock', 'phone-admission.json', 'phone-admission.lock', '.phone-admission-required',
    'desktop-last-seen.json', 'schedule-executions.json',
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
    '.control-idempotency-required',
    'enterprise-integrations/.archive-required', 'enterprise-operations/.archive-required',
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
    re.compile(r'sipnotify/history/[a-f0-9]{64}\.json'),
    re.compile(r'(?:seen-alerts|processed-alerts|audio-delivered|event-cooldowns)-[A-Za-z0-9_-]{1,64}\.txt(?:\.lock)?'),
    re.compile(r'(?:xweather-lightning-state|nws-lightning-gate)-[A-Za-z0-9_-]{1,64}\.json'),
    re.compile(r'announcement-jobs/job_[a-f0-9]{32}\.json'),
    re.compile(r'incidents/inc_[a-f0-9]{32}\.json'),
    re.compile(r'sipnotify/acknowledgements/[a-f0-9]{64}\.json'),
    re.compile(r'enterprise-state/(?:login|replay|directory_plans|subscriber_tokens|identity_audit|browser_subscriptions|browser_push_claims|browser_events_[a-f0-9]|browser_test_events|connector_claims|oauth_refresh)\.(?:json|required)'),
    re.compile(r'enterprise-floorplans/img_[a-f0-9]{64}'),
    re.compile(r'control-idempotency/announcement_claims\.(?:json|required)'),
    re.compile(r'enterprise-operations/archive/(?:approvals|drills|floorplan_retirements)\.(?:json|required)'),
    re.compile(r'enterprise-integrations/archive/(?:operations|responses)(?:_[a-f0-9]{2})?\.(?:json|required)'),
    re.compile(r'enterprise-integrations/archive/(?:operation|response)_tombstones_[a-f0-9]{2}\.(?:json|required)'),
]
DIRECTORIES = ['', 'automations', 'announcement-jobs', 'incidents', 'incidents/archive', 'sipnotify', 'sipnotify/acknowledgements', 'sipnotify/history',
               'enterprise-state', 'control-idempotency', 'enterprise-integrations/archive', 'enterprise-operations/archive', 'enterprise-cluster', 'enterprise-operations', 'enterprise-integrations', 'enterprise-floorplans']


class RecoveryError(RuntimeError):
    pass

def private_image_info(body):
    """Validate the complete bounded PNG/JPEG container before restore."""
    if not isinstance(body, bytes) or not 8 <= len(body) <= 5 * 1024 * 1024:
        raise ValueError('invalid private image size')
    width = height = image_type = None
    if body.startswith(b'\x89PNG\r\n\x1a\n'):
        position = 8; seen_header = seen_data = seen_end = False; compressed = bytearray()
        bit_depth = color_type = interlace = None
        while position < len(body):
            if position + 12 > len(body):
                raise ValueError('truncated PNG chunk')
            size = struct.unpack('>I', body[position:position + 4])[0]
            kind = body[position + 4:position + 8]
            end = position + 12 + size
            if size > 5 * 1024 * 1024 or end > len(body) or not re.fullmatch(rb'[A-Za-z]{4}', kind):
                raise ValueError('invalid PNG chunk')
            payload = body[position + 8:position + 8 + size]
            expected = struct.unpack('>I', body[position + 8 + size:end])[0]
            if zlib.crc32(kind + payload) & 0xffffffff != expected:
                raise ValueError('invalid PNG CRC')
            if not seen_header:
                if kind != b'IHDR' or size != 13:
                    raise ValueError('invalid PNG header')
                width, height, bit_depth, color_type, compression, filtering, interlace = struct.unpack('>IIBBBBB', payload)
                if (compression != 0 or filtering != 0 or interlace not in (0, 1)
                        or (color_type, bit_depth) not in {(0,1),(0,2),(0,4),(0,8),(0,16),(2,8),(2,16),
                                                          (3,1),(3,2),(3,4),(3,8),(4,8),(4,16),(6,8),(6,16)}):
                    raise ValueError('unsupported PNG header')
                seen_header = True
            elif kind == b'IHDR':
                raise ValueError('duplicate PNG header')
            if kind == b'IDAT':
                seen_data = True; compressed.extend(payload)
            if kind == b'IEND':
                if size != 0 or not seen_data or end != len(body):
                    raise ValueError('invalid PNG ending')
                seen_end = True
            position = end
            if seen_end:
                break
        if not seen_header or not seen_data or not seen_end or position != len(body):
            raise ValueError('incomplete PNG')
        if not (1 <= width <= 4096 and 1 <= height <= 4096 and width * height <= 8 * 1024 * 1024):
            raise ValueError('private image dimensions exceed policy')
        channels = {0:1, 2:3, 3:1, 4:2, 6:4}[color_type]
        if interlace == 0:
            passes = ((0, 0, 1, 1),)
        else:
            passes = ((0,0,8,8),(4,0,8,8),(0,4,4,8),(2,0,4,4),(0,2,2,4),(1,0,2,2),(0,1,1,2))
        decoded_size = 0
        for start_x, start_y, step_x, step_y in passes:
            pass_width = 0 if width <= start_x else (width - start_x + step_x - 1) // step_x
            pass_height = 0 if height <= start_y else (height - start_y + step_y - 1) // step_y
            if pass_width and pass_height:
                decoded_size += (((pass_width * channels * bit_depth + 7) // 8) + 1) * pass_height
        inflater = zlib.decompressobj()
        decoded = inflater.decompress(bytes(compressed), decoded_size + 1)
        if (len(decoded) != decoded_size or not inflater.eof or inflater.unused_data
                or inflater.unconsumed_tail):
            raise ValueError('invalid or oversized PNG data stream')
        image_type = 'png'
    elif body.startswith(b'\xff\xd8\xff') and body.endswith(b'\xff\xd9'):
        position = 2; seen_scan = seen_end = False
        sof = {0xc0,0xc1,0xc2,0xc3,0xc5,0xc6,0xc7,0xc9,0xca,0xcb,0xcd,0xce,0xcf}
        while position < len(body):
            if body[position] != 0xff:
                if not seen_scan:
                    raise ValueError('invalid JPEG marker')
                position += 1; continue
            while position < len(body) and body[position] == 0xff:
                position += 1
            if position >= len(body):
                raise ValueError('truncated JPEG marker')
            marker_value = body[position]; position += 1
            if marker_value == 0x00:
                if not seen_scan: raise ValueError('unexpected JPEG stuffing')
                continue
            if marker_value == 0xd9:
                if position != len(body): raise ValueError('JPEG trailing data')
                seen_end = True; break
            if marker_value in range(0xd0, 0xd8) or marker_value == 0x01:
                if not seen_scan and marker_value in range(0xd0, 0xd8): raise ValueError('unexpected JPEG restart')
                continue
            if marker_value == 0xd8:
                raise ValueError('duplicate JPEG start')
            if position + 2 > len(body):
                raise ValueError('truncated JPEG segment')
            size = struct.unpack('>H', body[position:position + 2])[0]
            if size < 2 or position + size > len(body):
                raise ValueError('invalid JPEG segment')
            payload = body[position + 2:position + size]
            if marker_value in sof:
                if width is not None or len(payload) < 6:
                    raise ValueError('invalid JPEG frame')
                height, width = struct.unpack('>HH', payload[1:5])
                components = payload[5]
                if components not in range(1, 5) or len(payload) != 6 + 3 * components:
                    raise ValueError('invalid JPEG frame components')
            if marker_value == 0xda:
                if width is None or len(payload) < 6:
                    raise ValueError('JPEG scan precedes frame')
                components = payload[0]
                if components not in range(1, 5) or len(payload) != 4 + 2 * components:
                    raise ValueError('invalid JPEG scan components')
                seen_scan = True
            position += size
        if width is None or height is None or not seen_scan or not seen_end:
            raise ValueError('incomplete JPEG')
        image_type = 'jpeg'
    else:
        raise ValueError('unsupported private image')
    if not (1 <= width <= 4096 and 1 <= height <= 4096 and width * height <= 8 * 1024 * 1024):
        raise ValueError('private image dimensions exceed policy')
    return {'type': image_type, 'width': width, 'height': height}


def strict_json(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            if key in result:
                raise ValueError('Duplicate recovery JSON field')
            result[key] = value
        return result
    def constant(value):
        raise ValueError('Nonfinite recovery JSON value')
    def number(value):
        parsed = float(value)
        if not math.isfinite(parsed):
            raise ValueError('Nonfinite recovery JSON number')
        return parsed
    try:
        return json.loads(raw, object_pairs_hook=pairs, parse_constant=constant, parse_float=number)
    except RecursionError as error:
        raise ValueError('Recovery JSON nesting exceeds its bound') from error


def compact_enterprise_payload(name, raw, now):
    """Omit only conclusively expired transient rows from private recovery evidence."""
    policies = {
        'enterprise-state/login.json': 'expires_at',
        'enterprise-state/replay.json': 'scalar_expiry',
        'enterprise-state/directory_plans.json': 'expires_at',
        'enterprise-state/subscriber_tokens.json': 'subscriber_proof',
        'enterprise-state/browser_push_claims.json': 'expires_at',
        'enterprise-state/browser_test_events.json': 'expires_at',
        'enterprise-state/connector_claims.json': 'expires_at',
        'control-idempotency/announcement_claims.json': 'expires_at',
    }
    policy = policies.get(name)
    if re.fullmatch(r'enterprise-state/browser_events_[a-f0-9]\.json', name):
        policy = 'expires_at'
    if policy is None:
        return raw
    try: rows = strict_json(raw)
    except (ValueError, TypeError, json.JSONDecodeError) as error:
        raise RecoveryError('Transient enterprise state is invalid; preserve the source and repair it before backup.') from error
    if not isinstance(rows, dict):
        raise RecoveryError('Transient enterprise state has an invalid root; preserve it before backup.')
    retained = {}
    for key, row in rows.items():
        expired = False
        if policy == 'scalar_expiry': expired = type(row) is int and row <= now
        elif isinstance(row, dict) and policy == 'subscriber_proof':
            created, expires = row.get('created_at'), row.get('expires_at')
            expired = type(created) is int and type(expires) is int and max(expires, created + 3600) <= now
        elif isinstance(row, dict):
            expires = row.get('expires_at'); expired = type(expires) is int and expires <= now
        if not expired: retained[key] = row
    return json.dumps(retained, separators=(',', ':'), ensure_ascii=True, allow_nan=False).encode()


def allowed(name):
    return isinstance(name, str) and (name in EXACT or any(pattern.fullmatch(name) for pattern in PATTERNS))


def source_bound(name):
    return MAX_LARGE_FILE if name in ('sipnotify/sipnotify_events.jsonl', 'control-api-audit.jsonl', 'security-audit.jsonl') else MAX_FILE


def record_bytes(row):
    """Decode one bounded evidence record, preserving v1 archive compatibility."""
    base = {'path', 'captured_at', 'bytes', 'sha256', 'base64'}
    if not isinstance(row, dict) or set(row) not in (base, base | {'codec', 'stored_bytes'}):
        raise ValueError('invalid record fields')
    if (not allowed(row['path']) or type(row['bytes']) is not int or not 0 <= row['bytes'] <= source_bound(row['path'])
            or not isinstance(row['base64'], str) or not isinstance(row['sha256'], str)
            or re.fullmatch(r'[a-f0-9]{64}', row['sha256']) is None):
        raise ValueError('invalid record bounds')
    stored = row.get('stored_bytes', row['bytes'])
    if type(stored) is not int or not 0 <= stored <= source_bound(row['path']) or len(row['base64']) != 4 * ((stored + 2) // 3):
        raise ValueError('invalid encoded size')
    encoded = base64.b64decode(row['base64'], validate=True)
    if len(encoded) != stored or base64.b64encode(encoded).decode('ascii') != row['base64']:
        raise ValueError('invalid base64')
    codec = row.get('codec', 'raw')
    if codec == 'zlib':
        decoder = zlib.decompressobj()
        decoded = decoder.decompress(encoded, row['bytes'] + 1)
        if not decoder.eof or decoder.unused_data or decoder.unconsumed_tail:
            raise ValueError('invalid compressed evidence')
    elif codec == 'raw':
        decoded = encoded
    else:
        raise ValueError('unsupported evidence codec')
    if len(decoded) != row['bytes'] or hashlib.sha256(decoded).hexdigest() != row['sha256']:
        raise ValueError('changed record')
    return decoded


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
    if len(body) > MAX_ARCHIVE: raise RecoveryError('Operational recovery evidence exceeds 128 MiB. Review retention before backing up; no records were silently omitted.')
    parent = directory(Path(path).parent); descriptor = -1; created = False
    try:
        descriptor = os.open(Path(path).name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=parent)
        created = True
        with os.fdopen(descriptor, 'wb', closefd=False) as stream:
            stream.write(body); stream.flush(); os.fsync(descriptor)
        os.fsync(parent)
    except BaseException:
        if created:
            opened = os.fstat(descriptor)
            try:
                named = os.stat(Path(path).name, dir_fd=parent, follow_symlinks=False)
            except FileNotFoundError:
                named = None
            if named is not None and (opened.st_dev, opened.st_ino) == (named.st_dev, named.st_ino):
                try:
                    os.unlink(Path(path).name, dir_fd=parent)
                except FileNotFoundError:
                    pass
                else:
                    os.fsync(parent)
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
                    if (entry.name == 'initialization.pending' or entry.name.endswith('.initializing')
                            or entry.name in ('.mail-admission-initializing', '.incident-replay-initializing.json')):
                        raise RecoveryError('A journal has unfinished first initialization. Complete its checked recovery before backup; initialization evidence was preserved.')
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


def snapshot_plan(data, names):
    total = 0
    estimated = 1024
    for name in names:
        parent = directory((Path(data) / name).parent)
        try:
            meta = os.stat(Path(name).name, dir_fd=parent, follow_symlinks=False)
            if (not stat.S_ISREG(meta.st_mode) or meta.st_nlink != 1 or meta.st_size > source_bound(name)
                    or meta.st_uid not in (0, os.geteuid()) or meta.st_mode & 0o022):
                raise RecoveryError('Operational backup source is unsafe or exceeds its per-file bound: ' + Path(name).name)
            total += meta.st_size
            estimated += 4 * ((meta.st_size + 2) // 3) + 256 + len(name.encode('utf-8')) * 6
        finally:
            os.close(parent)
    if total > MAX_SOURCE_BYTES or estimated > MAX_ARCHIVE:
        raise RecoveryError('Operational backup requires about %.1f MiB including encoding, above its bounded workspace. Export retained evidence and review retention before retrying. Nothing was omitted or deleted.' % (estimated / 1048576))
    return {'files': len(names), 'source_bytes': total, 'estimated_archive_bytes': estimated, 'maximum_archive_bytes': MAX_ARCHIVE}


def captured_snapshot(data, output):
    start = int(time.time())
    names = inventory(data)
    snapshot_plan(data, names)
    parent = directory(Path(output).parent)
    descriptor = -1
    created = False
    total = written = 0
    try:
        descriptor = os.open(Path(output).name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=parent)
        created = True
        with os.fdopen(descriptor, 'wb', closefd=False) as stream:
            def append(row):
                nonlocal written
                written += len(row)
                if written > MAX_ARCHIVE:
                    raise RecoveryError('Operational backup grew beyond 128 MiB during capture. No partial backup was accepted; retry after activity settles or review retention.')
                if stream.write(row) != len(row):
                    raise RecoveryError('Operational backup write failed. Check available space; source evidence was preserved.')
            append(line({'format': FORMAT, 'started_at': start, 'policy': 'evidence_only_no_replay'}))
            for name in names:
                raw = compact_enterprise_payload(name, read(Path(data) / name, source_bound(name)), start)
                if total + len(raw) > MAX_SOURCE_BYTES:
                    raise RecoveryError('Operational evidence grew beyond its source-byte bound during capture; no partial archive was accepted.')
                encoded = zlib.compress(raw, 1) if len(raw) > 65536 else raw
                codec = 'zlib' if len(encoded) < len(raw) else 'raw'
                if codec == 'raw': encoded = raw
                append(line({'path': name, 'captured_at': int(time.time()), 'bytes': len(raw),
                             'sha256': hashlib.sha256(raw).hexdigest(), 'codec': codec, 'stored_bytes': len(encoded),
                             'base64': base64.b64encode(encoded).decode('ascii')}))
                total += len(raw)
            append(line({'complete': True, 'files': len(names), 'source_bytes': total, 'finished_at': int(time.time())}))
            stream.flush()
            os.fsync(descriptor)
        os.fsync(parent)
    except BaseException:
        if created:
            opened = os.fstat(descriptor)
            try:
                named = os.stat(Path(output).name, dir_fd=parent, follow_symlinks=False)
            except FileNotFoundError:
                named = None
            if named is not None and (opened.st_dev, opened.st_ino) == (named.st_dev, named.st_ino):
                try:
                    os.unlink(Path(output).name, dir_fd=parent)
                except FileNotFoundError:
                    pass
                else:
                    os.fsync(parent)
        raise
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        os.close(parent)
    return {'ok': True, 'files': len(names), 'source_bytes': total, 'policy': 'evidence_only_no_replay'}


def inspect(body):
    if not body or len(body) > MAX_ARCHIVE or not body.endswith(b'\n'):
        raise RecoveryError('Operational evidence is truncated or exceeds 128 MiB.')
    rows = body.splitlines()
    if not 2 <= len(rows) <= MAX_FILES + 2:
        raise RecoveryError('Operational evidence has an invalid record count.')
    try:
        header, trailer = strict_json(rows[0]), strict_json(rows[-1])
        if (not isinstance(header, dict) or set(header) != {'format', 'started_at', 'policy'}
                or header['format'] not in (FORMAT, LEGACY_FORMAT) or header['policy'] != 'evidence_only_no_replay'
                or type(header['started_at']) is not int or not 0 < header['started_at'] < 32503680000
                or not isinstance(trailer, dict) or set(trailer) != {'complete', 'files', 'source_bytes', 'finished_at'}
                or trailer['complete'] is not True or type(trailer['files']) is not int
                or trailer['files'] != len(rows) - 2 or type(trailer['source_bytes']) is not int
                or type(trailer['finished_at']) is not int or not header['started_at'] <= trailer['finished_at'] < 32503680000):
            raise ValueError('invalid envelope')
        seen = set(); total = 0
        for raw in rows[1:-1]:
            row = strict_json(raw)
            if (not isinstance(row, dict) or (header['format'] == LEGACY_FORMAT and set(row) != {'path', 'captured_at', 'bytes', 'sha256', 'base64'})
                    or not allowed(row.get('path')) or row['path'] in seen or type(row.get('bytes')) is not int
                    or not 0 <= row['bytes'] <= source_bound(row['path']) or type(row.get('captured_at')) is not int
                    or not header['started_at'] <= row['captured_at'] <= trailer['finished_at']
                    or total + row['bytes'] > MAX_SOURCE_BYTES):
                raise ValueError('invalid record')
            decoded = record_bytes(row)
            if row['path'].startswith('enterprise-floorplans/'):
                image = Path(row['path']).name
                if image != 'img_' + row['sha256']:
                    raise ValueError('invalid private image identity')
                private_image_info(decoded)
            if row['path'] in ENTERPRISE_FENCES and decoded != ENTERPRISE_FENCES[row['path']]:
                raise ValueError('invalid enterprise replay fence')
            if row['path'] == '.enterprise-cluster-required.json':
                marker = strict_json(decoded)
                if (not isinstance(marker, dict) or set(marker) != {'schema', 'cluster_id', 'node_id', 'witness_epoch'}
                        or type(marker['schema']) is not int or marker['schema'] != 1
                        or any(not isinstance(marker[key], str) or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}', marker[key]) for key in ('cluster_id', 'node_id'))
                        or not isinstance(marker['witness_epoch'], str) or not re.fullmatch(r'[a-f0-9]{64}', marker['witness_epoch'])):
                    raise ValueError('invalid cluster replay fence')
            seen.add(row['path']); total += len(decoded)
        if total != trailer['source_bytes']: raise ValueError('invalid total')
    except (ValueError, TypeError, KeyError, OverflowError, zlib.error) as error:
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
        guard = strict_json(raw)
        if (not isinstance(guard, dict) or set(guard) != {'schema', 'ids'} or type(guard['schema']) is not int or guard['schema'] != 1
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
            ids.update(inspect_replay_guard(record_bytes(row)))
        elif name == 'incidents/archive/reports.sqlite':
            # Only this bounded, private backup is opened, read-only. No live
            # database, queues or desktop journal is opened or imported.
            with tempfile.TemporaryDirectory(prefix='sls-incident-replay-') as temporary:
                path = Path(temporary) / 'archive.sqlite'; path.write_bytes(record_bytes(row)); path.chmod(0o600)
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
    marker = Path(data) / '.incident-replay-required'
    intent = Path(data) / '.incident-replay-initializing.json'
    expected = b'SLS_RESTORED_INCIDENT_REPLAY_V1\n'
    try:
        planned = inspect_replay_guard(read(intent, 4 * 1024 * 1024))
    except FileNotFoundError:
        planned = None
    try:
        marker_body = read(marker, len(expected))
        if marker_body != expected:
            raise RecoveryError('The restored incident replay marker is damaged. Preserve it before retrying restore.')
        required = True
    except FileNotFoundError:
        required = False
    try:
        existing = inspect_replay_guard(read(destination, 4 * 1024 * 1024))
        present = True
    except FileNotFoundError:
        if required and planned is None:
            raise RecoveryError('Established restored incident replay history is missing. Preserve the marker and recovery evidence; it was not replaced.')
        existing = planned or set()
        present = False
    if planned is not None and present and not existing <= planned:
        raise RecoveryError('Interrupted replay initialization differs from retained history. Preserve both for reviewed recovery.')
    if planned is not None:
        merged = planned
    else:
        merged = existing | incoming
    if len(merged) > 100000:
        raise RecoveryError('The restored incident replay guard reached 100,000 identities. No identities were discarded.')
    if not required and planned is None:
        write_new(intent, line({'schema': 1, 'ids': sorted(merged)}))
        planned = merged
    if not required:
        write_new(marker, expected)
    if not present or merged != existing:
        raw = line({'schema': 1, 'ids': sorted(merged)})
        parent = directory(data)
        temporary = '.incident-replay-' + os.urandom(16).hex()
        try:
            write_new(Path(data) / temporary, raw)
            os.replace(temporary, destination.name, src_dir_fd=parent, dst_dir_fd=parent)
            os.fsync(parent)
        finally:
            try:
                os.unlink(temporary, dir_fd=parent)
            except FileNotFoundError:
                pass
            os.close(parent)
    if planned is not None:
        if inspect_replay_guard(read(destination, 4 * 1024 * 1024)) != planned:
            raise RecoveryError('Replay initialization did not preserve its planned identities.')
        parent = directory(data)
        try:
            os.unlink(intent.name, dir_fd=parent)
            os.fsync(parent)
        finally:
            os.close(parent)
        if incoming - planned:
            merge_replay_guard(data, incoming)


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
            path = Path(data) / row['path']; raw = record_bytes(row)
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
        count = 0; size = 0; lock_seen = False
        with os.scandir(child) as entries:
            for entry in entries:
                item = entry.stat(follow_symlinks=False)
                if entry.name == '.storage.lock':
                    if (item.st_dev, item.st_ino) != (locked.st_dev, locked.st_ino):
                        raise RecoveryError('Private image restore lock changed.')
                    lock_seen = True
                    continue
                if (not re.fullmatch(r'img_[a-f0-9]{64}', entry.name) or not stat.S_ISREG(item.st_mode)
                        or item.st_nlink != 1 or item.st_uid != os.geteuid()
                        or stat.S_IMODE(item.st_mode) != 0o600 or not 8 <= item.st_size <= 5 * 1024 * 1024):
                    raise RecoveryError('Private image restore found an unexpected, linked or unsafe file.')
                count += 1; size += item.st_size
        if not lock_seen:
            raise RecoveryError('Private image restore lock disappeared during inspection.')
        for row in images:
            name = Path(row['path']).name; target = Path(data) / 'enterprise-floorplans' / name
            raw = record_bytes(row)
            try: private_image_info(raw)
            except ValueError as error: raise RecoveryError('A restored private image is invalid.') from error
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
