#!/usr/bin/python3
"""Whole-audience phone admission and conservative Asterisk lifecycle accounting.

An elapsed playback estimate never releases active capacity. Release requires a
complete Asterisk channel inventory after the last recorded lifecycle change.
Expired unstarted tickets cannot authorize a late call file.
"""
from contextlib import contextmanager
import importlib.util
import argparse
import fcntl
import json
import math
import os
from pathlib import Path

import sys as _config_sys
_config_sys.dont_write_bytecode = True
_config_crypto_spec = importlib.util.spec_from_file_location("sls_config_crypto", Path(__file__).resolve().with_name("sls_config_crypto.py"))
_config_crypto = importlib.util.module_from_spec(_config_crypto_spec)
_config_crypto_spec.loader.exec_module(_config_crypto)
import re
import socket
import stat
import sys
import time

sys.path.insert(0, str(Path(__file__).resolve().parent))
from sls_nws_delivery_claims import _open_directory, _validate_parent_directory, _secure_file_metadata, _account_ids

DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
MAX_BYTES = 16 * 1024 * 1024
MAX_CLOSED = 1000
MAX_CLOSED_BYTES = 4 * 1024 * 1024
MAX_CONTACTS = 1000
MAX_CHANNELS = 4000
MAX_AMI_ACTION_BYTES = 32 * 1024 * 1024
HISTORY_MARKER = b'SLS_PHONE_ADMISSION_V1\n'
HISTORY_SECONDS = 7 * 86400
TOKEN = re.compile(r'[a-f0-9]{32}')
EXTENSION = re.compile(r'[0-9]{1,20}')
VOICE_ID = re.compile(r'voice_[a-f0-9]{24}')
UNIQUEID = re.compile(r'[A-Za-z0-9_.-]{1,128}')
SERVICES = {'announcement', 'nws', 'lightning', 'manual', 'live', 'outbound'}
VOICEMAIL_SERVICES = {'nws', 'lightning', 'live'}
MAILBOX = re.compile(r'([0-9]{1,20})@([A-Za-z0-9_-]{1,80})')
VOICEMAIL_SOUND = re.compile(r'SLS_Mass_Notifications_Plugin/voicemail/[a-f0-9]{32}')
GENERATED_SOUND = re.compile(r'SLS_Mass_Notifications_Plugin/(?:tts/)?[A-Za-z0-9_-]+')
UNANSWERED_STATUSES = {'BUSY', 'NOANSWER', 'CANCEL', 'CONGESTION', 'CHANUNAVAIL', 'DONTCALL'}
VOICEMAIL_REFUSALS = {'phone_voicemail_audio_invalid', 'phone_voicemail_audio_unavailable', 'phone_voicemail_audio_capacity',
    'phone_voicemail_mailbox_duration', 'phone_voicemail_mailbox_silence', 'phone_voicemail_mailbox_policy_unavailable',
    'phone_voicemail_mailbox_changed', 'phone_voicemail_recording_unconfirmed', 'phone_voicemail_cluster_fenced'}


class PhoneAdmissionError(RuntimeError):
    """A fixed operator-safe category; never embeds contact URIs or credentials."""


def checked_json(raw):
    try:
        return json.loads(raw, object_pairs_hook=_config_crypto._pairs,
                          parse_constant=_config_crypto._invalid_constant,
                          parse_float=_config_crypto._finite_float)
    except (ValueError, UnicodeError, RecursionError) as error:
        raise PhoneAdmissionError('phone_state_invalid') from error


def checked_lock_metadata(metadata):
    owner, group = _account_ids()
    if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size != 0
            or metadata.st_uid not in (0, owner) or metadata.st_gid not in (0, group)
            or stat.S_IMODE(metadata.st_mode) not in (0o600, 0o640, 0o644)):
        raise PhoneAdmissionError('phone_state_lock_unsafe')


def file_identity(metadata):
    return (metadata.st_dev, metadata.st_ino, metadata.st_mode, metadata.st_uid,
            metadata.st_gid, metadata.st_nlink, metadata.st_size, metadata.st_mtime_ns, metadata.st_ctime_ns)


def checked_time(value):
    if isinstance(value, bool) or not isinstance(value, (float, int)) or not math.isfinite(value) or value < 0:
        raise PhoneAdmissionError('phone_state_invalid')
    return float(value)


def checked_limit(value):
    if isinstance(value, bool) or not isinstance(value, int) or not 1 <= value <= MAX_CONTACTS:
        raise PhoneAdmissionError('phone_device_limit_invalid')
    return value


def contact_dials(value, endpoint):
    """Accept Asterisk's explicit contacts without losing URI transport options."""
    if not re.fullmatch(r'[A-Za-z0-9_.-]{1,80}', str(endpoint)):
        raise PhoneAdmissionError('phone_endpoint_invalid')
    if not isinstance(value, str) or len(value.encode()) > 65536:
        raise PhoneAdmissionError('phone_contact_inventory_invalid')
    if not value:
        return []
    if re.search(r'[\x00-\x20\x7f,"<>$\\]', value):
        raise PhoneAdmissionError('phone_contact_inventory_invalid')
    output = []
    for dial in value.split('&'):
        if not (dial.startswith('PJSIP/' + endpoint + '/sip:') or dial.startswith('PJSIP/' + endpoint + '/sips:')):
            raise PhoneAdmissionError('phone_contact_inventory_invalid')
        if len(dial) > 4096:
            raise PhoneAdmissionError('phone_contact_inventory_invalid')
        if dial not in output:
            output.append(dial)
    if len(output) > MAX_CONTACTS:
        raise PhoneAdmissionError('phone_audience_exceeds_limit')
    return output


def checked_targets(targets, *, voicemail=False, retain_offline=False):
    if not isinstance(targets, dict) or not 1 <= len(targets) <= MAX_CONTACTS:
        raise PhoneAdmissionError('phone_audience_invalid')
    result = {}
    for extension, dials in targets.items():
        if not isinstance(extension, str) or not (EXTENSION.fullmatch(extension) or VOICE_ID.fullmatch(extension)) or not isinstance(dials, list):
            raise PhoneAdmissionError('phone_audience_invalid')
        if voicemail:
            if (not EXTENSION.fullmatch(extension) or len(dials) != 1 or not isinstance(dials[0], str)
                    or not dials[0].startswith('VM/') or not MAILBOX.fullmatch(dials[0][3:])
                    or MAILBOX.fullmatch(dials[0][3:]).group(1) != extension):
                raise PhoneAdmissionError('phone_voicemail_target_invalid')
            result[extension] = list(dials)
            continue
        if VOICE_ID.fullmatch(extension):
            if len(dials) != 1 or not isinstance(dials[0], str) or not re.fullmatch(r'VOICE/\+[1-9][0-9]{1,14}', dials[0]):
                raise PhoneAdmissionError('phone_outbound_target_invalid')
            result[extension] = list(dials)
            continue
        normalized = []
        for dial in dials:
            if not isinstance(dial, str) or '/' not in dial:
                raise PhoneAdmissionError('phone_contact_inventory_invalid')
            parts = dial.split('/', 2)
            if len(parts) != 3:
                raise PhoneAdmissionError('phone_contact_inventory_invalid')
            checked = contact_dials(dial, parts[1])
            if len(checked) != 1:
                raise PhoneAdmissionError('phone_contact_inventory_invalid')
            if dial not in normalized:
                normalized.append(dial)
        # Unregistered recipients remain a producer-visible partial outcome;
        # there is no endpoint-only fallback that could expand after admission.
        if normalized or retain_offline:
            result[extension] = normalized
    if not result:
        raise PhoneAdmissionError('phone_no_registered_contacts')
    if sum(map(len, result.values())) > MAX_CONTACTS:
        raise PhoneAdmissionError('phone_audience_exceeds_limit')
    return result


def voicemail_enabled(settings, service):
    """The three explicit switches default on; malformed values fail closed."""
    if service == 'nws':
        value = settings.get('nws_voicemail_enabled', '1')
    elif service in {'lightning', 'live'}:
        section = settings.get('xweather' if service == 'lightning' else 'live_paging', {})
        if not isinstance(section, dict):
            return False
        value = section.get('voicemail_enabled', '1')
    else:
        return False
    return type(value) in {bool, int, str} and value in (True, 1, '1')


def checked_voicemail_context(value, service, scope):
    if service == 'live':
        if value is not None:
            raise PhoneAdmissionError('phone_voicemail_source_invalid')
        return
    if (not isinstance(value, dict) or set(value) != {'source_validity', 'event', 'severity'}
            or any(not isinstance(value.get(key), str) or len(value[key].encode()) > 256
                   or re.search(r'[\x00-\x1f\x7f]', value[key]) for key in ('event', 'severity'))):
        raise PhoneAdmissionError('phone_voicemail_source_invalid')
    validity = value.get('source_validity')
    provider = 'nws' if service == 'nws' else 'xweather'
    if (not isinstance(validity, dict) or type(validity.get('version')) is not int or validity['version'] != 1
            or validity.get('provider') != provider or validity.get('group_id') != scope
            or type(validity.get('observed_at')) is not int or not 0 <= validity['observed_at'] <= 253402300799
            or type(validity.get('expires_at')) is not int or not 0 < validity['expires_at'] <= 253402300799
            or len(json.dumps(validity, separators=(',', ':'), allow_nan=False).encode()) > 8192):
        raise PhoneAdmissionError('phone_voicemail_source_invalid')


