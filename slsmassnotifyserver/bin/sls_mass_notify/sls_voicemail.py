#!/usr/bin/python3
"""Bounded internal voicemail fallback using Asterisk's native mailbox writer."""
from __future__ import annotations

import array
from contextlib import contextmanager
import fcntl
import hashlib
import hmac
import json
import math
import os
from pathlib import Path
import re
import stat
import sys
import threading
import time
import wave

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent))
from sls_phone_admission import DATA, PhoneAdmissionError, PhoneAdmissionStore, PhoneAmi, read_settings, read_private_json, checked_limit, accountcode, checked_json
from sls_nws_delivery_claims import CoordinationError, _account_ids, _open_directory, _validate_parent_directory

TOKEN = re.compile(r'[a-f0-9]{32}')
SOUND = re.compile(r'SLS_Mass_Notifications_Plugin/(?:tts|tones|voicemail)/[A-Za-z0-9_-]{1,200}')
MAX_RECORD_SECONDS = 1800
MAX_AUDIO_SECONDS = 1800
MAX_LEASES = 1024
LIVE_FINALIZE_GRACE_SECONDS = 30
VM_CONTEXT = 'sls-alert-voicemail'


def flag(value):
    if type(value) not in (bool, int, str) or value not in (False, True, 0, 1, '0', '1'):
        raise PhoneAdmissionError('phone_voicemail_policy_invalid')
    return value in (True, 1, '1')


