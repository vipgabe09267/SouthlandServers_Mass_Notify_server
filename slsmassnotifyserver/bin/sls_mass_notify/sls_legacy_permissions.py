#!/usr/bin/python3
"""Tighten recognized legacy SLS files after authenticated upgrade approval.

No ownership adoption, journal replacement, service operation or delivery occurs.
The runtime's authoritative readers remain strict after this bounded migration.
"""
from __future__ import annotations

import argparse
import base64
import fcntl
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import pwd
import re
import stat
import sys
import time

sys.dont_write_bytecode = True
MAX_STATE = 16 * 1024 * 1024
CONFIG_NAMES = ('mass-notifications.config', 'mass-notifications.pending.config')
NAMES = ('enterprise-worker.lock', 'announcement-activity.lock', 'mass-notifications.config.lock',
         'phone-admission.lock', 'phone-admission.json', *CONFIG_NAMES)
CONFIG_KEYRING = Path('/etc/sls-mass-notify/config-keys.json')
DATA = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin'
MAX_CHOWN = 512 * 1024
CHOWN_BLOCK = ('\n; BEGIN SLS MASS NOTIFY DATA PERMISSIONS\n[blacklist]\n'
               'directory = ' + DATA + '\n; END SLS MASS NOTIFY DATA PERMISSIONS\n').encode()


class PermissionRecoveryError(RuntimeError):
    pass


def directory(path, account, *, protected=False):
    if any(part in ('.', '..') for part in os.fspath(path).split('/')):
        raise PermissionRecoveryError('Legacy permission recovery requires a safe absolute path')
    path = Path(path)
    if not path.is_absolute() or any(part in ('.', '..') for part in path.parts):
        raise PermissionRecoveryError('Legacy permission recovery requires a safe absolute path')
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    fd = os.open('/', flags)
    try:
        for index, part in enumerate(path.parts[1:]):
            child = os.open(part, flags, dir_fd=fd)
            os.close(fd); fd = child
            info = os.fstat(fd)
            owners = {0} if protected else {0, account.pw_uid}
            sticky_ancestor = info.st_uid == 0 and info.st_mode & stat.S_ISVTX and index < len(path.parts) - 2
            if (info.st_uid not in owners
                    or info.st_mode & 0o002 and not sticky_ancestor
                    or protected and info.st_mode & 0o020 and not sticky_ancestor
                    or not protected and info.st_mode & 0o020 and not sticky_ancestor and info.st_gid not in (0, account.pw_gid)):
                raise PermissionRecoveryError('Legacy permission recovery parent is unsafe')
        return fd
    except BaseException:
        os.close(fd)
        raise


def identity(info):
    return info.st_dev, info.st_ino, info.st_uid, info.st_gid, info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns, info.st_mode


def read(fd, maximum):
    info = os.fstat(fd)
    if info.st_size > maximum:
        raise PermissionRecoveryError('Legacy permission recovery file exceeds its size limit')
    raw = bytearray()
    while len(raw) <= maximum:
        block = os.read(fd, min(65536, maximum + 1 - len(raw)))
        if not block: break
        raw.extend(block)
    if len(raw) != info.st_size or identity(info) != identity(os.fstat(fd)):
        raise PermissionRecoveryError('Legacy permission recovery file changed during inspection')
    return bytes(raw)


def decoded(raw):
    def pairs(items):
        output = {}
        for key, value in items:
            if key in output: raise ValueError('duplicate key')
            output[key] = value
        return output
    def constant(_):
        raise ValueError('nonfinite number')
    try:
        return json.loads(raw, object_pairs_hook=pairs, parse_constant=constant)
    except (ValueError, UnicodeError, RecursionError):
        raise PermissionRecoveryError('Legacy permission recovery JSON is malformed') from None


