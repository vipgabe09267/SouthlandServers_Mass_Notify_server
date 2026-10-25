#!/usr/bin/python3
"""Show live-page text only after authenticated, bound handset bridge joins.

The AGI owns the input pipe. Its completion or disappearance stops monitoring
and requests one quiet, ownership-checked replacement of attempted popups.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import select
import sys
import syslog
import time

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent))
from sls_phone_admission import (DATA, PhoneAdmissionError, PhoneAdmissionStore,
    read_private_json, read_settings, check_live_audience)
import sls_notify as notify


class VisualStopped(RuntimeError):
    pass


def checked_request(value):
    keys = {'token', 'group', 'caller', 'external', 'external_number', 'channel',
            'uniqueid', 'owner', 'config_sha256'}
    optional = {'ready_cue', 'voicemail_ready', 'expires_at'}
    if (not isinstance(value, dict) or set(value) - optional != keys or type(value['external']) is not bool
            or type(value.get('ready_cue', False)) is not bool
            or type(value.get('voicemail_ready', False)) is not bool
            or (value.get('ready_cue') and (type(value.get('expires_at')) is not int or value['expires_at'] <= 0))):
        raise ValueError('paging_visual_request_invalid')
    for key, pattern in [('token', r'[a-f0-9]{32}'), ('owner', r'[a-f0-9]{32}'),
                         ('group', r'[A-Za-z0-9_-]{1,64}'), ('caller', r'[0-9]{1,20}'),
                         ('uniqueid', r'[A-Za-z0-9_.-]{1,128}'), ('config_sha256', r'[a-f0-9]{64}')]:
        if not isinstance(value[key], str) or not re.fullmatch(pattern, value[key]):
            raise ValueError('paging_visual_request_invalid')
    channel = value['channel']
    if (not isinstance(channel, str) or not 1 <= len(channel) <= 256 or re.search(r'[\x00-\x20\x7f]', channel)
            or not isinstance(value['external_number'], str)
            or (value['external'] and not re.fullmatch(r'\+[1-9][0-9]{6,14}', value['external_number']))
            or (not value['external'] and value['external_number'] != '')):
        raise ValueError('paging_visual_request_invalid')
    return value


def file_revision(path):
    info = path.lstat()
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def resolved_group(settings, identity):
    paging = settings.get('live_paging', {})
    groups = [row for row in paging.get('groups', []) if isinstance(row, dict) and row.get('group_id') == identity]
    if len(groups) != 1:
        raise ValueError('paging_visual_group_unavailable')
    group = dict(groups[0])
    if 'name' not in group:
        saved = [row for row in settings.get('announcement_groups', []) if isinstance(row, dict) and row.get('id') == identity]
        if len(saved) != 1:
            raise ValueError('paging_visual_group_unavailable')
        group.update(extensions=saved[0].get('extensions', []), notify_extensions=[],
                     text_message='Live page in progress.')
    return paging, group


class VisualMonitor:
    def __init__(self, request, directory=DATA, *, sender=notify.push_announcement,
                 config_loader=notify.load_config, clock=time.monotonic):
        self.request = checked_request(request)
        self.directory = Path(directory)
        self.config_path = self.directory / 'mass-notifications.config'
        self.revision = file_revision(self.config_path)
        raw = notify._config_crypto._read_protected(self.config_path, notify._config_crypto.MAX_FILE_BYTES)
        if hashlib.sha256(raw).hexdigest() != request['config_sha256']:
            raise ValueError('paging_visual_configuration_changed')
        self.settings = read_settings(self.directory)
        if file_revision(self.config_path) != self.revision:
            raise ValueError('paging_visual_configuration_changed')
        self.paging, self.group = resolved_group(self.settings, request['group'])
        audio = self.group.get('extensions', [])
        self.contact_origin = not request['external'] and request['channel'].startswith('PJSIP/')
        check_live_audience(self.settings, audio if self.contact_origin else [x for x in audio if x != request['caller']],
            request['caller'], request['group'], request['external'], request['external_number'], self.contact_origin)
        selected = self.group.get('notify_extensions', [])
        if (not isinstance(selected, list) or len(selected) > 1000
                or any(not isinstance(x, str) or not re.fullmatch(r'[0-9]{1,20}', x) for x in selected)
                or len(set(selected)) != len(selected)):
            raise ValueError('paging_visual_group_invalid')
        self.targets = set(selected) - {request['caller']}
        self.audio_targets = set(audio)
        self.message = self.group.get('text_message', '')
        if (not isinstance(self.message, str) or not self.message.strip() or len(self.message.encode()) > 1000
                or re.search(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]', self.message)):
            raise ValueError('paging_visual_text_invalid')
        duration = self.paging.get('max_duration_seconds', 300)
        if type(duration) is not int or not 30 <= duration <= 1800:
            raise ValueError('paging_visual_duration_invalid')
        self.clock, self.deadline = clock, clock() + duration + 5
        if request.get('ready_cue') and not 0 < request['expires_at'] - time.time() <= duration + 1:
            raise ValueError('paging_ready_deadline_invalid')
        self.ready_started = None
        self.ready_signaled = False
        timeout = int(self.settings.get('announcement_timeout_seconds', 300)) if self.settings.get('announcement_timeout_mode') == 'custom' else 0
        self.timeout = min(timeout, duration + 5) if timeout > 0 else duration + 5
        self.store, self.sender = PhoneAdmissionStore(self.directory), sender
        self.config_loader = config_loader
        self.attempted = {}
        self.connected_contacts = set()
        self.text_only_checked = False
        self.last_revision, self.cached_state = None, None
        self.failures = 0
        self.failure_reason = ''
        self.snapshot()  # Authenticate the admitted origin before signaling readiness.

    def config_current(self):
        return file_revision(self.config_path) == self.revision

    def snapshot(self):
        revision = file_revision(self.directory / 'phone-admission.json')
        if revision != self.last_revision:
            state = read_private_json('phone-admission.json', self.directory)
            self.store.validate(state)
            self.cached_state, self.last_revision = state, revision
        state = self.cached_state
        health = self.store.require_collector(state)
        batch = state['batches'].get(self.request['token'])
        if (not batch or batch['service'] != 'live' or batch['status'] == 'closed'
                or batch['boot_id'] != self.store.boot_id or batch['generation'] != health['generation']
                or set(batch['targets']) != {self.request['caller']} or batch.get('outcome_uncertain')):
            raise PhoneAdmissionError('paging_visual_evidence_unavailable')
        origin = batch['channels'].get(self.request['uniqueid'])
        if (not origin or origin['kind'] != 'origin' or origin['recipient'] != self.request['caller']
                or origin['channel'] != self.request['channel']):
            raise PhoneAdmissionError('paging_visual_evidence_unavailable')
        if origin.get('ended_at') is not None:
            raise VisualStopped('paging_visual_origin_ended')
        return batch

    def joined(self, batch):
        result = {}
        any_joined = False
        admitted = set(batch['targets'][self.request['caller']]['dials'])
        for row in batch['channels'].values():
            if (row['kind'] != 'contact' or row.get('ended_at') is not None
                    or row.get('joined_at') is None or row.get('dial') not in admitted):
                continue
            any_joined = True
            self.connected_contacts.add(row['channel'])
            match = re.fullmatch(r'PJSIP/([0-9]{1,20})/(sips?:.+)', row['dial'])
            if match and match[1] in self.targets & self.audio_targets and notify.registered_contact_uri(match[2]) == match[2]:
                result.setdefault(match[1], set()).add(match[2])
        return result, any_joined

    def ready_decision(self, batch):
        """A cohort is ready only when every admitted dial has settled."""
        origin = batch['channels'][self.request['uniqueid']]
        if origin.get('joined_at') is None:
            return 'pending'
        dials = set(batch['targets'][self.request['caller']]['dials'])
        contacts = {}
        for row in batch['channels'].values():
            if row['kind'] == 'contact' and row.get('dial') in dials:
                contacts.setdefault(row['dial'], []).append(row)
        joined = 0
        for dial in dials:
            rows = contacts.get(dial, ())
            if len(rows) != 1:
                return 'pending'
            row = rows[0]
            if row.get('ended_at') is not None:
                continue
            if row.get('joined_at') is None:
                return 'pending'
            joined += 1
        if joined:
            return 'ready'
        if self.voicemail_recording_current(batch):
            return 'recording'
        return 'unavailable'

    def voicemail_recording_current(self, batch):
        fallback=batch.get('vm_fallback', {})
        if not self.request.get('voicemail_ready') or not fallback.get('targets'):
            return False
        from sls_voicemail import VoicemailMedia, current_policy, may_need_fallback
        if (not current_policy(self.settings,batch,self.directory)
                or fallback.get('expires_at',0)<=time.time() or fallback.get('expires_tick',0)<=self.store.monotonic()
                or not any(may_need_fallback(batch,row,recipient) for recipient,row in fallback['targets'].items())):
            return False
        media = VoicemailMedia(self.directory)
        parent = media.open_directory()
        try:
            lease = media.read_lease(parent, self.request['token'])
            return (lease.get('kind') == 'live' and lease.get('state') == 'recording'
                    and lease.get('channel') == self.request['channel'] and lease['expires_at'] > time.time())
        finally:
            os.close(parent)

    def origin_actions(self, fields):
        """Only the exact still-admitted origin may receive internal control."""
        deadline = self.clock() + 4
        config = self.config_loader()
        with notify.AmiClient(config['ami'].get('host', '127.0.0.1'), config['ami'].getint('port', 5038),
                              config['ami'].get('username', ''), config['ami'].get('password', ''), timeout=1) as ami:
            for action in [{'Action':'Getvar', 'Variable':'UNIQUEID'}, *fields]:
                if self.clock() >= deadline or not self.config_current():
                    raise RuntimeError('paging_ready_control_expired')
                self.snapshot()
                ami.timeout = max(.05, min(1, deadline - self.clock()))
                response, _ = ami.action(dict(action, Channel=self.request['channel']))
                if str(response.get('Response', '')).lower() != 'success':
                    raise RuntimeError('paging_ready_control_rejected')
                if action['Action'] == 'Getvar' and response.get('Value') != self.request['uniqueid']:
                    raise RuntimeError('paging_ready_origin_changed')

    def arm_ready_gate(self):
        self.origin_actions([{'Action':'MuteAudio', 'Direction':'in', 'State':'on'}])

    def signal_ready_gate(self, batch):
        if self.ready_signaled:
            return
        if batch['channels'][self.request['uniqueid']].get('joined_at') is None:
            return
        if self.ready_started is None:
            self.ready_started = self.clock()
        decision = self.ready_decision(batch)
        answer_timeout = min(5, max(1, int(self.settings.get('paging_answer_timeout', 5))))
        if decision == 'pending' and self.clock() < self.ready_started + answer_timeout + 2:
            return
        # Never repeat a possibly accepted cue, even after an AMI timeout.
        self.ready_signaled = True
        self.origin_actions([
            {'Action':'Setvar', 'Variable':'SLS_PAGING_CUE_ARM', 'Value':self.request['owner']},
            {'Action':'PlayDTMF', 'Digit':'A', 'Duration':'100', 'Receive':'true'},
        ])

    def send(self, scopes, stop_requested, *, ended=False, continuing=()):
        if not scopes:
            return
        deadline = self.clock() + 8
        def guard():
            return (self.clock() < deadline and self.config_current()
                    and (ended or (self.clock() < self.deadline and not stop_requested())))
        if not guard():
            return
        self.sender(self.config_loader(), 'Paging ended.' if ended else self.message, sorted(scopes),
            print_results=False, api_publish=False, timeout_seconds=1 if ended else self.timeout,
            visual_owner='' if ended else self.request['owner'],
            clear_visual_owner=self.request['owner'] if ended else '',
            contact_targets={key: sorted(value) for key, value in scopes.items()},
            continuation_extensions=continuing if not ended else (), submission_guard=guard, operation_deadline=deadline)

    def record_failure(self, reason):
        self.failures += 1
        self.failure_reason = reason
        if self.failures == 1:
            # Categories are fixed call-site literals, never provider replies,
            # caller identifiers, configured text, PINs or raw exceptions.
            syslog.syslog(syslog.LOG_NOTICE, 'SLS live paging visual failure: ' + reason)

    def tick(self, stop_requested=lambda: False):
        if stop_requested() or self.clock() >= self.deadline:
            raise VisualStopped('paging_visual_complete')
        if not self.config_current():
            raise VisualStopped('paging_visual_configuration_changed')
        batch = self.snapshot()
        if self.request.get('ready_cue'):
            self.signal_ready_gate(batch)
            if not self.ready_signaled or self.ready_decision(batch) != 'ready':
                # Optional SIP text must never occupy its network budget while
                # another handset is still connecting and awaiting the cue.
                return
        scopes, connected = self.joined(batch)
        if connected and not self.text_only_checked:
            self.text_only_checked = True
            text_only = self.targets - self.audio_targets
            if text_only:
                config = self.config_loader()
                deadline = self.clock() + 8
                guard = lambda: self.clock() < deadline and not stop_requested() and self.config_current()
                with notify.AmiClient(config['ami'].get('host', '127.0.0.1'), config['ami'].getint('port', 5038),
                                      config['ami'].get('username', ''), config['ami'].get('password', ''), timeout=2) as ami:
                    info = notify.get_registered_endpoint_info(notify.GuardedContactAmi(ami, guard), sorted(text_only),
                        notify.endpoint_format_overrides(config), resolve_contact_uris=True, submission_guard=guard)
                for extension, value in info.items():
                    scopes[extension] = {uri for row in value.get('contacts', [])
                                         if (uri := notify.registered_contact_uri(row.get('contact', '')))}
        scopes = {key: values - self.attempted.get(key, set()) for key, values in scopes.items()}
        scopes = {key: value for key, value in scopes.items() if value}
        if not scopes:
            return
        # Retain the attempted cohort even if the AMI result is uncertain; the
        # ownership guard alone determines whether cleanup may replace it.
        previous = set(self.attempted)
        for key, value in scopes.items():
            self.attempted.setdefault(key, set()).update(value)
        try:
            self.send(scopes, stop_requested, ended=False, continuing=previous)
        except notify.NotifyBatchError:
            self.record_failure('contact_uri_submission_failed')
        except Exception:
            self.record_failure('contact_inventory_or_transport_unavailable')

    def finish(self):
        try:
            if not self.config_current():
                return {'status':'configuration_changed','reason':'configuration_changed',
                    'connected_contacts':len(self.connected_contacts),
                    'attempted_contacts':sum(map(len,self.attempted.values())),'failures':self.failures}
            if self.attempted:
                self.send(self.attempted, lambda: True, ended=True)
        except Exception:
            self.record_failure('paging_cleanup_unconfirmed')
        failures = self.failures
        reason = (self.failure_reason if failures else 'completed' if self.attempted else
                  'no_notification_contacts' if self.connected_contacts else 'no_audio_recipient_joined')
        return {'status': 'failed' if failures else 'submitted' if self.attempted else 'not_requested', 'reason':reason,
                'connected_contacts':len(self.connected_contacts),
                'attempted_contacts': sum(map(len, self.attempted.values())), 'failures': failures}


def main():
    if sys.argv[1:] not in (['--request-stdin'], ['--cue-stdin'], ['--recording-cue-stdin']):
        return 64
    monitor = None
    try:
        ready, _, _ = select.select([sys.stdin.buffer], [], [], 5)
        if not ready:
            return 64
        line = sys.stdin.buffer.readline(4097)
        if not line.endswith(b'\n') or len(line) > 4096:
            return 64
        monitor = VisualMonitor(notify._config_crypto._object(line, 4096))
        if sys.argv[1:] in (['--cue-stdin'], ['--recording-cue-stdin']):
            if not monitor.request.get('ready_cue') or not monitor.config_current():
                raise ValueError('paging_ready_request_invalid')
            batch=monitor.snapshot()
            if sys.argv[1:] == ['--recording-cue-stdin']:
                decision = ('recording' if not batch['targets'][monitor.request['caller']]['dials']
                    and monitor.voicemail_recording_current(batch) else 'unavailable')
            else:
                decision = monitor.ready_decision(batch)
            remaining = int(monitor.request['expires_at'] - time.time())
            print(json.dumps({'ok': True, 'decision': decision, 'remaining_seconds': max(0, remaining)}), flush=True)
            return 0
        if monitor.request.get('ready_cue'):
            monitor.arm_ready_gate()
        print(json.dumps({'ready': True}), flush=True)
        def stopped():
            readable, _, _ = select.select([sys.stdin.buffer], [], [], 0)
            return bool(readable)  # STOP input or EOF both revoke the parent lease.
        while True:
            monitor.tick(stopped)
            select.select([sys.stdin.buffer], [], [], .2)
    except VisualStopped as error:
        if monitor is None:
            print(json.dumps({'ready': False}), flush=True)
            return 1
        if str(error) == 'paging_visual_configuration_changed':
            while monitor.clock() < monitor.deadline:
                readable, _, _ = select.select([sys.stdin.buffer], [], [], .2)
                if readable:
                    break
    except Exception as error:
        syslog.syslog(syslog.LOG_NOTICE, 'SLS live paging visual monitor unavailable; review paging and collector health.')
        if monitor is None:
            print(json.dumps({'ready': False}), flush=True)
            return 1
        monitor.record_failure('phone_outcome_evidence_unavailable' if isinstance(error, PhoneAdmissionError)
                               else 'paging_visual_monitor_unavailable')
        # Loss of collector/storage evidence stops new popups, but does not
        # assert that an ongoing page ended. Await the actual AGI pipe closure.
        while monitor.clock() < monitor.deadline:
            readable, _, _ = select.select([sys.stdin.buffer], [], [], .2)
            if readable:
                break
    result = monitor.finish() if monitor is not None else {'status': 'failed'}
    print(json.dumps(result), flush=True)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
