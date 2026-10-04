#!/usr/bin/python3
"""Build Piper at its canonical path in a private mount namespace, then swap it.

No package installation targets the host's active venv. A directory exchange
retains the previous environment until post-activation validation succeeds.
"""
import argparse
import ctypes
import fcntl
import json
import os
from pathlib import Path
import re
import secrets
import select
import shutil
import signal
import stat
import subprocess
import sys
import time

sys.dont_write_bytecode = True
RUNTIME = Path('/usr/local/bin/sls_mass_notify')
DATA = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin'
STAGE = re.compile(r'\.replacement-[a-f0-9]{24}')
FLAGS = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC


class EnvironmentError(RuntimeError):
    pass


def protected_directory(path):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise EnvironmentError('Piper replacement requires canonical absolute directories.')
    fd = os.open('/', FLAGS)
    try:
        for part in path.parts[1:]:
            child = os.open(part, FLAGS, dir_fd=fd)
            os.close(fd); fd = child
            info = os.fstat(fd)
            if info.st_uid != 0 or info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX:
                raise EnvironmentError('Piper replacement refused an unprotected ancestor: ' + str(path))
        info = os.fstat(fd)
        if info.st_uid != 0 or info.st_mode & 0o022:
            raise EnvironmentError('Piper replacement directory must be root-owned and not group/world writable.')
        return fd
    except BaseException:
        os.close(fd)
        raise


def identity(parent, name):
    info = os.stat(name, dir_fd=parent, follow_symlinks=False)
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise EnvironmentError('Unsafe Piper environment directory: ' + name)
    return info.st_dev, info.st_ino


def exchange(parent, name, other, other_name):
    libc = ctypes.CDLL(None, use_errno=True)
    function = getattr(libc, 'renameat2', None)
    if function is None:
        raise EnvironmentError('This libc lacks atomic directory exchange; the existing Piper environment was preserved.')
    function.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    function.restype = ctypes.c_int
    if function(parent, name.encode(), other, other_name.encode(), 2) != 0:
        number = ctypes.get_errno()
        raise EnvironmentError('Atomic Piper directory exchange failed (errno %d). Check filesystem support and free space; no in-place upgrade is attempted.' % number)


def system_python(path):
    for _ in range(8):
        if not re.fullmatch(r'/usr/bin/python3(?:\.[0-9]{1,2})?', path):
            raise EnvironmentError('Unexpected distribution interpreter link.')
        parent = protected_directory('/usr/bin')
        try:
            name = Path(path).name
            info = os.stat(name, dir_fd=parent, follow_symlinks=False)
            if info.st_uid != 0 or (not stat.S_ISLNK(info.st_mode) and info.st_mode & 0o022):
                raise EnvironmentError('Distribution Python is not root protected.')
            if stat.S_ISLNK(info.st_mode):
                target = os.readlink(name, dir_fd=parent)
                path = target if target.startswith('/') else '/usr/bin/' + target
                continue
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
                raise EnvironmentError('Unsafe distribution Python executable.')
            return
        finally: os.close(parent)
    raise EnvironmentError('Distribution Python link cycle.')


def validate_tree(path, synchronize=False):
    """Only ordinary root files and standard venv interpreter/lib64 links."""
    parent = protected_directory(path); count = 0; deadline = time.monotonic() + 60
    def walk(fd, relative=''):
        nonlocal count
        for name in os.listdir(fd):
            count += 1
            if count > 50000 or time.monotonic() > deadline:
                raise EnvironmentError('Piper environment inspection exceeded its file/time bound.')
            rel = relative + '/' + name if relative else name
            before = os.stat(name, dir_fd=fd, follow_symlinks=False)
            if before.st_uid != 0 or (not stat.S_ISLNK(before.st_mode) and before.st_mode & 0o022):
                raise EnvironmentError('Piper environment contains unprotected entries.')
            if stat.S_ISLNK(before.st_mode):
                target = os.readlink(name, dir_fd=fd)
                if rel == 'lib64' and target == 'lib':
                    continue
                if (re.fullmatch(r'bin/python(?:3(?:\.[0-9]{1,2})?)?', rel)
                        and re.fullmatch(r'(?:/usr/bin/)?python3(?:\.[0-9]{1,2})?', target)):
                    if target.startswith('/'): system_python(target)
                    continue
                raise EnvironmentError('Piper environment contains an unexpected symbolic link.')
            directory = stat.S_ISDIR(before.st_mode)
            if not directory and (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1):
                raise EnvironmentError('Piper environment contains a hard link or special file.')
            child = os.open(name, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK
                            | (os.O_DIRECTORY if directory else 0), dir_fd=fd)
            try:
                opened = os.fstat(child)
                if (opened.st_dev, opened.st_ino, opened.st_mode, opened.st_nlink) != (
                        before.st_dev, before.st_ino, before.st_mode, before.st_nlink):
                    raise EnvironmentError('Piper environment changed during inspection.')
                if directory: walk(child, rel)
                if synchronize: os.fsync(child)
            finally:
                os.close(child)
        if synchronize: os.fsync(fd)
    try: walk(parent)
    finally: os.close(parent)


