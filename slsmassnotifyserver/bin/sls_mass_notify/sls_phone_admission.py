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
HISTORY_SECONDS = 7 * 86400
TOKEN = re.compile(r'[a-f0-9]{32}')
EXTENSION = re.compile(r'[0-9]{1,20}')
VOICE_ID = re.compile(r'voice_[a-f0-9]{24}')
UNIQUEID = re.compile(r'[A-Za-z0-9_.-]{1,128}')
SERVICES = {'announcement', 'nws', 'lightning', 'manual', 'live', 'outbound'}


class PhoneAdmissionError(RuntimeError):
    """A fixed operator-safe category; never embeds contact URIs or credentials."""


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


def checked_targets(targets):
    if not isinstance(targets, dict) or not 1 <= len(targets) <= MAX_CONTACTS:
        raise PhoneAdmissionError('phone_audience_invalid')
    result = {}
    for extension, dials in targets.items():
        if not isinstance(extension, str) or not (EXTENSION.fullmatch(extension) or VOICE_ID.fullmatch(extension)) or not isinstance(dials, list):
            raise PhoneAdmissionError('phone_audience_invalid')
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
        if normalized:
            result[extension] = normalized
    if not result:
        raise PhoneAdmissionError('phone_no_registered_contacts')
    if sum(map(len, result.values())) > MAX_CONTACTS:
        raise PhoneAdmissionError('phone_audience_exceeds_limit')
    return result


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
                _secure_file_metadata(lock.fileno())
                deadline = time.monotonic() + self.lock_seconds
                while True:
                    try:
                        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                        break
                    except BlockingIOError:
                        if time.monotonic() >= deadline:
                            raise PhoneAdmissionError('phone_state_busy')
                        time.sleep(0.01)
                try:
                    source = os.open('phone-admission.json', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory_fd)
                except FileNotFoundError:
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
                            state = json.loads(raw)
                        except (ValueError, UnicodeError):
                            raise PhoneAdmissionError('phone_state_invalid') from None
                self.validate(state)
                yield state
                self.compact(state)
                encoded = json.dumps(state, separators=(',', ':'), allow_nan=False).encode()
                if len(encoded) > MAX_BYTES:
                    raise PhoneAdmissionError('phone_state_capacity_exceeded')
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
        finally:
            if temporary is not None:
                try:
                    os.unlink(temporary, dir_fd=directory_fd)
                except FileNotFoundError:
                    pass
            os.close(directory_fd)

    @staticmethod
    def validate(state):
        if not isinstance(state, dict) or state.get('schema') != 1 or not isinstance(state.get('batches'), dict):
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
            normalized = checked_targets({key: row.get('dials') for key, row in targets.items()})
            if normalized != {key: row.get('dials') for key, row in targets.items()}:
                raise PhoneAdmissionError('phone_state_invalid')
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
        for index, (ended, token) in enumerate(closed):
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
            if same_boot and batch['generation'] == health['generation'] and tick < batch['start_deadline_tick'] and started != set(batch['targets']):
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
              latest_start=None, latest_start_tick=None, outbound_daily_limit=0):
        targets = checked_targets(targets)
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
            snapshot = read_private_json('phone-admission.json', self.directory)
            self.validate(snapshot)
            if not any(batch['status'] != 'closed' and source_uid in batch['channels']
                       and batch['channels'][source_uid]['channel'] == source_name
                       for batch in snapshot['batches'].values()):
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

    def collector_heartbeat(self, generation):
        accountcode(generation)
        with self.locked() as state:
            old = state.get('collector') or {}
            if old.get('generation') != generation or old.get('boot_id') != self.boot_id:
                for batch in state['batches'].values():
                    if batch['status'] != 'closed':
                        batch['outcome_uncertain'] = True
                        if any(VOICE_ID.fullmatch(row['recipient']) and row['kind'] == 'origin' for row in batch['channels'].values()):
                            batch['external_event_gap'] = True
            state['collector'] = {'generation': generation, 'boot_id': self.boot_id, 'heartbeat_tick': self.monotonic()}

    def require_collector(self, state):
        health = state.get('collector')
        if (not isinstance(health, dict) or health.get('boot_id') != self.boot_id
                or not isinstance(health.get('generation'), str) or not TOKEN.fullmatch(health['generation'])
                or not 0 <= self.monotonic() - checked_time(health.get('heartbeat_tick')) <= 15):
            raise PhoneAdmissionError('phone_event_collector_unavailable')
        return health


