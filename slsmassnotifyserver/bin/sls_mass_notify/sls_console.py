#!/usr/bin/python3
"""Pause notification admission and manage the fixed SLS phone collector."""
from contextlib import contextmanager
import argparse
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import pwd
import stat
import subprocess
import sys

sys.dont_write_bytecode = True
DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
SERVICE = 'sls-mass-notify-phone-events.service'


def running(settings):
    value = settings.get('runtime_enabled', True)
    if type(value) is not bool:
        raise RuntimeError('runtime_enabled must be a boolean; notification admission is blocked.')
    return value


def protected_file(path):
    path = Path(path).absolute()
    for item in [path, *path.parents]:
        info = item.lstat()
        if stat.S_ISLNK(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
            raise RuntimeError('Console runtime must be root-owned and protected: ' + str(item))
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise RuntimeError('Console runtime is not a single-link regular file.')


def load(name):
    path = Path(__file__).absolute().with_name(name + '.py')
    protected_file(path)
    spec = importlib.util.spec_from_file_location('_console_' + name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class RuntimeConsole:
    def __init__(self, crypto, run, *, data=DATA, keyring=None):
        self.crypto, self.run, self.data, self.keyring = crypto, run, Path(data), keyring
        account = pwd.getpwnam('asterisk')
        self.uid, self.gid = account.pw_uid, account.pw_gid

    @contextmanager
    def activity(self):
        path = self.data / 'announcement-activity.lock'
        with self.crypto._parent(path) as (parent, name):
            flags = os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
            try:
                fd = os.open(name, flags | os.O_CREAT | os.O_EXCL, 0o640, dir_fd=parent)
                os.fchown(fd, self.uid, self.gid)
            except FileExistsError:
                fd = os.open(name, flags, dir_fd=parent)
            try:
                info = os.fstat(fd)
                current = os.stat(name, dir_fd=parent, follow_symlinks=False)
                if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != self.uid or info.st_mode & 0o027 or (info.st_dev, info.st_ino) != (current.st_dev, current.st_ino):
                    raise RuntimeError('SLS activity lock is unsafe; no setting changed.')
                fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
                yield
            finally:
                os.close(fd)

    def read(self):
        return self.crypto.read_config(self.data / 'mass-notifications.config', self.keyring, allow_legacy=False)

    def set_running(self, value):
        with self.activity(), self.crypto._lock(self.data / 'mass-notifications.config.lock', self.uid, self.gid):
            files = []
            # The active file commits last; an interrupted resume stays paused.
            for name in ('mass-notifications.pending.config', 'mass-notifications.config'):
                path = self.data / name
                if name.startswith('mass-notifications.pending') and not (path.exists() or path.is_symlink()):
                    continue
                raw = self.crypto._read_protected(path, self.crypto.MAX_FILE_BYTES)
                settings = self.crypto.decode_config(raw, self.keyring, allow_legacy=False)
                running(settings)
                changed = settings.get('runtime_enabled', True) != value
                if changed:
                    settings['runtime_enabled'] = value
                    files.append((path, raw, self.crypto.encode_config(settings, self.keyring)))
            committed = []
            try:
                for path, before, sealed in files:
                    self.crypto._atomic_write(path, sealed, self.uid, self.gid)
                    committed.append((path, before))
            except BaseException:
                for path, before in reversed(committed):
                    self.crypto._atomic_write(path, before, self.uid, self.gid)
                raise

    def service(self, action):
        return self.run(['/usr/bin/systemctl', action, SERVICE], timeout=25)

    def status(self):
        settings = self.read()
        result = self.run(['/usr/bin/systemctl', 'show', '--property=ActiveState,SubState', SERVICE], timeout=10)
        fields = dict(line.split('=', 1) for line in result.stdout.decode('ascii', 'replace').splitlines() if '=' in line)
        return {'runtime': 'running' if running(settings) else 'stopped', 'notification_admission': running(settings),
                'phone_collector': fields.get('ActiveState', 'unknown'), 'collector_state': fields.get('SubState', 'unknown')}

    def execute(self, command):
        if command == 'status':
            return self.status()
        with self.activity(), self.crypto._lock(self.data / 'console-control.lock', self.uid, self.gid):
            previous = running(self.read())
            if command == 'stop':
                self.set_running(False)
            elif command == 'start':
                self.service('start')
                self.set_running(True)
            elif command == 'reboot':
                self.set_running(False)
                # A failed restart leaves admission paused. Existing PBX calls
                # are never killed and no prior announcement is replayed.
                self.service('restart')
                self.set_running(previous)
            else:
                raise ValueError('Unknown SLS console command.')
            return self.status()


def main():
    parser = argparse.ArgumentParser(prog='slsconsole', description=__doc__, epilog='stop pauses new SLS sends; existing calls and receipt tracking continue. reboot restarts only the SLS collector and preserves a stopped state. Configuration, credentials and PBX services remain intact.')
    parser.add_argument('command', nargs='?', default='help', choices=('start', 'stop', 'reboot', 'status', 'help'))
    parser.add_argument('--json', action='store_true', help='print a bounded, secret-free status object')
    args = parser.parse_args()
    if args.command == 'help':
        parser.print_help()
        return 0
    if os.geteuid() != 0:
        raise RuntimeError('Use sudo slsconsole ' + args.command + '; runtime control requires root.')
    protected_file(__file__)
    crypto = load('sls_config_crypto')
    installer = load('sls_privileged_install')
    installer.protect_entrypoint()
    installer.PrivilegedInstall(installer.load_trust(), Path('/var/lib/sls-mass-notify-trust')).admit()
    console = RuntimeConsole(crypto, installer.runner)
    status = console.execute(args.command)
    if args.json:
        print(json.dumps(status, separators=(',', ':')))
    else:
        print('SLS Mass Notify: ' + status['runtime'])
        print('New notifications: ' + ('enabled' if status['notification_admission'] else 'paused'))
        print('Phone receipt collector: ' + status['phone_collector'] + ' (' + status['collector_state'] + ')')
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except BlockingIOError:
        print('SLS console: protected maintenance or another console operation is active. Wait for it to finish and check slsconsole status before retrying.', file=sys.stderr)
        raise SystemExit(1)
    except (OSError, RuntimeError, ValueError, subprocess.SubprocessError) as error:
        print('SLS console: ' + str(error), file=sys.stderr)
        raise SystemExit(1)