def checked_voicemail_fallback(value, service, targets):
    """Validate a captured mailbox/contact cohort, including offline recipients."""
    keys = {'schema', 'scope', 'scope_hash', 'source_sound', 'sound', 'weather_context', 'live_origin', 'live_group', 'targets', 'expires_at', 'expires_tick'}
    if (not isinstance(value, dict) or set(value) != keys or value.get('schema') != 1
            or service not in VOICEMAIL_SERVICES or not isinstance(value.get('scope'), str)
            or not re.fullmatch(r'[A-Za-z0-9_-]{0,64}', value['scope'])
            or not isinstance(value.get('scope_hash'), str) or not re.fullmatch(r'[a-f0-9]{64}', value['scope_hash'])):
        raise PhoneAdmissionError('phone_voicemail_source_invalid')
    for name in ('expires_at', 'expires_tick'):
        checked_time(value.get(name))
    checked_voicemail_context(value.get('weather_context'), service, value['scope'])
    if service != 'live' and value['expires_at'] > value['weather_context']['source_validity']['expires_at']:
        raise PhoneAdmissionError('phone_voicemail_source_invalid')
    sound = value.get('sound')
    if sound is not None and (not isinstance(sound, str) or not VOICEMAIL_SOUND.fullmatch(sound)):
        raise PhoneAdmissionError('phone_voicemail_source_invalid')
    rows = value.get('targets')
    if not isinstance(rows, dict) or not 1 <= len(rows) <= MAX_CONTACTS:
        raise PhoneAdmissionError('phone_voicemail_source_invalid')
    for recipient, row in rows.items():
        if (not isinstance(recipient, str) or not EXTENSION.fullmatch(recipient) or not isinstance(row, dict)
                or set(row) != {'dials', 'mailbox', 'claimed_token'} or not isinstance(row.get('mailbox'), str)
                or not MAILBOX.fullmatch(row['mailbox']) or MAILBOX.fullmatch(row['mailbox']).group(1) != recipient):
            raise PhoneAdmissionError('phone_voicemail_source_invalid')
        if checked_targets({recipient: row.get('dials')}, retain_offline=True)[recipient] != row['dials']:
            raise PhoneAdmissionError('phone_voicemail_source_invalid')
        claimed = row.get('claimed_token')
        if claimed is not None and (not isinstance(claimed, str) or not TOKEN.fullmatch(claimed)):
            raise PhoneAdmissionError('phone_voicemail_source_invalid')
    if service == 'live':
        origin, group = value.get('live_origin'), value.get('live_group')
        if (not isinstance(origin, str) or not EXTENSION.fullmatch(origin) or origin in rows
                or not isinstance(group, str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', group)
                or value['scope'] != group or value.get('source_sound') is not None or set(targets) != {origin}):
            raise PhoneAdmissionError('phone_voicemail_source_invalid')
        original = set(targets[origin])
        if any(not set(row['dials']).issubset(original) for row in rows.values()):
            raise PhoneAdmissionError('phone_voicemail_source_invalid')
    else:
        if (value.get('live_origin') is not None or value.get('live_group') is not None
                or not isinstance(value.get('source_sound'), str) or not GENERATED_SOUND.fullmatch(value['source_sound'])
                or any(recipient not in targets or row['dials'] != targets[recipient] for recipient, row in rows.items())):
            raise PhoneAdmissionError('phone_voicemail_source_invalid')
    return value


def accountcode(token):
    if not isinstance(token, str) or not TOKEN.fullmatch(token):
        raise PhoneAdmissionError('phone_ticket_invalid')
    return 'slsphone_' + token


def inventory_rows(inventory):
    if not isinstance(inventory, dict) or inventory.get('complete') is not True:
        raise PhoneAdmissionError('phone_channel_inventory_unavailable')
    observed = checked_time(inventory.get('started_tick'))
    rows = inventory.get('channels')
    if not isinstance(rows, list) or len(rows) > 20000:
        raise PhoneAdmissionError('phone_channel_inventory_invalid')
    result = {}
    for row in rows:
        if not isinstance(row, dict) or not UNIQUEID.fullmatch(str(row.get('uniqueid', ''))):
            raise PhoneAdmissionError('phone_channel_inventory_invalid')
        uid = row['uniqueid']
        if uid in result:
            raise PhoneAdmissionError('phone_channel_inventory_invalid')
        if 'accountcode' not in row or not isinstance(row['accountcode'], str):
            raise PhoneAdmissionError('phone_channel_inventory_invalid')
        result[uid] = row
    return observed, result


class PhoneAdmissionStore:
    def __init__(self, directory=DATA, clock=time.time, lock_seconds=0.5, monotonic=None, boot_id=None):
        self.directory = Path(directory)
        self.clock = clock
        self.lock_seconds = lock_seconds
        self.monotonic = monotonic or (time.monotonic if clock is time.time else clock)
        self.boot_id = boot_id or Path('/proc/sys/kernel/random/boot_id').read_text().strip()
        self._correlation_revision = None
        self._correlation_index = set()
        if not re.fullmatch(r'[A-Za-z0-9-]{1,64}', self.boot_id):
            raise PhoneAdmissionError('phone_boot_identity_unavailable')

    @contextmanager
    def locked(self):
        directory_fd = _open_directory(self.directory)
        temporary = None
        try:
            _validate_parent_directory(directory_fd)
            fd = os.open('phone-admission.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o640, dir_fd=directory_fd)
            with os.fdopen(fd, 'a+') as lock:
                checked_lock_metadata(os.fstat(lock.fileno()))
                deadline = time.monotonic() + self.lock_seconds
                while True:
                    try:
                        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                        break
                    except BlockingIOError:
                        if time.monotonic() >= deadline:
                            raise PhoneAdmissionError('phone_state_busy')
                        time.sleep(0.01)
                opened = os.fstat(lock.fileno())
                named = os.stat('phone-admission.lock', dir_fd=directory_fd, follow_symlinks=False)
                checked_lock_metadata(opened)
                if file_identity(opened) != file_identity(named):
                    raise PhoneAdmissionError('phone_state_lock_changed')
                _secure_file_metadata(lock.fileno())
                self.verify_directory_identity(directory_fd)
                history_marker = self.read_history_marker(directory_fd)
                try:
                    source = os.open('phone-admission.json', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory_fd)
                except FileNotFoundError:
                    if history_marker is not None:
                        raise PhoneAdmissionError('phone_state_history_missing') from None
                    state = {'schema': 1, 'batches': {}, 'collector': None}
                else:
                    with os.fdopen(source, 'r', encoding='utf-8') as handle:
                        metadata = os.fstat(handle.fileno())
                        owner, group = _account_ids()
                        if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size > MAX_BYTES
                                or metadata.st_uid != owner or metadata.st_gid != group or stat.S_IMODE(metadata.st_mode) & 0o137):
                            raise PhoneAdmissionError('phone_state_unsafe')
                        raw = handle.read(MAX_BYTES + 1)
                        if len(raw.encode()) > MAX_BYTES:
                            raise PhoneAdmissionError('phone_state_oversized')
                        try:
                            state = checked_json(raw)
                        except (ValueError, UnicodeError):
                            raise PhoneAdmissionError('phone_state_invalid') from None
                self.validate(state)
                previous = json.dumps(state, separators=(',', ':'), allow_nan=False).encode()
                yield state
                self.verify_directory_identity(directory_fd)
                named = os.stat('phone-admission.lock', dir_fd=directory_fd, follow_symlinks=False)
                if file_identity(os.fstat(lock.fileno())) != file_identity(named):
                    raise PhoneAdmissionError('phone_state_lock_changed')
                try:
                    current = os.stat('phone-admission.json', dir_fd=directory_fd, follow_symlinks=False)
                except FileNotFoundError:
                    current = None
                if ((current is None) != ('metadata' not in locals())
                        or (current is not None and file_identity(current) != file_identity(metadata))):
                    raise PhoneAdmissionError('phone_state_changed')
                self.compact(state)
                encoded = json.dumps(state, separators=(',', ':'), allow_nan=False).encode()
                if len(encoded) > MAX_BYTES:
                    raise PhoneAdmissionError('phone_state_capacity_exceeded')
                if encoded == previous and 'metadata' in locals():
                    self.commit_history_marker(directory_fd, history_marker)
                    return
                temporary = '.phone-admission-' + os.urandom(16).hex()
                target = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640, dir_fd=directory_fd)
                with os.fdopen(target, 'wb') as handle:
                    _secure_file_metadata(handle.fileno())
                    if handle.write(encoded) != len(encoded):
                        raise PhoneAdmissionError('phone_state_write_failed')
                    handle.flush(); os.fsync(handle.fileno())
                os.rename(temporary, 'phone-admission.json', src_dir_fd=directory_fd, dst_dir_fd=directory_fd)
                temporary = None
                try:
                    os.fsync(directory_fd)
                except OSError:
                    # The mutation may already be visible. Do not return a token
                    # or replay a channel action after this uncertain commit.
                    raise PhoneAdmissionError('phone_state_commit_uncertain') from None
                self.commit_history_marker(directory_fd, history_marker)
        finally:
            if temporary is not None:
                try:
                    os.unlink(temporary, dir_fd=directory_fd)
                except FileNotFoundError:
                    pass
            os.close(directory_fd)

    def verify_directory_identity(self, descriptor):
        named = _open_directory(self.directory)
        try:
            _validate_parent_directory(named)
            opened, current = os.fstat(descriptor), os.fstat(named)
            if (opened.st_dev, opened.st_ino) != (current.st_dev, current.st_ino):
                raise PhoneAdmissionError('phone_state_directory_changed')
        finally:
            os.close(named)

    def read_history_marker(self, parent):
        try:
            descriptor = os.open('.phone-admission-required', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        except FileNotFoundError:
            return None
        try:
            metadata = os.fstat(descriptor)
            owner, group = _account_ids()
            if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1
                    or metadata.st_uid not in (0, owner) or metadata.st_gid not in (0, group)
                    or stat.S_IMODE(metadata.st_mode) not in (0o600, 0o640) or metadata.st_size > len(HISTORY_MARKER)):
                raise PhoneAdmissionError('phone_state_history_marker_unsafe')
            raw = os.read(descriptor, len(HISTORY_MARKER) + 1)
            if not HISTORY_MARKER.startswith(raw):
                raise PhoneAdmissionError('phone_state_history_marker_invalid')
            return raw, file_identity(metadata)
        finally:
            os.close(descriptor)

    def commit_history_marker(self, parent, previous):
        current = self.read_history_marker(parent)
        if current != previous:
            raise PhoneAdmissionError('phone_state_history_marker_changed')
        if previous is not None and previous[0] == HISTORY_MARKER:
            return
        flags = os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
        if previous is None:
            flags |= os.O_CREAT | os.O_EXCL
        descriptor = os.open('.phone-admission-required', flags, 0o640, dir_fd=parent)
        try:
            opened = os.fstat(descriptor)
            named = os.stat('.phone-admission-required', dir_fd=parent, follow_symlinks=False)
            if (file_identity(opened) != file_identity(named)
                    or (previous is not None and file_identity(opened) != previous[1])):
                raise PhoneAdmissionError('phone_state_history_marker_changed')
            _secure_file_metadata(descriptor)
            if os.pwrite(descriptor, HISTORY_MARKER, 0) != len(HISTORY_MARKER):
                raise PhoneAdmissionError('phone_state_commit_uncertain')
            os.ftruncate(descriptor, len(HISTORY_MARKER))
            os.fsync(descriptor)
            self.verify_directory_identity(parent)
            named = os.stat('.phone-admission-required', dir_fd=parent, follow_symlinks=False)
            if file_identity(os.fstat(descriptor)) != file_identity(named):
                raise PhoneAdmissionError('phone_state_history_marker_changed')
            os.fsync(parent)
        finally:
            os.close(descriptor)

    @staticmethod
    def validate(state):
        if not isinstance(state, dict) or type(state.get('schema')) is not int or state['schema'] != 1 or not isinstance(state.get('batches'), dict):
            raise PhoneAdmissionError('phone_state_invalid')
        if len(state['batches']) > MAX_CLOSED + MAX_CONTACTS:
            raise PhoneAdmissionError('phone_state_capacity_exceeded')
        for token, batch in state['batches'].items():
            accountcode(token)
            if not isinstance(batch, dict) or batch.get('status') not in {'reserved', 'active', 'closed'} or batch.get('service') not in SERVICES:
                raise PhoneAdmissionError('phone_state_invalid')
            for name in ('created_at', 'updated_at', 'start_deadline'):
                checked_time(batch.get(name))
            for name in ('created_tick', 'updated_tick', 'start_deadline_tick'):
                checked_time(batch.get(name))
            if not isinstance(batch.get('generation'), str) or not TOKEN.fullmatch(batch['generation']) or not re.fullmatch(r'[A-Za-z0-9-]{1,64}', str(batch.get('boot_id', ''))):
                raise PhoneAdmissionError('phone_state_invalid')
            targets = batch.get('targets')
            if not isinstance(targets, dict) or any(not isinstance(row, dict) for row in targets.values()):
                raise PhoneAdmissionError('phone_state_invalid')
            purpose = batch.get('purpose', 'audio')
            if purpose not in {'audio', 'voicemail'}:
                raise PhoneAdmissionError('phone_state_invalid')
            vm_fallback = batch.get('vm_fallback')
            if vm_fallback is not None:
                checked_voicemail_fallback(vm_fallback, batch['service'], {key: row.get('dials') for key, row in targets.items()})
                if purpose != 'audio':
                    raise PhoneAdmissionError('phone_state_invalid')
                if vm_fallback.get('sound') is not None and vm_fallback['sound'].rsplit('/', 1)[-1] != token:
                    raise PhoneAdmissionError('phone_state_invalid')
            if 'voicemail_failures' in batch:
                failures = batch['voicemail_failures']
                if (not vm_fallback or not isinstance(failures, dict) or len(failures) > MAX_CONTACTS
                        or any(recipient not in vm_fallback['targets'] or not isinstance(code, str) or code not in VOICEMAIL_REFUSALS
                               for recipient, code in failures.items())):
                    raise PhoneAdmissionError('phone_state_invalid')
            normalized = checked_targets({key: row.get('dials') for key, row in targets.items()},
                                         voicemail=purpose == 'voicemail', retain_offline=vm_fallback is not None)
            if normalized != {key: row.get('dials') for key, row in targets.items()}:
                raise PhoneAdmissionError('phone_state_invalid')
            if purpose == 'voicemail':
                proof = batch.get('voicemail')
                if (batch['service'] not in VOICEMAIL_SERVICES or len(targets) != 1 or not isinstance(proof, dict)
                        or set(proof) != {'source_token', 'mailbox', 'sound', 'result', 'playback_completed'}
                        or not isinstance(proof.get('source_token'), str) or not TOKEN.fullmatch(proof['source_token'])
                        or not isinstance(proof.get('mailbox'), str) or not MAILBOX.fullmatch(proof['mailbox'])
                        or not isinstance(proof.get('sound'), str) or not VOICEMAIL_SOUND.fullmatch(proof['sound'])
                        or proof['sound'].rsplit('/', 1)[-1] != proof['source_token']
                        or type(proof.get('playback_completed')) is not bool
                        or next(iter(targets.values()))['dials'] != ['VM/' + proof['mailbox']]):
                    raise PhoneAdmissionError('phone_state_invalid')
                result = proof.get('result')
                if result is not None:
                    if (not isinstance(result, dict) or set(result) != {'status', 'recorded_at'}
                            or result.get('status') not in {'SUCCESS', 'FAILED', 'USEREXIT'}):
                        raise PhoneAdmissionError('phone_state_invalid')
                    checked_time(result.get('recorded_at'))
            for key, target in targets.items():
                if VOICE_ID.fullmatch(key):
                    snapshot = target.get('outbound')
                    proof = target.get('route_proof')
                    if (not isinstance(snapshot, dict) or snapshot.get('id') != key
                            or target['dials'] != ['VOICE/' + str(snapshot.get('number', ''))]
                            or not isinstance(proof, dict) or not re.fullmatch('[a-f0-9]{64}', str(proof.get('fingerprint', '')))):
                        raise PhoneAdmissionError('phone_state_invalid')
                    if 'incident_response' in snapshot:
                        checked_incident_response(snapshot['incident_response'], key)
                    if (type(snapshot.get('acknowledgement_required', False)) is not bool
                            or type(snapshot.get('ack_timeout_seconds', 10)) is not int
                            or not 5 <= snapshot.get('ack_timeout_seconds', 10) <= 30):
                        raise PhoneAdmissionError('phone_state_invalid')
                    if 'playback' in target:
                        evidence = target['playback']
                        if (not isinstance(evidence, dict) or set(evidence) != {'completed_at', 'ack_requested', 'ack_status', 'acknowledged_at'}
                                or type(evidence['ack_requested']) is not bool
                                or evidence['ack_requested'] != snapshot.get('acknowledgement_required', False)
                                or evidence['ack_status'] not in {'pending', 'not_requested', 'not_played', 'acknowledged', 'timeout', 'other_key', 'prompt_failed'}):
                            raise PhoneAdmissionError('phone_state_invalid')
                        for name in ('completed_at', 'acknowledged_at'):
                            if evidence[name] is not None: checked_time(evidence[name])
                        if ((evidence['ack_status'] == 'acknowledged') != (evidence['acknowledged_at'] is not None)
                                or (evidence['ack_status'] == 'not_played') != (evidence['completed_at'] is None)
                                or (evidence['ack_status'] in {'pending', 'acknowledged', 'timeout', 'other_key', 'prompt_failed'} and not evidence['ack_requested'])
                                or (evidence['ack_status'] == 'not_requested' and evidence['ack_requested'])):
                            raise PhoneAdmissionError('phone_state_invalid')
            channels = batch.get('channels')
            if not isinstance(channels, dict) or len(channels) > MAX_CHANNELS:
                raise PhoneAdmissionError('phone_state_invalid')
            for uid, row in channels.items():
                if not UNIQUEID.fullmatch(uid) or not isinstance(row, dict) or row.get('kind') not in {'origin', 'contact'}:
                    raise PhoneAdmissionError('phone_state_invalid')
                if row.get('recipient') not in batch['targets'] or not isinstance(row.get('channel'), str):
                    raise PhoneAdmissionError('phone_state_invalid')
                checked_time(row.get('created_at'))
                if row.get('ended_at') is not None:
                    checked_time(row['ended_at'])

    def compact(self, state):
        closed = sorted((batch.get('closed_at', batch['updated_at']), token) for token, batch in state['batches'].items() if batch['status'] == 'closed')
        sizes = {token: len(json.dumps(state['batches'][token], separators=(',', ':')).encode()) for _, token in closed}
        retained_bytes = sum(sizes.values())
        retained_sources = {batch['voicemail']['source_token'] for batch in state['batches'].values()
                            if batch.get('purpose') == 'voicemail' and batch['status'] != 'closed'}
        for index, (ended, token) in enumerate(closed):
            if token in retained_sources:
                continue
            if ended < self.clock() - HISTORY_SECONDS or index < len(closed) - MAX_CLOSED or retained_bytes > MAX_CLOSED_BYTES:
                del state['batches'][token]
                retained_bytes -= sizes[token]

    def reconcile(self, state, inventory):
        observed, current = inventory_rows(inventory)
        now = self.clock()
        tick = self.monotonic()
        health = self.require_collector(state)
        if inventory.get('generation') != health['generation'] or inventory.get('boot_id') != self.boot_id:
            raise PhoneAdmissionError('phone_channel_inventory_generation_changed')
        if not 0 <= tick - observed <= 10:
            raise PhoneAdmissionError('phone_channel_inventory_stale')
        known_uids = {uid for batch in state['batches'].values() if batch['status'] != 'closed' for uid in batch['channels']}
        local_pairs = {row['channel'][:-2] for batch in state['batches'].values() if batch['status'] != 'closed'
                       for row in batch['channels'].values() if re.fullmatch(r'Local/.+;[12]', row['channel'])}
        for uid, row in current.items():
            code = row['accountcode']
            channel = str(row.get('channel', ''))
            tagged = code.startswith('slsphone_')
            known_tag = tagged and code[9:] in state['batches'] and state['batches'][code[9:]]['status'] != 'closed'
            if tagged and not known_tag:
                raise PhoneAdmissionError('phone_orphaned_channels_active')
            legacy = (str(row.get('context', '')) in {'sls-alert-audio', 'nws-alert-audio'}
                      or bool(re.match(r'Local/[^\r\n]*@(?:sls-alert-audio|nws-alert-audio)-', channel)))
            paired = channel.endswith((';1', ';2')) and channel[:-2] in local_pairs
            if legacy and not known_tag and uid not in known_uids and not paired:
                raise PhoneAdmissionError('phone_legacy_channels_active')
        for token, batch in state['batches'].items():
            same_boot = batch['boot_id'] == self.boot_id
            if batch['status'] == 'closed' or (same_boot and batch['updated_tick'] >= observed):
                continue
            # PBX macros can overwrite account codes. A known Uniqueid still
            # holds capacity even if its tag changes. A reused ID after restart
            # may conservatively delay release, never authorize or free a call.
            live = {uid for uid, row in current.items() if row['accountcode'] == accountcode(token) or uid in batch['channels']}
            if live:
                continue
            # After an AMI gap, FreePBX may have created a trunk child and
            # replaced its account code before we observed either event. No
            # nonempty inventory can prove that unknown child has ended.
            if batch.get('external_event_gap') and current:
                continue
            started = {row['recipient'] for row in batch['channels'].values() if row['kind'] == 'origin'}
            mailbox_only_source = (batch.get('vm_fallback') is not None and batch['service'] != 'live'
                                   and not any(target['dials'] for target in batch['targets'].values()))
            if (not mailbox_only_source and same_boot and batch['generation'] == health['generation']
                    and tick < batch['start_deadline_tick'] and started != set(batch['targets'])):
                continue
            # A complete current inventory proves there is no remaining channel.
            # Missed end events become unknown outcomes, never successful plays.
            for channel in batch['channels'].values():
                if channel.get('ended_at') is None:
                    channel.update(ended_at=now, outcome_uncertain=True)
            batch.update(status='closed', closed_at=now, updated_at=now, updated_tick=tick)
        if any(batch.get('runtime_fault') for batch in state['batches'].values() if batch['status'] != 'closed'):
            raise PhoneAdmissionError('phone_outbound_channel_unexpected')
        if current and any(batch.get('external_event_gap') for batch in state['batches'].values() if batch['status'] != 'closed'):
            raise PhoneAdmissionError('phone_outbound_evidence_gap')

    @staticmethod
    def used_contacts(state):
        return sum(len(target['dials']) for batch in state['batches'].values() if batch['status'] != 'closed' for target in batch['targets'].values())

    def admit(self, targets, limit, inventory, *, service, correlation='', start_seconds=60, outbound=None,
              latest_start=None, latest_start_tick=None, outbound_daily_limit=0, vm_fallback=None):
        if vm_fallback is not None:
            checked_voicemail_fallback(vm_fallback, service, targets)
        targets = checked_targets(targets, retain_offline=vm_fallback is not None)
        outbound = outbound or {}
        if not isinstance(outbound, dict) or {key for key in targets if VOICE_ID.fullmatch(key)} != set(outbound):
            raise PhoneAdmissionError('phone_outbound_target_invalid')
        limit = checked_limit(limit)
        size = sum(map(len, targets.values()))
        if size > limit:
            raise PhoneAdmissionError('phone_audience_exceeds_limit')
        if service not in SERVICES or not isinstance(correlation, str) or not re.fullmatch(r'[A-Za-z0-9_.:-]{0,128}', correlation):
            raise PhoneAdmissionError('phone_source_identity_invalid')
        if not isinstance(start_seconds, int) or not 5 <= start_seconds <= 120:
            raise PhoneAdmissionError('phone_start_deadline_invalid')
        if latest_start is not None and (type(latest_start) is not int or not 0 < latest_start <= 253402300799):
            raise PhoneAdmissionError('phone_start_deadline_invalid')
        with self.locked() as state:
            now = self.clock()
            tick = self.monotonic()
            start_window = min(start_seconds, latest_start - now) if latest_start is not None else start_seconds
            if latest_start_tick is not None:
                start_window = min(start_window, latest_start_tick - tick)
            if start_window <= 0:
                raise PhoneAdmissionError('schedule_deadline_expired')
            self.reconcile(state, inventory)
            if self.used_contacts(state) + size > limit:
                raise PhoneAdmissionError('phone_capacity_busy')
            selected = {dial for values in targets.values() for dial in values}
            if any(selected.intersection(target['dials']) for batch in state['batches'].values()
                   if batch['status'] != 'closed' for target in batch['targets'].values()):
                raise PhoneAdmissionError('phone_recipient_busy')
            if any(set(targets).intersection(batch['targets']) for batch in state['batches'].values()
                   if batch['status'] != 'closed' and batch.get('purpose') == 'voicemail'):
                raise PhoneAdmissionError('phone_recipient_busy')
            if outbound:
                self.reserve_outbound_budget(state, len(outbound), outbound_daily_limit, now)
            token = os.urandom(16).hex()
            state['batches'][token] = {'status': 'reserved', 'service': service, 'correlation': correlation,
                'created_at': now, 'updated_at': now, 'start_deadline': now + start_window,
                'created_tick': tick, 'updated_tick': tick, 'start_deadline_tick': tick + start_window,
                'boot_id': self.boot_id, 'generation': state['collector']['generation'],
                'targets': {key: {'dials': values} for key, values in targets.items()}, 'channels': {}}
            for key, metadata in outbound.items():
                state['batches'][token]['targets'][key].update(outbound=metadata['target'], route_proof=metadata['proof'])
            if vm_fallback is not None:
                state['batches'][token]['vm_fallback'] = json.loads(json.dumps(vm_fallback))
            self.validate(state)
        return token

    @staticmethod
    def reserve_outbound_budget(state, count, limit, now):
        """Reserve before any call files exist; uncertain attempts are not refunded.

        The counter survives closed-history compaction and process restart. UTC
        boundaries avoid DST repeats. Legacy installations begin accounting at
        their first admission after upgrade; pre-upgrade calls are not invented.
        """
        if type(limit) is not int or not 0 <= limit <= 10000:
            raise PhoneAdmissionError('outbound_budget_invalid')
        if type(count) is not int or not 1 <= count <= 1000:
            raise PhoneAdmissionError('outbound_budget_invalid')
        day = int(checked_time(now)) // 86400 * 86400
        budget = state.get('outbound_budget', {'day': day, 'used': 0, 'last_observed_at': now})
        if (not isinstance(budget, dict) or set(budget) != {'day', 'used', 'last_observed_at'}
                or type(budget.get('day')) is not int or not 0 <= budget['day'] <= 253402300799 or budget['day'] % 86400
                or type(budget.get('used')) is not int or not 0 <= budget['used'] <= 1000000000
                or isinstance(budget.get('last_observed_at'), bool)
                or not isinstance(budget.get('last_observed_at'), (int, float))
                or not math.isfinite(budget['last_observed_at'])
                or not budget['day'] <= budget['last_observed_at'] < budget['day'] + 86400):
            raise PhoneAdmissionError('outbound_budget_invalid')
        if day < budget['day'] or now < budget['last_observed_at'] - 60:
            raise PhoneAdmissionError('outbound_budget_clock_rollback')
        used = budget['used'] if day == budget['day'] else 0
        if used + count > (limit if limit else 1000000000):
            raise PhoneAdmissionError('outbound_call_budget_exceeded')
        state['outbound_budget'] = {'day': day, 'used': used + count,
                                    'last_observed_at': max(now, budget['last_observed_at'])}

    def bind_origin(self, token, recipient, uniqueid, channel):
        return self._bind(token, recipient, uniqueid, channel, 'origin')

    def attach_voicemail_sound(self, token, source_sound, sound):
        """Publish an immutable generated-audio copy once, without changing audio calls."""
        return self._attach_voicemail_sound(token, sound, source_sound=source_sound)

    def attach_live_voicemail_sound(self, token, channel, sound):
        """Finalize only the admitted live caller's recording, including its hangup path."""
        return self._attach_voicemail_sound(token, sound, channel=channel)

    def _attach_voicemail_sound(self, token, sound, *, source_sound=None, channel=None):
        accountcode(token)
        if sound != 'SLS_Mass_Notifications_Plugin/voicemail/' + token:
            raise PhoneAdmissionError('phone_voicemail_audio_invalid')
        with self.locked() as state:
            health = self.require_collector(state)
            batch = state['batches'].get(token)
            fallback = batch.get('vm_fallback') if batch else None
            if (not fallback or batch.get('purpose', 'audio') != 'audio' or batch['boot_id'] != self.boot_id
                    or batch['generation'] != health['generation'] or batch.get('outcome_uncertain')
                    or self.monotonic() >= fallback['expires_tick'] or self.clock() < batch['created_at'] - 60):
                raise PhoneAdmissionError('phone_voicemail_source_unavailable')
            if batch['service'] == 'live':
                origins = [row for row in batch['channels'].values()
                           if row['kind'] == 'origin' and row['recipient'] == fallback['live_origin']]
                if source_sound is not None or not isinstance(channel, str) or len(origins) != 1 or origins[0]['channel'] != channel:
                    raise PhoneAdmissionError('phone_voicemail_source_invalid')
            elif channel is not None or source_sound != fallback['source_sound']:
                raise PhoneAdmissionError('phone_voicemail_source_invalid')
            if fallback['sound'] is not None:
                if fallback['sound'] == sound:
                    return True
                raise PhoneAdmissionError('phone_voicemail_source_invalid')
            fallback['sound'] = sound
            if batch['service'] != 'live' and not batch['channels'] and not any(row['dials'] for row in batch['targets'].values()):
                # This original admission cannot produce an audio call file:
                # every selected phone was offline. The separate mailbox
                # claim still requires a fresh complete channel inventory.
                batch.update(status='closed', closed_at=self.clock(), updated_at=self.clock(), updated_tick=self.monotonic())
            self.validate(state)
        return True

    def admit_voicemail(self, source_token, recipient, limit, inventory, mailbox, sound):
        """Claim one mailbox deposit after exact, complete unanswered-call evidence.

        The parent claim is durable before an originate can exist. A lost response,
        failed deposit or uncertain channel never permits a second attempt.
        """
        accountcode(source_token)
        limit = checked_limit(limit)
        if (not isinstance(recipient, str) or not EXTENSION.fullmatch(recipient)
                or not isinstance(mailbox, str) or not MAILBOX.fullmatch(mailbox)
                or MAILBOX.fullmatch(mailbox).group(1) != recipient
                or sound != 'SLS_Mass_Notifications_Plugin/voicemail/' + source_token):
            raise PhoneAdmissionError('phone_voicemail_target_invalid')
        with self.locked() as state:
            self.reconcile(state, inventory)
            health = self.require_collector(state)
            source = state['batches'].get(source_token)
            fallback = source.get('vm_fallback') if source else None
            selected = fallback.get('targets', {}).get(recipient) if fallback else None
            if (not fallback or source.get('purpose', 'audio') != 'audio' or source['status'] != 'closed'
                    or source['boot_id'] != self.boot_id or source['generation'] != health['generation']
                    or source.get('outcome_uncertain') or source.get('external_event_gap') or source.get('runtime_fault')
                    or not selected or fallback['sound'] != sound or selected['mailbox'] != mailbox
                    or self.monotonic() >= fallback['expires_tick'] or self.clock() >= fallback['expires_at']
                    or self.clock() < source['created_at'] - 60):
                raise PhoneAdmissionError('phone_voicemail_source_unavailable')
            if selected['claimed_token'] is not None or recipient in source.get('voicemail_failures', {}):
                raise PhoneAdmissionError('phone_voicemail_already_attempted')
            if (re.fullmatch(r'vm-[a-f0-9]{64}', source['correlation'])
                    and any(batch.get('purpose') == 'voicemail' and batch['service'] == source['service']
                            and batch['correlation'] == source['correlation'] and recipient in batch['targets']
                            for batch in state['batches'].values())):
                raise PhoneAdmissionError('phone_voicemail_already_attempted')
            for dial in selected['dials']:
                contacts = [row for row in source['channels'].values() if row['kind'] == 'contact' and row.get('dial') == dial]
                if (len(contacts) != 1 or contacts[0].get('ended_at') is None
                        or contacts[0].get('outcome_uncertain') or contacts[0].get('answered_at') is not None
                        or contacts[0].get('joined_at') is not None or contacts[0].get('dial_status') not in UNANSWERED_STATUSES):
                    raise PhoneAdmissionError('phone_voicemail_noanswer_unconfirmed')
            if self.used_contacts(state) + 1 > limit:
                raise PhoneAdmissionError('phone_capacity_busy')
            if any(recipient in batch['targets'] for batch in state['batches'].values() if batch['status'] != 'closed'):
                raise PhoneAdmissionError('phone_recipient_busy')
            now, tick = self.clock(), self.monotonic()
            start_window = min(60, fallback['expires_at'] - now, fallback['expires_tick'] - tick)
            token = os.urandom(16).hex()
            state['batches'][token] = {'status': 'reserved', 'purpose': 'voicemail', 'service': source['service'],
                'correlation': source['correlation'], 'created_at': now, 'updated_at': now,
                'start_deadline': now + start_window, 'created_tick': tick, 'updated_tick': tick,
                'start_deadline_tick': tick + start_window, 'boot_id': self.boot_id, 'generation': health['generation'],
                'targets': {recipient: {'dials': ['VM/' + mailbox]}}, 'channels': {},
                'voicemail': {'source_token': source_token, 'mailbox': mailbox, 'sound': sound,
                              'playback_completed': False, 'result': None}}
            selected['claimed_token'] = token
            self.validate(state)
        return token

    def record_voicemail_refusal(self, source_token, recipient, failure_code):
        """Keep a bounded terminal refusal without consuming or retrying a mailbox call."""
        _, unclaimed = self._record_voicemail_refusals(source_token, [recipient], failure_code)
        return bool(unclaimed)

    def record_voicemail_refusals(self, source_token, recipients, failure_code):
        """Record a validated cohort's terminal failures in one ledger transaction."""
        changed, _ = self._record_voicemail_refusals(source_token, recipients, failure_code)
        return changed

    def _record_voicemail_refusals(self, source_token, recipients, failure_code):
        accountcode(source_token)
        if not isinstance(failure_code, str) or failure_code not in VOICEMAIL_REFUSALS:
            raise PhoneAdmissionError('phone_voicemail_result_invalid')
        if (not isinstance(recipients, (list, tuple)) or not 1 <= len(recipients) <= MAX_CONTACTS
                or any(not isinstance(recipient, str) or not EXTENSION.fullmatch(recipient) for recipient in recipients)
                or len(set(recipients)) != len(recipients)):
            raise PhoneAdmissionError('phone_voicemail_source_invalid')
        with self.locked() as state:
            source = state['batches'].get(source_token)
            fallback = source.get('vm_fallback') if source else None
            targets = fallback.get('targets', {}) if isinstance(fallback, dict) else {}
            if not source or source.get('purpose', 'audio') != 'audio' or any(recipient not in targets for recipient in recipients):
                raise PhoneAdmissionError('phone_voicemail_source_invalid')
            changed = unclaimed = 0
            for recipient in recipients:
                if targets[recipient]['claimed_token'] is not None:
                    continue
                unclaimed += 1
                failures = source.setdefault('voicemail_failures', {})
                if recipient not in failures:
                    failures[recipient] = failure_code
                    changed += 1
            self.validate(state)
        return changed, unclaimed

    def voicemail_target(self, token, recipient):
        accountcode(token)
        state = read_private_json('phone-admission.json', self.directory)
        self.validate(state)
        batch = state['batches'].get(token)
        if not batch or batch.get('purpose') != 'voicemail' or recipient not in batch['targets']:
            raise PhoneAdmissionError('phone_voicemail_target_invalid')
        proof = json.loads(json.dumps(batch['voicemail']))
        parent = state['batches'].get(proof['source_token'])
        fallback = parent.get('vm_fallback') if parent else None
        if (not fallback or fallback['targets'].get(recipient, {}).get('claimed_token') != token
                or fallback['sound'] != proof['sound'] or fallback['targets'][recipient]['mailbox'] != proof['mailbox']):
            raise PhoneAdmissionError('phone_voicemail_source_unavailable')
        return {**proof, 'service': batch['service'], 'scope': fallback['scope'], 'scope_hash': fallback['scope_hash']}

    def bind_voicemail(self, token, recipient, uniqueid, channel):
        target = self.voicemail_target(token, recipient)
        self._bind(token, recipient, uniqueid, channel, 'origin')
        return target

    def record_voicemail_result(self, token, recipient, channel, status):
        accountcode(token)
        if status not in {'SUCCESS', 'FAILED', 'USEREXIT'}:
            raise PhoneAdmissionError('phone_voicemail_result_invalid')
        with self.locked() as state:
            health = self.require_collector(state)
            batch = state['batches'].get(token)
            if (not batch or batch.get('purpose') != 'voicemail' or batch['status'] not in {'active', 'closed'}
                    or recipient not in batch['targets'] or batch['generation'] != health['generation']
                    or batch['boot_id'] != self.boot_id or batch.get('outcome_uncertain')
                    or self.monotonic() - batch['created_tick'] > 2400):
                raise PhoneAdmissionError('phone_voicemail_result_unconfirmed')
            rows = [row for row in batch['channels'].values() if row['recipient'] == recipient and row['kind'] == 'origin']
            if len(rows) != 1 or rows[0]['channel'] != channel or rows[0].get('outcome_uncertain'):
                raise PhoneAdmissionError('phone_voicemail_result_unconfirmed')
            if status == 'SUCCESS' and not batch['voicemail']['playback_completed']:
                raise PhoneAdmissionError('phone_voicemail_result_unconfirmed')
            previous = batch['voicemail']['result']
            if previous is not None and previous['status'] != status:
                raise PhoneAdmissionError('phone_voicemail_result_invalid')
            if previous is None:
                batch['voicemail']['result'] = {'status': status, 'recorded_at': self.clock()}
            self.validate(state)
        return True

    def record_voicemail_playback(self, token, recipient, channel, status):
        """The exact playback Local half must finish before a saved result is trusted."""
        accountcode(token)
        if status not in {'SUCCESS', 'FAILED'}:
            raise PhoneAdmissionError('phone_voicemail_result_invalid')
        with self.locked() as state:
            health = self.require_collector(state)
            batch = state['batches'].get(token)
            if (not batch or batch.get('purpose') != 'voicemail' or batch['status'] != 'active'
                    or recipient not in batch['targets'] or batch['generation'] != health['generation']
                    or batch['boot_id'] != self.boot_id or batch.get('outcome_uncertain')):
                raise PhoneAdmissionError('phone_voicemail_result_unconfirmed')
            rows = [row for row in batch['channels'].values() if row['recipient'] == recipient and row['kind'] == 'origin']
            if (len(rows) != 1 or rows[0]['channel'][:-1] + '1' != channel
                    or rows[0].get('ended_at') is not None or rows[0].get('outcome_uncertain')):
                raise PhoneAdmissionError('phone_voicemail_result_unconfirmed')
            if status == 'SUCCESS':
                batch['voicemail']['playback_completed'] = True
            self.validate(state)
        return True

    def bind_contact(self, token, recipient, uniqueid, channel, dial):
        return self._bind(token, recipient, uniqueid, channel, 'contact', dial)

    def _bind(self, token, recipient, uniqueid, channel, kind, dial=None):
        accountcode(token)
        if not UNIQUEID.fullmatch(str(uniqueid)) or not isinstance(channel, str) or not 1 <= len(channel) <= 256 or re.search(r'[\x00-\x20\x7f]', channel):
            raise PhoneAdmissionError('phone_channel_identity_invalid')
        with self.locked() as state:
            now = self.clock()
            tick = self.monotonic()
            health = self.require_collector(state)
            batch = state['batches'].get(token)
            if not batch or batch['status'] == 'closed' or recipient not in batch['targets']:
                raise PhoneAdmissionError('phone_ticket_unavailable')
            if batch['boot_id'] != self.boot_id or batch['generation'] != health['generation']:
                raise PhoneAdmissionError('phone_ticket_generation_changed')
            if batch.get('purpose') == 'voicemail':
                if (kind != 'origin' or not re.fullmatch(r'Local/' + re.escape(str(recipient))
                        + r'@sls-alert-voicemail-[a-f0-9]{1,32};2', channel)):
                    raise PhoneAdmissionError('phone_voicemail_origin_invalid')
            if kind == 'contact' and dial not in batch['targets'][recipient]['dials']:
                raise PhoneAdmissionError('phone_contact_not_admitted')
            if kind == 'contact' and VOICE_ID.fullmatch(str(recipient)):
                raise PhoneAdmissionError('phone_contact_not_admitted')
            previous = batch['channels'].get(uniqueid)
            if previous:
                if previous['recipient'] == recipient and previous['kind'] == kind and previous['channel'] == channel and previous.get('ended_at') is None:
                    return list(batch['targets'][recipient]['dials'])
                raise PhoneAdmissionError('phone_channel_identity_conflict')
            same = [row for row in batch['channels'].values() if row['recipient'] == recipient]
            if kind == 'origin':
                if tick >= batch['start_deadline_tick'] or any(row['kind'] == 'origin' for row in same):
                    raise PhoneAdmissionError('phone_ticket_expired_or_used')
            elif (not any(row['kind'] == 'origin' and row.get('ended_at') is None for row in same)
                    or any(row.get('dial') == dial for row in same)):
                raise PhoneAdmissionError('phone_contact_not_admitted')
            batch['channels'][uniqueid] = {'recipient': recipient, 'kind': kind, 'channel': channel,
                'created_at': now, 'ended_at': None, 'dial_status': '', 'answered_at': None, 'joined_at': None}
            if kind == 'contact':
                batch['channels'][uniqueid]['dial'] = dial
            batch.update(status='active', updated_at=now, updated_tick=tick)
            return list(batch['targets'][recipient]['dials'])

    def ended(self, token, uniqueid, cause=None):
        accountcode(token)
        now = self.clock()
        with self.locked() as state:
            batch = state['batches'].get(token)
            channel = batch.get('channels', {}).get(uniqueid) if batch else None
            if channel is None:
                return False
            if channel.get('ended_at') is None:
                channel['ended_at'] = now
            if isinstance(cause, int) and not isinstance(cause, bool) and 0 <= cause <= 127:
                channel['hangup_cause'] = cause
            batch.update(updated_at=now, updated_tick=self.monotonic())
        return True

    def _ledger_revision(self):
        parent = _open_directory(self.directory)
        try:
            _validate_parent_directory(parent)
            fd = os.open('phone-admission.json', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
            try:
                info = os.fstat(fd)
                owner, group = _account_ids()
                if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > MAX_BYTES
                        or info.st_uid not in (0, owner) or info.st_gid != group or stat.S_IMODE(info.st_mode) & 0o137):
                    raise PhoneAdmissionError('phone_config_unsafe')
                return (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns,
                        info.st_uid, info.st_gid, info.st_mode, info.st_nlink)
            finally:
                os.close(fd)
        finally:
            os.close(parent)

    def _possibly_correlated(self, uniqueid, channel):
        """Negative prefilter only; relevant events still use the authoritative lock."""
        revision = self._ledger_revision()
        if revision != self._correlation_revision:
            for attempt in range(2):
                snapshot = read_private_json('phone-admission.json', self.directory)
                self.validate(snapshot)
                index = {(uid, row['channel']) for batch in snapshot['batches'].values()
                         if batch['status'] != 'closed' for uid, row in batch['channels'].items()}
                after = self._ledger_revision()
                if after == revision:
                    self._correlation_index = index
                    self._correlation_revision = revision
                    break
                revision = after
            else:
                return True  # Busy state must never suppress a potentially relevant event.
        return (uniqueid, channel) in self._correlation_index

    def record_event(self, event):
        """Retain only correlated outcome evidence, never the raw AMI message."""
        if not isinstance(event, dict) or event.get('Event') not in {'DialBegin', 'DialEnd', 'ConfbridgeJoin', 'Hangup'}:
            return False
        kind = event['Event']
        uid = str(event.get('DestUniqueid') if kind in {'DialBegin', 'DialEnd'} else event.get('Uniqueid') or '')
        code = str(event.get('DestAccountCode') if kind in {'DialBegin', 'DialEnd'} else event.get('AccountCode') or '')
        if kind == 'DialBegin':
            # FreePBX creates outbound trunk legs. Its DialBegin identifies the
            # authenticated Local origin even before a destination account code
            # is reflected in later channel snapshots.
            code = str(event.get('AccountCode') or code)
        if not UNIQUEID.fullmatch(uid):
            return False
        token = code[9:] if code.startswith('slsphone_') and TOKEN.fullmatch(code[9:]) else None
        source_uid = str(event.get('Uniqueid', '')) if kind == 'DialBegin' else uid
        source_name = str(event.get('Channel', '')) if kind == 'DialBegin' else str(event.get('DestChannel' if kind == 'DialEnd' else 'Channel', ''))
        if token is None:
            # Do not acquire a writer lock/fsync the full ledger for unrelated
            # PBX traffic. Bound identities are durable before their Dial starts;
            # any candidate is still checked authoritatively under the lock.
            if not self._possibly_correlated(source_uid, source_name):
                return False
        now = self.clock()
        with self.locked() as state:
            health = self.require_collector(state)
            # Asterisk/FreePBX may replace account codes. Recover correlation
            # only through an already bound UID AND its exact channel name in
            # this collector incarnation; an account code alone never binds a
            # new origin or grants permission to place a call.
            candidates = [(key, batch) for key, batch in state['batches'].items()
                          if batch['status'] != 'closed' and batch['boot_id'] == self.boot_id
                          and batch['generation'] == health['generation']
                          and source_uid in batch['channels']
                          and batch['channels'][source_uid]['channel'] == source_name]
            if len(candidates) == 1:
                token = candidates[0][0]
            batch = state['batches'].get(token)
            if (not batch or batch['status'] == 'closed' or batch['boot_id'] != self.boot_id
                    or batch['generation'] != health['generation']):
                return False
            channel = batch.get('channels', {}).get(uid) if batch else None
            if kind == 'DialBegin' and batch:
                origin = batch['channels'].get(str(event.get('Uniqueid', '')))
                if (not origin or origin['kind'] != 'origin' or not VOICE_ID.fullmatch(origin['recipient'])
                        or origin['channel'] != str(event.get('Channel', ''))):
                    return False
                target = batch['targets'][origin['recipient']]
                destination = str(event.get('DestChannel', ''))
                allowed = target['route_proof'].get('trunk_endpoints', [])
                expected = (isinstance(allowed, list) and any(destination.startswith('PJSIP/' + str(endpoint) + '-') for endpoint in allowed)
                            and re.fullmatch(r'PJSIP/[A-Za-z0-9_.-]{1,160}', destination))
                simultaneous = any(row['kind'] == 'contact' and row['recipient'] == origin['recipient']
                                   and row.get('ended_at') is None and key != uid for key, row in batch['channels'].items())
                if not expected or simultaneous or origin.get('ended_at') is not None:
                    batch['outcome_uncertain'] = True
                    batch['runtime_fault'] = 'phone_outbound_channel_unexpected'
                    # An unsupported Local/fanout branch can itself create
                    # further children. Only an empty inventory ends this
                    # quarantine, even if the first unexpected child exits.
                    batch['external_event_gap'] = True
                if channel is None:
                    if (len(batch['channels']) >= MAX_CHANNELS or not 1 <= len(destination) <= 256
                            or re.search(r'[\x00-\x20\x7f]', destination)):
                        batch['outcome_uncertain'] = True
                        batch['external_event_gap'] = True
                        batch.update(updated_at=now, updated_tick=self.monotonic())
                        return False
                    batch['channels'][uid] = {'recipient': origin['recipient'], 'kind': 'contact', 'channel': destination,
                        'created_at': now, 'ended_at': None, 'dial_status': '', 'answered_at': None, 'joined_at': None}
                elif channel['channel'] != destination or channel['recipient'] != origin['recipient']:
                    batch['outcome_uncertain'] = True
                    batch['external_event_gap'] = True
                batch.update(updated_at=now, updated_tick=self.monotonic())
                return True
            if channel is None:
                return False
            if source_name and channel['channel'] != source_name:
                batch['outcome_uncertain'] = True
                return False
            if kind == 'DialEnd':
                status = str(event.get('DialStatus', ''))
                if status not in {'ANSWER', 'BUSY', 'NOANSWER', 'CANCEL', 'CONGESTION', 'CHANUNAVAIL', 'DONTCALL', 'TORTURE', 'INVALIDARGS'}:
                    return False
                channel['dial_status'] = status
                if status == 'ANSWER' and channel.get('answered_at') is None:
                    channel['answered_at'] = now
            elif kind == 'ConfbridgeJoin':
                if channel.get('joined_at') is None:
                    channel['joined_at'] = now
            else:
                if channel.get('ended_at') is None:
                    channel['ended_at'] = now
                cause = str(event.get('Cause', ''))
                if cause.isdigit() and 0 <= int(cause) <= 127:
                    channel['hangup_cause'] = int(cause)
                # Page's asynchronous dial can omit per-contact DialEnd on
                # timeout. Use only the exact bound handset's terminal AMI
                # state and explicit negative cause, never a Local answer or
                # an AGI end hook alone, as alternate no-answer evidence.
                if (channel['kind'] == 'contact' and not VOICE_ID.fullmatch(channel['recipient'])
                        and source_name == channel['channel']):
                    terminal_state = str(event.get('ChannelState', ''))
                    description = str(event.get('ChannelStateDesc', ''))
                    if terminal_state == '6' and description == 'Up':
                        if channel.get('answered_at') is None:
                            channel['answered_at'] = now
                    elif (channel.get('dial_status') == '' and channel.get('answered_at') is None
                            and channel.get('joined_at') is None
                            and {'0': 'Down', '3': 'Dialing', '4': 'Ring', '5': 'Ringing',
                                 '7': 'Busy'}.get(terminal_state) == description
                            and cause in {'17', '18', '19', '20'}):
                        channel['dial_status'] = {'17': 'BUSY', '18': 'NOANSWER',
                                                  '19': 'NOANSWER', '20': 'CHANUNAVAIL'}[cause]
                        channel['dial_status_source'] = 'hangup'
            batch.update(updated_at=now, updated_tick=self.monotonic())
        return True

    def outbound_target(self, token, recipient):
        accountcode(token)
        with self.locked() as state:
            batch = state['batches'].get(token)
            target = batch.get('targets', {}).get(recipient) if batch else None
            if not target or not VOICE_ID.fullmatch(str(recipient)):
                raise PhoneAdmissionError('phone_outbound_target_invalid')
            return json.loads(json.dumps(target))

    def outbound_answered(self, token, recipient, channel):
        """Read-only playback gate for this exact Local pair and trunk recipient.

        Answering a Local channel (including a PBX failure prompt) is insufficient.
        The collector must have observed this recipient's trunk DialEnd ANSWER.
        A different recipient, a previous call or an AMI gap cannot release audio.
        """
        state = read_private_json('phone-admission.json', self.directory)
        return self._outbound_answered(state, token, recipient, channel)

    def _outbound_answered(self, state, token, recipient, channel):
        accountcode(token)
        if (not isinstance(recipient, str) or not VOICE_ID.fullmatch(recipient)
                or not isinstance(channel, str)
                or not re.fullmatch(r'Local/' + re.escape(recipient) + r'@sls-outbound-voice-[a-f0-9]{1,32};1', channel)):
            raise PhoneAdmissionError('phone_playback_identity_invalid')
        self.validate(state)
        health = self.require_collector(state)
        batch = state['batches'].get(token)
        if not batch or batch['status'] != 'active' or recipient not in batch['targets']:
            raise PhoneAdmissionError('phone_ticket_unavailable')
        if batch['boot_id'] != self.boot_id or batch['generation'] != health['generation']:
            raise PhoneAdmissionError('phone_ticket_generation_changed')
        if batch.get('runtime_fault') or batch.get('external_event_gap') or batch.get('outcome_uncertain'):
            raise PhoneAdmissionError('phone_outbound_evidence_gap')
        rows = [row for row in batch['channels'].values() if row['recipient'] == recipient]
        origins = [row for row in rows if row['kind'] == 'origin']
        if (len(origins) != 1 or origins[0]['channel'] != channel[:-1] + '2'
                or origins[0].get('ended_at') is not None):
            raise PhoneAdmissionError('phone_playback_identity_invalid')
        contacts = [row for row in rows if row['kind'] == 'contact' and row.get('ended_at') is None]
        if len(contacts) > 1:
            raise PhoneAdmissionError('phone_outbound_channel_unexpected')
        if not contacts:
            return False
        row = contacts[0]
        allowed = batch['targets'][recipient]['route_proof'].get('trunk_endpoints', [])
        if (not any(re.fullmatch(r'PJSIP/' + re.escape(str(endpoint)) + r'-[a-f0-9]{1,32}', row['channel']) for endpoint in allowed)
                or row.get('outcome_uncertain')):
            raise PhoneAdmissionError('phone_outbound_channel_unexpected')
        return row.get('dial_status') == 'ANSWER' and row.get('answered_at') is not None and checked_time(row['answered_at']) > 0

    def record_outbound_playback(self, token, recipient, channel, result):
        allowed = {'complete', 'failed', 'acknowledged', 'timeout', 'other_key', 'prompt_failed'}
        if result not in allowed:
            raise PhoneAdmissionError('phone_acknowledgement_invalid')
        with self.locked() as state:
            if not self._outbound_answered(state, token, recipient, channel):
                raise PhoneAdmissionError('phone_outbound_answer_unconfirmed')
            target = state['batches'][token]['targets'][recipient]
            required = target['outbound'].get('acknowledgement_required', False)
            previous = target.get('playback')
            if result in {'complete', 'failed'}:
                if previous is None:
                    target['playback'] = {'completed_at': self.clock() if result == 'complete' else None,
                        'ack_requested': required, 'ack_status': ('pending' if required else 'not_requested') if result == 'complete' else 'not_played',
                        'acknowledged_at': None}
            else:
                if not required or previous is None or previous['completed_at'] is None:
                    raise PhoneAdmissionError('phone_acknowledgement_invalid')
                if previous['ack_status'] == 'pending':
                    previous['ack_status'] = result
                    if result == 'acknowledged': previous['acknowledged_at'] = self.clock()
            self.validate(state)
        return True

    def mark_event_gap(self):
        with self.locked() as state:
            state['collector'] = None
            for batch in state['batches'].values():
                if batch['status'] != 'closed':
                    batch['outcome_uncertain'] = True
                    if any(VOICE_ID.fullmatch(row['recipient']) and row['kind'] == 'origin' for row in batch['channels'].values()):
                        batch['external_event_gap'] = True

    def has_open_batches(self):
        revision = self._ledger_revision()
        if getattr(self, '_open_revision', None) != revision:
            state = read_private_json('phone-admission.json', self.directory)
            self.validate(state)
            self._open_batches = any(batch['status'] != 'closed' for batch in state['batches'].values())
            self._open_revision = revision
        return self._open_batches

    def display_snapshot(self):
        revision = self._ledger_revision()
        if getattr(self, '_display_revision', None) != revision:
            state = read_private_json('phone-admission.json', self.directory)
            self.validate(state)
            self._display_state = state
            self._display_revision = revision
        state = self._display_state
        health = self.require_collector(state)
        from sls_atomic_json import locked_json
        with locked_json(self.directory / 'phone-collector-display.json', maximum=1024, runtime_owner=True) as view:
            view.clear()
            view.update({'schema': 1, 'connected': True, 'boot_id': self.boot_id,
                         'heartbeat_tick': health['heartbeat_tick'], 'updated_at': int(self.clock()),
                         'active_batches': sum(batch['status'] != 'closed' for batch in state['batches'].values()),
                         'reserved_contacts': self.used_contacts(state)})

    def collector_heartbeat(self, generation):
        accountcode(generation)
        try:
            revision = self._ledger_revision()
        except FileNotFoundError:
            revision = None
        if not hasattr(self, '_heartbeat_anchor') or getattr(self, '_heartbeat_revision', None) != revision:
            state = read_private_json('phone-admission.json', self.directory) if revision is not None else {'schema': 1, 'batches': {}, 'collector': None}
            self.validate(state)
            if revision is not None:
                parent = _open_directory(self.directory)
                try:
                    _validate_parent_directory(parent)
                    marker = self.read_history_marker(parent)
                finally:
                    os.close(parent)
                if marker is None or marker[0] != HISTORY_MARKER:
                    with self.locked():
                        pass
            self._heartbeat_anchor = state.get('collector')
            self._heartbeat_revision = revision
        anchor = self._heartbeat_anchor or {}
        if anchor.get('generation') != generation or anchor.get('boot_id') != self.boot_id:
            with self.locked() as state:
                for batch in state['batches'].values():
                    if batch['status'] != 'closed':
                        batch['outcome_uncertain'] = True
                        if any(VOICE_ID.fullmatch(row['recipient']) and row['kind'] == 'origin' for row in batch['channels'].values()):
                            batch['external_event_gap'] = True
                state['collector'] = {'generation': generation, 'boot_id': self.boot_id, 'heartbeat_tick': self.monotonic()}
                anchor = dict(state['collector'])
            self._heartbeat_anchor = anchor
            self._heartbeat_revision = self._ledger_revision()
        from sls_atomic_json import locked_json
        with locked_json(self.directory / 'phone-collector-heartbeat.json', maximum=1024, runtime_owner=True) as pulse:
            pulse.clear()
            pulse.update({'schema': 1, 'generation': generation, 'boot_id': self.boot_id,
                          'anchor_tick': anchor['heartbeat_tick'], 'heartbeat_tick': self.monotonic()})

    def require_collector(self, state):
        anchor = state.get('collector')
        if (not isinstance(anchor, dict) or anchor.get('boot_id') != self.boot_id
                or not isinstance(anchor.get('generation'), str) or not TOKEN.fullmatch(anchor['generation'])):
            raise PhoneAdmissionError('phone_event_collector_unavailable')
        anchor_tick = checked_time(anchor.get('heartbeat_tick'))
        health = anchor
        try:
            pulse = read_private_json('phone-collector-heartbeat.json', self.directory)
        except FileNotFoundError:
            pulse = None
        if pulse is not None:
            if (set(pulse) != {'schema', 'generation', 'boot_id', 'anchor_tick', 'heartbeat_tick'}
                    or type(pulse.get('schema')) is not int or pulse['schema'] != 1):
                raise PhoneAdmissionError('phone_event_collector_unavailable')
            if (pulse.get('generation') == anchor['generation'] and pulse.get('boot_id') == self.boot_id
                    and pulse.get('anchor_tick') == anchor_tick):
                health = {key: pulse[key] for key in ('generation', 'boot_id', 'heartbeat_tick')}
        if not 0 <= self.monotonic() - checked_time(health.get('heartbeat_tick')) <= 15:
            raise PhoneAdmissionError('phone_event_collector_unavailable')
        return health