def approved(path, account):
    parent = directory(Path(path).parent, account, protected=True)
    try:
        fd = os.open(Path(path).name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        try:
            info = os.fstat(fd)
            if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != 0
                    or info.st_gid != 0 or stat.S_IMODE(info.st_mode) != 0o600):
                raise PermissionRecoveryError('Previous release approval is not a private root-owned file')
            value = decoded(read(fd, 8 * 1024 * 1024))
        finally: os.close(fd)
    finally: os.close(parent)
    if not isinstance(value, dict) or type(value.get('schema')) is not int or value['schema'] != 1:
        raise PermissionRecoveryError('Previous release approval is invalid')
    if value.get('source') == 'fresh-install' and value.get('files') == {}:
        return False
    if (value.get('source') != 'publisher-verified-previous-release'
            or not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?', str(value.get('version', '')))
            or not re.fullmatch(r'[a-f0-9]{64}', str(value.get('archive_sha256', '')))
            or not isinstance(value.get('files'), dict) or not value['files']
            or any(not isinstance(name, str) or not name.startswith('/') or not isinstance(digest, str)
                   or not re.fullmatch(r'[a-f0-9]{64}', digest) for name, digest in value['files'].items())):
        raise PermissionRecoveryError('A publisher-verified previous release approval is required')
    return True


def checked_phone_state(raw):
    state = decoded(raw)
    if (not isinstance(state, dict) or type(state.get('schema')) is not int or state['schema'] != 1
            or not isinstance(state.get('batches'), dict) or len(state['batches']) > 2000):
        raise PermissionRecoveryError('Legacy phone admission journal has an invalid structure')
    collector = state.get('collector')
    if collector is not None and (not isinstance(collector, dict)
            or not re.fullmatch(r'[a-f0-9]{32}', str(collector.get('generation', '')))
            or not re.fullmatch(r'[A-Za-z0-9-]{1,64}', str(collector.get('boot_id', '')))
            or type(collector.get('heartbeat_tick')) not in (int, float)
            or not finite_nonnegative(collector['heartbeat_tick'])):
        raise PermissionRecoveryError('Legacy phone admission collector record is malformed')
    for token, batch in state['batches'].items():
        if (not re.fullmatch(r'[a-f0-9]{32}', token) or not isinstance(batch, dict)
                or batch.get('status') not in {'reserved', 'active', 'closed'}
                or batch.get('service') not in {'announcement', 'nws', 'lightning', 'manual', 'live', 'outbound'}
                or not isinstance(batch.get('targets'), dict) or not 1 <= len(batch['targets']) <= 1000
                or not isinstance(batch.get('channels'), dict) or len(batch['channels']) > 4000
                or not re.fullmatch(r'[a-f0-9]{32}', str(batch.get('generation', '')))
                or not re.fullmatch(r'[A-Za-z0-9-]{1,64}', str(batch.get('boot_id', '')))):
            raise PermissionRecoveryError('Legacy phone admission batch record is malformed')
        for name in ('created_at', 'updated_at', 'start_deadline', 'created_tick', 'updated_tick', 'start_deadline_tick'):
            value = batch.get(name)
            if not finite_nonnegative(value):
                raise PermissionRecoveryError('Legacy phone admission timing record is malformed')
        for recipient, row in batch['targets'].items():
            if (not re.fullmatch(r'(?:[0-9]{1,20}|voice_[a-f0-9]{24})', recipient) or not isinstance(row, dict)
                    or not isinstance(row.get('dials'), list) or len(row['dials']) > 1000
                    or any(not isinstance(dial, str) or len(dial) > 4096 or re.search(r'[\x00-\x20\x7f]', dial) for dial in row['dials'])):
                raise PermissionRecoveryError('Legacy phone admission target record is malformed')
        for uid, row in batch['channels'].items():
            if (not re.fullmatch(r'[A-Za-z0-9_.-]{1,128}', uid) or not isinstance(row, dict)
                    or row.get('kind') not in {'origin', 'contact'} or row.get('recipient') not in batch['targets']
                    or not isinstance(row.get('channel'), str) or len(row['channel']) > 4096):
                raise PermissionRecoveryError('Legacy phone admission channel record is malformed')


def finite_nonnegative(value):
    try:
        return type(value) in (int, float) and math.isfinite(value) and value >= 0
    except OverflowError:
        return False


def checked_configuration(raw):
    # The installer stages this adjacent helper only after authenticating the
    # candidate package. Decode in memory; never migrate keys or rewrite data.
    spec = importlib.util.spec_from_file_location('sls_legacy_config_crypto', Path(__file__).resolve().with_name('sls_config_crypto.py'))
    crypto = importlib.util.module_from_spec(spec)
    try:
        spec.loader.exec_module(crypto)
        crypto.decode_config(raw, CONFIG_KEYRING, allow_legacy=True)
    except (OSError, ValueError, ImportError, AttributeError):
        raise PermissionRecoveryError('Central configuration could not be authenticated; preserve its original file and encryption keys for review') from None