def checked(command, timeout=30):
    result = subprocess.run(command, stdin=subprocess.DEVNULL, timeout=timeout, check=False)
    if result.returncode:
        raise EnvironmentError('Piper replacement command failed (exit %d): %s. Review the preceding installer output.'
                               % (result.returncode, Path(command[0]).name))


def validate_runtime(root):
    python = str(root / 'venv/bin/python')
    validate_tree(root / 'venv')
    checked([python, '-I', '-c', 'import os,sys;sys.exit(0 if sys.prefix != sys.base_prefix and os.path.realpath(sys.prefix)==os.path.realpath(sys.argv[1]) else 1)', str(root / 'venv')])
    checked([python, '-I', str(RUNTIME / 'sls_piper_dependencies.py'), '--requirements', str(RUNTIME / 'piper-requirements.txt')])
    checked([python, '-I', '-m', 'pip', 'check'])
    checked([python, '-I', '-m', 'piper', '-h'])
    checked([str(root / 'venv/bin/piper'), '-h'])


def build_private(stage, root, wheelhouse=None):
    # A direct invocation must never hide/overwrite the host's active venv.
    if os.readlink('/proc/self/ns/mnt') == os.readlink('/proc/%d/ns/mnt' % os.getppid()):
        raise EnvironmentError('Piper construction requires a separate private mount namespace.')
    for path in (stage, stage / 'venv', root, root / 'venv'):
        fd = protected_directory(path); os.close(fd)
    checked(['/usr/bin/mount', '--bind', str(stage / 'venv'), str(root / 'venv')])
    os.umask(0o022)
    checked(['/usr/bin/python3', '-I', '-m', 'venv', str(root / 'venv')], 120)
    python = str(root / 'venv/bin/python')
    source = ['--index-url', 'https://pypi.org/simple']
    if wheelhouse is not None:
        fd = protected_directory(wheelhouse); os.close(fd)
        source = ['--no-index', '--find-links', str(wheelhouse)]
    for lock in ('piper-packaging.lock', 'piper-requirements.lock'):
        checked([python, '-I', '-m', 'pip', '--isolated', 'install', '--no-cache-dir', '--only-binary=:all:',
                 '--require-hashes', '--no-input', *source, '-r', str(RUNTIME / lock)], 180)
    validate_runtime(root)
    validate_tree(root / 'venv', synchronize=True)


