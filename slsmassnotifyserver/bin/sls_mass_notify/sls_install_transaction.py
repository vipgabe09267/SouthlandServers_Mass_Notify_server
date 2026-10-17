#!/usr/bin/python3
"""Durable, root-only installer evidence; never authorizes observed live bytes."""
from __future__ import annotations

import argparse
import hashlib
import json
import math
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
PHASES = {'preparing', 'prepared', 'mutating', 'recovering', 'recovery_failed'} | TERMINAL
STAGES = {'initialization', 'hardware admission', 'platform validation', 'release download and validation',
          'publisher and stock approval', 'FreePBX ownership protection',
          'legacy configuration and runtime permissions', 'authenticated prerequisite validation',
          'previous phone collector recovery', 'notification worker coordination',
          'configuration coordination', 'dependency installation', 'timezone validation',
          'protected runtime preparation', 'module activation', 'runtime integration',
          'post-install verification', 'interrupted installation recovery'}
NAME = re.compile(r'transaction-[0-9a-f]{24}\Z')
LIMIT = 65536
BOOTSTRAP_FILES = {
    'sls_module_trust.py', 'sls_release_trust.py', 'sls_privileged_install.py',
    'sls_installer_recovery.py', 'sls_install_transaction.py',
    'sls_install_idle.py', 'sls_install_guard.py', 'sls_audio_state.py',
    'sls_config_crypto.py', 'sls_upgrade_trust.py',
    'sls_legacy_permissions.py', 'freepbx-mirror-signing.pub',
}


class TransactionError(RuntimeError):
    pass


def decode_json(body):
    """Decode one unambiguous, finite JSON document from private evidence."""
    def object_pairs(items):
        value = {}
        for key, item in items:
            if key in value:
                raise TransactionError('Duplicate installer transaction metadata member')
            value[key] = item
        return value

    def finite_float(text):
        value = float(text)
        if not math.isfinite(value):
            raise TransactionError('Nonfinite installer transaction metadata number')
        return value

    def invalid_constant(_text):
        raise TransactionError('Nonfinite installer transaction metadata number')

    try:
        return json.loads(body, object_pairs_hook=object_pairs,
                          parse_float=finite_float, parse_constant=invalid_constant)
    except (RecursionError, UnicodeDecodeError) as error:
        raise TransactionError('Invalid installer transaction metadata encoding') from error


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
    try:
        value = decode_json(read(path / 'transaction.json'))
    except FileNotFoundError:
        handle = directory(path)
        try:
            info = os.fstat(handle)
            if stat.S_IMODE(info.st_mode) != 0o700 or os.listdir(handle):
                raise TransactionError('Missing transaction metadata has nonempty or unsafe evidence; preserve it for review')
            created = max(0, int(info.st_mtime))
        finally:
            os.close(handle)
        # begin() cannot return to its caller before publishing the first
        # record. An empty private directory grants no recovery authority.
        return {'schema': 1, 'directory': str(path), 'phase': 'abandoned',
                'created_at': created, 'updated_at': created, 'settings': {},
                'empty_preparation': True}
    if (not isinstance(value, dict) or type(value.get('schema')) is not int or value.get('schema') != 1
            or value.get('phase') not in PHASES
            or value.get('directory') != str(path) or not isinstance(value.get('settings'), dict)
            or set(value['settings']) - set(FIELDS) or type(value.get('created_at')) is not int
            or type(value.get('updated_at')) is not int or value['created_at'] < 0
            or value['updated_at'] < value['created_at'] or value['updated_at'] > 2 ** 63 - 1):
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
        if value.get('empty_preparation') and not (path / 'transaction.json').exists():
            if names:
                raise TransactionError('Empty preparation evidence changed before archival; preserve it for review')
            atomic(path / 'transaction.json', value)
            names = ['transaction.json']
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
    atomic(path / 'transaction.json', {'schema': 1, 'directory': str(path), 'phase': 'preparing',
                                      'created_at': int(time.time()), 'updated_at': int(time.time()), 'settings': {}})
    return path