def repair(data, approval, *, timeout=120):
    if os.geteuid() != 0:
        raise PermissionRecoveryError('Legacy permission recovery requires root')
    account = pwd.getpwnam('asterisk')
    if account.pw_uid == 0:
        raise PermissionRecoveryError('Asterisk UID must be nonzero')
    if type(timeout) not in (int, float) or not 0 < timeout <= 120:
        raise PermissionRecoveryError('Legacy permission recovery lock timeout is invalid')
    previous = approved(approval, account)
    try:
        parent = directory(data, account)
    except FileNotFoundError:
        if previous:
            raise PermissionRecoveryError('Previously installed SLS data directory is missing; preserve recovery evidence') from None
        missing_parent = directory(Path(data).parent, account)
        try:
            try: os.stat(Path(data).name, dir_fd=missing_parent, follow_symlinks=False)
            except FileNotFoundError: return {'ok': True, 'repaired_files': [], 'preserved_files': 0, 'fresh_install': True}
            raise PermissionRecoveryError('SLS data directory appeared during fresh-install inspection')
        finally: os.close(missing_parent)
    files = []
    try:
        info = os.fstat(parent)
        if (info.st_uid != account.pw_uid or info.st_gid != account.pw_gid
                or stat.S_IMODE(info.st_mode) not in (0o700, 0o750, 0o755)):
            raise PermissionRecoveryError('Legacy SLS data directory has unsafe ownership or permissions')
        parent_identity = info.st_dev, info.st_ino
        if stat.S_IMODE(info.st_mode) == 0o755 and not previous:
            raise PermissionRecoveryError('Legacy directory migration requires authenticated previous-release approval')
        deadline = time.monotonic() + timeout
        for name in NAMES:
            try:
                fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
            except FileNotFoundError: continue
            info = os.fstat(fd)
            files.append((name, fd, identity(info)))
            if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != account.pw_uid
                    or info.st_gid != account.pw_gid or stat.S_IMODE(info.st_mode) not in (0o600, 0o640, 0o644)):
                raise PermissionRecoveryError('Legacy SLS file ownership, links or mode are unsafe: ' + name)
            if name.endswith('.lock'):
                if info.st_size != 0:
                    raise PermissionRecoveryError('Legacy worker/admission lock is not the recognized empty layout: ' + name)
                while True:
                    try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB); break
                    except BlockingIOError:
                        if time.monotonic() >= deadline:
                            raise PermissionRecoveryError('Legacy SLS lock is active; stop the verified worker before repair: ' + name) from None
                        time.sleep(.05)
            elif name == 'phone-admission.json':
                if not any(entry[0] == 'phone-admission.lock' for entry in files):
                    raise PermissionRecoveryError('Existing phone admission journal is missing its permanent lock; preserve it for reviewed recovery')
                checked_phone_state(read(fd, MAX_STATE))
            else:
                if not any(entry[0] == 'mass-notifications.config.lock' for entry in files):
                    raise PermissionRecoveryError('Existing central configuration is missing its permanent lock; preserve it for reviewed recovery')
                checked_configuration(read(fd, 3 * 1024 * 1024))
            if stat.S_IMODE(info.st_mode) == 0o644 and not previous:
                raise PermissionRecoveryError('Legacy file migration requires authenticated previous-release approval: ' + name)
        # Inspect every recognized file before any permission change. Never
        # replace a locked inode or rewrite a journal merely to repair its mode.
        live = directory(data, account)
        try:
            current_parent = os.fstat(live)
            if (current_parent.st_dev, current_parent.st_ino) != parent_identity:
                raise PermissionRecoveryError('Legacy SLS data directory changed before repair')
        finally: os.close(live)
        for name, fd, original in files:
            current = os.stat(name, dir_fd=parent, follow_symlinks=False)
            if identity(current) != original or identity(os.fstat(fd)) != original:
                raise PermissionRecoveryError('Legacy SLS file changed before repair: ' + name)
        changed = []
        for name, fd, original in files:
            if identity(os.stat(name, dir_fd=parent, follow_symlinks=False)) != identity(os.fstat(fd)):
                raise PermissionRecoveryError('Legacy SLS file name changed during repair: ' + name)
            if stat.S_IMODE(os.fstat(fd).st_mode) == 0o644:
                os.fchmod(fd, 0o640); os.fsync(fd); changed.append(name)
        if stat.S_IMODE(os.fstat(parent).st_mode) == 0o755:
            os.fchmod(parent, 0o750); changed.append('.')
        os.fsync(parent)
        live = directory(data, account)
        try:
            current_parent = os.fstat(live)
            if (current_parent.st_dev, current_parent.st_ino) != parent_identity:
                raise PermissionRecoveryError('Legacy SLS data directory changed during repair; review the preserved files')
        finally: os.close(live)
        return {'ok': True, 'repaired_files': changed, 'preserved_files': len(files)}
    finally:
        for _, fd, _ in files: os.close(fd)
        os.close(parent)