def guard_workers():
    process = subprocess.Popen(['/usr/bin/python3', '-I', str(RUNTIME / 'sls_install_guard.py'),
                                '--data', DATA, '--timeout', '120', 'hold'],
                               stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
    try:
        ready, _, _ = select.select([process.stdout], [], [], 125)
        if not ready or process.stdout.readline().strip() != 'ready':
            raise EnvironmentError('Notification workers did not become idle; the active Piper environment was preserved.')
        return process
    except BaseException:
        process.terminate(); process.communicate(timeout=5)
        raise


def replace_environment(root, builder, validator, workers_paused=False):
    """Injectable builder/validator allow private fault tests without package I/O."""
    parent = protected_directory(root); lock = stage_fd = None; stage = None; guard = None
    exchanged = False; committed = False; original = replacement = None
    try:
        lock = os.open('.replacement.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
                       0o600, dir_fd=parent)
        info = os.fstat(lock)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1 or info.st_mode & 0o077:
            raise EnvironmentError('Unsafe Piper replacement lock; no interpreter was executed.')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        leftovers = [name for name in os.listdir(parent) if STAGE.fullmatch(name)]
        if len(leftovers) >= 3:
            raise EnvironmentError('Three Piper recovery directories remain under ' + str(root) + '. Review them before creating another replacement; the active environment was preserved.')
        # A fresh installation needs an empty bind target. Existing content is
        # never removed or passed to pip on the host.
        try: original = identity(parent, 'venv')
        except FileNotFoundError:
            os.mkdir('venv', 0o755, dir_fd=parent); os.fsync(parent); original = identity(parent, 'venv')
        validate_tree(root / 'venv')
        stage = root / ('.replacement-' + secrets.token_hex(12))
        os.mkdir(stage.name, 0o700, dir_fd=parent)
        stage_fd = os.open(stage.name, FLAGS, dir_fd=parent)
        os.mkdir('venv', 0o755, dir_fd=stage_fd)
        os.fsync(stage_fd); os.fsync(parent)
        replacement = identity(stage_fd, 'venv')
        builder(stage, lock)
        validate_tree(stage / 'venv', synchronize=True)
        if not workers_paused: guard = guard_workers()
        if identity(parent, 'venv') != original or identity(stage_fd, 'venv') != replacement:
            raise EnvironmentError('A Piper environment changed during preparation; no directory was activated.')
        exchange(parent, 'venv', stage_fd, 'venv'); exchanged = True
        os.fsync(parent); os.fsync(stage_fd)
        validator(root)
        # Check both inode identities again before removing the old tree.
        if identity(parent, 'venv') != replacement or identity(stage_fd, 'venv') != original:
            raise EnvironmentError('Piper activation identities changed; preserve the recovery directory.')
        if not shutil.rmtree.avoids_symlink_attacks:
            raise EnvironmentError('Safe Piper recovery cleanup is unavailable; preserve the recovery directory.')
        validate_tree(stage / 'venv')
        committed = True
        shutil.rmtree('venv', dir_fd=stage_fd)
        os.fsync(stage_fd); os.rmdir(stage.name, dir_fd=parent); os.fsync(parent)
        stage = None
        return {'ok': True, 'replaced': True}
    except BaseException as error:
        # Once cleanup has started, the original may be incomplete. Never swap
        # a partially deleted old tree back into service.
        if committed:
            return {'ok': True, 'replaced': True, 'cleanup_pending': True,
                    'recovery_directory': str(stage),
                    'warning': 'The new Piper environment passed validation. Old-environment cleanup was incomplete; preserve the recovery directory for review.'}
        if exchanged and stage is not None:
            try:
                if identity(parent, 'venv') != replacement or identity(stage_fd, 'venv') != original:
                    raise EnvironmentError('Piper rollback identities changed.')
                exchange(parent, 'venv', stage_fd, 'venv')
                os.fsync(parent); os.fsync(stage_fd)
            except (OSError, EnvironmentError):
                raise EnvironmentError('Piper rollback could not be verified. Preserve recovery directory ' + str(stage) + '.') from error
        if isinstance(error, BlockingIOError):
            detail = 'Another Piper replacement is running; retry after it finishes.'
        elif isinstance(error, OSError):
            detail = 'Piper filesystem operation failed (errno %s): %s.' % (error.errno, error.strerror)
        else:
            detail = str(error) if isinstance(error, EnvironmentError) else type(error).__name__
        if stage is not None: detail += ' Recovery directory: ' + str(stage) + '.'
        raise EnvironmentError(detail) from error
    finally:
        if guard is not None:
            guard.communicate('release\n', timeout=5)
        for fd in (stage_fd, lock, parent):
            if fd is not None: os.close(fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--workers-paused', action='store_true', help='Authenticated installer/repair already owns worker leases.')
    parser.add_argument('--wheelhouse', type=Path)
    parser.add_argument('--build-private', type=Path, help=argparse.SUPPRESS)
    args = parser.parse_args()
    if os.geteuid() != 0: parser.error('Piper replacement requires root.')
    root = RUNTIME / 'piper'
    try:
        if args.build_private is not None:
            stage = args.build_private
            if stage.parent != root or not STAGE.fullmatch(stage.name):
                raise EnvironmentError('Invalid private Piper build directory.')
            build_private(stage, root, args.wheelhouse)
        else:
            def builder(stage, lock):
                command = ['/usr/bin/unshare', '--mount', '--propagation', 'private', '/usr/bin/python3', '-I',
                           str(Path(__file__).resolve()), '--build-private', str(stage)]
                if args.wheelhouse is not None: command += ['--wheelhouse', str(args.wheelhouse)]
                process = subprocess.Popen(command, stdin=subprocess.DEVNULL, pass_fds=(lock,), start_new_session=True)
                try:
                    process.wait(timeout=600)
                except BaseException:
                    try: os.killpg(process.pid, signal.SIGTERM)
                    except ProcessLookupError: pass
                    try: process.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        try: os.killpg(process.pid, signal.SIGKILL)
                        except ProcessLookupError: pass
                        process.wait()
                    raise
                if process.returncode:
                    raise EnvironmentError('Private Piper construction failed. Check mount namespace permission, Python venv support, wheel hashes, network/offline source and free space. The existing environment was preserved.')
            print(json.dumps(replace_environment(root, builder, validate_runtime, args.workers_paused)))
        return 0
    except (OSError, RuntimeError, subprocess.SubprocessError) as error:
        print(str(error), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