def scope_fingerprint(settings, service, scope):
    """Freeze authorization as well as recipients; edits never expand an old page."""
    if not isinstance(scope, str) or not scope or len(scope) > 80:
        return ''
    if service == 'live':
        paging = settings.get('live_paging', {})
        rows = paging.get('groups', []) if isinstance(paging, dict) else []
        group = [row for row in rows if isinstance(row, dict) and row.get('group_id') == scope]
        if len(group) != 1:
            return ''
        body = {'paging': paging, 'legacy_groups': settings.get('announcement_groups', [])}
    elif service in ('nws', 'lightning'):
        from sls_notification_destinations import _source_group
        group = _source_group(settings, {'provider': 'nws' if service == 'nws' else 'xweather', 'group_id': scope})
        if not isinstance(group, dict):
            return ''
        if service == 'nws':
            names = ('id', 'name', 'site_id', 'zone', 'quiet_hours_enabled', 'quiet_hours_start',
                     'quiet_hours_end', 'quiet_critical_events')
            profile = {key: group.get(key) for key in names}
            profile['extensions'] = group.get('extensions', group.get('recipients', settings.get('alert_recipients', [])))
            body = {'group': profile, 'enabled': settings.get('enabled', '0'),
                    'voicemail_enabled': settings.get('nws_voicemail_enabled', '1')}
        else:
            config = settings.get('xweather', {})
            body = {'group': group, 'enabled': config.get('enabled', '0'),
                    'voicemail_enabled': config.get('voicemail_enabled', '1')}
    else:
        return ''
    return hashlib.sha256(json.dumps(body, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()).hexdigest()


def current_policy(settings, batch, directory=DATA):
    runtime = settings.get('runtime_enabled', True)
    if type(runtime) is not bool or not runtime:
        return False
    service = batch.get('service')
    config = settings.get('live_paging' if service == 'live' else 'xweather', {})
    if service == 'nws':
        enabled = flag(settings.get('enabled', '0')) and flag(settings.get('nws_voicemail_enabled', '1'))
    elif service in ('lightning', 'live') and isinstance(config, dict):
        enabled = flag(config.get('enabled', '0')) and flag(config.get('voicemail_enabled', '1'))
    else:
        return False
    fallback = batch.get('vm_fallback') or {}
    expected = fallback.get('scope_hash', '')
    actual = scope_fingerprint(settings, service, fallback.get('scope'))
    if not enabled or not expected or not actual or not hmac.compare_digest(expected, actual):
        return False
    if service != 'live':
        from sls_notification_destinations import validate_external_weather
        context = fallback.get('weather_context')
        if not isinstance(context, dict):
            return False
        status, _, _ = validate_external_weather({'source_validity': context.get('source_validity'),
            'payload': {'event': context.get('event', ''), 'severity': context.get('severity', '')}}, settings, directory)
        return status == 'eligible'
    return True


def canonical_sound(token):
    if not isinstance(token, str) or not TOKEN.fullmatch(token):
        raise PhoneAdmissionError('phone_voicemail_token_invalid')
    return 'SLS_Mass_Notifications_Plugin/voicemail/' + token


class VoicemailMedia:
    def __init__(self, directory=DATA):
        self.directory = Path(directory)
        self.path = self.directory / 'sounds' / 'voicemail'

    def open_directory(self):
        parent = _open_directory(self.directory)
        try:
            _validate_parent_directory(parent)
            for component in ('sounds', 'voicemail'):
                try:
                    os.mkdir(component, 0o750, dir_fd=parent)
                    uid, gid = _account_ids()
                    os.chown(component, uid, gid, dir_fd=parent, follow_symlinks=False)
                except FileExistsError:
                    pass
                child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
                os.close(parent)
                parent = child
                _validate_parent_directory(parent)
            return parent
        except BaseException:
            os.close(parent)
            raise

    @staticmethod
    def file_info(fd, limit):
        info = os.fstat(fd)
        uid, gid = _account_ids()
        if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid not in (0, uid)
                or info.st_gid not in (0, gid) or info.st_mode & 0o027 or info.st_size > limit):
            raise PhoneAdmissionError('phone_voicemail_audio_invalid')
        return info

    @staticmethod
    def maximum_bytes(seconds):
        if type(seconds) not in (int, float) or not math.isfinite(seconds) or not 0 < seconds <= MAX_AUDIO_SECONDS:
            raise PhoneAdmissionError('phone_voicemail_audio_invalid')
        return int(math.ceil(seconds) * 16000) + 65536

    @staticmethod
    def wav_info(handle, maximum, require_signal=False):
        try:
            with wave.open(handle, 'rb') as audio:
                if (audio.getnchannels(), audio.getsampwidth(), audio.getframerate(), audio.getcomptype()) != (1, 2, 8000, 'NONE'):
                    raise ValueError('format')
                frames = audio.getnframes()
                if not 4000 <= frames <= int(maximum * 8000):
                    raise ValueError('duration')
                remaining = frames
                has_signal = False
                while remaining:
                    raw = audio.readframes(min(remaining, 8192))
                    if not raw or len(raw) % 2:
                        raise ValueError('truncated')
                    samples = array.array('h', raw)
                    if sys.byteorder != 'little':
                        samples.byteswap()
                    has_signal = has_signal or any(abs(value) > 4 for value in samples)
                    remaining -= len(samples)
                if require_signal and not has_signal:
                    raise ValueError('empty')
                return frames / 8000
        except (EOFError, OSError, wave.Error, ValueError):
            raise PhoneAdmissionError('phone_voicemail_audio_invalid') from None

    def read_lease(self, parent, token):
        canonical_sound(token)
        fd = os.open(token + '.json', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        try:
            self.file_info(fd, 4096)
            raw = os.read(fd, 4097)
            value = checked_json(raw)
            if (not isinstance(value, dict) or value.get('token') != token
                    or type(value.get('schema')) is not int or value['schema'] != 1
                    or value.get('kind') not in ('live', 'snapshot') or value.get('state') not in ('recording', 'complete')
                    or any(type(value.get(key)) not in (int, float) or not math.isfinite(value[key]) or value[key] < 0
                           for key in ('created_at', 'expires_at'))):
                raise ValueError('lease')
            self.maximum_bytes(value.get('max_seconds'))
            if value['state'] == 'complete' and (not isinstance(value.get('sha256'), str)
                    or not re.fullmatch(r'[a-f0-9]{64}', value['sha256'])
                    or type(value.get('bytes')) is not int or not 44 <= value['bytes'] <= self.maximum_bytes(value['max_seconds'])
                    or type(value.get('duration_seconds')) not in (int, float)
                    or not math.isfinite(value['duration_seconds']) or not .5 <= value['duration_seconds'] <= value['max_seconds']):
                raise ValueError('lease')
            return value
        except (ValueError, OSError, PhoneAdmissionError):
            raise PhoneAdmissionError('phone_voicemail_audio_invalid') from None
        finally:
            os.close(fd)

    @staticmethod
    def write_lease(parent, token, value, replace=False):
        raw = json.dumps(value, separators=(',', ':'), allow_nan=False).encode()
        name = token + ('.pending-' + os.urandom(8).hex() if replace else '.json')
        fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
        try:
            uid, gid = _account_ids()
            os.fchown(fd, uid, gid)
            with os.fdopen(fd, 'wb', closefd=False) as handle:
                handle.write(raw); handle.flush(); os.fsync(fd)
            if replace:
                os.replace(name, token + '.json', src_dir_fd=parent, dst_dir_fd=parent)
            os.fsync(parent)
        except BaseException:
            try: os.unlink(name, dir_fd=parent)
            except OSError: pass
            raise
        finally:
            os.close(fd)

    def reserve(self, parent, seconds, token):
        try:
            fd = os.open('recording-reserve.lock', os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_NONBLOCK, 0o640, dir_fd=parent)
            uid, gid = _account_ids(); os.fchown(fd, uid, gid)
        except FileExistsError:
            fd = os.open('recording-reserve.lock', os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        try:
            self.file_info(fd, 4096)
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except (OSError, PhoneAdmissionError):
            os.close(fd)
            raise PhoneAdmissionError('phone_voicemail_audio_capacity') from None
        try:
            names = os.listdir(parent)
            if len(names) > MAX_LEASES * 4 + 1:
                raise PhoneAdmissionError('phone_voicemail_audio_capacity')
            # Receive-only and mixed temporary WAVs are both finite allocations.
            needed = 2 * self.maximum_bytes(seconds) + 16 * 1024 * 1024
            for name in names:
                if re.fullmatch(r'[a-f0-9]{32}\.json', name):
                    lease = self.read_lease(parent, name[:-5])
                    if lease['state'] == 'recording' and lease.get('expires_at', 0) > time.time():
                        needed += 2 * self.maximum_bytes(lease.get('max_seconds'))
            fs = os.fstatvfs(parent)
            if fs.f_bavail * fs.f_frsize < needed:
                raise PhoneAdmissionError('phone_voicemail_audio_capacity')
            reserve_quota(self.directory, token, seconds)
            return fd
        except BaseException:
            os.close(fd)
            raise

    def validate(self, token):
        parent = self.open_directory()
        try:
            lease = self.read_lease(parent, token)
            if lease['state'] != 'complete':
                raise PhoneAdmissionError('phone_voicemail_audio_unavailable')
            fd = os.open(token + '.wav', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
            try:
                info = self.file_info(fd, self.maximum_bytes(lease['max_seconds']))
                with os.fdopen(fd, 'rb', closefd=False) as handle:
                    raw = handle.read(info.st_size + 1)
                if len(raw) != info.st_size or not hmac.compare_digest(hashlib.sha256(raw).hexdigest(), lease.get('sha256', '')):
                    raise PhoneAdmissionError('phone_voicemail_audio_invalid')
                return lease
            finally:
                os.close(fd)
        finally:
            os.close(parent)


def snapshot_audio(token, sound, duration, directory=DATA):
    canonical_sound(token)
    if not isinstance(sound, str) or not SOUND.fullmatch(sound) or '/voicemail/' in sound:
        raise PhoneAdmissionError('phone_voicemail_audio_invalid')
    # The actual WAV duration is checked independently of a producer's estimate.
    maximum = MAX_AUDIO_SECONDS if duration is None else min(MAX_AUDIO_SECONDS, max(1, float(duration) + 1))
    media = VoicemailMedia(directory)
    parent = media.open_directory()
    reserve = None
    created = False
    try:
        reserve = media.reserve(parent, maximum, token)
        relative = sound.split('/', 2)
        source_parent = _open_directory(Path(directory) / 'sounds' / relative[1])
        try:
            _validate_parent_directory(source_parent)
            source = os.open(relative[2] + '.wav', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=source_parent)
        finally:
            os.close(source_parent)
        try:
            before = media.file_info(source, media.maximum_bytes(maximum))
            with os.fdopen(os.dup(source), 'rb') as handle:
                actual_duration = media.wav_info(handle, maximum)
            os.lseek(source, 0, os.SEEK_SET)
            raw = os.read(source, before.st_size + 1)
            after = os.fstat(source)
            if len(raw) != before.st_size or (before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
                raise PhoneAdmissionError('phone_voicemail_audio_invalid')
        finally:
            os.close(source)
        output = os.open(token + '.wav', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
        created = True
        try:
            uid, gid = _account_ids(); os.fchown(output, uid, gid)
            with os.fdopen(output, 'wb', closefd=False) as handle:
                handle.write(raw); handle.flush(); os.fsync(output)
        finally:
            os.close(output)
        media.write_lease(parent, token, {'schema': 1, 'token': token, 'kind': 'snapshot', 'state': 'complete',
            'max_seconds': maximum, 'created_at': time.time(), 'expires_at': time.time() + 1800,
            'sha256': hashlib.sha256(raw).hexdigest(), 'bytes': len(raw), 'duration_seconds': actual_duration})
        release_quota(directory, token)
        return canonical_sound(token)
    except (OSError, ValueError, PhoneAdmissionError) as error:
        if created:
            try: os.unlink(token + '.wav', dir_fd=parent)
            except OSError: pass
        release_quota(directory, token)
        if isinstance(error, PhoneAdmissionError): raise
        raise PhoneAdmissionError('phone_voicemail_audio_unavailable') from None
    finally:
        if reserve is not None: os.close(reserve)
        os.close(parent)


def prepare_live_recording(token, channel, seconds, directory=DATA):
    canonical_sound(token)
    if type(seconds) is not int or not 1 <= seconds <= MAX_RECORD_SECONDS:
        raise PhoneAdmissionError('phone_voicemail_recording_limit')
    store = PhoneAdmissionStore(directory)
    with store.locked() as state:
        batch = state['batches'].get(token, {})
        if (batch.get('service') != 'live' or not current_policy(read_settings(directory), batch, directory)
                or not batch.get('vm_fallback', {}).get('targets')
                or not any(row.get('kind') == 'origin' and row.get('channel') == channel and not row.get('ended_at')
                           for row in batch.get('channels', {}).values())):
            raise PhoneAdmissionError('phone_voicemail_recording_not_admitted')
    media = VoicemailMedia(directory); parent = media.open_directory(); reserve = None; created = []
    try:
        reserve = media.reserve(parent, seconds, token)
        for suffix in ('-receive.wav', '-mix.wav'):
            name = token + suffix
            fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
            created.append(name)
            try:
                uid, gid = _account_ids(); os.fchown(fd, uid, gid)
            finally: os.close(fd)
        media.write_lease(parent, token, {'schema': 1, 'token': token, 'kind': 'live', 'state': 'recording',
            'channel': channel, 'max_seconds': seconds, 'created_at': time.time(), 'expires_at': time.time() + seconds + 90})
        return {'token': token, 'receive_path': str(media.path / (token + '-receive.wav')),
                'mix_path': str(media.path / (token + '-mix.wav'))}
    except (OSError, ValueError, PhoneAdmissionError) as error:
        for name in created:
            try: os.unlink(name, dir_fd=parent)
            except OSError: pass
        release_quota(directory, token)
        if isinstance(error, PhoneAdmissionError): raise
        raise PhoneAdmissionError('phone_voicemail_audio_unavailable') from None
    finally:
        if reserve is not None: os.close(reserve)
        os.close(parent)


def finalize_live_recording(token, channel, directory=DATA):
    """Called only after this channel's own StopMixMonitor has completed."""
    media = VoicemailMedia(directory); parent = media.open_directory(); owned = False
    try:
        lease = media.read_lease(parent, token)
        if lease.get('channel') != channel or lease['kind'] != 'live':
            raise PhoneAdmissionError('phone_voicemail_recording_not_admitted')
        owned = True
        if lease['state'] == 'complete':
            media.validate(token)
            PhoneAdmissionStore(directory).attach_live_voicemail_sound(token, channel, canonical_sound(token))
            release_quota(directory, token)
            return canonical_sound(token)
        fd = os.open(token + '-receive.wav', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        try:
            info = media.file_info(fd, media.maximum_bytes(lease['max_seconds']))
            with os.fdopen(os.dup(fd), 'rb') as handle:
                duration = media.wav_info(handle, lease['max_seconds'], require_signal=True)
            os.lseek(fd, 0, os.SEEK_SET); raw = os.read(fd, info.st_size + 1)
            after = os.fstat(fd)
            if len(raw) != info.st_size or (info.st_size, info.st_mtime_ns, info.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
                raise PhoneAdmissionError('phone_voicemail_audio_invalid')
        finally: os.close(fd)
        # link/unlink provides a no-replace rename and retains the checked inode.
        os.link(token + '-receive.wav', token + '.wav', src_dir_fd=parent, dst_dir_fd=parent, follow_symlinks=False)
        os.unlink(token + '-receive.wav', dir_fd=parent)
        lease.update(state='complete', sha256=hashlib.sha256(raw).hexdigest(), bytes=len(raw),
                     duration_seconds=duration, expires_at=time.time() + 1800)
        media.write_lease(parent, token, lease, replace=True)
        os.unlink(token + '-mix.wav', dir_fd=parent)
        PhoneAdmissionStore(directory).attach_live_voicemail_sound(token, channel, canonical_sound(token))
        release_quota(directory, token)
        return canonical_sound(token)
    except (OSError, ValueError, PhoneAdmissionError) as error:
        code = str(error) if isinstance(error, PhoneAdmissionError) else 'phone_voicemail_audio_unavailable'
        if owned:
            try:
                mark_live_recording_failure(PhoneAdmissionStore(directory), token, channel, code)
            except (OSError, ValueError, PhoneAdmissionError):
                report_problem('phone_voicemail_recording_unconfirmed')
            # Only this entry point's confirmed stopped recorder releases its
            # reservation. Retained temporary files remain counted by inventory.
            release_quota(directory, token)
        if isinstance(error, PhoneAdmissionError): raise
        raise PhoneAdmissionError('phone_voicemail_audio_unavailable') from None
    finally: os.close(parent)


def mark_live_recording_failure(store, token, channel=None, code='phone_voicemail_recording_unconfirmed'):
    """Refuse unresolved mailbox targets; leave ordinary phone evidence intact."""
    from sls_phone_admission import VOICEMAIL_REFUSALS
    canonical_sound(token)
    state = read_private_json('phone-admission.json', store.directory)
    store.validate(state)
    batch = state['batches'].get(token, {})
    fallback = batch.get('vm_fallback')
    if (batch.get('service') != 'live' or batch.get('purpose', 'audio') != 'audio'
            or batch.get('boot_id') != store.boot_id or not isinstance(fallback, dict)
            or fallback.get('sound') is not None):
        return 0
    origins = [row for row in batch.get('channels', {}).values() if row.get('kind') == 'origin'
               and row.get('recipient') == fallback.get('live_origin')]
    if len(origins) != 1:
        return 0
    if channel is not None:
        if origins[0].get('channel') != channel:
            return 0
    elif not live_recording_settled(batch, store.clock(), store.monotonic()):
        return 0
    code = code if code in VOICEMAIL_REFUSALS else 'phone_voicemail_recording_unconfirmed'
    recipients = [recipient for recipient, row in fallback['targets'].items()
                  if may_need_fallback(batch, row, recipient)]
    if not recipients:
        return 0
    marked = store.record_voicemail_refusals(token, recipients, code)
    if marked:
        report_problem(code)
    return marked


def live_recording_settled(batch, now, tick):
    closed = batch.get('closed_at', batch.get('updated_at'))
    updated = batch.get('updated_tick')
    if (batch.get('status') != 'closed' or any(type(value) not in (int, float) or not math.isfinite(value)
            for value in (closed, updated)) or now < closed or tick - updated < LIVE_FINALIZE_GRACE_SECONDS):
        return False
    fallback = batch.get('vm_fallback') or {}
    origins = [row for row in batch.get('channels', {}).values() if row.get('kind') == 'origin'
               and row.get('recipient') == fallback.get('live_origin')]
    return len(origins) == 1 and origins[0].get('ended_at') is not None


def reserve_quota(directory, token, seconds):
    from sls_speech_admission import Admission, SpeechAdmissionError, MAX_SLOTS
    from sls_storage_maintenance import generated_inventory, MAX_GENERATED_FILES
    admission = None
    try:
        admission = Admission(read_settings(directory), 1, directory=directory)
        control = admission.file('speech-admission.lock')
        if not admission.available(control):
            raise BlockingIOError('speech admission budget is busy')
        slots = [admission.file('speech-slot-%02d.lock' % index) for index in range(MAX_SLOTS)]
        rows = generated_inventory(admission.directory, admission.web)
        used = sum(row[3].st_size for row in rows)
        reserved = sum(row['bytes'] for slot in slots for row in admission.record(slot))
        amount = 2 * VoicemailMedia.maximum_bytes(seconds)
        if len(rows) >= MAX_GENERATED_FILES or used + reserved + amount > admission.budget:
            raise PhoneAdmissionError('phone_voicemail_audio_capacity')
        fs = os.statvfs(directory)
        if fs.f_bavail * fs.f_frsize < admission.reserve + reserved + amount:
            raise PhoneAdmissionError('phone_voicemail_audio_capacity')
        target = next((slot for slot in slots if len(admission.record(slot)) < 64), None)
        if target is None:
            raise PhoneAdmissionError('phone_voicemail_audio_capacity')
        admission.write(target, admission.record(target) + [{'token': token, 'bytes': amount,
                        'expires': time.time() + seconds + 90}])
    except (OSError, RuntimeError) as error:
        if isinstance(error, PhoneAdmissionError): raise
        raise PhoneAdmissionError('phone_voicemail_audio_capacity') from None
    finally:
        if admission is not None: admission.close()


def release_quota(directory, token):
    from sls_speech_admission import Admission, MAX_SLOTS
    admission = None
    try:
        admission = Admission(read_settings(directory), 1, directory=directory)
        control = admission.file('speech-admission.lock')
        if not admission.available(control):
            raise BlockingIOError('speech admission budget is busy')
        for index in range(MAX_SLOTS):
            slot = admission.file('speech-slot-%02d.lock' % index)
            rows = admission.record(slot)
            remaining = [row for row in rows if row['token'] != token]
            if len(rows) != len(remaining): admission.write(slot, remaining)
    except (OSError, RuntimeError):
        # Expiry remains the safe upper bound if a concurrent quota transaction
        # prevents immediate release. Never overwrite its newer reservation.
        pass
    finally:
        if admission is not None: admission.close()


def cleanup_media(directory=DATA):
    with voicemail_worker_lease(directory) as available:
        if not available or not (Path(directory) / 'sounds' / 'voicemail').is_dir():
            return 0
        return _cleanup_media(directory)


def _cleanup_media(directory):
    media = VoicemailMedia(directory); parent = media.open_directory(); removed = 0
    try:
        names = os.listdir(parent)
        if len(names) > MAX_LEASES * 4 + 1:
            raise PhoneAdmissionError('phone_voicemail_audio_capacity')
        store = PhoneAdmissionStore(directory)
        if not any(re.fullmatch(r'[a-f0-9]{32}\.json', name) for name in names): return 0
        try: state = read_private_json('phone-admission.json', directory)
        except FileNotFoundError:
            history_parent = _open_directory(Path(directory))
            try:
                _validate_parent_directory(history_parent)
                if store.read_history_marker(history_parent) is not None:
                    raise PhoneAdmissionError('phone_state_history_missing') from None
            finally:
                os.close(history_parent)
            state = {'schema': 1, 'batches': {}, 'collector': None}
        store.validate(state)
        active = {token for token, batch in state['batches'].items() if batch['status'] != 'closed'}
        active.update(batch['voicemail']['source_token'] for batch in state['batches'].values()
                      if batch.get('purpose') == 'voicemail' and batch['status'] != 'closed')
        retained = {token for token, batch in state['batches'].items()
                    if (batch.get('vm_fallback') or {}).get('expires_at', 0) > time.time()}
        for name in names:
            if removed >= 32 or not re.fullmatch(r'[a-f0-9]{32}\.json', name): continue
            token = name[:-5]
            try: lease = media.read_lease(parent, token)
            except (OSError, PhoneAdmissionError):
                report_problem('phone_voicemail_audio_invalid')
                continue
            if token in active or token in retained or lease.get('expires_at', float('inf')) > time.time(): continue
            try:
                for suffix in ('.wav', '-receive.wav', '-mix.wav', '.json'):
                    target = token + suffix
                    try:
                        fd = os.open(target, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
                    except FileNotFoundError: continue
                    try:
                        before = media.file_info(fd, media.maximum_bytes(MAX_AUDIO_SECONDS))
                        current = os.stat(target, dir_fd=parent, follow_symlinks=False)
                        if (current.st_dev, current.st_ino, current.st_size, current.st_mtime_ns) != (
                                before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns):
                            raise PhoneAdmissionError('phone_voicemail_audio_invalid')
                        os.unlink(target, dir_fd=parent)
                    finally: os.close(fd)
            except (OSError, PhoneAdmissionError):
                report_problem('phone_voicemail_audio_invalid')
                continue
            release_quota(directory, token); removed += 1
        if removed: os.fsync(parent)
        return removed
    finally: os.close(parent)


def mailbox_limits(mailbox, etc_directory=Path('/etc/asterisk')):
    """Read a bounded static mailbox profile without changing its PBX policy.

    Realtime mailboxes and executable/template configuration are not guessed.
    VM_INFO(mailbox,exists) is checked against the running Asterisk instance.
    """
    if not isinstance(mailbox, str) or not re.fullmatch(r'[0-9]{1,20}@[A-Za-z0-9_-]{1,80}', mailbox):
        raise PhoneAdmissionError('phone_voicemail_mailbox_invalid')
    parent = _open_directory(Path(etc_directory))
    categories = {}; visited = set(); total = 0
    uid, _ = _account_ids()
    def read(name, category='', depth=0, optional=False):
        nonlocal total
        if depth > 8 or name in visited or len(visited) >= 64 or not re.fullmatch(r'[A-Za-z0-9_.-]{1,120}\.conf', name):
            raise PhoneAdmissionError('phone_voicemail_mailbox_policy_unavailable')
        visited.add(name)
        try:
            fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        except FileNotFoundError:
            if optional: return category
            raise PhoneAdmissionError('phone_voicemail_mailbox_policy_unavailable') from None
        try:
            info = os.fstat(fd)
            if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid not in (0, uid)
                    or info.st_mode & 0o022 or info.st_size > 1024 * 1024):
                raise PhoneAdmissionError('phone_voicemail_mailbox_policy_unavailable')
            raw = os.read(fd, info.st_size + 1)
            total += len(raw)
            if len(raw) != info.st_size or total > 2 * 1024 * 1024:
                raise PhoneAdmissionError('phone_voicemail_mailbox_policy_unavailable')
            lines = raw.decode('utf-8').splitlines()
        finally: os.close(fd)
        for line in lines:
            line = line.split(';', 1)[0].strip()
            if not line: continue
            include = re.fullmatch(r'#(include|tryinclude)\s+["<]?([A-Za-z0-9_.-]+\.conf)[">]?', line)
            if include:
                category = read(include[2], category, depth + 1, include[1] == 'tryinclude')
                continue
            section = re.fullmatch(r'\[([A-Za-z0-9_-]{1,80})\](?:\(\+\))?', line)
            if section:
                category = section[1]; categories.setdefault(category, {})
                continue
            if line.startswith(('#', '[')):
                raise PhoneAdmissionError('phone_voicemail_mailbox_policy_unavailable')
            assignment = re.fullmatch(r'([A-Za-z0-9_-]{1,80})\s*(?:=>|=)\s*(.*)', line)
            if assignment and category:
                categories[category][assignment[1].lower()] = assignment[2]
        return category
    try:
        _validate_parent_directory(parent)
        read('voicemail.conf')
    except (OSError, UnicodeError):
        raise PhoneAdmissionError('phone_voicemail_mailbox_policy_unavailable') from None
    finally: os.close(parent)
    number, context = mailbox.split('@')
    entry = categories.get(context, {}).get(number)
    if not isinstance(entry, str):
        raise PhoneAdmissionError('phone_voicemail_mailbox_policy_unavailable')
    parts = entry.split(',', 4)
    options = {}
    if len(parts) == 5:
        for item in parts[4].split('|'):
            key, sep, value = item.partition('=')
            if sep: options[key.strip().lower()] = value.strip()
    defaults = categories.get('general', {})
    def integer(value, fallback, maximum):
        if value is None: return fallback
        if not re.fullmatch(r'[0-9]{1,6}', value.strip()) or int(value) > maximum:
            raise PhoneAdmissionError('phone_voicemail_mailbox_policy_unavailable')
        return int(value)
    maximum = integer(defaults.get('maxsecs', defaults.get('maxmessage')), 180, 86400)
    minimum = integer(defaults.get('minsecs', defaults.get('minmessage')), 0, 86400)
    maximum = integer(options.get('maxsecs', options.get('maxmessage')), maximum, 86400)
    minimum = integer(options.get('minsecs'), minimum, 86400)
    if maximum <= 0 or minimum > maximum:
        raise PhoneAdmissionError('phone_voicemail_mailbox_policy_unavailable')
    return {'maxsecs': maximum, 'minsecs': minimum,
            'maxsilence': integer(defaults.get('maxsilence'), 0, 86400),
            'silencethreshold': integer(defaults.get('silencethreshold'), 128, 32767)}


def check_mailbox_audio(mailbox, audio_path, lease, etc_directory=Path('/etc/asterisk')):
    limits = mailbox_limits(mailbox, etc_directory)
    duration = lease.get('duration_seconds')
    if type(duration) not in (int, float) or not math.isfinite(duration):
        raise PhoneAdmissionError('phone_voicemail_audio_invalid')
    if duration < max(0.5, limits['minsecs']) or duration + 1 > limits['maxsecs']:
        raise PhoneAdmissionError('phone_voicemail_mailbox_duration')
    if not limits['maxsilence']:
        return limits
    fd = os.open(audio_path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        VoicemailMedia.file_info(fd, VoicemailMedia.maximum_bytes(MAX_AUDIO_SECONDS))
        silence = 0.0
        with os.fdopen(fd, 'rb', closefd=False) as handle, wave.open(handle, 'rb') as audio:
            while True:
                raw = audio.readframes(160)
                if not raw: break
                samples = array.array('h', raw)
                if sys.byteorder != 'little': samples.byteswap()
                silent = sum(abs(value) for value in samples) <= limits['silencethreshold'] * len(samples)
                silence = silence + len(samples) / 8000 if silent else 0
                if silence + 0.1 >= limits['maxsilence']:
                    raise PhoneAdmissionError('phone_voicemail_mailbox_silence')
    except (wave.Error, EOFError):
        raise PhoneAdmissionError('phone_voicemail_audio_invalid') from None
    finally: os.close(fd)
    return limits


def native_transport_guard(settings):
    from sls_cluster_guard import ClusterFenced, fence_legacy
    try: fence_legacy(settings)
    except (ClusterFenced, OSError, ValueError, RuntimeError):
        raise PhoneAdmissionError('phone_voicemail_cluster_fenced') from None


def check_native_target(store, token, recipient, settings, ami):
    native_transport_guard(settings)
    target = store.voicemail_target(token, recipient)
    with store.locked() as state:
        parent = state['batches'].get(target['source_token'], {})
        if not current_policy(settings, parent, store.directory):
            raise PhoneAdmissionError('phone_voicemail_policy_changed')
    current = ami.mailboxes([recipient]).get(recipient)
    if current != target['mailbox'] or target['sound'] != canonical_sound(target['source_token']):
        raise PhoneAdmissionError('phone_voicemail_mailbox_changed')
    media = VoicemailMedia(store.directory)
    lease = media.validate(target['source_token'])
    check_mailbox_audio(target['mailbox'], media.path / (target['source_token'] + '.wav'), lease)
    return target


@contextmanager
def voicemail_worker_lease(directory):
    """Exclude protected maintenance before touching phone, media or settings state."""
    parent = descriptor = None
    try:
        try:
            parent = _open_directory(Path(directory))
            _validate_parent_directory(parent)
            name = 'enterprise-worker.lock'
            descriptor = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
            owner, group = _account_ids()
            def identity():
                opened = os.fstat(descriptor)
                named = os.stat(name, dir_fd=parent, follow_symlinks=False)
                for entry in (opened, named):
                    if (not stat.S_ISREG(entry.st_mode) or entry.st_nlink != 1
                            or entry.st_uid not in (0, owner) or entry.st_gid not in (0, group)
                            or stat.S_IMODE(entry.st_mode) not in (0o600, 0o640) or entry.st_size > 4096):
                        raise PhoneAdmissionError('phone_voicemail_worker_lock_unsafe')
                if (opened.st_dev, opened.st_ino) != (named.st_dev, named.st_ino):
                    raise PhoneAdmissionError('phone_voicemail_worker_lock_unsafe')
            identity()
            available = True
            try:
                fcntl.flock(descriptor, fcntl.LOCK_SH | fcntl.LOCK_NB)
            except BlockingIOError:
                available = False
            if available: identity()
        except (OSError, CoordinationError):
            raise PhoneAdmissionError('phone_voicemail_worker_lock_unsafe') from None
        yield available
    finally:
        if descriptor is not None: os.close(descriptor)
        if parent is not None: os.close(parent)


def drain_voicemail(directory=DATA, ami_factory=PhoneAmi):
    with voicemail_worker_lease(directory) as available:
        if not available:
            return 0
        return _drain_voicemail(directory, ami_factory)


def _drain_voicemail(directory, ami_factory):
    store = PhoneAdmissionStore(directory)
    state = read_private_json('phone-admission.json', directory)
    store.validate(state)
    # A failed owned StopMixMonitor can prevent the finalizer from running.
    # Closed caller evidence plus a settle period makes missing audio terminal;
    # this path neither stops a recorder nor creates a mailbox call.
    unresolved = [token for token, batch in state['batches'].items()
                  if batch.get('service') == 'live' and batch.get('boot_id') == store.boot_id
                  and live_recording_settled(batch, store.clock(), store.monotonic())
                  and batch.get('vm_fallback') and batch['vm_fallback'].get('sound') is None
                  and any(may_need_fallback(batch, row, recipient)
                          for recipient, row in batch['vm_fallback']['targets'].items())]
    for token in unresolved[:8]:
        try: mark_live_recording_failure(store, token)
        except (OSError, ValueError, PhoneAdmissionError): report_problem('phone_voicemail_recording_unconfirmed')
    candidates = [(token, dict(batch)) for token, batch in state['batches'].items()
                  if batch.get('purpose') != 'voicemail' and batch.get('status') == 'closed'
                  and (batch.get('vm_fallback') or {}).get('sound')]
    if not candidates:
        return 0
    settings = read_settings(directory)
    eligible = []
    for token, batch in candidates:
        try:
            if (current_policy(settings, batch, directory)
                    and batch['created_at'] - 60 <= time.time() < batch['vm_fallback']['expires_at']
                    and time.monotonic() < batch['vm_fallback']['expires_tick']
                    and any(may_need_fallback(batch, row, recipient) for recipient, row in batch['vm_fallback']['targets'].items())):
                eligible.append((token, batch))
        except (PhoneAdmissionError, ValueError, OSError):
            report_problem('phone_voicemail_policy_invalid')
    if not eligible:
        return 0
    limit = checked_limit(settings.get('phone_device_limit', 25))
    submitted = 0
    with ami_factory(settings) as ami:
        for source_token, batch in eligible[:64]:
            if submitted >= 2: break
            media = VoicemailMedia(directory)
            try: lease = media.validate(source_token)
            except (OSError, PhoneAdmissionError) as error:
                code = str(error) if isinstance(error, PhoneAdmissionError) else 'phone_voicemail_audio_unavailable'
                recipients = [recipient for recipient, row in batch['vm_fallback']['targets'].items()
                              if may_need_fallback(batch, row, recipient)]
                if recipients and store.record_voicemail_refusals(source_token, recipients, code): report_problem(code)
                continue
            for recipient, row in batch['vm_fallback']['targets'].items():
                if not may_need_fallback(batch, row, recipient) or submitted >= 2:
                    continue
                current_settings = read_settings(directory)
                if not current_policy(current_settings, batch, directory):
                    continue
                try: native_transport_guard(current_settings)
                except PhoneAdmissionError as error:
                    refuse(store, source_token, recipient, error)
                    continue
                mailbox = ami.mailboxes([recipient]).get(recipient)
                if mailbox != row['mailbox']:
                    refuse(store, source_token, recipient, PhoneAdmissionError('phone_voicemail_mailbox_changed'))
                    continue
                try: check_mailbox_audio(mailbox, media.path / (source_token + '.wav'), lease)
                except (OSError, PhoneAdmissionError) as error:
                    refuse(store, source_token, recipient, error)
                    continue
                with store.locked() as state:
                    generation = store.require_collector(state)['generation']
                inventory = ami.inventory(generation, store.boot_id)
                try:
                    child = store.admit_voicemail(source_token, recipient, limit, inventory, mailbox, canonical_sound(source_token))
                except PhoneAdmissionError as error:
                    if str(error) in ('phone_capacity_busy', 'phone_recipient_busy', 'phone_voicemail_source_unavailable',
                                     'phone_voicemail_noanswer_unconfirmed', 'phone_voicemail_already_attempted'):
                        continue
                    raise
                # The claim is durable before the AMI write. Lost replies never
                # permit a second deposit attempt for the same source/mailbox.
                ami.action({'Action': 'Originate', 'Channel': 'Local/' + recipient + '@' + VM_CONTEXT + '/n',
                    'Context': 'sls-voicemail-playback', 'Exten': 's', 'Priority': '1', 'Timeout': '10000',
                    'Async': 'true', 'Variable': '__SLS_PHONE_TOKEN=' + child + ',__SLS_PHONE_RECIPIENT=' + recipient,
                    'Account': accountcode(child), 'CallerID': 'SLS Mass Notify System <SLS>'})
                submitted += 1
    return submitted


def may_need_fallback(batch, row, recipient=''):
    if row.get('claimed_token') or recipient in batch.get('voicemail_failures', {}): return False
    for dial in row['dials']:
        contacts = [entry for entry in batch['channels'].values() if entry.get('kind') == 'contact' and entry.get('dial') == dial]
        if (len(contacts) != 1 or contacts[0].get('ended_at') is None or contacts[0].get('answered_at') is not None
                or contacts[0].get('joined_at') is not None or contacts[0].get('outcome_uncertain')
                or contacts[0].get('dial_status') not in {'BUSY', 'NOANSWER', 'CANCEL', 'CONGESTION', 'CHANUNAVAIL', 'DONTCALL'}):
            return False
    return True


def refuse(store, source_token, recipient, error):
    code = str(error) if isinstance(error, PhoneAdmissionError) else 'phone_voicemail_audio_unavailable'
    if not re.fullmatch(r'phone_voicemail_[a-z_]{1,80}', code): code = 'phone_voicemail_audio_unavailable'
    store.record_voicemail_refusal(source_token, recipient, code)
    report_problem(code)


_problem_times = {}
def report_problem(code):
    from sls_phone_admission import operator_message
    now = time.monotonic()
    if now - _problem_times.get(code, 0) >= 60:
        if len(_problem_times) >= 32: _problem_times.clear()
        _problem_times[code] = now
        print('SLS voicemail: ' + code + '. ' + operator_message(code), file=sys.stderr)


def start_voicemail_worker(directory=DATA):
    stop = threading.Event()
    def work():
        last_cleanup = 0.0
        while not stop.wait(5):
            try:
                drain_voicemail(directory)
            except Exception as error:
                code = str(error) if isinstance(error, PhoneAdmissionError) else 'phone_voicemail_runtime_unavailable'
                report_problem(code if re.fullmatch(r'phone_[a-z_]{1,80}', code) else 'phone_voicemail_runtime_unavailable')
            if time.monotonic() - last_cleanup >= 60:
                try: cleanup_media(directory)
                except Exception: report_problem('phone_voicemail_cleanup_unavailable')
                last_cleanup = time.monotonic()
    thread = threading.Thread(target=work, name='sls-voicemail', daemon=True)
    thread.start()
    return stop


def main():
    try:
        if sys.argv[1:] != ['--live-request']:
            return 64
        raw = sys.stdin.buffer.read(4097)
        request = checked_json(raw)
        if len(raw) > 4096 or not isinstance(request, dict) or set(request) != {'action', 'token', 'channel', 'seconds'}:
            raise PhoneAdmissionError('phone_voicemail_request_invalid')
        if request['action'] != 'prepare':
            raise PhoneAdmissionError('phone_voicemail_request_invalid')
        result = prepare_live_recording(request['token'], request['channel'], request['seconds'])
        print(json.dumps({'ok': True, **result}))
        return 0
    except (PhoneAdmissionError, ValueError, OSError):
        print(json.dumps({'ok': False, 'failure_code': 'phone_voicemail_recording_unavailable'}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
