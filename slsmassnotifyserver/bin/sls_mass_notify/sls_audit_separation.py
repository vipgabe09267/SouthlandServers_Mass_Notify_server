#!/usr/bin/python3
"""Explicit bounded audit split. Preserves raw evidence; never replays events."""
import argparse
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import time
import sys
sys.dont_write_bytecode = True
_spec=importlib.util.spec_from_file_location('audit_separation_storage',Path(__file__).resolve().with_name('sls_storage_maintenance.py'))
storage=importlib.util.module_from_spec(_spec);_spec.loader.exec_module(storage)

SNAPSHOTS = ('audit-separation-original.jsonl', 'audit-separation-security-before.jsonl',
             'audit-separation-control-after.jsonl', 'audit-separation-security-after.jsonl')
MAX_RECORDS = 100000
MAX_BYTES = 2 * 1024 * 1024


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def read_fd(fd):
    before = os.fstat(fd)
    if before.st_size > MAX_BYTES:
        raise RuntimeError('Audit evidence exceeds its bounded 2 MiB migration limit')
    os.lseek(fd, 0, os.SEEK_SET); chunks = []; remaining = before.st_size
    while remaining:
        chunk = os.read(fd, min(65536, remaining))
        if not chunk:
            raise RuntimeError('Audit evidence could not be read completely')
        chunks.append(chunk); remaining -= len(chunk)
    after = os.fstat(fd)
    if (os.read(fd, 1) or (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns)
            != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)):
        raise RuntimeError('Audit evidence changed during capture')
    return b''.join(chunks)


def partition(raw):
    lines = raw.splitlines(keepends=True)
    if len(lines) > MAX_RECORDS:
        raise RuntimeError('Audit evidence exceeds its bounded record limit')
    moved, kept = [], []
    for line in lines:
        selected = False
        if line.endswith(b'\n'):
            try:
                record = json.loads(line, object_pairs_hook=storage.reject_duplicate_json)
                storage.encode_security_audit(record)
                selected = storage.human_audit_record(record)
            except (ValueError, TypeError, AttributeError, UnicodeError):
                pass
        (moved if selected else kept).append(line)
    return b''.join(kept), b''.join(moved), len(moved), len(kept)


def file_write(parent, name, raw, identity, replace=False):
    fd, _ = storage.audit_regular(parent, name, create=True)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        storage.audit_identity(parent, name, fd)
        existing = read_fd(fd)
        if existing and not replace and existing != raw:
            raise RuntimeError('Established migration evidence differs; preserve it for review')
        if os.geteuid() == 0:
            os.fchown(fd, identity.st_uid, identity.st_gid)
        os.fchmod(fd, 0o640)
        os.ftruncate(fd, 0); os.lseek(fd, 0, os.SEEK_SET)
        storage.write_all(fd, raw); os.fsync(fd)
    finally:
        os.close(fd)
    os.fsync(parent)


def ledger_write(parent, value, identity):
    name = '.audit-separation-ledger-' + os.urandom(12).hex()
    fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640, dir_fd=parent)
    try:
        if os.geteuid() == 0:
            os.fchown(fd, identity.st_uid, identity.st_gid)
        storage.write_all(fd, json.dumps(value, separators=(',', ':')).encode() + b'\n'); os.fsync(fd)
        os.replace(name, 'audit-separation.json', src_dir_fd=parent, dst_dir_fd=parent); os.fsync(parent)
    finally:
        os.close(fd)
        try: os.unlink(name, dir_fd=parent)
        except FileNotFoundError: pass


def load(parent, name):
    fd, _ = storage.audit_regular(parent, name)
    try:
        fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
        return read_fd(fd)
    finally:
        os.close(fd)


def rewrite_log(fd, raw):
    os.ftruncate(fd, 0); os.lseek(fd, 0, os.SEEK_SET)
    storage.write_all(fd, raw); os.fsync(fd)


