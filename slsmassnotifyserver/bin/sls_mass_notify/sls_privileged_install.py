#!/usr/bin/python3
"""Fixed SLS installation operations; no FreePBX/PHP bootstrap runs as root.

Executable and web bytes come only from the authenticated, protected SLS
release generation. Configuration supplies a bounded retention integer and an optional verified IANA
timezone, never commands, paths, service names or executable contents. No operations run on import.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
from pathlib import Path

import sys as _config_sys
_config_sys.dont_write_bytecode = True
_config_crypto_spec = importlib.util.spec_from_file_location("sls_config_crypto", Path(__file__).resolve().with_name("sls_config_crypto.py"))
_config_crypto = importlib.util.module_from_spec(_config_crypto_spec)
_config_crypto_spec.loader.exec_module(_config_crypto)
import pwd
import re
import selectors
import signal
import time
import stat
import subprocess
import sys

# -I ignores PYTHONDONTWRITEBYTECODE. Authenticated source generations must never
# acquire interpreter-generated files when importing the adjacent trust helper.
sys.dont_write_bytecode = True

DATA = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin'
RUNTIME = '/usr/local/bin/sls_mass_notify'
WEB = '/var/www/html'
CONFIG = DATA + '/mass-notifications.config'
SERVICE = 'sls-mass-notify-phone-events.service'
SERVICE_PATH = '/etc/systemd/system/' + SERVICE
APACHE_PATH = '/etc/apache2/conf-available/sls-mass-notify.conf'
LOGROTATE_PATH = '/etc/logrotate.d/sls-mass-notify'
SIGNER_PATH = '/usr/local/sbin/sign_sls_mass_notify_local_sig.sh'
CONSOLE_PATH = '/usr/local/bin/slsconsole'
CRON_LINES = (
    '* * * * * /usr/bin/timeout 900 /usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh',
    '17 * * * * /usr/bin/timeout 1800 /usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh',
)
UNIT = '''# Managed by SLS Mass Notify Server
[Unit]
Description=SLS Mass Notify phone outcome collector
After=network.target asterisk.service freepbx.service
StartLimitIntervalSec=0

[Service]
Type=simple
User=asterisk
Group=asterisk
UMask=0027
ExecStart=/usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_phone_events.py
Restart=on-failure
RestartSec=5
TimeoutStopSec=10
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=/var/lib/asterisk/SLS_Mass_Notifications_Plugin
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
RestrictSUIDSGID=yes
LockPersonality=yes

[Install]
WantedBy=multi-user.target
'''
APACHE = '''# Southland Servers Mass Notifications Server
<Directory /var/www/html/api/sipnotify>
    Require all granted
    Options -Indexes
    AllowOverride All
    SetEnvIfNoCase Authorization "^(.*)$" HTTP_AUTHORIZATION=$1
</Directory>
<Directory /var/www/html/api/sls-mass-notify>
    Require all granted
    Options -Indexes
    AllowOverride All
    SetEnvIfNoCase Authorization "^(.*)$" HTTP_AUTHORIZATION=$1
</Directory>
<Directory /var/www/html/mass-notify>
    Require all granted
    Options -Indexes -MultiViews
    AllowOverride All
</Directory>
<Directory /var/www/html/sls_mass_notify>
    Require all granted
    Options -Indexes -MultiViews
    AllowOverride None
    RewriteEngine On
    RewriteRule ^assets/[A-Za-z0-9_.-]+\\.(?:png|jpg|svg|ico)$ - [END]
    RewriteRule ^([A-Za-z0-9][A-Za-z0-9_.-]{0,179}\\.(?:png|xml))$ /api/sls-mass-notify/media.php?file=$1 [END]
    RewriteRule ^ - [F,END]
</Directory>
'''


class InstallError(RuntimeError):
    pass


def load_trust():
    path = Path(__file__).with_name('sls_module_trust.py')
    spec = importlib.util.spec_from_file_location('_sls_root_module_trust', path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def protect_entrypoint():
    """Check before importing another root program; no web-writable parents."""
    for path in (Path(__file__).absolute(), Path(__file__).absolute().with_name('sls_module_trust.py')):
        for item in [path] + list(path.parents)[:-1]:
            info = item.lstat()
            if (stat.S_ISLNK(info.st_mode) or info.st_uid != 0
                    or info.st_mode & 0o022 and not (item != path and info.st_mode & stat.S_ISVTX)):
                raise InstallError('privileged installer or parent is writable outside root')
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise InstallError('privileged installer helper is not a single-link regular file')


class Files:
    """Descriptor-relative fixed destination operations; prefix is test-only."""
    def __init__(self, prefix=Path('/')):
        self.prefix = Path(prefix)
        if not self.prefix.is_absolute():
            raise InstallError('filesystem root must be absolute')
        self.account = pwd.getpwnam('asterisk')

    def path(self, path):
        path = Path(path)
        if not path.is_absolute() or '..' in path.parts:
            raise InstallError('noncanonical destination')
        return self.prefix.joinpath(*path.parts[1:])

    def directory(self, path, *, create=False):
        target = self.path(path)
        protected = str(path).startswith(('/usr/', '/etc/'))
        fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
        try:
            for part in target.parts[1:]:
                if create:
                    try: os.mkdir(part, 0o755, dir_fd=fd)
                    except FileExistsError: pass
                child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
                os.close(fd); fd = child
                info = os.fstat(fd)
                if protected and (info.st_uid != 0 or info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX):
                    raise InstallError('privileged destination ancestor is writable outside root: ' + str(target))
            result, fd = fd, -1
            return result
        finally:
            if fd >= 0: os.close(fd)

    def metadata(self, path):
        parent = self.directory(str(Path(path).parent))
        try:
            try: return os.stat(Path(path).name, dir_fd=parent, follow_symlinks=False)
            except FileNotFoundError: return None
        finally: os.close(parent)

    def mkdir(self, path, mode, user=False):
        fd = self.directory(path, create=True)
        try:
            info = os.fstat(fd)
            if info.st_uid not in ((0, self.account.pw_uid) if user else (0,)):
                raise InstallError('unexpected directory owner: ' + path)
            os.fchown(fd, self.account.pw_uid if user else 0, self.account.pw_gid if user else 0)
            os.fchmod(fd, mode)
        finally: os.close(fd)

    @staticmethod
    def regular(info):
        return stat.S_ISREG(info.st_mode) and info.st_nlink == 1

    def read(self, path, limit=64 * 1024 * 1024):
        parent = self.directory(str(Path(path).parent)); fd = -1
        try:
            fd = os.open(Path(path).name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
            info = os.fstat(fd); current = os.stat(Path(path).name, dir_fd=parent, follow_symlinks=False)
            if not self.regular(info) or info.st_size > limit or (info.st_dev, info.st_ino) != (current.st_dev, current.st_ino):
                raise InstallError('unsafe or oversized file: ' + path)
            with os.fdopen(fd, 'rb') as stream:
                fd = -1; body = stream.read(limit + 1); after = os.fstat(stream.fileno())
            if len(body) > limit or (info.st_size, info.st_mtime_ns, info.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
                raise InstallError('file changed during read: ' + path)
            return body
        finally:
            if fd >= 0: os.close(fd)
            os.close(parent)

    def write(self, path, body, mode, *, user=False, preserve=False, managed_prefix=None):
        parent = self.directory(str(Path(path).parent), create=True)
        name = Path(path).name; temporary = None; fd = -1
        try:
            try: existing = os.stat(name, dir_fd=parent, follow_symlinks=False)
            except FileNotFoundError: existing = None
            if existing is not None:
                if not self.regular(existing) or existing.st_uid not in (0, self.account.pw_uid):
                    raise InstallError('refusing unsafe existing destination: ' + path)
                if preserve: return
                if managed_prefix is not None and not self.read(path).startswith(managed_prefix):
                    raise InstallError('refusing unrelated existing configuration: ' + path)
            temporary = '.sls-install-' + os.urandom(12).hex()
            fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=parent)
            with os.fdopen(fd, 'wb') as stream:
                fd = -1; stream.write(body); stream.flush()
                os.fchown(stream.fileno(), self.account.pw_uid if user else 0, self.account.pw_gid if user else 0)
                os.fchmod(stream.fileno(), mode); os.fsync(stream.fileno())
            os.replace(temporary, name, src_dir_fd=parent, dst_dir_fd=parent)
            temporary = None; os.fsync(parent)
        finally:
            if fd >= 0: os.close(fd)
            if temporary is not None:
                try: os.unlink(temporary, dir_fd=parent)
                except FileNotFoundError: pass
            os.close(parent)

    def touch(self, path):
        try: self.secure_file(path, 0o640, user=True)
        except FileNotFoundError:
            self.write(path, b'', 0o640, user=True, preserve=True)
            self.secure_file(path, 0o640, user=True)

    def secure_file(self, path, mode, *, user=False):
        parent = self.directory(str(Path(path).parent)); fd = -1
        try:
            fd = os.open(Path(path).name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
            info = os.fstat(fd); current = os.stat(Path(path).name, dir_fd=parent, follow_symlinks=False)
            if (not self.regular(info) or info.st_uid not in (0, self.account.pw_uid)
                    or (info.st_dev, info.st_ino, info.st_nlink) != (current.st_dev, current.st_ino, current.st_nlink)):
                raise InstallError('unsafe file permission target: ' + path)
            os.fchown(fd, self.account.pw_uid if user else 0, self.account.pw_gid if user else 0)
            os.fchmod(fd, mode)
        finally:
            if fd >= 0: os.close(fd)
            os.close(parent)

    def sound_link(self, path):
        parent = self.directory(str(Path(path).parent), create=True)
        name = Path(path).name
        try:
            try: info = os.stat(name, dir_fd=parent, follow_symlinks=False)
            except FileNotFoundError: info = None
            if info is not None:
                if not stat.S_ISLNK(info.st_mode) or os.readlink(name, dir_fd=parent) != DATA + '/sounds':
                    raise InstallError('refusing unrelated Asterisk sound path: ' + path)
            else:
                os.symlink(DATA + '/sounds', name, dir_fd=parent)
            os.chown(name, self.account.pw_uid, self.account.pw_gid, dir_fd=parent, follow_symlinks=False)
        finally: os.close(parent)

    def cleanup_legacy_links(self):
        """Unlink only named obsolete aliases; never inspect their targets."""
        try:
            parent = self.directory(DATA + '/links')
        except FileNotFoundError:
            return
        try:
            info = os.fstat(parent)
            if info.st_uid not in (0, self.account.pw_uid) or info.st_mode & 0o022:
                raise InstallError('legacy alias directory has unsafe ownership or permissions')
            for name in ('freepbx-module-nwsalerts', 'dashboard-announcement-section.php',
                         'dashboard-announcement-view.php', 'dedicated-sounds', 'live-nws-poller',
                         'manual-nws-test', 'mass-notify-api', 'visual-sip-notify-sender', 'web-assets'):
                try: entry = os.stat(name, dir_fd=parent, follow_symlinks=False)
                except FileNotFoundError: continue
                if stat.S_ISLNK(entry.st_mode):
                    os.unlink(name, dir_fd=parent)
            os.fsync(parent)
        finally:
            os.close(parent)

    def secure_tree(self, path, *, public=False):
        """Repair only ordinary SLS state; never traverse dependency links."""
        root = self.directory(path)
        visited = 0
        def visit(fd, relative=''):
            nonlocal visited
            info = os.fstat(fd)
            if info.st_uid not in (0, self.account.pw_uid):
                raise InstallError('unexpected state directory owner: ' + relative)
            for name in os.listdir(fd):
                visited += 1
                if visited > 25000:
                    raise InstallError('SLS state exceeds safe entry limit')
                rel = relative + '/' + name if relative else name
                before = os.stat(name, dir_fd=fd, follow_symlinks=False)
                if not public and rel == 'piper/venv':
                    if not stat.S_ISDIR(before.st_mode) or before.st_uid != 0 or before.st_gid != 0 or before.st_mode & 0o022:
                        raise InstallError('unsafe Piper compatibility directory')
                    continue
                flags = os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK
                if stat.S_ISDIR(before.st_mode): flags |= os.O_DIRECTORY
                elif not self.regular(before):
                    raise InstallError('unsafe SLS state entry: ' + rel)
                child = os.open(name, flags, dir_fd=fd)
                try:
                    opened = os.fstat(child)
                    current = os.stat(name, dir_fd=fd, follow_symlinks=False)
                    if ((opened.st_dev, opened.st_ino) != (before.st_dev, before.st_ino)
                            or (opened.st_dev, opened.st_ino) != (current.st_dev, current.st_ino)
                            or opened.st_uid not in (0, self.account.pw_uid)):
                        raise InstallError('SLS state changed during permission repair: ' + rel)
                    if stat.S_ISDIR(opened.st_mode): visit(child, rel)
                    else:
                        if not self.regular(opened): raise InstallError('unsafe SLS state file: ' + rel)
                        os.fchown(child, self.account.pw_uid, self.account.pw_gid)
                        mode = 0o644 if public or rel.startswith(('sounds/', 'piper/voices/')) else 0o640
                        if not public and rel.startswith(('event-log-recovery/', 'sms/', 'recovery-archives/', '.freepbx-restore-stage-')):
                            mode = 0o600
                        os.fchmod(child, mode)
                finally: os.close(child)
            os.fchown(fd, self.account.pw_uid, self.account.pw_gid)
            mode = 0o755 if public or relative not in ('', 'sipnotify', 'config-backups', 'piper') else 0o750
            if not public and (relative in ('event-log-recovery', 'sms', 'recovery-archives') or relative.startswith(('event-log-recovery/', 'sms/', 'recovery-archives/', '.freepbx-restore-stage-'))):
                mode = 0o700
            os.fchmod(fd, mode)
        try: visit(root)
        finally: os.close(root)

    def prune(self, path, expected, *, preserve=()):
        root = self.directory(path)
        count = 0
        def visit(fd, prefix=''):
            nonlocal count
            for name in os.listdir(fd):
                count += 1
                if count > 20000: raise InstallError('managed directory exceeds safe inventory limit')
                rel = prefix + name
                info = os.stat(name, dir_fd=fd, follow_symlinks=False)
                if not prefix and name in preserve:
                    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
                        raise InstallError('preserved dependency directory is unsafe: ' + rel)
                    continue
                if stat.S_ISDIR(info.st_mode):
                    child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
                    try: visit(child, rel + '/')
                    finally: os.close(child)
                    if not any(item.startswith(rel + '/') for item in expected):
                        os.rmdir(name, dir_fd=fd)
                elif not self.regular(info):
                    raise InstallError('unsafe managed runtime entry: ' + rel)
                elif rel not in expected:
                    os.unlink(name, dir_fd=fd)
        try: visit(root)
        finally: os.close(root)


def runner(arguments, *, timeout=30, input=None, allowed=(0,)):
    """Bound stdout/stderr while a command runs, including failure/timeout paths."""
    process = subprocess.Popen(arguments, stdin=subprocess.PIPE if input is not None else subprocess.DEVNULL,
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True,
                               env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C.UTF-8'})
    output = {'stdout': bytearray(), 'stderr': bytearray()}
    deadline = time.monotonic() + timeout
    pending = memoryview(input or b'')
    try:
        with selectors.DefaultSelector() as selector:
            for label in ('stdout', 'stderr'):
                stream = getattr(process, label)
                os.set_blocking(stream.fileno(), False)
                selector.register(stream, selectors.EVENT_READ, label)
            if process.stdin is not None:
                os.set_blocking(process.stdin.fileno(), False)
                if pending: selector.register(process.stdin, selectors.EVENT_WRITE, 'stdin')
                else: process.stdin.close()
            while selector.get_map():
                remaining = deadline - time.monotonic()
                if remaining <= 0: raise subprocess.TimeoutExpired(arguments, timeout)
                for key, event in selector.select(min(remaining, 1)):
                    if key.data == 'stdin':
                        try: pending = pending[os.write(key.fd, pending[:65536]):]
                        except BrokenPipeError: pending = pending[:0]
                        if not pending:
                            selector.unregister(key.fileobj); key.fileobj.close()
                        continue
                    chunk = os.read(key.fd, 65536)
                    if not chunk:
                        selector.unregister(key.fileobj); key.fileobj.close(); continue
                    if len(output[key.data]) + len(chunk) > 1024 * 1024:
                        raise InstallError('system command exceeded 1 MiB ' + key.data + ' limit: ' + Path(arguments[0]).name)
                    output[key.data].extend(chunk)
            remaining = deadline - time.monotonic()
            if remaining <= 0: raise subprocess.TimeoutExpired(arguments, timeout)
            code = process.wait(timeout=remaining)
    except BaseException:
        try: os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError: pass
        process.wait()
        raise
    finally:
        for stream in (process.stdin, process.stdout, process.stderr):
            if stream is not None and not stream.closed: stream.close()
    result = subprocess.CompletedProcess(arguments, code, bytes(output['stdout']), bytes(output['stderr']))
    if code not in allowed:
        detail = result.stderr.decode('utf-8', 'replace').strip()[-500:]
        raise InstallError(f'{Path(arguments[0]).name} failed with exit {code}: {detail}')
    return result


class PrivilegedInstall:
    def __init__(self, trust, trust_root, *, files=None, run=runner):
        self.trust = trust; self.trust_root = Path(trust_root)
        self.files = files or Files(); self.run = run
        data, generation = trust.load(self.trust_root, 'slsmassnotifyserver')
        if data['source']['kind'] != 'publisher-ed25519':
            raise InstallError('SLS privileged installation requires a publisher-verified generation')
        self.data = data; self.source = generation / 'module'
        actual = trust.inventory_tree(self.source, 'slsmassnotifyserver')
        if actual != set(data['files']):
            raise InstallError('immutable publisher generation has missing or additional code')
        self.contents = {}
        for name, entry in data['files'].items():
            body = trust.read(self.source / name, 64 * 1024 * 1024, protected=True)
            if trust.digest(body) != entry['sha256']:
                raise InstallError('immutable publisher generation changed: ' + name)
            self.contents[name] = body
        required = ('bin/sls_mass_notify_maintenance.sh', 'bin/sls_mass_notify_update.sh',
                    'bin/slsconsole', 'bin/sls_mass_notify/sls_console.py', 'bin/sls_mass_notify/sls_runtime_state.php',
                    'bin/sls_mass_notify_install_piper_voices.sh', 'bin/sign_sls_mass_notify_local_sig.sh',
                    'bin/sls_mass_notify/sls_phone_events.py', 'api/sipnotify/index.php', 'api/sls-mass-notify/index.php')
        missing = [name for name in required if name not in self.contents]
        if missing: raise InstallError('authenticated generation is incomplete: ' + ', '.join(missing))

    def copies(self):
        result = []
        for name, body in sorted(self.contents.items()):
            if name == 'bin/sign_sls_mass_notify_local_sig.sh':
                result.append((name, SIGNER_PATH, 0o755, False, False))
            elif name == 'bin/slsconsole':
                result.append((name, CONSOLE_PATH, 0o755, False, False))
            elif name.startswith('bin/sls_mass_notify/'):
                result.append((name, RUNTIME + '/' + name[len('bin/sls_mass_notify/'):], 0o755, False, False))
            elif name.startswith('bin/') and '/' not in name[4:]:
                result.append((name, RUNTIME + '/' + name[4:], 0o755, False, False))
            elif name.startswith(('api/sipnotify/', 'api/sls-mass-notify/')):
                result.append((name, WEB + '/' + name, 0o644, True, False))
            elif name.startswith('portal/'):
                result.append((name, WEB + '/mass-notify/' + name[len('portal/'):], 0o644, True, False))
            elif name.startswith('assets/'):
                result.append((name, WEB + '/sls_mass_notify/' + name, 0o644, True, False))
            elif name.startswith('sounds/'):
                result.append((name, DATA + '/' + name, 0o644, True, True))
        return result

    def copy_directories(self):
        """Only authenticated payload parents, with explicit traversal modes.

        mkdir's mode is reduced by the installer's umask. Preparing each known
        ancestor also repairs pre-existing root:root 0750 API package folders
        without changing unrelated web, system or protected-state directories.
        """
        directories = {}
        for source, target, _, user, _ in self.copies():
            if source in ('bin/sign_sls_mass_notify_local_sig.sh', 'bin/slsconsole'):
                continue
            if source.startswith('api/'):
                root = Path(WEB, 'api', source.split('/')[1])
            elif source.startswith('portal/'):
                root = Path(WEB, 'mass-notify')
            elif source.startswith('assets/'):
                root = Path(WEB, 'sls_mass_notify')
            elif source.startswith('sounds/'):
                root = Path(DATA, 'sounds')
            else:
                root = Path(RUNTIME)
            parent = Path(target).parent
            if not parent.is_relative_to(root):
                raise InstallError('payload directory escaped its managed root')
            while True:
                directories[str(parent)] = user
                if parent == root:
                    break
                parent = parent.parent
        return sorted(directories.items(), key=lambda row: (len(Path(row[0]).parts), row[0]))

    def retention(self):
        try: raw = self.files.read(CONFIG, _config_crypto.MAX_FILE_BYTES)
        except FileNotFoundError: return 90
        data = _config_crypto.decode_config(raw)
        if not isinstance(data, dict): raise InstallError('protected configuration root is invalid')
        value = data.get('log_retention_days', 90)
        if isinstance(value, bool) or not isinstance(value, (str, int)) or not re.fullmatch(r'[0-9]{1,4}', str(value)):
            raise InstallError('log retention must be an integer in the protected configuration')
        days = int(value)
        if not 1 <= days <= 365: raise InstallError('log retention is outside the supported day range')
        return days

    def timezone(self):
        """Apply only an explicitly saved, valid IANA zone; never restart PBX services."""
        from zoneinfo import ZoneInfo, available_timezones
        self.admit()
        info = self.files.metadata(CONFIG)
        account = pwd.getpwnam('asterisk')
        if info is None or not self.files.regular(info) or info.st_nlink != 1 or info.st_uid != account.pw_uid or stat.S_IMODE(info.st_mode) != 0o640:
            raise InstallError('timezone requires the protected current configuration')
        value = _config_crypto.decode_config(self.files.read(CONFIG, _config_crypto.MAX_FILE_BYTES)).get('pbx_timezone')
        if not isinstance(value, str) or value not in available_timezones():
            raise InstallError('select an existing IANA timezone in the setup wizard')
        ZoneInfo(value)
        self.run(['/usr/bin/timedatectl', 'set-timezone', value], timeout=30)

    def rotation(self):
        days = self.retention()
        return f'/var/log/sls_mass_notify.log {{\n    daily\n    maxsize 2M\n    rotate {days}\n    maxage {days}\n    missingok\n    notifempty\n    compress\n    delaycompress\n    copytruncate\n    su root asterisk\n}}\n'.encode()

    def root_cron(self, *, apply=False):
        result = self.run(['/usr/bin/crontab', '-u', 'root', '-l'], allowed=(0, 1))
        if result.returncode == 1 and (result.stdout or result.stderr.strip() != b'no crontab for root'):
            raise InstallError('unable to read root crontab safely')
        before = result.stdout.decode('utf-8')
        kept = [line for line in before.splitlines() if not any(name in line for name in (
            '/usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh',
            '/usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh',
            '/usr/local/bin/nwsalerts_ensure_menu_patch.sh', '/usr/local/bin/nws_weather_alert.sh'))]
        after = ('\n'.join(kept + list(CRON_LINES)) + '\n').encode()
        if apply and before.encode() != after:
            self.run(['/usr/bin/crontab', '-u', 'root', '-'], input=after)
        return before

    def prepare(self):
        # Source authentication completes in __init__ before any mutation.
        self.retention()
        for path in (DATA, DATA + '/sipnotify', DATA + '/config-backups', DATA + '/piper'):
            self.files.mkdir(path, 0o750, user=True)
        self.files.mkdir(DATA + '/event-log-recovery', 0o700, user=True)
        for path in (DATA + '/sounds', DATA + '/sounds/tones', DATA + '/sounds/tts', DATA + '/piper/voices',
                     WEB + '/sls_mass_notify', WEB + '/sls_mass_notify/assets', WEB + '/api/sipnotify', WEB + '/api/sls-mass-notify', WEB + '/mass-notify',
                     '/var/lib/asterisk/sounds/en/custom'):
            self.files.mkdir(path, 0o755, user=True)
        self.files.mkdir(RUNTIME, 0o755)
        for path in ('/var/log/sls_mass_notify.log', '/var/log/sls_mass_notify_events.jsonl', '/var/log/sls_mass_notify_push.log', DATA + '/sipnotify/sipnotify_events.jsonl'):
            self.files.touch(path)
        try: self.files.secure_file(CONFIG, 0o640, user=True)
        except FileNotFoundError: pass
        self.files.sound_link('/var/lib/asterisk/sounds/SLS_Mass_Notifications_Plugin')
        self.files.sound_link('/var/lib/asterisk/sounds/en/SLS_Mass_Notifications_Plugin')
        copies = self.copies()
        for path, user in self.copy_directories():
            self.files.mkdir(path, 0o755, user=user)
        for source, target, mode, user, preserve in copies:
            self.files.write(target, self.contents[source], mode, user=user, preserve=preserve)
        for base in (RUNTIME, WEB + '/api/sipnotify', WEB + '/api/sls-mass-notify', WEB + '/mass-notify', WEB + '/sls_mass_notify/assets'):
            expected = {target[len(base) + 1:] for _, target, _, _, _ in copies if target.startswith(base + '/')}
            self.files.prune(base, expected, preserve=('piper',) if base == RUNTIME else ())
        self.files.cleanup_legacy_links()
        self.files.secure_tree(DATA)
        self.files.secure_tree(WEB + '/sls_mass_notify', public=True)
        self.files.write(SERVICE_PATH, UNIT.encode(), 0o644, managed_prefix=b'# Managed by SLS Mass Notify Server\n[Unit]\n')
        self.files.write(APACHE_PATH, APACHE.encode(), 0o644, managed_prefix=b'# Southland Servers Mass Notifications Server\n')
        self.files.write(LOGROTATE_PATH, self.rotation(), 0o644, managed_prefix=b'/var/log/sls_mass_notify.log {\n')
        self.admit()

    def admit(self):
        """Verify only root-executable package files, without executing any."""
        for source, target, mode, user, _ in self.copies():
            if user: continue
            info = self.files.metadata(target)
            if (info is None or not self.files.regular(info) or info.st_uid != 0 or info.st_gid != 0
                    or stat.S_IMODE(info.st_mode) != mode or self.files.read(target) != self.contents[source]):
                raise InstallError('root runtime differs from authenticated release: ' + target)

    def dependencies(self):
        self.admit()
        # Existing authenticated root script performs dependency/hash/voice
        # checks and private compatibility-path repair. No PHP is loaded.
        script = RUNTIME + '/sls_mass_notify_install_piper_voices.sh'
        self.run(['/bin/bash', script, '--runtime-only', '--workers-paused'], timeout=930)
        self.run(['/bin/bash', script, '--workers-paused'], timeout=1830)
        self.run(['/bin/bash', script, '--repair-permissions-only'], timeout=130)

    def activate(self):
        self.admit()
        if self.files.read(SERVICE_PATH) != UNIT.encode() or self.files.read(APACHE_PATH) != APACHE.encode():
            raise InstallError('protected service configuration differs; run the prepare phase first')
        self.run(['/usr/sbin/a2enmod', 'rewrite', 'setenvif'])
        self.run(['/usr/sbin/a2enconf', 'sls-mass-notify'])
        self.run(['/usr/sbin/apache2ctl', 'configtest'])
        self.run(['/usr/bin/systemctl', 'reload', 'apache2'])
        for args in (['daemon-reload'], ['enable', SERVICE], ['restart', SERVICE]):
            self.run(['/usr/bin/systemctl', *args])
        self.root_cron(apply=True)

    def verify(self):
        self.admit()
        for path, user in self.copy_directories():
            descriptor = self.files.directory(path)
            try:
                info = os.fstat(descriptor)
                expected = (self.files.account.pw_uid, self.files.account.pw_gid) if user else (0, 0)
                if (info.st_uid, info.st_gid) != expected or stat.S_IMODE(info.st_mode) != 0o755:
                    raise InstallError('managed payload directory ownership or traversal mode differs: ' + path)
            finally:
                os.close(descriptor)
        for path, body in ((SERVICE_PATH, UNIT.encode()), (APACHE_PATH, APACHE.encode()), (LOGROTATE_PATH, self.rotation())):
            info = self.files.metadata(path)
            if info is None or not self.files.regular(info) or info.st_uid != 0 or info.st_mode & 0o022 or self.files.read(path) != body:
                raise InstallError('protected integration file failed verification: ' + path)
        config = self.files.metadata(CONFIG)
        if config is None or not self.files.regular(config) or config.st_uid != self.files.account.pw_uid or stat.S_IMODE(config.st_mode) != 0o640:
            raise InstallError('central configuration ownership/permissions are not protected')
        cron = self.root_cron().splitlines()
        if any(cron.count(line) != 1 for line in CRON_LINES):
            raise InstallError('root maintenance/update schedule is missing or duplicated')
        for args in (['is-enabled', '--quiet', SERVICE], ['is-active', '--quiet', SERVICE]):
            self.run(['/usr/bin/systemctl', *args])
        self.run(['/usr/sbin/apache2ctl', 'configtest'])
        return True

    def plan(self):
        return {'generation': self.source.parent.name, 'module_version': self.data['version'],
                'managed_package_files': len(self.copies()), 'protected_configuration': [SERVICE_PATH, APACHE_PATH, LOGROTATE_PATH],
                'root_cron': list(CRON_LINES), 'configuration_rewritten': False, 'php_bootstrap': False,
                'phases': ['prepare', 'dependencies', 'unprivileged FreePBX installation', 'activate', 'unprivileged FreePBX reload', 'approved-inventory signing', 'verify', 'unprivileged integration verification']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--trust-root', type=Path, default=Path('/var/lib/sls-mass-notify-trust'))
    parser.add_argument('phase', choices=('plan', 'prepare', 'admit', 'dependencies', 'activate', 'verify', 'timezone'))
    parser.add_argument('--apply', action='store_true', help='required for prepare/dependencies/activate')
    args = parser.parse_args()
    if os.geteuid() != 0: raise InstallError('protected installation operations require root')
    protect_entrypoint()
    trust = load_trust()
    action = PrivilegedInstall(trust, args.trust_root)
    if args.phase in ('prepare', 'dependencies', 'activate', 'timezone') and not args.apply:
        raise InstallError('mutating phase requires --apply; use plan to review fixed operations')
    if args.phase == 'plan':
        print(json.dumps(action.plan(), indent=2))
    else:
        getattr(action, args.phase)()
        print(json.dumps({'ok': True, 'phase': args.phase, 'generation': action.source.parent.name}))


if __name__ == '__main__':
    try: main()
    except (InstallError, OSError, ValueError, KeyError, RuntimeError, subprocess.SubprocessError) as error:
        print('SLS protected installation: ' + str(error), file=sys.stderr)
        raise SystemExit(1)
