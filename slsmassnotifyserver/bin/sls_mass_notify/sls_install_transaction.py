#!/usr/bin/python3
"""Durable, root-only installer evidence; never authorizes observed live bytes."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import shutil
import sys
import tarfile
import time
import types

sys.dont_write_bytecode = True
ROOT = Path('/var/lib/sls-mass-notify-installer')
FIELDS = ('RECOVERY_DIR', 'INSTALL_BOOTSTRAP_DIR', 'UPGRADE_TRUST_DIR',
          'CONFIG_SNAPSHOT', 'CONFIG_HASH_BEFORE', 'PENDING_CONFIG_STATE',
          'PENDING_CONFIG_PATH', 'PENDING_CONFIG_SNAPSHOT', 'PENDING_CONFIG_HASH',
          'MODULE_BACKUP_DIR', 'STAGING_DIR', 'HAD_EXISTING_MODULE',
          'MODULE_WAS_ENABLED', 'MODULE_ACTIVATED', 'STATIC_MUTATION_STARTED',
          'ORIGINAL_SYSTEM_TIMEZONE', 'SYSTEM_TIMEZONE_CHANGED')
TERMINAL = {'committed', 'rolled_back', 'abandoned'}
PHASES = {'prepared', 'mutating', 'recovering', 'recovery_failed'} | TERMINAL
STAGES = {'initialization', 'hardware admission', 'platform validation', 'release download and validation',
          'publisher and stock approval', 'FreePBX ownership protection',
          'legacy configuration and runtime permissions', 'authenticated prerequisite validation',
          'previous phone collector recovery', 'notification worker coordination',
          'configuration coordination', 'dependency installation', 'timezone validation',
          'protected runtime preparation', 'module activation', 'runtime integration',
          'post-install verification', 'interrupted installation recovery'}
NAME = re.compile(r'transaction-[0-9a-f]{24}\Z')
LIMIT = 65536


class TransactionError(RuntimeError):
    pass


def directory(path):
    """Inspect every component by descriptor; writable ancestors are rejected."""
    path = Path(path)
    if not path.is_absolute() or any(part in ('.', '..') for part in path.parts):
        raise TransactionError('Invalid installer evidence path')
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        for part in path.parts[1:]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
            os.close(fd); fd = child
            info = os.fstat(fd)
            # /tmp is permitted only for disposable tests, never the default root.
            if info.st_uid != 0 or info.st_mode & 0o022 and not (part == 'tmp' and info.st_mode & stat.S_ISVTX):
                raise TransactionError('Installer evidence has an unprotected parent: ' + str(path))
        return fd
    except BaseException:
        os.close(fd)
        raise


def read(path, bound=LIMIT, *, private=True):
    parent = directory(Path(path).parent)
    try:
        fd = os.open(Path(path).name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        try:
            before = os.fstat(fd)
            if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_uid != 0 or before.st_mode & (0o077 if private else 0o022) or before.st_size > bound:
                raise TransactionError('Unsafe installer evidence file: ' + str(path))
            body = bytearray()
            while len(body) <= bound:
                block = os.read(fd, min(65536, bound + 1 - len(body)))
                if not block: break
                body.extend(block)
            after = os.fstat(fd)
            if len(body) != before.st_size or (before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns):
                raise TransactionError('Installer evidence changed during inspection')
            return bytes(body)
        finally: os.close(fd)
    finally: os.close(parent)


def atomic(path, value):
    body = (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode()
    if len(body) > LIMIT: raise TransactionError('Installer transaction metadata exceeds its bound')
    parent = directory(path.parent)
    temporary = '.new-' + os.urandom(12).hex()
    try:
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
        with os.fdopen(fd, 'wb') as output:
            output.write(body); output.flush(); os.fsync(output.fileno())
        os.replace(temporary, path.name, src_dir_fd=parent, dst_dir_fd=parent)
        os.fsync(parent)
    finally:
        try: os.unlink(temporary, dir_fd=parent)
        except FileNotFoundError: pass
        os.close(parent)


def state(path):
    if not NAME.fullmatch(path.name): raise TransactionError('Invalid installer transaction identity')
    value = json.loads(read(path / 'transaction.json'))
    if (not isinstance(value, dict) or value.get('schema') != 1 or value.get('phase') not in PHASES
            or value.get('directory') != str(path) or not isinstance(value.get('settings'), dict)
            or set(value['settings']) - set(FIELDS) or not isinstance(value.get('created_at'), int)
            or not isinstance(value.get('updated_at'), int) or value['created_at'] < 0 or value['updated_at'] < value['created_at']):
        raise TransactionError('Invalid installer transaction metadata')
    for field, item in value['settings'].items():
        if not isinstance(item, str) or len(item) > 4096 or any(ord(c) < 32 or ord(c) == 127 for c in item):
            raise TransactionError('Invalid transaction setting: ' + field)
    if ('stage' in value or 'stage_at' in value) and (value.get('stage') not in STAGES
            or type(value.get('stage_at')) is not int or not value['created_at'] <= value['stage_at'] <= value['updated_at']):
        raise TransactionError('Invalid installer stage metadata')
    return value


def pending(root):
    if not root.exists(): return []
    fd = directory(root)
    try:
        if os.fstat(fd).st_mode & 0o077: raise TransactionError('Installer evidence root must be mode 0700')
        names = os.listdir(fd)
    finally: os.close(fd)
    if len(names) > 100: raise TransactionError('Installer evidence directory needs administrator archival')
    result = []
    for name in sorted(names):
        if not NAME.fullmatch(name): raise TransactionError('Unknown installer evidence directory: ' + name)
        value = state(root / name)
        if value['phase'] not in TERMINAL: result.append(value)
    return result


def clean_completed_evidence(value):
    # A durable terminal state already permits removal. A kill between commit
    # and normal shell cleanup must not accumulate complete package snapshots.
    # Never follow saved settings paths outside this protected generation.
    path = Path(value['directory'])
    handle = directory(path)
    try:
        names = os.listdir(handle)
        fixed = {'transaction.json', 'installer.sh', 'package.tgz', 'release-manifest.json',
                 'release-manifest.sig', 'bootstrap', 'approval', 'config.snapshot', 'pending.snapshot'}
        if any(name not in fixed and not re.fullmatch(r'sls-mass-notify-recovery\.[A-Za-z0-9]{6,12}', name) for name in names):
            raise TransactionError('A completed installer generation has unknown evidence; preserve it for administrator review: ' + str(path))
        for name in names:
            if name == 'transaction.json': continue
            info = os.stat(name, dir_fd=handle, follow_symlinks=False)
            if stat.S_ISDIR(info.st_mode):
                shutil.rmtree(name, dir_fd=handle)
            else:
                os.unlink(name, dir_fd=handle)
        os.fsync(handle)
    finally:
        os.close(handle)


def begin(root):
    if pending(root): raise TransactionError('An unfinished SLS installation must be recovered before a new installation')
    if root.exists():
        completed = sorted((state(path) for path in root.iterdir()), key=lambda row: row['updated_at'])
        for value in completed:
            clean_completed_evidence(value)
        for value in completed[:-20]:
            # Every entry was validated by pending(); a terminal record is a
            # completion marker, never a rollback authority. Root-only parents
            # keep the cleanup separate from public FreePBX module paths.
            shutil.rmtree(Path(value['directory']))
    if not root.exists():
        parent = directory(root.parent)
        try: os.mkdir(root.name, 0o700, dir_fd=parent); os.fsync(parent)
        finally: os.close(parent)
    name = 'transaction-' + os.urandom(12).hex()
    parent = directory(root)
    try: os.mkdir(name, 0o700, dir_fd=parent); os.fsync(parent)
    finally: os.close(parent)
    path = root / name
    atomic(path / 'transaction.json', {'schema': 1, 'directory': str(path), 'phase': 'prepared',
                                      'created_at': int(time.time()), 'updated_at': int(time.time()), 'settings': {}})
    return path


def update(path, phase, settings, stage=None):
    value = state(path)
    if value['phase'] in TERMINAL and phase != value['phase'] and not (value['phase']=='committed' and phase=='recovery_failed'):
        raise TransactionError('A finished transaction cannot return to an unfinished state')
    if phase not in PHASES or set(settings) - set(FIELDS): raise TransactionError('Invalid installer transition')
    value['settings'] = {key: str(item) for key, item in settings.items()}
    value['phase'] = phase; value['updated_at'] = max(int(time.time()),value['created_at'],value['updated_at'])
    if stage is not None:
        if stage not in STAGES:raise TransactionError('Invalid installer stage')
        value['stage']=stage;value['stage_at']=value['updated_at']
    # Validate the complete serializable input before any replacement.
    for key, item in value['settings'].items():
        if len(item) > 4096 or any(ord(c) < 32 or ord(c) == 127 for c in item): raise TransactionError('Invalid installer setting: ' + key)
    atomic(path / 'transaction.json', value)


def authenticate(path):
    """Authorize saved executable bytes with publisher evidence, never journal hashes."""
    value = state(path)
    here = Path(__file__).absolute().parent
    source = read(here / 'sls_release_trust.py', 262144, private=False)
    module = types.ModuleType('transaction_publisher'); module.__file__ = str(here / 'sls_release_trust.py')
    exec(compile(source, module.__file__, 'exec'), module.__dict__)
    manifest = module.authenticate_manifest(read(path / 'release-manifest.json'), read(path / 'release-manifest.sig'))
    for filename, field, bound in (('installer.sh', 'installer_sha256', 2 * 1024 * 1024),
                                   ('package.tgz', 'package_sha256', 64 * 1024 * 1024)):
        if hashlib.sha256(read(path / filename, bound)).hexdigest() != manifest.get(field):
            raise TransactionError('Saved installer recovery artifact differs from its publisher signature: ' + filename)
    bootstrap = Path(value['settings'].get('INSTALL_BOOTSTRAP_DIR', ''))
    if bootstrap != path / 'bootstrap': raise TransactionError('Recovery bootstrap is outside the durable transaction')
    with tarfile.open(path / 'package.tgz', 'r:gz') as archive:
        members = archive.getmembers()
        if len(members) > 10000: raise TransactionError('Saved recovery package has too many entries')
        for child in bootstrap.iterdir():
            if child.name == 'freepbx-mirror-signing.pub':
                name = 'slsmassnotifyserver/bin/sls_mass_notify/freepbx-mirror-signing.pub'
            else:
                if not re.fullmatch(r'sls_[a-z_]+\.py', child.name): raise TransactionError('Unexpected recovery helper')
                name = 'slsmassnotifyserver/bin/sls_mass_notify/' + child.name
            matches = [member for member in members if member.name.rstrip('/') == name]
            if len(matches) != 1 or not matches[0].isfile() or matches[0].size > 2 * 1024 * 1024:
                raise TransactionError('Recovery helper is absent from the authenticated package')
            stream = archive.extractfile(matches[0])
            if stream is None or read(child, 2 * 1024 * 1024) != stream.read(2 * 1024 * 1024 + 1):
                raise TransactionError('Saved recovery helper differs from its authenticated package')
    return value


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=ROOT)
    parser.add_argument('--transaction', type=Path)
    parser.add_argument('--phase', choices=sorted(PHASES))
    parser.add_argument('--stage', choices=sorted(STAGES))
    parser.add_argument('action', choices=('pending', 'begin', 'update', 'stage', 'authenticate', 'values'))
    args = parser.parse_args()
    if os.geteuid() != 0: raise TransactionError('Installer transaction inspection requires root')
    if args.action == 'pending':
        records = pending(args.root)
        print(json.dumps({'ok': not records, 'unfinished': records}))
        return 0 if not records else 3
    if args.action == 'begin': print(begin(args.root)); return 0
    if args.transaction is None: parser.error('--transaction is required')
    if args.transaction.parent != args.root: raise TransactionError('Transaction must belong to its protected evidence root')
    if args.action == 'update':
        settings = json.loads(sys.stdin.buffer.read(LIMIT + 1))
        if not isinstance(settings, dict) or args.phase is None: raise TransactionError('Transition requires a phase and settings object')
        update(args.transaction, args.phase, settings, args.stage)
    elif args.action == 'stage':
        value=state(args.transaction)
        if value['phase'] in TERMINAL or args.stage is None:raise TransactionError('A current stage requires an unfinished transaction')
        update(args.transaction,value['phase'],value['settings'],args.stage)
    elif args.action == 'authenticate':
        authenticate(args.transaction); print('Saved installer, package and recovery helpers match publisher-authenticated evidence.')
    else:
        value = authenticate(args.transaction)
        for field in FIELDS: print(value['settings'].get(field, ''))
    return 0


if __name__ == '__main__':
    try: raise SystemExit(main())
    except (TransactionError, OSError, ValueError, KeyError, tarfile.TarError) as error:
        raise SystemExit('SLS installer transaction: ' + str(error)) from None