def split(directory, recover=False):
    parent = storage.open_directory(directory); outer = control = security = None
    try:
        outer, _ = storage.audit_regular(parent, '.audit-separation.lock', create=True)
        fcntl.flock(outer, fcntl.LOCK_EX | fcntl.LOCK_NB)
        storage.audit_identity(parent, '.audit-separation.lock', outer)
        established=any(os.path.lexists(Path(directory)/name) for name in ('.audit-separation-required','audit-separation.json'))
        control, _ = storage.audit_regular(parent, 'control-api-audit.jsonl', create=not established)
        security, _ = storage.audit_regular(parent, 'security-audit.jsonl', create=not established)
        for fd in (control, security):
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        identity = storage.audit_identity(parent, 'control-api-audit.jsonl', control)
        storage.audit_identity(parent, 'security-audit.jsonl', security)
        for name in ('control-api-audit.jsonl', 'security-audit.jsonl'):
            for suffix in ('backup', 'kept'):
                try: os.stat('.sls-retention-' + name + '.' + suffix, dir_fd=parent, follow_symlinks=False)
                except FileNotFoundError: continue
                raise RuntimeError('Audit retention requires recovery before separation')
        if established:
            if load(parent, '.audit-separation-required') != b'SLS_AUDIT_SEPARATION_V1\n':
                raise RuntimeError('Migration marker is missing or damaged')
            value = json.loads(load(parent, 'audit-separation.json'), object_pairs_hook=storage.reject_duplicate_json)
            storage.validate_audit_separation(value, complete=False)
            if value['state'] == 'complete':
                for name, evidence in value['snapshots'].items():
                    raw = load(parent, name)
                    if len(raw) != evidence['bytes'] or digest(raw) != evidence['sha256']:
                        raise RuntimeError('Established migration evidence is missing or damaged')
                return {'ok': True, 'already_complete': True, 'moved_records': value['moved_records']}
            if not recover:
                raise RuntimeError('Audit separation requires explicit --recover')
            before_control, before_security = read_fd(control), read_fd(security)
            if value['state'] == 'preparing':
                original = value['snapshots'][SNAPSHOTS[0]]; prior = value['snapshots'][SNAPSHOTS[1]]
                if digest(before_control) != original['sha256'] or digest(before_security) != prior['sha256']:
                    raise RuntimeError('Unprepared source audit changed; preserve evidence for review')
                kept, moved, count, retained = partition(before_control)
                bodies = (before_control, before_security, kept, before_security + moved)
                for name, raw in zip(SNAPSHOTS, bodies):
                    if digest(raw) != value['snapshots'][name]['sha256']:
                        raise RuntimeError('Prepared migration differs from reviewed source')
                    file_write(parent, name, raw, identity, replace=True)
                value['state'] = 'prepared'; ledger_write(parent, value, identity)
        else:
            before_control, before_security = read_fd(control), read_fd(security)
            if before_security and not before_security.endswith(b'\n'):
                raise RuntimeError('Security audit has an unfinished tail; preserve it for review')
            kept, moved, count, retained = partition(before_control)
            bodies = (before_control, before_security, kept, before_security + moved)
            if any(len(raw) > MAX_BYTES for raw in bodies):
                raise RuntimeError('Combined security audit exceeds the 2 MiB migration limit; no source was removed')
            value = {'schema': 1, 'state': 'preparing', 'predicate': 'positive-human-audit-v1',
                     'created_at': int(time.time()), 'completed_at': 0, 'moved_records': count, 'retained_records': retained,
                     'snapshots': {name: {'sha256': digest(raw), 'bytes': len(raw)} for name, raw in zip(SNAPSHOTS, bodies)}}
            # Claim before snapshots or any cutover: a crash cannot reset history.
            file_write(parent, '.audit-separation-required', b'SLS_AUDIT_SEPARATION_V1\n', identity)
            ledger_write(parent, value, identity)
            for name, raw in zip(SNAPSHOTS, bodies):
                file_write(parent, name, raw, identity)
            value['state'] = 'prepared'; ledger_write(parent, value, identity)
        bodies = {name: load(parent, name) for name in SNAPSHOTS}
        for name, raw in bodies.items():
            evidence = value['snapshots'][name]
            if digest(raw) != evidence['sha256'] or len(raw) != evidence['bytes']:
                raise RuntimeError('Prepared migration evidence is incomplete or damaged')
        for current, prior, after in ((before_control, bodies[SNAPSHOTS[0]], bodies[SNAPSHOTS[2]]),
                                       (before_security, bodies[SNAPSHOTS[1]], bodies[SNAPSHOTS[3]])):
            if current != prior and not after.startswith(current):
                raise RuntimeError('Audit changed outside pending migration; preserve evidence for review')
        # Both logs retain their original inode. Complete the destination first.
        rewrite_log(security, bodies[SNAPSHOTS[3]])
        rewrite_log(control, bodies[SNAPSHOTS[2]])
        os.fsync(parent)
        for name, fd, expected in (('security-audit.jsonl',security,bodies[SNAPSHOTS[3]]),('control-api-audit.jsonl',control,bodies[SNAPSHOTS[2]])):
            storage.audit_identity(parent,name,fd)
            actual=read_fd(fd)
            if len(actual)!=len(expected) or digest(actual)!=digest(expected):
                raise RuntimeError('Durable audit cutover failed readback verification')
        checked_kept, checked_moved, checked_count, checked_retained=partition(bodies[SNAPSHOTS[0]])
        if checked_kept!=bodies[SNAPSHOTS[2]] or bodies[SNAPSHOTS[1]]+checked_moved!=bodies[SNAPSHOTS[3]] or checked_count!=value['moved_records'] or checked_retained!=value['retained_records']:
            raise RuntimeError('Audit separation record counts do not match prepared evidence')
        value['state'] = 'complete'; value['completed_at'] = int(time.time()); ledger_write(parent, value, identity)
        return {'ok': True, 'moved_records': value['moved_records'], 'retained_records': value['retained_records']}
    finally:
        for fd in (security, control, outer, parent):
            if fd is not None: os.close(fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory', required=True, type=Path)
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument('--split', action='store_true'); modes.add_argument('--recover', action='store_true')
    args = parser.parse_args()
    try:
        result = split(args.directory, args.recover)
    except (OSError, ValueError, TypeError, RuntimeError) as error:
        print(json.dumps({'ok': False, 'error': str(error)[:240]})); return 1
    print(json.dumps(result)); return 0


if __name__ == '__main__':
    raise SystemExit(main())