ERROR_MESSAGES = {
    'phone_state_history_missing': 'Established phone admission history is missing. Preserve its permanent marker and restore a verified backup before repairing collector health. The history and external-call budget were not reset.',
    'phone_event_collector_state_missing': 'The private phone admission ledger has not been created. Check collector service status and its saved local AMI login and permissions.',
    'phone_event_collector_state_permission': 'The collector health probe cannot read the private admission ledger. Review its asterisk ownership and protected data permissions.',
    'phone_event_collector_never_connected': 'No authenticated collector heartbeat has been recorded. Review the collector service journal and the saved loopback AMI login and permissions.',
    'phone_event_collector_stale': 'The authenticated collector heartbeat is older than fifteen seconds. Review service status, local AMI connectivity and CPU/storage pressure.',
    'phone_event_collector_rebooted': 'The recorded heartbeat belongs to an earlier boot. Wait for a current authenticated heartbeat or inspect the collector service journal.',
    'phone_event_collector_clock_invalid': 'The monotonic heartbeat is invalid or in the future. Review boot/time evidence and the collector service; do not fabricate a fresh heartbeat in the ledger.',
    'outbound_call_budget_exceeded': 'The configured daily SLS external-call limit was reached. No additional external call was authorized; internal announcements remain eligible. Admitted attempts count even if delivery later fails or is uncertain. The counter resets at 00:00 UTC; review the limit in General Settings before deliberately increasing it.',
    'outbound_budget_invalid': 'The external-call budget configuration or retained counter is invalid. External calls were withheld. Review General Settings and protected phone-admission state; do not reset delivery history to bypass the limit.',
    'outbound_budget_clock_rollback': 'The PBX clock moved backwards relative to the external-call budget. External calls were withheld to prevent resetting the daily allowance. Check time synchronization; existing calls are not interrupted.',
    'phone_ack_prompt_unavailable': 'The external keypad acknowledgement prompt is missing or does not match the installed release. Run Repair Installation before enabling keypad acknowledgement.',
    'phone_acknowledgement_invalid': 'The external keypad result could not be associated with this answered call. No acknowledgement was recorded; do not automatically redial.',
    'phone_outbound_answer_unconfirmed': 'External playback was withheld because this recipient\'s trunk answer could not be confirmed within five seconds of the Local channel answering. Check the trunk and SLS phone-events service; no automatic redial was attempted.',
    'phone_playback_identity_invalid': 'External playback did not match its admitted call. Audio was withheld; inspect the SLS phone evidence before sending again.',
    'schedule_deadline_expired': 'The scheduled start deadline elapsed before this phone submission. Unstarted calls were rejected; calls already in progress were not interrupted.',
    'phone_outbound_evidence_gap': 'External call capacity is uncertain after a phone-event connection gap. New phone audio waits until a complete Asterisk inventory has no active calls; existing calls are not interrupted and playback remains unverified.',
    'outbound_route_changed': 'FreePBX outbound routing changed after this alert was admitted. The unstarted external call was rejected; review the routing before sending a new alert.',
    'outbound_forwarding_unbounded': 'This trunk allows SIP forwarding. SLS cannot bound the resulting call legs with the current routing; no external calls were submitted. An SLS-specific forwarding policy is required before this route can be enabled.',
    'phone_outbound_channel_unexpected': 'A tracked outbound call created an unexpected channel. New phone audio is blocked until those calls end; inspect the trunk route and SLS phone evidence.',
    'phone_orphaned_channels_active': 'Asterisk still has SLS calls whose admission history is missing or was restored. Wait for those calls to end; new audio is blocked to preserve the device limit.',
    'phone_legacy_channels_active': 'An older SLS phone call is still active without admission tracking. Wait for it to end before starting another notification; no call was interrupted.',
    'phone_audience_exceeds_limit': 'This complete phone audience exceeds the configured simultaneous-device limit. Reduce the selected audience or increase the limit after its resource check passes. No audio was submitted.',
    'phone_capacity_busy': 'Other notification calls occupy the available phone capacity. No new audio was submitted within the wait limit.',
    'phone_recipient_busy': 'A selected registered phone is still handling another notification. No new audio was submitted within the wait limit.',
    'phone_event_collector_unavailable': 'The phone event collector is unavailable. Check the SLS phone-events service and its local Asterisk connection before trying again.',
    'phone_no_registered_contacts': 'None of the selected internal phones currently has a usable registered contact. Check phone registration before trying again.',
    'phone_channel_inventory_unavailable': 'Asterisk did not provide a complete channel inventory. Existing capacity remains reserved; check the local AMI connection.',
    'phone_state_commit_uncertain': 'The phone reservation changed, but durable storage confirmation failed. No new call files were authorized; inspect storage health before retrying.',
    'phone_ticket_expired_or_used': 'This phone admission has expired or was already started. The delayed call file was rejected without redialing.',
    'phone_ticket_generation_changed': 'The phone event connection restarted after admission. This unstarted call was rejected; active calls were not replayed.',
    'phone_voicemail_already_attempted': 'This alert already claimed its one voicemail attempt for the extension. It will not be deposited or retried again.',
    'phone_voicemail_noanswer_unconfirmed': 'Voicemail was withheld because every admitted handset could not be confirmed unanswered. Answered calls and incomplete phone evidence do not create voicemail.',
    'phone_voicemail_source_unavailable': 'The voicemail source is expired, incomplete, changed or affected by a phone-event gap. No mailbox deposit was authorized.',
    'phone_voicemail_target_invalid': 'The voicemail target does not match the captured FreePBX extension mailbox. No mailbox deposit was authorized.',
    'phone_voicemail_origin_invalid': 'The voicemail channel does not match its separately admitted Local channel. No mailbox deposit was authorized.',
    'phone_voicemail_result_invalid': 'The voicemail application result is invalid or conflicts with the recorded attempt. It was not treated as a successful deposit.',
    'phone_voicemail_result_unconfirmed': 'The voicemail result could not be confirmed for its active admitted channel. Delivery remains unconfirmed and is not retried.',
    'phone_voicemail_audio_unavailable': 'The alert audio could not be retained safely for voicemail. Review storage and audio-file permissions; ordinary phone delivery may still proceed.',
    'phone_voicemail_audio_invalid': 'The voicemail recording is missing, empty or outside the supported audio format or duration. No mailbox deposit was authorized.',
    'phone_voicemail_audio_capacity': 'There is insufficient reserved space for the voicemail recording. Ordinary phone delivery may still proceed; review free space and retention.',
    'phone_voicemail_mailbox_duration': 'The full alert is outside this mailbox\'s existing minimum or maximum recording duration. No truncated voicemail was deposited. Review the mailbox limits or shorten the alert.',
    'phone_voicemail_mailbox_silence': 'This mailbox\'s silence limit could end the alert before its audio finishes. No partial voicemail was deposited. Review the recording or the existing mailbox silence settings.',
    'phone_voicemail_mailbox_policy_unavailable': 'The native mailbox recording policy could not be verified safely. Review its static Asterisk voicemail configuration; realtime or executable profiles are not supported by this fallback. Ordinary paging remains available.',
    'phone_voicemail_policy_changed': 'Voicemail was withheld because the feature, alert validity, quiet hours or saved audience authorization changed before deposit. Newly added recipients never receive an old alert.',
    'phone_voicemail_mailbox_changed': 'This extension\'s mailbox was removed or changed after the alert was admitted. No voicemail was deposited into a different mailbox.',
    'phone_voicemail_recording_not_admitted': 'The live recording does not match the admitted paging caller and ticket. No mailbox deposit was authorized.',
    'phone_voicemail_recording_unconfirmed': 'A complete stopped recording of the live paging caller could not be confirmed. No partial or uncertain recording was deposited.',
    'phone_voicemail_cluster_fenced': 'Notification failover or site authorization withheld this mailbox deposit. Review the active host and site lease; no separate voicemail attempt bypasses the existing notification fence.',
}


