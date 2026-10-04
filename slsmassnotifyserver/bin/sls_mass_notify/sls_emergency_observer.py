"""Passive DialBegin observer. It cannot originate, redirect or hang up a call."""
import importlib.util
import hashlib
import json
import os
from pathlib import Path

import sys as _config_sys
_config_sys.dont_write_bytecode = True
_config_crypto_spec = importlib.util.spec_from_file_location("sls_config_crypto", Path(__file__).resolve().with_name("sls_config_crypto.py"))
_config_crypto = importlib.util.module_from_spec(_config_crypto_spec)
_config_crypto_spec.loader.exec_module(_config_crypto)
import re
import stat
import subprocess
import time

from sls_phone_admission import DATA
from sls_nws_delivery_claims import _open_directory, _validate_parent_directory, _account_ids


def dial_number(event):
    """Recognize explicit numeric PJSIP Dial targets; never infer from caller ID."""
    value = event.get('DialString', '')
    if not isinstance(value, str) or len(value) > 512:
        return None
    value = value.removeprefix('PJSIP/')
    for pattern in (r'([0-9]{1,20})@[A-Za-z0-9_.-]{1,80}',
                    r'[A-Za-z0-9_.-]{1,80}/([0-9]{1,20})',
                    r'[A-Za-z0-9_.-]{1,80}/sips?:([0-9]{1,20})@[A-Za-z0-9.:-]{1,253}'):
        match = re.fullmatch(pattern, value)
        if match:
            return match[1]
    return None


def observations(event, settings, digest, now):
    if not isinstance(event, dict) or event.get('Event') != 'DialBegin' or str(settings.get('enabled')) != '1':
        return []
    channel = event.get('Channel', '')
    match = re.fullmatch(r'PJSIP/([0-9]{1,20})-[a-fA-F0-9]{8,16}', channel) if isinstance(channel,str) else None
    unique = event.get('Uniqueid', '')
    if not match or not isinstance(unique,str) or not re.fullmatch(r'[A-Za-z0-9_.:-]{1,100}', unique):
        return []
    caller = match[1]
    number = dial_number(event)
    if not number:
        return []
    rows = []
    for rule in settings.get('automations', {}).get('rules', []):
        if rule.get('kind') != 'emergency_call' or rule.get('enabled') is not True or caller not in rule.get('callers', []) or number not in rule.get('numbers', []):
            continue
        identity = hashlib.sha256((rule['id'] + '|' + unique + '|' + number).encode()).hexdigest()
        rows.append({'id': 'job_' + identity[:32], 'schema': 1, 'state':'observed', 'settings_sha256':digest,
            'rule_id':rule['id'], 'request_id':identity, 'sent_at':int(now), 'expires_at':int(now)+rule['max_age_seconds'],
            'event':number, 'caller':caller, 'message':'Designated outbound dial attempt observed.', 'is_test':False})
    return rows


class EmergencyObserver:
    def __init__(self, directory=DATA, clock=time.time):
        self.directory = Path(directory)
        self.clock = clock
        self.loaded = 0
        self.settings = {}
        self.digest = ''
        self.last_start = 0
        self.worker = None

    def refresh(self):
        if self.worker is not None and self.worker.poll() is not None:
            self.worker = None
        now = self.clock()
        if now - self.loaded < 5:
            return
        directory = _open_directory(self.directory)
        try:
            _validate_parent_directory(directory)
            descriptor = os.open('mass-notifications.config', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
            with os.fdopen(descriptor, 'rb') as handle:
                m = os.fstat(handle.fileno()); uid, gid = _account_ids()
                if not stat.S_ISREG(m.st_mode) or m.st_nlink != 1 or m.st_uid not in (0, uid) or m.st_gid != gid or m.st_mode & 0o137 or m.st_size > _config_crypto.MAX_FILE_BYTES:
                    raise RuntimeError('Emergency observer config ownership or permissions are unsafe.')
                raw = handle.read((_config_crypto.MAX_FILE_BYTES + 1))
            if len(raw)>_config_crypto.MAX_FILE_BYTES:
                raise RuntimeError('Emergency observer config is oversized.')
            settings = _config_crypto.decode_config(raw)
            if not isinstance(settings, dict):
                raise RuntimeError('Emergency observer config is invalid.')
            self.settings, self.digest, self.loaded = settings, hashlib.sha256(raw).hexdigest(), now
        finally:
            os.close(directory)

    def record(self, event):
        if not isinstance(event, dict) or event.get('Event') != 'DialBegin':
            return
        self.refresh()
        rows = observations(event, self.settings, self.digest, self.clock())
        if not rows:
            return
        parent = _open_directory(self.directory)
        try:
            _validate_parent_directory(parent)
            try:
                os.mkdir('emergency-observations', 0o750, dir_fd=parent)
                os.fsync(parent)
            except FileExistsError:
                pass
            directory = os.open('emergency-observations', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
            try:
                m = os.fstat(directory)
                if m.st_uid != os.geteuid() or m.st_mode & 0o022:
                    raise RuntimeError('Emergency observation storage is unsafe.')
                with os.scandir(directory) as entries:
                    for count, _entry in enumerate(entries, 1):
                        if count >= 550:
                            raise RuntimeError('Emergency observation journal is full; preserve and review its history.')
                for row in rows:
                    name = row['id'] + '.json'
                    try:
                        previous = os.stat(name, dir_fd=directory, follow_symlinks=False)
                    except FileNotFoundError:
                        previous = None
                    if previous is not None:
                        if not stat.S_ISREG(previous.st_mode) or previous.st_nlink != 1 or previous.st_uid != os.geteuid() or previous.st_mode & 0o022:
                            raise RuntimeError('Emergency observation record is unsafe.')
                        continue
                    temporary = '.observed-' + os.urandom(16).hex()
                    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640, dir_fd=directory)
                    try:
                        with os.fdopen(fd, 'wb') as handle:
                            handle.write(json.dumps(row,separators=(',',':')).encode()); handle.flush(); os.fsync(handle.fileno())
                        # Atomic creation without replacement preserves the first observation.
                        try:
                            os.link(temporary, name, src_dir_fd=directory, dst_dir_fd=directory, follow_symlinks=False)
                        except FileExistsError:
                            pass
                        os.unlink(temporary, dir_fd=directory); os.fsync(directory)
                    finally:
                        try: os.unlink(temporary, dir_fd=directory)
                        except FileNotFoundError: pass
            finally:
                os.close(directory)
        finally:
            os.close(parent)
        if self.worker is None and self.clock() - self.last_start >= 5:
            self.worker = subprocess.Popen(['/usr/bin/timeout', '--kill-after=5', '120', '/usr/bin/php',
                '/usr/local/bin/sls_mass_notify/sls_mass_notify_automation_worker.php', '--observe'],
                stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                close_fds=True, start_new_session=True, env={'PATH':'/usr/bin:/bin','LANG':'C.UTF-8'})
            self.last_start = self.clock()
