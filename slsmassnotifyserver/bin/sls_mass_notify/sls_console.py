#!/usr/bin/python3
"""Manage SLS runtime, inspect installation health, or uninstall the signed module."""
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
import types
import hashlib
import re
import shutil
import tempfile

sys.dont_write_bytecode = True
DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
SERVICE = 'sls-mass-notify-phone-events.service'
UNINSTALLER = Path('/usr/local/bin/sls_mass_notify/sls_mass_notify_uninstall.sh')
TRUST_ROOT = Path('/var/lib/sls-mass-notify-trust')


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


def authenticate_uninstaller(trust, *, path=UNINSTALLER, root=TRUST_ROOT):
    """Authorize the fixed teardown script without requiring working collectors."""
    path = Path(path)
    protected_file(path)
    data, generation = trust.load(Path(root), 'slsmassnotifyserver')
    name = 'bin/sls_mass_notify_uninstall.sh'
    if data.get('source', {}).get('kind') != 'publisher-ed25519' or name not in data['files']:
        raise RuntimeError('The bundled uninstaller lacks publisher-approved installation evidence.')
    entry = data['files'][name]
    if entry['target'] != 'admin/modules/slsmassnotifyserver/' + name:
        raise RuntimeError('The bundled uninstaller destination is invalid.')
    approved = trust.read(generation / 'module' / name, 2 * 1024 * 1024, protected=True)
    installed = trust.read(path, 2 * 1024 * 1024, protected=True)
    if trust.digest(approved) != entry['sha256'] or installed != approved:
        raise RuntimeError('The bundled uninstaller differs from its signed release. Preserve the installation evidence and review the changed file.')
    return path