def operator_message(code):
    if code == 'phone_live_contact_unidentified':
        return 'The paging caller could not be distinguished from other registered handsets. Check the caller contact address and SIP proxy registration; no page was started.'
    if code == 'phone_live_audience_changed':
        return 'The paging group audience changed or differs from the saved group. Save the group again and retry; no page was started.'
    if code == 'phone_origin_binding_failed':
        return 'Asterisk could not bind the paging caller to its admitted phone ticket. Inspect the phone AGI error and collector journal; no page was started.'
    if code in ERROR_MESSAGES:
        return ERROR_MESSAGES[code]
    if code.startswith('phone_state_'):
        return 'Phone admission storage is unavailable or invalid. No additional calls were authorized; inspect the private phone-admission state and storage permissions.'
    if code.startswith('phone_channel_inventory_') or code.startswith('phone_ami_'):
        return 'The local Asterisk phone inventory could not be verified. No additional calls were authorized; check AMI permissions and the phone-events service.'
    if code.startswith('phone_contact_') or code.startswith('phone_endpoint_'):
        return 'A selected phone contact could not be matched to its admitted registration. The affected call was rejected; check its PJSIP registration.'
    if code == 'outbound_trunk_forwarding_override_unsupported':
        return 'This trunk overrides Dial options without ignore-forwarding. SLS cannot safely alter that override for this call; ordinary trunk settings were not changed. Use a supported route or trunk, or review its SLS integration.'
    if code == 'outbound_agi_callback_unsupported':
        return 'This outbound path runs an AGI or add-on callback whose call behavior has not been verified for SLS capacity limits. The external alert call was rejected. Existing PBX callbacks and ordinary calls were not changed.'
    if code.startswith('outbound_callback_'):
        return 'A FreePBX metadata callback differs from the reviewed SLS compatibility profile, or its file cannot be read safely (' + code + '). Review the installed core, missedcall, CRM and allowlist versions and their signatures before enabling this external path.'
    if code.startswith('outbound_'):
        return 'FreePBX outbound routing could not be verified for this alert (' + code + '). No additional external call was authorized. Check the selected PJSIP trunk, route dial patterns, custom hooks, PIN requirements, and trunk Dial options; unsupported route behavior must be reviewed before use.'
    return 'Phone delivery could not be safely admitted. Check the selected recipients, runtime integration, and SLS phone-events service before trying again.'