def update(path, phase, settings, stage=None):
    value = state(path)
    if value['phase'] in TERMINAL and phase != value['phase'] and not (value['phase']=='committed' and phase=='recovery_failed'):
        raise TransactionError('A finished transaction cannot return to an unfinished state')
    if (phase not in PHASES or type(settings) is not dict or set(settings) - set(FIELDS)
            or any(type(item) is not str for item in settings.values())):
        raise TransactionError('Invalid installer transition')
    if value['phase'] == 'preparing' and phase == 'prepared':
        if set(settings) != set(FIELDS) or settings.get('STATIC_MUTATION_STARTED') != '0' or settings.get('MODULE_ACTIVATED') != '0':
            raise TransactionError('Complete unmutated preparation settings are required before publishing recovery readiness')
        for filename, bound in (('installer.sh', 2097152), ('package.tgz', 54525952),
                                ('release-manifest.json', 16384), ('release-manifest.sig', 64)):
            if not read(path / filename, bound):
                raise TransactionError('Preparation artifact is empty: ' + filename)
        for field, name in (('INSTALL_BOOTSTRAP_DIR', 'bootstrap'), ('UPGRADE_TRUST_DIR', 'approval')):
            if settings.get(field) != str(path / name):
                raise TransactionError('Prepared evidence directory does not belong to this transaction')
            checked_directory = directory(path / name)
            os.close(checked_directory)
    value['settings'] = dict(settings)
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
    bootstrap_fd = directory(bootstrap)
    try:
        bootstrap_info = os.fstat(bootstrap_fd)
        names = set(os.listdir(bootstrap_fd))
        if bootstrap_info.st_uid != 0 or bootstrap_info.st_gid != 0 or stat.S_IMODE(bootstrap_info.st_mode) != 0o700:
            raise TransactionError('Recovery bootstrap directory permissions are unsafe')
        if names != BOOTSTRAP_FILES:
            raise TransactionError('Recovery bootstrap helper inventory is incomplete or contains unknown files')
        for name in names:
            info = os.stat(name, dir_fd=bootstrap_fd, follow_symlinks=False)
            expected_mode = 0o600 if name == 'freepbx-mirror-signing.pub' else 0o700
            if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
                    or info.st_uid != 0 or info.st_gid != 0
                    or stat.S_IMODE(info.st_mode) != expected_mode):
                raise TransactionError('Recovery bootstrap helper metadata is unsafe: ' + name)
    finally:
        os.close(bootstrap_fd)
    with tarfile.open(path / 'package.tgz', 'r:gz') as archive:
        members = archive.getmembers()
        if len(members) > 10000: raise TransactionError('Saved recovery package has too many entries')
        for child in sorted(bootstrap.iterdir(), key=lambda item: item.name):
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
        raw = sys.stdin.buffer.read(LIMIT + 1)
        if len(raw) > LIMIT: raise TransactionError('Transition input exceeds its size limit')
        settings = decode_json(raw)
        if type(settings) is not dict or args.phase is None: raise TransactionError('Transition requires a phase and settings object')
        update(args.transaction, args.phase, settings, args.stage)
    elif args.action == 'stage':
        value=state(args.transaction)
        if value['phase'] in TERMINAL or args.stage is None:raise TransactionError('A current stage requires an unfinished transaction')
        update(args.transaction,value['phase'],value['settings'],args.stage)
    elif args.action == 'authenticate':
        authenticate(args.transaction); print('Saved installer, package and recovery helpers match publisher-authenticated evidence.')
    else:
        value = authenticate(args.transaction)
        if value['phase'] not in ('prepared', 'mutating', 'recovering', 'recovery_failed'):
            raise TransactionError('Only an unfinished installation can supply operational recovery settings')
        for field in FIELDS: print(value['settings'].get(field, ''))
    return 0


if __name__ == '__main__':
    try: raise SystemExit(main())
    except (TransactionError, OSError, ValueError, KeyError, tarfile.TarError) as error:
        raise SystemExit('SLS installer transaction: ' + str(error)) from None