def chown_transform(raw):
    """Use the native LoadConfig section/repeated-key contract; keep original bytes."""
    if len(raw) > MAX_CHOWN or b'\x00' in raw:
        raise PermissionRecoveryError('FreePBX chown configuration is oversized or malformed')
    try: text = raw.decode('utf-8')
    except UnicodeError:
        raise PermissionRecoveryError('FreePBX chown configuration is not UTF-8') from None
    section = ''; excluded = False; in_block = False
    for line in text.splitlines():
        line = line.strip()
        if in_block:
            if '--;' not in line: continue
            line = line.split('--;', 1)[1]; in_block = False
        line = re.sub(r'(?<!\\);--.*?--;', '', line)
        if re.search(r'(?<!\\);--', line):
            in_block = True
            line = re.sub(r'(?<!\\);--.*', '', line)
        if not in_block: line = re.sub(r'(?<!\\);.*', '', line)
        line = line.strip()
        if not line: continue
        if line.startswith('#'):
            raise PermissionRecoveryError('Included chown configuration requires review')
        header = re.fullmatch(r'\[([^\s\]]+)\](?:\(([^)]*)\))?', line)
        if header:
            section = header[1]
            if header[2] not in (None, '+'):
                raise PermissionRecoveryError('Templated chown configuration requires review')
            continue
        entry = re.fullmatch(r'([^+=]+?)\s*(?:=>?)\s*(.*)', line)
        if not entry:
            raise PermissionRecoveryError('FreePBX chown configuration contains an unsupported directive')
        key, value = entry[1].strip(), entry[2].strip().replace('\\;', ';')
        if section == 'blacklist' and key == 'directory':
            path = value.rstrip('/')
            if path == DATA or DATA.startswith(path + '/') and path.startswith('/'):
                excluded = True
        if section == 'custom' and key in ('file', 'dir', 'rdir', 'execdir'):
            path = value.split(',', 1)[0].strip().rstrip('/')
            if path == DATA or path.startswith(DATA + '/'):
                raise PermissionRecoveryError('A custom SLS data permission directive conflicts with the managed exclusion')
    if in_block:
        raise PermissionRecoveryError('FreePBX chown configuration has an unterminated comment block')
    markers = (b'BEGIN SLS MASS NOTIFY DATA PERMISSIONS', b'END SLS MASS NOTIFY DATA PERMISSIONS')
    if any(marker in raw for marker in markers) and (raw.count(CHOWN_BLOCK) != 1 or any(raw.count(marker) != 1 for marker in markers)):
        raise PermissionRecoveryError('An incomplete SLS chown exclusion requires review')
    if excluded: return raw
    return raw + CHOWN_BLOCK


def config_read(parent, name, account):
    try: fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
    except FileNotFoundError: return None, None
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
                or info.st_uid not in (0, account.pw_uid) or info.st_gid not in (0, account.pw_gid)
                or stat.S_IMODE(info.st_mode) not in (0o600, 0o640, 0o644, 0o660, 0o664)):
            raise PermissionRecoveryError('FreePBX chown configuration ownership, links or mode are unsafe')
        body = read(fd, MAX_CHOWN)
        if identity(os.stat(name, dir_fd=parent, follow_symlinks=False)) != identity(info):
            raise PermissionRecoveryError('FreePBX chown configuration changed during inspection')
        return body, info
    finally: os.close(fd)