def read_private_json(name, directory=DATA):
    if name not in {'mass-notifications.config', 'phone-admission.json', 'phone-collector-heartbeat.json'}:
        raise PhoneAdmissionError('phone_config_unsafe')
    directory_fd = _open_directory(Path(directory))
    try:
        _validate_parent_directory(directory_fd)
        descriptor = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory_fd)
        with os.fdopen(descriptor, 'r', encoding='utf-8') as handle:
            metadata = os.fstat(handle.fileno())
            owner, group = _account_ids()
            if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size > MAX_BYTES
                    or metadata.st_uid not in {0, owner} or metadata.st_gid != group or stat.S_IMODE(metadata.st_mode) & 0o137):
                raise PhoneAdmissionError('phone_config_unsafe')
            raw = handle.read(MAX_BYTES + 1)
            if len(raw.encode()) > MAX_BYTES:
                raise PhoneAdmissionError('phone_config_unsafe')
            value = _config_crypto.decode_config(raw) if name == "mass-notifications.config" else checked_json(raw)
        if not isinstance(value, dict):
            raise PhoneAdmissionError('phone_config_invalid')
        return value
    except (ValueError, UnicodeError):
        raise PhoneAdmissionError('phone_config_invalid') from None
    finally:
        os.close(directory_fd)