def load_uninstall_trust(root=TRUST_ROOT):
    """Use retained signed source even when an unrelated runtime repair failed."""
    root = Path(root)
    def read(path, maximum):
        protected_file(path)
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
        with os.fdopen(fd, 'rb') as source:
            before = os.fstat(source.fileno())
            current = Path(path).lstat()
            if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_mode & 0o022
                    or before.st_nlink != 1 or before.st_size > maximum
                    or (before.st_dev, before.st_ino) != (current.st_dev, current.st_ino)):
                raise RuntimeError('Uninstall evidence is unsafe or exceeds its size limit.')
            body = source.read(maximum + 1)
            after = os.fstat(source.fileno())
            if len(body) != before.st_size or (before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
                raise RuntimeError('Uninstall evidence changed while being read.')
            return body
    reference = json.loads(read(root / 'slsmassnotifyserver.active.json', 1024))
    if not isinstance(reference, dict):
        raise RuntimeError('The protected SLS publisher reference is invalid.')
    digest = reference.get('sha256', '')
    if not isinstance(digest, str) or not re.fullmatch('[a-f0-9]{64}', digest) or reference.get('generation') != 'slsmassnotifyserver-' + digest:
        raise RuntimeError('The protected SLS publisher generation is invalid.')
    generation = root / reference['generation']
    raw = read(generation / 'inventory.json', 8 * 1024 * 1024)
    if hashlib.sha256(raw).hexdigest() != digest:
        raise RuntimeError('The protected SLS publisher inventory changed.')
    inventory = json.loads(raw)
    if not isinstance(inventory, dict) or not isinstance(inventory.get('files'), dict):
        raise RuntimeError('The protected SLS publisher inventory is invalid.')
    name = 'bin/sls_mass_notify/sls_module_trust.py'
    entry = inventory.get('files', {}).get(name)
    path = generation / 'module' / name
    body = read(path, 2 * 1024 * 1024)
    if not isinstance(entry, dict) or hashlib.sha256(body).hexdigest() != entry.get('sha256'):
        raise RuntimeError('The retained SLS trust helper changed.')
    trust = types.ModuleType('_console_uninstall_trust'); trust.__file__ = str(path)
    exec(compile(body, str(path), 'exec'), trust.__dict__)
    data, checked = trust.load(root, 'slsmassnotifyserver')
    if checked != generation or data['source']['kind'] != 'publisher-ed25519':
        raise RuntimeError('An existing publisher-approved SLS generation is required.')
    return trust


def reconcile_stock_command(*, root=TRUST_ROOT, packages=None, online=False):
    trust=load_uninstall_trust(root)
    data,generation=trust.load(Path(root),'slsmassnotifyserver')
    required=('sls_upgrade_trust.py','sls_release_trust.py','sls_module_trust.py','freepbx-mirror-signing.pub')
    source=generation/'module/bin/sls_mass_notify'
    for filename in required:
        name='bin/sls_mass_notify/'+filename;entry=data['files'].get(name)
        body=trust.read(source/filename,2*1024*1024,protected=True)
        if not entry or trust.digest(body)!=entry['sha256']:
            raise RuntimeError('The retained stock-reconciliation helper changed. Preserve the publisher evidence and run verified repair.')
    path=source/'sls_upgrade_trust.py'
    upgrade=types.ModuleType('_console_stock_reconciliation');upgrade.__file__=str(path)
    exec(compile(trust.read(path,2*1024*1024,protected=True),str(path),'exec'),upgrade.__dict__)
    lock=upgrade.coordination_lock(Path('/run/lock/sls-mass-notify-maintenance.lock'))
    workspace=None
    try:
        workspace=Path(tempfile.mkdtemp(prefix='stock-review-',dir=root))
        result=upgrade.reconcile_stock(workspace,Path(root),packages=packages,online=online)
    except BaseException as error:
        location=' Review evidence retained at '+str(workspace) if workspace is not None else ' The private review workspace could not be created.'
        raise RuntimeError(str(error)+location) from error
    else:
        shutil.rmtree(workspace)
        return result
    finally:os.close(lock)


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
    parser.add_argument('command', nargs='?', default='help', choices=('start', 'stop', 'reboot', 'status', 'doctor', 'reconcile-stock', 'uninstall', 'help'))
    parser.add_argument('--json', action='store_true', help='print a bounded, secret-free status object')
    parser.add_argument('--purge', action='store_true', help='with uninstall only: permanently delete retained SLS settings, media and history')
    parser.add_argument('--online',action='store_true',help='with reconcile-stock only: obtain independently signed current and prior FreePBX stock packages')
    parser.add_argument('--upstream-packages',type=Path,help='with reconcile-stock only: directory containing local signed FreePBX packages')
    args = parser.parse_args()
    if (args.online or args.upstream_packages) and args.command!='reconcile-stock':parser.error('Stock package options require reconcile-stock')
    if args.purge and args.command != 'uninstall':
        parser.error('--purge is available only with uninstall')
    if args.json and args.command == 'uninstall':
        parser.error('uninstall reports its teardown progress as text; omit --json')
    if args.command == 'help':
        parser.print_help()
        return 0
    if os.geteuid() != 0:
        raise RuntimeError('Use sudo slsconsole ' + args.command + '; runtime control requires root.')
    protected_file(__file__)
    if args.command=='reconcile-stock':
        result=reconcile_stock_command(packages=args.upstream_packages,online=args.online)
        print(json.dumps(result,separators=(',',':')) if args.json else 'Signed FreePBX stock approvals reconciled. Changed modules: '+(', '.join(result['changed_modules']) or 'none')+'. No PBX settings or delivery queues were changed.')
        return 0
    if args.command == 'uninstall':
        path = authenticate_uninstaller(load_uninstall_trust())
        environment = dict(os.environ)
        environment['SLS_MASS_NOTIFY_PURGE_CONFIG'] = '1' if args.purge else '0'
        os.execve('/usr/bin/bash', ['/usr/bin/bash', str(path)], environment)
        return 0
    if args.command == 'doctor':
        doctor=load('sls_install_doctor')
        # The doctor is also useful when configuration or installation trust is
        # damaged. It performs bounded reads only and never starts root repairs.
        sys.argv=[str(Path(__file__).with_name('sls_install_doctor.py'))]+(['--json'] if args.json else [])
        return doctor.main()
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