ERROR_MESSAGES = {
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
}


def operator_message(code):
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
    if name not in {'mass-notifications.config', 'phone-admission.json'}:
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
            value = _config_crypto.decode_config(handle.read(MAX_BYTES + 1)) if name == "mass-notifications.config" else json.load(handle)
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
        health = store.require_collector(state)
        return {'ok': True, 'connected': True,
                'heartbeat_age_seconds': round(store.monotonic() - health['heartbeat_tick'], 3),
                'active_batches': sum(batch['status'] != 'closed' for batch in state['batches'].values()),
                'reserved_contacts': store.used_contacts(state)}
    except Exception as error:
        code = str(error) if isinstance(error, PhoneAdmissionError) else 'phone_event_collector_unavailable'
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
        for line in raw.decode('utf-8', errors='strict').split('\r\n'):
            if ':' not in line:
                continue  # The AMI greeting precedes the first response.
            key, value = line.split(':', 1)
            if key in message or len(message) >= 128:
                raise PhoneAdmissionError('phone_ami_message_invalid')
            message[key] = value.lstrip()
        return message

    def action(self, fields, complete_event=None):
        action_id = os.urandom(16).hex()
        self.send({**fields, 'ActionID': action_id})
        deadline = time.monotonic() + 5
        response = None
        rows = []
        for _ in range(25000):
            message = self.receive(deadline)
            if message.get('ActionID') != action_id:
                if self.callback and 'Event' in message:
                    self.callback(message)
                continue
            if 'Response' in message:
                if message.get('Response', '').lower() != 'success':
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

    def variable(self, expression):
        response, _ = self.action({'Action': 'Getvar', 'Variable': expression})
        return str(response.get('Value', ''))

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


def check_live_audience(settings, extensions, live_origin, live_group, live_external=False, live_caller_number=None):
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
            or set(extensions) != set(targets) - {live_origin}):
        raise PhoneAdmissionError('phone_live_audience_changed')


def admit_audience(extensions, *, outbound=None, service, correlation='', wait_seconds=30, duration=None, sound=None,
                   live_origin=None, live_group=None, directory=DATA, settings=None, ami_factory=PhoneAmi,
                   latest_start=None, live_external=False, live_caller_number=None):
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
    outbound = outbound or []
    if not isinstance(extensions, list) or not isinstance(outbound, list) or len(outbound) > 1000 or (live_origin is not None and outbound):
        raise PhoneAdmissionError('phone_audience_invalid')
    if type(live_external) is not bool:
        raise PhoneAdmissionError('phone_live_origin_invalid')
    if service == 'live' or live_origin is not None or live_group is not None or live_external or live_caller_number not in (None, ''):
        if service != 'live':
            raise PhoneAdmissionError('phone_live_origin_invalid')
        check_live_audience(settings, extensions, live_origin, live_group, live_external, live_caller_number)
    with ami_factory(settings) as ami:
        targets = ami.contacts(extensions) if extensions else {}
        offline = [key for key, values in targets.items() if not values]
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
        checked = checked_targets(targets)
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
                                    outbound_daily_limit=settings.get('outbound_voice', {}).get('daily_call_limit', 0) if outbound_metadata else 0)
                preserve_audio_media(directory, extensions, duration, sound)
                check_deadline()
                return {'token': token, 'targets': [key for key in checked if not VOICE_ID.fullmatch(key)],
                        'outbound_targets': list(outbound_metadata), 'outbound_failures': outbound_failures, 'unavailable': offline,
                        'contacts': sum(map(len, checked.values())), 'live_dial': '&'.join(live_dials) if live_dials is not None else ''}
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
            request = json.loads(raw)
            if not isinstance(request, dict) or set(request) - {'internal', 'outbound', 'service', 'correlation', 'wait_seconds', 'duration', 'sound', 'live_origin', 'live_group', 'live_external', 'live_caller_number', 'latest_start'}:
                raise PhoneAdmissionError('phone_request_invalid')
            result = admit_audience(request.get('internal', []), outbound=request.get('outbound', []),
                service=request.get('service', ''), correlation=request.get('correlation', ''),
                wait_seconds=request.get('wait_seconds', 30), duration=request.get('duration'),
                sound=request.get('sound'), live_origin=request.get('live_origin'), live_group=request.get('live_group'),
                latest_start=request.get('latest_start'), live_external=request.get('live_external', False), live_caller_number=request.get('live_caller_number'))
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