def read_settings(directory=DATA):
    settings = read_private_json('mass-notifications.config', directory)
    if not isinstance(settings.get('ami'), dict):
        raise PhoneAdmissionError('phone_config_invalid')
    return settings


def collector_health(directory=DATA):
    try:
        store = PhoneAdmissionStore(directory)
        state = read_private_json('phone-admission.json', directory)
        store.validate(state)
        heartbeat = state.get('collector')
        if not isinstance(heartbeat, dict) or not heartbeat.get('generation'):
            raise PhoneAdmissionError('phone_event_collector_never_connected')
        if heartbeat.get('boot_id') != store.boot_id:
            raise PhoneAdmissionError('phone_event_collector_rebooted')
        try:
            health = store.require_collector(state)
        except PhoneAdmissionError:
            tick=heartbeat.get('heartbeat_tick')
            if type(tick) not in (int,float) or not math.isfinite(tick) or store.monotonic()-tick<0:
                raise PhoneAdmissionError('phone_event_collector_clock_invalid')
            if store.monotonic()-tick>15:
                raise PhoneAdmissionError('phone_event_collector_stale')
            raise
        tick=health.get('heartbeat_tick')
        if type(tick) not in (int,float) or not math.isfinite(tick) or store.monotonic()-tick<0:
            raise PhoneAdmissionError('phone_event_collector_clock_invalid')
        if store.monotonic()-tick>15:
            raise PhoneAdmissionError('phone_event_collector_stale')
        return {'ok': True, 'connected': True,
                'heartbeat_age_seconds': round(store.monotonic() - health['heartbeat_tick'], 3),
                'active_batches': sum(batch['status'] != 'closed' for batch in state['batches'].values()),
                'reserved_contacts': store.used_contacts(state)}
    except Exception as error:
        code = str(error) if isinstance(error, PhoneAdmissionError) else ('phone_event_collector_state_missing' if isinstance(error,FileNotFoundError) else ('phone_event_collector_state_permission' if isinstance(error,PermissionError) else 'phone_event_collector_unavailable'))
        return {'ok': False, 'connected': False, 'failure_code': code, 'detail': operator_message(code)}