def current_name(parent, name, expected):
    try: current = os.stat(name, dir_fd=parent, follow_symlinks=False)
    except FileNotFoundError: current = None
    if (current is None) != (expected is None) or current is not None and identity(current) != identity(expected):
        raise PermissionRecoveryError('FreePBX chown configuration changed before replacement')


def current_parent(parent, path, account):
    live = directory(path, account)
    try:
        original = os.fstat(parent); current = os.fstat(live)
        if (original.st_dev, original.st_ino) != (current.st_dev, current.st_ino):
            raise PermissionRecoveryError('FreePBX chown configuration directory changed during protection')
    finally: os.close(live)


def write_atomic(parent, path, body, uid, gid, mode, expected, account):
    name = path.name
    temporary = '.sls-chown-' + os.urandom(16).hex()
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=parent)
    try:
        os.fchown(fd, 0, 0); os.fchmod(fd, 0o600)
        with os.fdopen(fd, 'wb', closefd=False) as handle:
            if handle.write(body) != len(body): raise PermissionRecoveryError('SLS chown protection write was incomplete')
            handle.flush(); os.fsync(fd)
        current_parent(parent, path.parent, account)
        current_name(parent, name, expected)
        stage = os.fstat(fd)
        current_name(parent, temporary, stage)
        os.replace(temporary, name, src_dir_fd=parent, dst_dir_fd=parent); os.fsync(parent)
        current_parent(parent, path.parent, account)
        current_name(parent, name, os.fstat(fd))
        os.fchown(fd, uid, gid); os.fchmod(fd, mode); os.fsync(fd)
        current_name(parent, name, os.fstat(fd))
    finally:
        os.close(fd)
        try: os.unlink(temporary, dir_fd=parent)
        except FileNotFoundError: pass


def snapshot_write(path, value, account):
    parent = directory(Path(path).parent, account, protected=True)
    try:
        fd = os.open(Path(path).name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=parent)
        try:
            os.fchown(fd, 0, 0)
            with os.fdopen(fd, 'wb', closefd=False) as handle:
                handle.write(json.dumps(value, separators=(',', ':')).encode()); handle.flush(); os.fsync(fd)
            os.fsync(parent)
        finally: os.close(fd)
    finally: os.close(parent)


def snapshot_read(path, account):
    parent = directory(Path(path).parent, account, protected=True)
    try:
        fd = os.open(Path(path).name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        try:
            info = os.fstat(fd)
            if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != 0 or info.st_gid != 0
                    or stat.S_IMODE(info.st_mode) != 0o600):
                raise PermissionRecoveryError('SLS chown recovery snapshot is unsafe')
            return decoded(read(fd, 2 * MAX_CHOWN))
        finally: os.close(fd)
    finally: os.close(parent)


def protect_chown(path, snapshot, approval):
    if os.geteuid() != 0: raise PermissionRecoveryError('SLS chown protection requires root')
    account = pwd.getpwnam('asterisk'); path = Path(path)
    approved(approval, account)
    parent = directory(path.parent, account)
    try:
        original, info = config_read(parent, path.name, account)
        installed = chown_transform(original or b'')
        record = {'schema': 1, 'path': str(path), 'existed': original is not None,
                  'original_base64': base64.b64encode(original or b'').decode(),
                  'uid': info.st_uid if info else 0, 'gid': info.st_gid if info else 0,
                  'mode': stat.S_IMODE(info.st_mode) if info else 0o644,
                  'installed_sha256': hashlib.sha256(installed).hexdigest(), 'changed': installed != original}
        snapshot_write(snapshot, record, account)
        current, current_info = config_read(parent, path.name, account)
        if current != original or (info is not None and identity(current_info) != identity(info)):
            raise PermissionRecoveryError('FreePBX chown configuration changed before protection')
        if installed != original:
            write_atomic(parent, path, installed, record['uid'], record['gid'], record['mode'], current_info, account)
        return {'ok': True, 'changed': installed != original, 'snapshot': str(snapshot)}
    finally: os.close(parent)