class PhoneAmi:
    """Bounded, action-correlated loopback AMI; raw responses are never logged."""
    def __init__(self, settings, events=False, callback=None, connect=socket.create_connection):
        self.settings = settings
        self.events = events
        self.callback = callback
        self.connect = connect
        self.sock = None
        self.buffer = b''

    def __enter__(self):
        ami = self.settings.get('ami')
        if not isinstance(ami, dict) or str(ami.get('host', '127.0.0.1')).lower() not in {'localhost', '127.0.0.1', '::1'}:
            raise PhoneAdmissionError('phone_ami_endpoint_invalid')
        try:
            port = int(ami.get('port') or 5038)
        except (TypeError, ValueError):
            raise PhoneAdmissionError('phone_ami_endpoint_invalid') from None
        if not 1 <= port <= 65535:
            raise PhoneAdmissionError('phone_ami_endpoint_invalid')
        if any(not isinstance(ami.get(key), str) or not 1 <= len(ami[key]) <= 512 or re.search(r'[\x00-\x1f\x7f]', ami[key]) for key in ('username', 'password')):
            raise PhoneAdmissionError('phone_ami_credentials_invalid')
        try:
            host = '::1' if str(ami.get('host', '')).lower() == '::1' else '127.0.0.1'
            self.sock = self.connect((host, port), timeout=3)
            self.action({'Action': 'Login', 'Username': ami['username'], 'Secret': ami['password'],
                         'Events': 'call,system' if self.events else 'off'})
            return self
        except BaseException:
            self.close()
            raise

    def __exit__(self, *args):
        self.close()

    def close(self):
        if self.sock is not None:
            self.sock.close()
            self.sock = None

    def send(self, fields):
        lines = []
        for key, value in fields.items():
            if not re.fullmatch(r'[A-Za-z][A-Za-z0-9]*', key) or re.search(r'[\r\n\x00]', str(value)):
                raise PhoneAdmissionError('phone_ami_request_invalid')
            lines.append(key + ': ' + str(value))
        self.sock.sendall(('\r\n'.join(lines) + '\r\n\r\n').encode())

    def receive(self, deadline):
        if time.monotonic() >= deadline:
            raise TimeoutError('phone_ami_timeout')
        while b'\r\n\r\n' not in self.buffer:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise TimeoutError('phone_ami_timeout')
            self.sock.settimeout(remaining)
            chunk = self.sock.recv(65536)
            if not chunk:
                raise PhoneAdmissionError('phone_ami_disconnected')
            self.buffer += chunk
            if len(self.buffer) > 262144:
                raise PhoneAdmissionError('phone_ami_message_oversized')
        raw, self.buffer = self.buffer.split(b'\r\n\r\n', 1)
        if len(raw) > 131072:
            raise PhoneAdmissionError('phone_ami_message_oversized')
        message = {}
        seen = set()
        canonical = {name.lower(): name for name in (
            'Event', 'Response', 'ActionID', 'Message', 'Uniqueid', 'DestUniqueid', 'Linkedid',
            'Channel', 'DestChannel', 'AccountCode', 'DestAccountCode', 'DialStatus', 'Cause',
            'ChannelState', 'ChannelStateDesc', 'Value', 'Family', 'Key', 'Val', 'ObjectName',
            'EndpointName', 'URI', 'Contact', 'Status', 'Context', 'Extension', 'Priority',
            'Application', 'AppData', 'ChanVariable', 'Variable', 'Output')}
        try:
            lines = raw.decode('utf-8', errors='strict').split('\r\n')
        except UnicodeError as error:
            raise PhoneAdmissionError('phone_ami_message_invalid') from error
        if len(lines) > 128:
            raise PhoneAdmissionError('phone_ami_message_oversized')
        for line in lines:
            if ':' not in line:
                continue  # The AMI greeting precedes the first response.
            key, value = line.split(':', 1)
            if not re.fullmatch(r'[A-Za-z][A-Za-z0-9_-]{0,79}', key) or '\x00' in value:
                raise PhoneAdmissionError('phone_ami_message_invalid')
            folded = key.lower()
            key = canonical.get(folded, key)
            if folded in seen:
                if folded not in {'chanvariable', 'variable', 'output'}:
                    raise PhoneAdmissionError('phone_ami_message_invalid')
                prior = message[key]
                message[key] = (prior if isinstance(prior, list) else [prior]) + [value.lstrip()]
            else:
                seen.add(folded)
                message[key] = value.lstrip()
        return message

    def action(self, fields, complete_event=None, *, missing_database=False):
        action_id = os.urandom(16).hex()
        self.send({**fields, 'ActionID': action_id})
        deadline = time.monotonic() + 5
        response = None
        rows = []
        received_bytes = 0
        for _ in range(25000):
            message = self.receive(deadline)
            received_bytes += len(json.dumps(message, ensure_ascii=False, separators=(',', ':')).encode())
            if received_bytes > MAX_AMI_ACTION_BYTES:
                raise PhoneAdmissionError('phone_ami_response_oversized')
            if message.get('ActionID') != action_id:
                if self.callback and 'Event' in message:
                    self.callback(message)
                continue
            if 'Response' in message:
                if message.get('Response', '').lower() != 'success':
                    if (missing_database and fields.get('Action') == 'DBGet'
                            and message.get('Response', '').lower() == 'error'
                            and message.get('Message') == 'Database entry not found'):
                        return message, []
                    raise PhoneAdmissionError('phone_ami_action_rejected')
                response = message
                if complete_event is None:
                    return response, []
            elif 'Event' in message:
                rows.append(message)
                if message['Event'] == complete_event:
                    if response is None:
                        raise PhoneAdmissionError('phone_ami_response_incomplete')
                    return response, rows
        raise PhoneAdmissionError('phone_ami_response_oversized')

    def variable(self, expression, channel=None):
        fields = {'Action': 'Getvar', 'Variable': expression}
        if channel is not None:
            if not isinstance(channel, str) or not re.fullmatch(r'PJSIP/[A-Za-z0-9_.-]{1,80}-[a-fA-F0-9]{8}', channel):
                raise PhoneAdmissionError('phone_live_origin_invalid')
            fields['Channel'] = channel
        response, _ = self.action(fields)
        return str(response.get('Value', ''))

    def database(self, family, key):
        """Read a literal FreePBX key without enabling dangerous dialplan functions."""
        if family != 'AMPUSER' or not isinstance(key, str) or not re.fullmatch(r'[0-9]{1,20}/voicemail', key):
            raise PhoneAdmissionError('phone_ami_request_invalid')
        response, events = self.action({'Action': 'DBGet', 'Family': family, 'Key': key},
                                       'DBGetResponse', missing_database=True)
        if response.get('Response', '').lower() == 'error':
            return ''
        if (len(events) != 1 or events[0].get('Event') != 'DBGetResponse' or events[0].get('Family') != family
                or events[0].get('Key') != key or not isinstance(events[0].get('Val'), str)
                or len(events[0]['Val'].encode()) > 4096 or re.search(r'[\x00-\x1f\x7f]', events[0]['Val'])):
            raise PhoneAdmissionError('phone_ami_database_response_invalid')
        return events[0]['Val']

    def contacts(self, extensions):
        if not isinstance(extensions, list) or not 1 <= len(extensions) <= MAX_CONTACTS or any(not isinstance(value, str) or not EXTENSION.fullmatch(value) for value in extensions):
            raise PhoneAdmissionError('phone_audience_invalid')
        targets = {}
        deadline = time.monotonic() + 30
        for extension in dict.fromkeys(extensions):
            if time.monotonic() >= deadline:
                raise PhoneAdmissionError('phone_ami_inventory_timeout')
            endpoint = extension
            value = self.variable('PJSIP_DIAL_CONTACTS(' + endpoint + ')')
            if not value:
                alias = self.variable('DB(DEVICE/' + extension + '/dial)')
                match = re.fullmatch(r'PJSIP/([A-Za-z0-9_.-]{1,80})', alias)
                if match:
                    endpoint = match[1]
                    value = self.variable('PJSIP_DIAL_CONTACTS(' + endpoint + ')')
            targets[extension] = contact_dials(value, endpoint)
        return targets

    def mailboxes(self, extensions):
        """Use FreePBX's own extension mailbox context and Asterisk existence check."""
        if (not isinstance(extensions, list) or len(extensions) > MAX_CONTACTS
                or any(not isinstance(value, str) or not EXTENSION.fullmatch(value) for value in extensions)):
            raise PhoneAdmissionError('phone_voicemail_target_invalid')
        result = {}
        deadline = time.monotonic() + 30
        for extension in dict.fromkeys(extensions):
            if time.monotonic() >= deadline:
                raise PhoneAdmissionError('phone_ami_inventory_timeout')
            context = self.database('AMPUSER', extension + '/voicemail')
            if context in {'', 'novm'} or not re.fullmatch(r'[A-Za-z0-9_-]{1,80}', context):
                continue
            mailbox = extension + '@' + context
            if self.variable('VM_INFO(' + mailbox + ',exists)') == '1':
                result[extension] = mailbox
        return result

    def inventory(self, generation, boot_id):
        # Fence against binds that happen DURING collection. Completion time
        # would falsely make those channels eligible for absent-row cleanup.
        started = time.monotonic()
        _, events = self.action({'Action': 'CoreShowChannels'}, 'CoreShowChannelsComplete')
        rows = [event for event in events if event.get('Event') == 'CoreShowChannel']
        complete = events[-1]
        if (len(events) != len(rows) + 1 or any(any(key not in row for key in ('AccountCode', 'Uniqueid', 'Channel', 'Context')) for row in rows)
                or not str(complete.get('ListItems', '')).isdigit() or int(complete['ListItems']) != len(rows)):
            raise PhoneAdmissionError('phone_channel_inventory_incomplete')
        return {'complete': True, 'started_tick': started, 'generation': generation, 'boot_id': boot_id,
                'channels': [{'uniqueid': row['Uniqueid'], 'accountcode': row['AccountCode'],
                              'channel': row['Channel'], 'context': row['Context']} for row in rows]}

    def dialplan(self, context, extension=None):
        if not re.fullmatch(r'[A-Za-z0-9_-]{1,80}', context) or (extension is not None and not re.fullmatch(r'[+0-9A-Za-z_-]{1,80}', extension)):
            raise PhoneAdmissionError('phone_ami_request_invalid')
        fields = {'Action': 'ShowDialPlan', 'Context': context}
        if extension is not None:
            fields['Extension'] = extension
        _, events = self.action(fields, 'ShowDialPlanComplete')
        rows = [event for event in events if event.get('Event') == 'ListDialplan']
        complete = events[-1]
        if len(events) != len(rows) + 1 or not str(complete.get('ListItems', '')).isdigit() or int(complete['ListItems']) != len(rows) or len(rows) > 10000:
            raise PhoneAdmissionError('phone_dialplan_inventory_incomplete')
        return rows


def checked_incident_response(value, recipient):
    if (not isinstance(value, dict)
            or set(value) != {'binding_id', 'token', 'incident_id', 'person_id', 'recipient_id'}
            or not re.fullmatch(r'[a-f0-9]{64}', str(value.get('binding_id', '')))
            or not re.fullmatch(r'[A-F0-9]{12}', str(value.get('token', '')))
            or not re.fullmatch(r'inc_[a-f0-9]{32}', str(value.get('incident_id', '')))
            or not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', str(value.get('person_id', '')))
            or value.get('recipient_id') != recipient):
        raise PhoneAdmissionError('phone_incident_response_invalid')
    return value


def check_current_outbound(settings, target):
    fields = ('id', 'number', 'route_mode', 'trunk_id', 'caller_id')
    if (not isinstance(target, dict) or not set(fields) <= set(target)
            or set(target) - set(fields) - {'acknowledgement_required', 'ack_timeout_seconds', 'incident_response'}
            or any(not isinstance(target[key], str) for key in fields)
            or type(target.get('acknowledgement_required', False)) is not bool
            or type(target.get('ack_timeout_seconds', 10)) is not int
            or not 5 <= target.get('ack_timeout_seconds', 10) <= 30):
        raise PhoneAdmissionError('phone_outbound_target_invalid')
    if 'incident_response' in target:
        checked_incident_response(target['incident_response'], target['id'])
    voice = settings.get('outbound_voice')
    if not isinstance(voice, dict) or str(voice.get('enabled', '0')).lower() not in {'1', 'true'}:
        raise PhoneAdmissionError('phone_outbound_disabled_or_changed')
    rows = voice.get('recipients')
    if not isinstance(rows, list):
        raise PhoneAdmissionError('phone_outbound_target_invalid')
    matched = [row for row in rows if isinstance(row, dict) and row.get('id') == target['id'] and str(row.get('enabled', '1')).lower() in {'1', 'true'}]
    expected = {'id': target['id'], 'number': matched[0].get('number') if len(matched) == 1 else None,
                'route_mode': voice.get('route_mode'), 'trunk_id': voice.get('trunk_id') if voice.get('route_mode') == 'trunk' else '',
                'caller_id': voice.get('caller_id', '')}
    if expected != {key: target[key] for key in fields}:
        raise PhoneAdmissionError('phone_outbound_disabled_or_changed')


def preserve_audio_media(directory, extensions, duration, sound):
    if duration is None and sound is None:
        return
    if isinstance(duration, bool) or not isinstance(duration, (int, float)) or not math.isfinite(duration) or not 0 < duration <= 1800:
        raise PhoneAdmissionError('phone_audio_duration_invalid')
    if not isinstance(sound, str) or not re.fullmatch(r'SLS_Mass_Notifications_Plugin/(?:tts/)?[A-Za-z0-9_-]+', sound):
        raise PhoneAdmissionError('phone_audio_media_invalid')
    from sls_audio_queue import ticket_state
    now = time.time()
    end = now + math.ceil(duration) + 5
    # Existing shared media leases must cover time spent waiting for global
    # channel capacity as well as the ensuing Page. Actual channel accounting
    # remains in phone-admission; these timestamps only protect the audio file.
    with ticket_state(directory) as state:
        state['media'] = {key: value for key, value in state['media'].items() if value > now}
        name = sound.rsplit('/', 1)[-1] + '.wav'
        state['media'][name] = max(state['media'].get(name, 0), end + 900)
        for extension in extensions:
            state['recipients'][extension] = max(state['recipients'].get(extension, 0), end)