def restore_chown(path, snapshot):
    if os.geteuid() != 0: raise PermissionRecoveryError('SLS chown recovery requires root')
    account = pwd.getpwnam('asterisk'); path = Path(path)
    record = snapshot_read(snapshot, account)
    if (not isinstance(record, dict) or type(record.get('schema')) is not int or record['schema'] != 1 or record.get('path') != str(path)
            or type(record.get('existed')) is not bool or type(record.get('changed')) is not bool
            or record.get('uid') not in (0, account.pw_uid) or record.get('gid') not in (0, account.pw_gid)
            or record.get('mode') not in (0o600, 0o640, 0o644, 0o660, 0o664)):
        raise PermissionRecoveryError('SLS chown recovery snapshot is malformed')
    try: original = base64.b64decode(record['original_base64'], validate=True)
    except (KeyError, ValueError, TypeError):
        raise PermissionRecoveryError('SLS chown recovery snapshot contents are malformed') from None
    if len(original) > MAX_CHOWN or hashlib.sha256(chown_transform(original)).hexdigest() != record.get('installed_sha256'):
        raise PermissionRecoveryError('SLS chown recovery snapshot transformation is invalid')
    if (not record['existed'] and original or record['changed'] != (chown_transform(original) != original)
            or not record['existed'] and (record['uid'], record['gid'], record['mode']) != (0, 0, 0o644)):
        raise PermissionRecoveryError('SLS chown recovery snapshot state is inconsistent')
    parent = directory(path.parent, account)
    try:
        current, info = config_read(parent, path.name, account)
        if current == original and record['existed'] or current is None and not record['existed']:
            return {'ok': True, 'restored': False}
        if (current is None or hashlib.sha256(current).hexdigest() != record['installed_sha256']
                or info.st_uid != record['uid'] or info.st_gid != record['gid'] or stat.S_IMODE(info.st_mode) != record['mode']):
            raise PermissionRecoveryError('FreePBX chown configuration changed after protection; preserve the snapshot for review')
        if not record['changed']: return {'ok': True, 'restored': False}
        if record['existed']:
            write_atomic(parent, path, original, record['uid'], record['gid'], record['mode'], info, account)
        else:
            current_parent(parent, path.parent, account); current_name(parent, path.name, info)
            os.unlink(path.name, dir_fd=parent); os.fsync(parent)
            current_parent(parent, path.parent, account); current_name(parent, path.name, None)
        return {'ok': True, 'restored': True}
    finally: os.close(parent)


def remove_chown(path):
    if os.geteuid() != 0: raise PermissionRecoveryError('SLS chown removal requires root')
    account = pwd.getpwnam('asterisk'); path = Path(path); parent = directory(path.parent, account)
    try:
        body, info = config_read(parent, path.name, account)
        if body is None or b'BEGIN SLS MASS NOTIFY DATA PERMISSIONS' not in body:
            return {'ok': True, 'changed': False}
        if not body.endswith(CHOWN_BLOCK) or body.count(CHOWN_BLOCK) != 1:
            raise PermissionRecoveryError('The managed SLS exclusion was edited or followed by administrator changes; review before removing it')
        write_atomic(parent, path, body[:-len(CHOWN_BLOCK)], info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), info, account)
        return {'ok': True, 'changed': True}
    finally: os.close(parent)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--data', default=DATA)
    parser.add_argument('--approval')
    parser.add_argument('--chown-config', default='/etc/asterisk/freepbx_chown.conf')
    parser.add_argument('--snapshot')
    parser.add_argument('action', choices=['repair', 'protect-chown', 'restore-chown', 'remove-chown'])
    args = parser.parse_args()
    if args.action in ('repair', 'protect-chown') and not args.approval: parser.error('this action requires --approval')
    if args.action in ('protect-chown', 'restore-chown') and not args.snapshot: parser.error('this action requires --snapshot')
    try:
        if args.action == 'repair': result = repair(args.data, args.approval)
        elif args.action == 'protect-chown': result = protect_chown(args.chown_config, args.snapshot, args.approval)
        elif args.action == 'restore-chown': result = restore_chown(args.chown_config, args.snapshot)
        else: result = remove_chown(args.chown_config)
    except (OSError, KeyError, PermissionRecoveryError) as error:
        print('SLS legacy permission recovery: ' + str(error), file=sys.stderr)
        return 1
    print(json.dumps(result, separators=(',', ':')))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