def check_live_audience(settings, extensions, live_origin, live_group, live_external=False, live_caller_number=None, include_origin=False):
    """Bind live admission to one current group and its exact phone audience."""
    paging = settings.get('live_paging', {})
    if (not isinstance(live_origin, str) or not EXTENSION.fullmatch(live_origin)
            or not isinstance(live_group, str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', live_group)
            or not isinstance(paging, dict) or str(paging.get('enabled')) != '1'):
        raise PhoneAdmissionError('phone_live_origin_invalid')
    rows = paging.get('groups', [])
    if not isinstance(rows, list) or len(rows) > 10:
        raise PhoneAdmissionError('phone_live_group_invalid')
    selected = [row for row in rows if isinstance(row, dict) and row.get('group_id') == live_group]
    if len(selected) != 1:
        raise PhoneAdmissionError('phone_live_group_invalid')
    group = selected[0]
    callers = group.get('allowed_callers') if 'name' in group else paging.get('allowed_callers', [])
    if type(live_external) is not bool:
        raise PhoneAdmissionError('phone_live_origin_invalid')
    if live_external:
        # The trusted local AGI verifies the PIN before requesting admission.
        # Recheck the opt-ins here; an arbitrary caller ID never grants access.
        approved = group.get('external_callers', [])
        if (not isinstance(approved, list) or not 1 <= len(approved) <= 100
                or any(not isinstance(number, str) or not re.fullmatch(r'\+[1-9][0-9]{6,14}', number) for number in approved)
                or not isinstance(live_caller_number, str) or live_caller_number not in approved
                or str(paging.get('external_access', '0')) != '1'
                or str(group.get('allow_external', '0')) != '1'
                or not group.get('pin_hash') or live_origin != paging.get('extension')):
            raise PhoneAdmissionError('phone_live_origin_invalid')
    elif live_caller_number not in (None, '') or not isinstance(callers, list) or live_origin not in callers:
        raise PhoneAdmissionError('phone_live_origin_invalid')
    if 'name' in group:
        targets = group.get('extensions')
    else:
        saved = settings.get('announcement_groups', [])
        if not isinstance(saved, list):
            raise PhoneAdmissionError('phone_live_group_invalid')
        referenced = [row for row in saved if isinstance(row, dict) and row.get('id') == live_group]
        if len(referenced) != 1:
            raise PhoneAdmissionError('phone_live_group_invalid')
        targets = referenced[0].get('extensions')
    if (not isinstance(targets, list) or not 1 <= len(targets) <= 1000
            or any(not isinstance(value, str) or not EXTENSION.fullmatch(value) for value in targets)
            or not isinstance(extensions, list) or not extensions
            or any(not isinstance(value, str) or not EXTENSION.fullmatch(value) for value in extensions)
            or len(extensions) != len(set(extensions))
            or set(extensions) != (set(targets) if include_origin else set(targets) - {live_origin})):
        raise PhoneAdmissionError('phone_live_audience_changed')


def exclude_live_contact(ami, targets, origin, channel, uniqueid):
    """Exclude the authenticated caller's contact, retaining other handsets."""
    import ipaddress
    if (not isinstance(channel, str) or not re.fullmatch(r'PJSIP/[A-Za-z0-9_.-]{1,80}-[a-fA-F0-9]{8}', channel)
            or not isinstance(uniqueid, str) or not re.fullmatch(r'[A-Za-z0-9_.-]{1,128}', uniqueid)
            or ami.variable('UNIQUEID', channel) != uniqueid):
        raise PhoneAdmissionError('phone_live_origin_invalid')
    endpoint = ami.variable('CHANNEL(endpoint)', channel)
    if not EXTENSION.fullmatch(endpoint) or ami.variable('DB(DEVICE/' + endpoint + '/dial)') != 'PJSIP/' + endpoint:
        raise PhoneAdmissionError('phone_live_origin_invalid')
    user = ami.variable('DB(DEVICE/' + endpoint + '/user)')
    if (endpoint if user in ('', 'none') else user) != origin:
        raise PhoneAdmissionError('phone_live_origin_invalid')

    def address(value, sip=False):
        if sip:
            match = re.fullmatch(r'PJSIP/[A-Za-z0-9_.-]{1,80}/(sips?):([^;?]+)(?:[;?].*)?', value)
            if not match: return None
            authority = match[2].rsplit('@', 1)[-1]
            default = 5061 if match[1] == 'sips' else 5060
        else:
            authority, default = value, None
        match = re.fullmatch(r'(\[[0-9a-fA-F:]+\]|[^:\[\]]+)(?::([0-9]{1,5}))?', authority)
        if not match: return None
        try: host = str(ipaddress.ip_address(match[1].strip('[]')))
        except ValueError: return None
        port = int(match[2]) if match[2] else default
        return (host, port) if port is not None and 1 <= port <= 65535 else None

    remote = address(ami.variable('CHANNEL(pjsip,remote_addr)', channel))
    source = ami.contacts([endpoint]).get(endpoint, [])
    matched = [dial for dial in source if remote is not None and address(dial, True) == remote]
    if len(matched) != 1:
        # One registered contact can safely be excluded when a proxy hides its
        # address. Several contacts need positive identification to avoid paging
        # the caller or silently discarding every handset on the extension.
        if len(source) != 1: raise PhoneAdmissionError('phone_live_contact_unidentified')
        matched = source
    caller_uri = matched[0].split('/', 2)[-1]
    return {recipient: [dial for dial in dials if dial.split('/', 2)[-1] != caller_uri]
            for recipient, dials in targets.items()}


def admit_audience(extensions, *, outbound=None, service, correlation='', wait_seconds=30, duration=None, sound=None,
                   live_origin=None, live_group=None, directory=DATA, settings=None, ami_factory=PhoneAmi,
                   latest_start=None, live_external=False, live_caller_number=None, live_channel=None, live_uniqueid=None,
                   voicemail=False, voicemail_scope='', voicemail_context=None):
    if not isinstance(wait_seconds, (float, int)) or not 0 <= wait_seconds <= 300:
        raise PhoneAdmissionError('phone_wait_limit_invalid')
    if latest_start is not None and (type(latest_start) is not int or not 0 < latest_start <= 253402300799):
        raise PhoneAdmissionError('phone_start_deadline_invalid')
    latest_start_tick = None if latest_start is None else time.monotonic() + max(0, latest_start - time.time())
    def check_deadline():
        if latest_start is not None and (time.time() >= latest_start or time.monotonic() >= latest_start_tick):
            raise PhoneAdmissionError('schedule_deadline_expired')
    check_deadline()
    settings = settings if settings is not None else read_settings(directory)
    limit = checked_limit(settings.get('phone_device_limit', 25))
    store = PhoneAdmissionStore(directory)
    if (type(voicemail) is not bool or not isinstance(voicemail_scope, str)
            or not re.fullmatch(r'[A-Za-z0-9_-]{0,64}', voicemail_scope)
            or (voicemail and service not in VOICEMAIL_SERVICES)):
        raise PhoneAdmissionError('phone_voicemail_source_invalid')
    voicemail = voicemail and voicemail_enabled(settings, service)
    outbound = outbound or []
    if not isinstance(extensions, list) or not isinstance(outbound, list) or len(outbound) > 1000 or (live_origin is not None and outbound):
        raise PhoneAdmissionError('phone_audience_invalid')
    if type(live_external) is not bool:
        raise PhoneAdmissionError('phone_live_origin_invalid')
    contact_origin = live_channel is not None or live_uniqueid is not None
    if contact_origin and (live_external or not live_channel or not live_uniqueid):
        raise PhoneAdmissionError('phone_live_origin_invalid')
    if service == 'live' or live_origin is not None or live_group is not None or live_external or live_caller_number not in (None, '') or contact_origin:
        if service != 'live':
            raise PhoneAdmissionError('phone_live_origin_invalid')
        check_live_audience(settings, extensions, live_origin, live_group, live_external, live_caller_number, contact_origin)
    with ami_factory(settings) as ami:
        targets = ami.contacts(extensions) if extensions else {}
        if contact_origin:
            targets = exclude_live_contact(ami, targets, live_origin, live_channel, live_uniqueid)
        offline = [key for key, values in targets.items() if not values]
        fallback = None
        if voicemail:
            vm_extensions = [extension for extension in extensions if extension != live_origin]
            mailboxes = ami.mailboxes(vm_extensions)
            if mailboxes:
                from sls_voicemail import scope_fingerprint
                scope = live_group if service == 'live' else voicemail_scope
                scope_hash = scope_fingerprint(settings, service, scope)
                if not isinstance(scope_hash, str) or not re.fullmatch(r'[a-f0-9]{64}', scope_hash):
                    raise PhoneAdmissionError('phone_voicemail_source_invalid')
                if service != 'live' and (not isinstance(sound, str) or not GENERATED_SOUND.fullmatch(sound)):
                    raise PhoneAdmissionError('phone_voicemail_audio_invalid')
                checked_voicemail_context(voicemail_context, service, scope)
                now, tick = store.clock(), store.monotonic()
                retention = min(1800, math.ceil(duration)) if isinstance(duration, (int, float)) and not isinstance(duration, bool) and math.isfinite(duration) and 0 < duration <= 1800 else 1800
                expires_at = min(now + retention + 600, latest_start) if latest_start is not None else now + retention + 600
                if service != 'live':
                    expires_at = min(expires_at, voicemail_context['source_validity']['expires_at'])
                if expires_at <= now:
                    raise PhoneAdmissionError('phone_voicemail_source_unavailable')
                fallback = {'schema': 1, 'scope': scope, 'scope_hash': scope_hash,
                    'source_sound': None if service == 'live' else sound, 'sound': None,
                    'weather_context': json.loads(json.dumps(voicemail_context)),
                    'live_origin': live_origin if service == 'live' else None,
                    'live_group': live_group if service == 'live' else None,
                    'expires_at': expires_at, 'expires_tick': tick + max(0, expires_at - now),
                    'targets': {extension: {'dials': list(targets[extension]), 'mailbox': mailbox, 'claimed_token': None}
                                for extension, mailbox in mailboxes.items()}}
        outbound_metadata = {}
        outbound_failures = []
        seen_voice_ids = set()
        for target in outbound:
            check_deadline()
            if (not isinstance(target, dict) or not isinstance(target.get('id'), str)
                    or not VOICE_ID.fullmatch(target['id']) or target['id'] in seen_voice_ids):
                raise PhoneAdmissionError('phone_outbound_target_invalid')
            seen_voice_ids.add(target['id'])
            from sls_outbound_routes import validate_route, OutboundRouteError
            try:
                check_current_outbound(settings, target)
                proof = validate_route(ami, target)
            except (OutboundRouteError, PhoneAdmissionError) as error:
                # A refused external destination must not suppress authorized
                # internal paging or another independently valid destination.
                code = str(error)
                outbound_failures.append({'id': target['id'], 'failure_code': code,
                                          'detail': operator_message(code), 'retryable': False})
                continue
            targets[target['id']] = ['VOICE/' + target['number']]
            outbound_metadata[target['id']] = {'target': dict(target), 'proof': proof}
        if not any(targets.values()) and outbound_failures:
            raise PhoneAdmissionError(outbound_failures[0]['failure_code'])
        live_dials = None
        if live_origin is not None:
            live_dials = list(dict.fromkeys(dial for values in targets.values() for dial in values))
            targets = {live_origin: live_dials}
        checked = checked_targets(targets, retain_offline=fallback is not None)
        if sum(map(len, checked.values())) > limit:
            raise PhoneAdmissionError('phone_audience_exceeds_limit')
        deadline = time.monotonic() + wait_seconds
        while True:
            check_deadline()
            with store.locked() as state:
                generation = store.require_collector(state)['generation']
            inventory = ami.inventory(generation, store.boot_id)
            try:
                token = store.admit(checked, limit, inventory, service=service, correlation=correlation, outbound=outbound_metadata,
                                    latest_start=latest_start, latest_start_tick=latest_start_tick,
                                    outbound_daily_limit=settings.get('outbound_voice', {}).get('daily_call_limit', 0) if outbound_metadata else 0,
                                    vm_fallback=fallback)
                if service != 'live':
                    preserve_audio_media(directory, extensions, duration, sound)
                check_deadline()
                voicemail_error = None
                if fallback is not None and service != 'live':
                    try:
                        from sls_voicemail import snapshot_audio
                        retained_sound = snapshot_audio(token, sound, duration, directory)
                        store.attach_voicemail_sound(token, sound, retained_sound)
                    except (PhoneAdmissionError, OSError, ValueError, RuntimeError) as error:
                        # The normal audio reservation already exists. Its calls
                        # remain valid even if retaining a voicemail copy fails.
                        voicemail_error = str(error) if isinstance(error, PhoneAdmissionError) and str(error) in VOICEMAIL_REFUSALS else 'phone_voicemail_audio_unavailable'
                        for recipient in fallback['targets']:
                            try:
                                store.record_voicemail_refusal(token, recipient, voicemail_error)
                            except (PhoneAdmissionError, OSError, ValueError):
                                # Failure reporting cannot revoke or silently
                                # recreate the admitted ordinary audio ticket.
                                pass
                return {'token': token, 'targets': [key for key in checked if not VOICE_ID.fullmatch(key) and checked[key]],
                        'outbound_targets': list(outbound_metadata), 'outbound_failures': outbound_failures, 'unavailable': offline,
                        'contacts': sum(map(len, checked.values())), 'live_dial': '&'.join(live_dials) if live_dials is not None else '',
                        'voicemail_targets': list(fallback['targets']) if fallback else [],
                        'vm_only': fallback is not None and not any(checked.values()),
                        'voicemail_failure_code': voicemail_error}
            except PhoneAdmissionError as error:
                if outbound_metadata and str(error) in {'outbound_budget_invalid', 'outbound_budget_clock_rollback', 'outbound_call_budget_exceeded'}:
                    for recipient in outbound_metadata:
                        outbound_failures.append({'id': recipient, 'failure_code': str(error),
                                                  'detail': operator_message(str(error)), 'retryable': False})
                    checked = {key: value for key, value in checked.items() if key not in outbound_metadata}
                    outbound_metadata = {}
                    if checked: continue
                if str(error) not in {'phone_capacity_busy', 'phone_recipient_busy'} or time.monotonic() >= deadline:
                    raise
                time.sleep(min(0.25, max(0, deadline - time.monotonic())))


def admit_internal(extensions, **options):
    return admit_audience(extensions, **options)


def main():
    parser = argparse.ArgumentParser(description='Admit a complete internal phone audience before call-file submission.')
    parser.add_argument('--recipients')
    parser.add_argument('--service', choices=sorted(SERVICES))
    parser.add_argument('--probe-ami', action='store_true')
    parser.add_argument('--request-stdin', action='store_true')
    parser.add_argument('--correlation', default='')
    parser.add_argument('--wait', type=int, default=30)
    parser.add_argument('--format', choices=('json', 'lines'), default='json')
    args = parser.parse_args()
    try:
        if args.probe_ami:
            with PhoneAmi(read_settings()) as ami:
                ami.action({'Action': 'Ping'})
                ami.dialplan('outbound-allroutes')
                ami.inventory('0' * 32, 'probe')
            print(json.dumps({'ok': True, 'ping': True, 'show_dialplan': True}))
            return 0
        if args.request_stdin:
            raw = sys.stdin.buffer.read(1048577)
            if len(raw) > 1048576:
                raise PhoneAdmissionError('phone_request_oversized')
            request = checked_json(raw)
            if not isinstance(request, dict) or set(request) - {'internal', 'outbound', 'service', 'correlation', 'wait_seconds', 'duration', 'sound', 'live_origin', 'live_group', 'live_external', 'live_caller_number', 'live_channel', 'live_uniqueid', 'latest_start', 'voicemail', 'voicemail_scope', 'voicemail_context'}:
                raise PhoneAdmissionError('phone_request_invalid')
            result = admit_audience(request.get('internal', []), outbound=request.get('outbound', []),
                service=request.get('service', ''), correlation=request.get('correlation', ''),
                wait_seconds=request.get('wait_seconds', 30), duration=request.get('duration'),
                sound=request.get('sound'), live_origin=request.get('live_origin'), live_group=request.get('live_group'),
                latest_start=request.get('latest_start'), live_external=request.get('live_external', False), live_caller_number=request.get('live_caller_number'),
                live_channel=request.get('live_channel'), live_uniqueid=request.get('live_uniqueid'),
                voicemail=request.get('voicemail', False), voicemail_scope=request.get('voicemail_scope', ''),
                voicemail_context=request.get('voicemail_context'))
            print(json.dumps({'ok': True, **result, 'retryable': False}))
            return 0
        if not args.recipients or not args.service:
            parser.error('--recipients and --service are required for admission')
        result = admit_internal(args.recipients.split(','), service=args.service, correlation=args.correlation, wait_seconds=args.wait)
    except (PhoneAdmissionError, OSError, ValueError) as error:
        code = str(error) if isinstance(error, PhoneAdmissionError) else 'phone_runtime_unavailable'
        print(json.dumps({'ok': False, 'failure_code': code, 'detail': operator_message(code), 'retryable': False}))
        return 1
    if args.format == 'lines':
        print('\n'.join([result['token'], *result['targets']]))
    else:
        print(json.dumps({'ok': True, **result, 'retryable': False}))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
