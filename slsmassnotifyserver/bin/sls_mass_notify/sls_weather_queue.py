#!/usr/bin/python3
"""Fresh observations, bounded durable work, and two bounded, audience-ordered delivery workers.

No delivery is performed under the observation lock. Running jobs are never
automatically replayed after interruption. Configuration is resolved at dispatch.
"""
import argparse
import concurrent.futures
import copy
from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path

import importlib.util
import sys as _config_sys
_config_sys.dont_write_bytecode = True
_config_crypto_spec = importlib.util.spec_from_file_location("sls_config_crypto", Path(__file__).resolve().with_name("sls_config_crypto.py"))
_config_crypto = importlib.util.module_from_spec(_config_crypto_spec)
_config_crypto_spec.loader.exec_module(_config_crypto)
import re
import signal
import stat
import subprocess
import sys
import tempfile
import time

sys.path.insert(0, str(Path(__file__).resolve().parent))
from sls_nws_delivery_claims import _open_directory, _validate_parent_directory, _secure_file_metadata
from sls_nws_status import mutate_status, reconcile_status
from sls_cluster_guard import runtime_running

DATA = Path(os.environ.get('DATA_DIR', '/var/lib/asterisk/SLS_Mass_Notifications_Plugin'))
RUNTIME = Path(os.environ.get('RUNTIME_DIR', '/usr/local/bin/sls_mass_notify'))
MAX_BYTES = 16 * 1024 * 1024
MAX_JOBS = 1000
FRESH_SECONDS = 180
ACTIVE_STATES = {'queued', 'running'}
HISTORY_SECONDS = 7 * 86400
DISPATCH_WORKERS = 2
DISPATCH_SECONDS = 3300
# 900s synthesis + 300s audio queue + 1,774s complete page hold +
# admission/visual overhead. Already submitted PBX calls outlive the helper.
DELIVERY_SECONDS = 3200
DELIVERY_STOP_GRACE = 2
QUEUE_MAX_AGE = 3600


class WeatherQueueCapacityError(RuntimeError):
    """Capacity failures safe to display without exposing notification bodies."""


def compact_terminal_history(data, now):
    """Keep replay guards, but completed payloads cannot consume active slots.

    An alert chain still active in a current observation remains suppressed even
    when its original delivery is older than the normal history window. Queued
    and running payloads are never compacted or evicted to make room.
    """
    for job_id, job in list(data['jobs'].items()):
        if job.get('state') in ACTIVE_STATES:
            continue
        payload = job.get('payload', {})
        expires = timestamp(payload.get('feature', {}).get('properties', {}).get('expires'))
        snapshot = data['snapshots'].get(job.get('group_id'), {})
        current = snapshot.get('active', {}).get(job.get('key'), {}) if job.get('service') == 'nws' else {}
        current_expires = timestamp(current.get('properties', {}).get('expires'))
        retain_until = max(float(job.get('updated_at', now)) + HISTORY_SECONDS,
                           float(job.get('deduplicate_until', 0)), expires, current_expires)
        if retain_until <= now:
            del data['jobs'][job_id]
            continue
        if job.get('state') in {'complete', 'uncertain', 'failed', 'expired', 'cancelled'}:
            # Full requests are needed only while unsent or running; channel
            # journals retain receipts. Cancelled chains may reappear, so keep
            # their original audience below. Other terminal states suppress replay.
            job['deduplicate_until'] = retain_until
            if job.get('state') == 'cancelled' and isinstance(payload.get('routing_snapshot'), dict):
                # A withdrawn observation may reappear before its original
                # delivery deadline. Keep that original audience, not its body.
                job['payload'] = {'routing_snapshot': payload['routing_snapshot']}
            else:
                job.pop('payload', None)


def timestamp(value):
    try:
        result = datetime.fromisoformat(str(value).replace('Z', '+00:00'))
        return result.timestamp() if result.tzinfo else 0
    except (ValueError, TypeError, OverflowError):
        return 0


def alert_key(feature):
    props = feature['properties']
    identifier = str(feature.get('id') or props.get('id') or '').rsplit('/', 1)[-1]
    references = []
    if str(props.get('messageType', '')).lower() == 'update':
        for index, ref in enumerate(props.get('references') or []):
            if isinstance(ref, dict):
                value = str(ref.get('identifier') or ref.get('@id') or '').rsplit('/', 1)[-1]
                if value:
                    references.append((timestamp(ref.get('sent')) or float('inf'), index, value))
    return str(props.get('event') or '') + '|' + (min(references)[2] if references else identifier)


def priority(feature):
    props = feature['properties']
    event = str(props.get('event') or '').lower()
    order = 0 if 'advisory' in event else (2 if 'warning' in event else 1)
    return [timestamp(props.get('onset') or props.get('effective') or props.get('sent')) or time.time(), order]


def actionable(feature, now):
    props = feature.get('properties', {})
    expires = timestamp(props.get('expires'))
    return (props.get('status') == 'Actual' and props.get('messageType') != 'Cancel'
            and bool(props.get('event')) and bool(feature.get('id') or props.get('id'))
            and expires > now)


def validate_collection(value):
    if not isinstance(value, dict) or value.get('type') != 'FeatureCollection' or not isinstance(value.get('features'), list):
        raise ValueError('Invalid Weather.gov FeatureCollection')
    if len(value['features']) > 1000:
        raise ValueError('Weather.gov feature limit exceeded')
    for feature in value['features']:
        if not isinstance(feature, dict) or not isinstance(feature.get('properties'), dict):
            raise ValueError('Malformed Weather.gov feature')
        props = feature['properties']
        if props.get('status') == 'Actual' and props.get('messageType') != 'Cancel':
            if not (feature.get('id') or props.get('id')) or not props.get('event') or not timestamp(props.get('expires')):
                raise ValueError('Weather.gov alert is missing identity or expiration')
        if not isinstance(props.get('references') or [], list):
            raise ValueError('Weather.gov alert references are malformed')
        if len(json.dumps(feature)) > 256 * 1024:
            raise ValueError('Weather.gov feature size limit exceeded')
    return value['features']


def read_json_at(directory_fd, name, maximum=MAX_BYTES):
    fd = os.open(name, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW, dir_fd=directory_fd)
    with os.fdopen(fd, 'r', encoding='utf-8') as handle:
        metadata = os.fstat(handle.fileno())
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size > maximum:
            raise RuntimeError('Weather queue file is unsafe or oversized')
        raw = handle.read(maximum + 1)
        if len(raw.encode()) > maximum:
            raise RuntimeError('Weather queue file exceeds capacity')
        return _config_crypto.decode_config(raw) if name.endswith(".config") else json.loads(raw)


def write_gate(group_id, row, events, now):
    directory_fd = _open_directory(DATA)
    temporary = '.nws-gate-' + os.urandom(12).hex()
    try:
        _validate_parent_directory(directory_fd)
        descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640, dir_fd=directory_fd)
        with os.fdopen(descriptor, 'w') as target:
            _secure_file_metadata(target.fileno())
            matching = sorted(event for event in events if 'thunderstorm' in event.lower())
            json.dump({'updated_at': now, 'zone': row[2], 'group': row[1], 'group_id': group_id,
                       'active': bool(matching), 'events': matching}, target)
            target.flush(); os.fsync(target.fileno())
        os.rename(temporary, f'nws-lightning-gate-{group_id}.json', src_dir_fd=directory_fd, dst_dir_fd=directory_fd)
    finally:
        try:
            os.unlink(temporary, dir_fd=directory_fd)
        except FileNotFoundError:
            pass
        os.close(directory_fd)


@contextmanager
def state_lock(directory=DATA, write=True):
    directory_fd = _open_directory(Path(directory))
    try:
        _validate_parent_directory(directory_fd)
        fd = os.open('weather-delivery.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o640, dir_fd=directory_fd)
        with os.fdopen(fd, 'a+') as handle:
            _secure_file_metadata(handle.fileno())
            lock_deadline = time.monotonic() + 2
            while True:
                try:
                    fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    break
                except BlockingIOError:
                    if time.monotonic() >= lock_deadline:
                        raise RuntimeError('Weather queue state is busy; retry on the next worker cycle')
                    time.sleep(0.01)
            try:
                data = read_json_at(directory_fd, 'weather-delivery.json')
            except FileNotFoundError:
                data = {'schema': 1, 'jobs': {}, 'snapshots': {}}
            if not isinstance(data, dict) or data.get('schema') != 1 or not isinstance(data.get('jobs'), dict) or not isinstance(data.get('snapshots'), dict):
                raise RuntimeError('Weather queue state is corrupt')
            yield data
            if not write:
                return
            compact_terminal_history(data, time.time())
            encoded = json.dumps(data, separators=(',', ':'), allow_nan=False).encode()
            if len(encoded) > MAX_BYTES:
                raise WeatherQueueCapacityError(
                    f'Weather queue state requires {len(encoded) / 1048576:.2f} MiB; '
                    f'the limit is {MAX_BYTES / 1048576:.0f} MiB. '
                    'This state update was not saved. Review the weather backlog and storage health.')
            name = '.weather-delivery-' + os.urandom(12).hex()
            out = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640, dir_fd=directory_fd)
            try:
                with os.fdopen(out, 'wb') as target:
                    _secure_file_metadata(target.fileno())
                    target.write(encoded); target.flush(); os.fsync(target.fileno())
                os.rename(name, 'weather-delivery.json', src_dir_fd=directory_fd, dst_dir_fd=directory_fd)
                os.fsync(directory_fd)
            finally:
                try:
                    os.unlink(name, dir_fd=directory_fd)
                except FileNotFoundError:
                    pass
    finally:
        os.close(directory_fd)


def enqueue_locked(data, service, group_id, key, payload, now, order):
    job_id = hashlib.sha256((service + '\n' + group_id + '\n' + key).encode()).hexdigest()
    old = data['jobs'].get(job_id, {})
    if old.get('state') in {'running', 'complete', 'uncertain', 'failed', 'expired'}:
        return job_id
    if isinstance(old.get('payload', {}).get('routing_snapshot'), dict):
        payload = {**payload, 'routing_snapshot': old['payload']['routing_snapshot']}
    # Only unsent work is replaced by a newer observation of the same chain.
    compact_terminal_history(data, now)
    active_count = sum(job.get('state') in ACTIVE_STATES for job in data['jobs'].values())
    if old.get('state') not in ACTIVE_STATES and active_count >= MAX_JOBS:
        raise WeatherQueueCapacityError(
            f'Weather queue has {active_count} queued or running deliveries; '
            f'the active limit is {MAX_JOBS}. Wait for dispatch or review worker health. '
            'Completed delivery history does not count toward this limit.')
    data['jobs'][job_id] = {'service': service, 'group_id': group_id, 'key': key,
        'payload': payload, 'state': 'queued', 'created_at': old.get('created_at', now),
        'updated_at': now, 'priority': order}
    return job_id


def enqueue_lightning(group_id, key, payload, directory=DATA):
    if len(json.dumps(payload)) > 16384 or not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', group_id):
        raise ValueError('Invalid Lightning queue record')
    with state_lock(directory) as data:
        return enqueue_locked(data, 'lightning', group_id, key, payload, time.time(), [payload['observed_at'], 1])


def groups():
    result = subprocess.run([str(RUNTIME / 'sls_mass_notify_weather_poll.sh'), '--groups-json'],
                            capture_output=True, text=True, timeout=15, check=True)
    records = json.loads(result.stdout)
    if not isinstance(records, list) or len(records) > 5 or any(not isinstance(row, list) or len(row) != 11 for row in records):
        raise RuntimeError('Invalid configured Weather groups')
    return {row[0]: row for row in records}


class WeatherPollError(RuntimeError):
    """An operator-safe network failure; never include raw response bodies."""


def fetch_zone(zone):
    if not re.fullmatch(r'[A-Z]{2}[CZ][0-9]{3}', zone):
        raise ValueError('Invalid Weather zone')
    # The system resolver commonly waits five seconds before trying its next
    # configured server. A five-second connection limit can defeat healthy DNS
    # failover. Keep the overall request/worker deadlines bounded and preserve
    # the administrator's DNS servers, proxy policy, and TLS verification.
    try:
        result = subprocess.run(['/usr/bin/curl', '-fsS', '--connect-timeout', '12', '--max-time', '20',
            '--retry', '1', '--retry-max-time', '45', '--max-filesize', '10485760',
            '-H', 'Accept: application/geo+json', '-H',
            'User-Agent: SLS-Mass-Notify/0.1.5-beta (https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server)',
            'https://api.weather.gov/alerts/active?zone=' + zone + '&status=actual'], capture_output=True, timeout=50, check=False)
    except subprocess.TimeoutExpired:
        raise WeatherPollError('Weather.gov request exceeded its retry deadline; check PBX DNS and connectivity.') from None
    if result.returncode:
        error = (result.stderr or b'').decode('utf-8', errors='replace').lower()
        if result.returncode in (5, 6) or (result.returncode == 28 and 'resolving timed out' in error):
            message = 'Weather.gov DNS resolution failed or timed out; check the PBX DNS servers.'
        elif result.returncode in (35, 51, 58, 60, 77, 83):
            message = 'Weather.gov TLS verification/connection failed; check PBX time, CA certificates, and HTTPS access.'
        elif result.returncode == 22:
            code = re.search(r'returned error:\s*([1-5][0-9]{2})\b', error)
            message = 'Weather.gov returned ' + ('HTTP ' + code[1] if code else 'an HTTP error') + '; retrying on the next poll.'
        elif result.returncode == 28:
            message = 'Weather.gov connection/request timed out; check PBX DNS and connectivity.'
        elif result.returncode == 63:
            message = 'Weather.gov response exceeded the permitted size.'
        else:
            message = 'Weather.gov request failed (curl code ' + str(int(result.returncode)) + '); check PBX connectivity.'
        raise WeatherPollError(message)
    return validate_collection(json.loads(result.stdout))


def current_channel_snapshot(provider, group_id):
    from sls_notification_destinations import _source_group
    from sls_weather_channels import snapshot
    path = Path(os.environ.get('CONFIG_FILE', str(DATA / 'mass-notifications.config')))
    descriptor = _open_directory(path.parent)
    try:
        config = read_json_at(descriptor, path.name, _config_crypto.MAX_FILE_BYTES)
    finally:
        os.close(descriptor)
    group = _source_group(config, {'provider': provider, 'group_id': group_id})
    return snapshot(config, group or {})


def observe_nws():
    configured = groups()
    reconcile_status(DATA / 'status.json', configured)
    outcomes = {}
    failures = {}
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        futures = {pool.submit(fetch_zone, zone): zone for zone in {row[2] for row in configured.values()}}
        for future in concurrent.futures.as_completed(futures):
            try:
                outcomes[futures[future]] = future.result()
            except Exception as error:
                outcomes[futures[future]] = None
                failures[futures[future]] = (str(error) if isinstance(error, WeatherPollError) else
                    'Weather.gov returned invalid JSON or alert data.' if isinstance(error, (ValueError, UnicodeError)) else
                    'Weather.gov observation could not complete; check the runtime and API connection.')
    now = time.time()
    iso = datetime.now(timezone.utc).isoformat()
    capacity_failures = []
    for group_id, row in configured.items():
        features = outcomes.get(row[2])
        if features is None:
            mutate_status(DATA / 'status.json', group_id, row[1], row[2],
                          {'api_failure': {'at': iso, 'message': failures.get(row[2], 'Weather.gov observation failed; queued alerts require a fresh observation.'), 'threshold': 3}})
            continue
        by_chain = {}
        for feature in features:
            if actionable(feature, now):
                key = alert_key(feature)
                previous = by_chain.get(key)
                if previous is None or timestamp(feature['properties'].get('sent')) >= timestamp(previous['properties'].get('sent')):
                    by_chain[key] = feature
        active = list(by_chain.values())
        group_capacity_failures = []
        with state_lock() as data:
            previous = copy.deepcopy(data)
            data['snapshots'] = {k: v for k, v in data['snapshots'].items() if k in configured}
            data['snapshots'][group_id] = {'zone': row[2], 'observed_at': now,
                'active': {alert_key(feature): feature for feature in active}}
            # Cancellation truth and admission share one commit. Full queues
            # must never preserve a superseded snapshot that still permits an
            # alert removed by the successful fresh API observation to dispatch.
            for job in data['jobs'].values():
                if (job.get('state') == 'queued' and job.get('service') == 'nws' and job.get('group_id') == group_id
                        and (job.get('key') not in by_chain or job.get('payload', {}).get('zone') != row[2])):
                    job.update(state='cancelled', updated_at=now, detail='Fresh Weather observation removed this alert or changed its zone.')
            for feature in sorted(active, key=priority):
                try:
                    enqueue_locked(data, 'nws', group_id, alert_key(feature), {
                        'zone': row[2], 'feature': feature,
                        'routing_snapshot': {'phones': row[3].split(','), 'desktops': row[4].split(','),
                                             'emails': row[5].split(), 'webhooks': row[10].split(','),
                                             'channels': current_channel_snapshot('nws', group_id)},
                    }, now, priority(feature))
                except WeatherQueueCapacityError as error:
                    if str(error) not in group_capacity_failures:
                        group_capacity_failures.append(str(error))
            compact_terminal_history(data, now)
            if len(json.dumps(data, separators=(',', ':'), allow_nan=False).encode()) > MAX_BYTES:
                # No dispatcher can enter while this lock is held. Restore the
                # durable backlog and invalidate its old observation before the
                # lock is released; existing pending audiences remain intact.
                data.clear(); data.update(previous)
                data['snapshots'] = {k: v for k, v in data['snapshots'].items() if k in configured}
                data['snapshots'].pop(group_id, None)
                for job in data['jobs'].values():
                    if (job.get('state') == 'queued' and job.get('service') == 'nws' and job.get('group_id') == group_id
                            and (job.get('key') not in by_chain or job.get('payload', {}).get('zone') != row[2])):
                        job.update(state='cancelled', updated_at=now, detail='Fresh Weather observation removed this alert or changed its zone.')
                group_capacity_failures = [
                    f'Fresh Weather observation and admission exceed the {MAX_BYTES / 1048576:g} MiB queue-state limit. '
                    'The previous observation was invalidated; this group cannot dispatch until a fresh observation fits. '
                    'Existing queued requests were preserved. Review the weather backlog and storage capacity.'
                ]
        events = {}
        for feature in active:
            event = feature['properties']['event']; events[event] = events.get(event, 0) + 1
        write_gate(group_id, row, events, now)
        queue_message = ' '.join(group_capacity_failures)
        update = {'reset_api': True, 'patch': {
            'last_poll_at': iso, 'last_poll_ok_at': iso, 'last_poll_status': 'fault' if group_capacity_failures else 'ok',
            'last_poll_feature_count': len(features), 'last_poll_candidate_count': len(active),
            'last_poll_candidate_events': events, 'last_poll_events': events,
            'last_poll_message': queue_message or f'Weather.gov observation complete: {len(active)} active alert(s); delivery runs separately.'}}
        if group_capacity_failures:
            update['fault'] = {'stage': 'queue', 'at': iso, 'message': queue_message}
            capacity_failures.extend(group_capacity_failures)
        else:
            update['clear_fault_stage'] = 'queue'
        mutate_status(DATA / 'status.json', group_id, row[1], row[2], update)
    if capacity_failures:
        raise WeatherQueueCapacityError(' '.join(dict.fromkeys(capacity_failures)))
    return len(configured)


def valid_nws(job, data, now):
    snapshot = data['snapshots'].get(job['group_id'], {})
    if not 0 <= now - snapshot.get('observed_at', 0) <= FRESH_SECONDS:
        return False
    if snapshot.get('zone') != job['payload'].get('zone'):
        return False
    feature = snapshot.get('active', {}).get(job['key'])
    original = job['payload'].get('feature')
    if not (isinstance(feature, dict) and isinstance(original, dict)
            and actionable(feature, now) and actionable(original, now)):
        return False
    properties = feature['properties']
    original_properties = original['properties']
    # Updates deliberately share an alert-chain key. A running worker retains
    # its captured payload, so a newer revision cannot authorize its old text.
    # Keep the full provider identity, as external Weather retry validation does.
    if str(feature.get('id') or properties.get('id') or '') != str(original.get('id') or original_properties.get('id') or ''):
        return False
    sent = properties.get('sent')
    original_sent = original_properties.get('sent')
    if sent or original_sent:
        # Equivalent timezone encodings are the same instant. Present malformed
        # or one-sided timestamps cannot prove that this is the captured revision.
        if not timestamp(sent) or timestamp(sent) != timestamp(original_sent):
            return False
    return True


def check_job(job_id, directory=DATA):
    with state_lock(directory, write=False) as data:
        job = data['jobs'].get(job_id, {})
        current = (job.get('state') == 'running' and time.time() < job.get('deadline_at', float('inf'))
                   and (job.get('service') != 'nws' or valid_nws(job, data, time.time())))
        job = dict(job)
    if not current or job.get('service') != 'nws':
        return current
    # Rendering/reservation waits can cross a quiet-hour boundary. The check is
    # called again immediately before local submission, using current policy.
    row = groups().get(job['group_id'])
    if not row or row[2] != job['payload'].get('zone'):
        return False
    from sls_notification_destinations import _quiet_now
    policy = {'quiet_hours_enabled': row[6], 'quiet_hours_start': row[7], 'quiet_hours_end': row[8]}
    critical = job['payload']['feature']['properties'].get('event') in row[9].split('\x1f')
    return not _quiet_now(policy, time.time()) or critical


def nws_environment(row, cycle_id):
    group_id, name, zone, phones, desktops, emails, quiet, start, end, critical, hooks = row
    values = {'NWS_ZONE_OVERRIDE': zone, 'NWS_ZONE_GROUP_NAME_OVERRIDE': name,
        'NWS_ZONE_GROUP_ID_OVERRIDE': group_id, 'NWS_RECIPIENTS_OVERRIDE': phones,
        'NWS_DESKTOP_CLIENTS_OVERRIDE': desktops, 'NWS_EMAIL_RECIPIENTS_OVERRIDE': emails,
        'NWS_QUIET_HOURS_ENABLED_OVERRIDE': quiet, 'NWS_QUIET_HOURS_START_OVERRIDE': start,
        'NWS_QUIET_HOURS_END_OVERRIDE': end, 'NWS_QUIET_CRITICAL_EVENTS_OVERRIDE': critical,
        'NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE': hooks, 'NWS_DISPATCH_CYCLE_ID': cycle_id,
        'NWS_DISPATCH_GROUP_RANK': '0', 'NWS_DISPATCH_GROUP_COUNT': '1',
        'NWS_CROSS_ZONE_CLAIM_STATE': str(DATA / 'nws-cross-zone-delivery-claims.json'),
        'NWS_CROSS_ZONE_CLAIM_HELPER': str(RUNTIME / 'sls_nws_delivery_claims.py'),
        'STATUS_FILE': str(DATA / 'status.json'), 'LOCK_FILE': str(DATA / f'nws-poll-{group_id}.lock'),
        'NWS_AUDIO_DELIVERY_LOCK': str(DATA / f'nws-audio-delivery-{group_id}.lock')}
    for key, name in [('SEEN_ALERTS', 'seen-alerts'), ('PROCESSED_ALERTS', 'processed-alerts'),
                      ('AUDIO_DELIVERED_ALERTS', 'audio-delivered'), ('EVENT_COOLDOWN_FILE', 'event-cooldowns')]:
        values[key] = str(DATA / f'{name}-{group_id}.txt')
    for key, name in [('LOCAL_DISPATCH_STATE', 'local-dispatch-intents'), ('EXTERNAL_DELIVERY_STATE', 'external-deliveries')]:
        values[key] = str(DATA / f'{name}-{group_id}.json')
    return dict(os.environ, **values)


def dispatch_nws(job_id, job):
    row = groups().get(job['group_id'])
    if not row or row[2] != job['payload']['zone']:
        return 'cancelled', 'Weather zone was removed or changed before dispatch.'
    snapshot = job['payload'].get('routing_snapshot')
    if not isinstance(snapshot, dict):
        return 'cancelled', 'Original Weather audience is unavailable; delayed delivery was cancelled.'
    row = list(row)
    for index, name, separator in ((3, 'phones', ','), (4, 'desktops', ','), (5, 'emails', ' '), (10, 'webhooks', ',')):
        original = {str(value).lower() for value in snapshot.get(name, []) if value}
        row[index] = separator.join(value for value in row[index].split(separator) if value and value.lower() in original)
    channels = snapshot.get('channels') or {'voice_recipient_ids':[], 'sms_recipient_ids':[], 'fingerprints':{}}
    if not any(row[index] for index in (3, 4, 5, 10)) and not (channels['voice_recipient_ids'] or channels['sms_recipient_ids']):
        return 'cancelled', 'Original Weather recipients were removed or disabled.'
    cycle_id = 'nws_' + os.urandom(16).hex()
    env = nws_environment(row, cycle_id)
    env['SLS_WEATHER_CHANNEL_SNAPSHOT'] = json.dumps(channels, separators=(',',':'))
    env['SLS_WORKER_DEADLINE_EPOCH'] = str(int(job.get('deadline_at', time.time() + DELIVERY_SECONDS)))
    subprocess.run([str(RUNTIME / 'sls_nws_delivery_claims.py')], input=json.dumps(
        {'op': 'begin_cycle', 'cycle_id': cycle_id, 'group_count': 1}), text=True,
        check=True, timeout=10, stdout=subprocess.DEVNULL, env=env)
    fd, temporary = tempfile.mkstemp(prefix='.weather-job-', dir=str(DATA))
    try:
        with os.fdopen(fd, 'w') as target:
            _secure_file_metadata(target.fileno())
            json.dump({'type': 'FeatureCollection', 'features': [job['payload']['feature']]}, target)
        env['NWS_DELIVERY_PAYLOAD'] = temporary
        env['SLS_WEATHER_JOB_ID'] = job_id
        result = subprocess.run([str(RUNTIME / 'sls_mass_notify_nws_poll.sh')], env=env, timeout=max(1, float(job.get('deadline_at', time.time() + DELIVERY_SECONDS)) - time.time()))
        return ('complete', 'Weather worker completed; consult per-channel delivery logs.') if result.returncode == 0 else ('failed', 'Weather delivery worker failed; automatic replay suppressed.')
    finally:
        os.unlink(temporary)


@contextmanager
def singleton(name):
    fd = _open_directory(DATA)
    try:
        _validate_parent_directory(fd)
        lock = os.open(name, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o640, dir_fd=fd)
        with os.fdopen(lock, 'a+') as handle:
            _secure_file_metadata(handle.fileno())
            try:
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                yield False; return
            yield True
    finally:
        os.close(fd)


def retry_pending_external():
    configured = groups()
    # Include removed areas so their pending records are explicitly cancelled,
    # rather than becoming orphaned until the old area is re-created.
    paths = {DATA / f'external-deliveries-{group_id}.json' for group_id in configured}
    paths.update(path for path in DATA.glob('external-deliveries-*.json')
                 if re.fullmatch(r'external-deliveries-[A-Za-z0-9_-]{1,64}\.json', path.name))
    paths.add(DATA / 'nws-external-deliveries.json')
    states = [(str(path), 'nws') for path in sorted(paths)]
    states.append((str(DATA / 'xweather-external-deliveries.json'), 'xweather'))
    for path, source in states:
        if not Path(path).is_file():
            continue
        try:
            result = subprocess.run([sys.executable, str(RUNTIME / 'sls_notification_destinations.py'),
                os.environ.get('CONFIG_FILE', str(DATA / 'mass-notifications.config')), '--retry-state', path],
                env=dict(os.environ, SLS_NOTIFICATION_LIVE='1', SLS_NOTIFICATION_TEST='0',
                    SLS_NOTIFICATION_DRY_RUN='0', SLS_DESTINATION_SOURCE=source, SLS_EXTERNAL_RETRY_ONLY='1'),
                timeout=45, capture_output=True, text=True)
            outcome = json.loads(result.stdout) if len(result.stdout) <= 256 * 1024 else {}
            failed = result.returncode not in {0, 1} or not isinstance(outcome, dict) or outcome.get('pending', -1) < 0
            uncertain = int(outcome.get('uncertain', 0)) if isinstance(outcome, dict) else 0
            pending = int(outcome.get('pending', 0)) if isinstance(outcome, dict) else 0
            status = 'fault' if failed or uncertain else 'pending' if pending else 'complete'
            message = ('External retry worker could not validate its durable state.' if failed else
                       f'{uncertain} external delivery record(s) have unconfirmed submissions; automatic replay is suppressed.' if uncertain else
                       f'{pending} external delivery record(s) remain pending source validity, quiet hours, or a confirmed submission failure.' if pending else
                       'External retry processing completed; retained per-destination records include cancellation reasons.')
        except (subprocess.TimeoutExpired, ValueError, TypeError):
            status, message = 'fault', 'External retry worker timed out or returned an invalid result; submission outcomes require review.'
        now = datetime.now(timezone.utc).isoformat()
        if source == 'xweather':
            from sls_mass_notify_xweather_poll import atomic_json_update
            patch = {'last_xweather_external_at': now, 'last_xweather_external_status': status,
                     'last_xweather_external_message': message}
            if status == 'fault':
                patch.update(last_xweather_delivery_at=now, last_xweather_delivery_status='fault', last_xweather_delivery_message=message)
            atomic_json_update(DATA / 'status.json', patch)
        else:
            group_id = Path(path).stem.removeprefix('external-deliveries-')
            row = configured.get(group_id)
            if row:
                mutation = {'fault': {'stage': 'external', 'at': now, 'message': message}} if status == 'fault' else {'clear_fault_stage': 'external'}
                mutate_status(DATA / 'status.json', group_id, row[1], row[2], mutation)


def kick_external():
    subprocess.Popen([sys.executable, str(RUNTIME / 'sls_weather_queue.py'), 'external'],
        stdin=subprocess.DEVNULL, start_new_session=True, close_fds=True)


class WeatherDeliveryDeadline(BaseException):
    pass


class WeatherDeliveryInterrupted(BaseException):
    pass


def process_identity(pid):
    """Read Linux identity without signaling a reused PID."""
    try:
        raw = Path(f'/proc/{int(pid)}/stat').read_text()
        fields = raw[raw.rfind(')') + 2:].split()
        return {'pid': int(pid), 'state': fields[0], 'parent': int(fields[1]),
                'session': int(fields[3]), 'start_ticks': int(fields[19])}
    except (OSError, ValueError, IndexError):
        return None


def delivery_process_alive(job):
    identity = process_identity(job.get('worker_pid', 0))
    return bool(identity and identity['state'] != 'Z'
                and identity['start_ticks'] == job.get('worker_start_ticks')
                and identity['session'] == job.get('worker_pid'))


def delivery_session_members(pid, start_ticks):
    leader = process_identity(pid)
    if leader and leader['start_ticks'] != start_ticks:
        return []
    members = []
    for entry in Path('/proc').iterdir():
        if not entry.name.isdigit():
            continue
        item = process_identity(int(entry.name))
        if item and item['session'] == pid and item['start_ticks'] >= start_ticks and item['state'] != 'Z':
            members.append(item)
    return members


def stop_delivery_session(pid, start_ticks, include_leader=True, grace=DELIVERY_STOP_GRACE):
    """Kill the worker session, including timeout's separate process groups.

    Asterisk's already submitted calls are not descendants of this session.
    Their complete audio is neither trimmed nor hung up here.
    """
    def members():
        return [row for row in delivery_session_members(pid, start_ticks)
                if include_leader or row['pid'] != os.getpid()]
    def send(rows, signum):
        for row in rows:
            current = process_identity(row['pid'])
            if current and current['start_ticks'] == row['start_ticks'] and current['session'] == pid:
                try:
                    os.kill(row['pid'], signum)
                except ProcessLookupError:
                    pass
    send(members(), signal.SIGTERM)
    deadline = time.monotonic() + grace
    while members() and time.monotonic() < deadline:
        time.sleep(0.02)
    send(members(), signal.SIGKILL)


def update_dispatch_metrics(data, now):
    jobs = list(data['jobs'].values())
    queued = [max(0, now - float(job.get('created_at', now))) for job in jobs if job.get('state') == 'queued']
    running = [max(0, now - float(job.get('started_at', job.get('updated_at', now)))) for job in jobs if job.get('state') == 'running']
    data['dispatch_metrics'] = {'updated_at': now, 'max_workers': DISPATCH_WORKERS,
        'running': len(running), 'oldest_queued_age_seconds': max(queued, default=0),
        'oldest_running_age_seconds': max(running, default=0),
        'deadline_misses': sum(bool(job.get('deadline_missed')) and float(job.get('updated_at', 0)) > now - HISTORY_SECONDS for job in jobs)}


def webhook_audience_aliases():
    from urllib.parse import urlsplit, urlunsplit
    from sls_notification_destinations import _destination_rows
    path = Path(os.environ.get('CONFIG_FILE', str(DATA / 'mass-notifications.config')))
    descriptor = _open_directory(path.parent)
    try:
        try:
            config = read_json_at(descriptor, path.name, _config_crypto.MAX_FILE_BYTES)
        except FileNotFoundError:
            return {}
    finally:
        os.close(descriptor)
    aliases = {}
    for row in _destination_rows(config, 'discord') + _destination_rows(config, 'generic'):
        try:
            parsed = urlsplit(row['url'])
            hostname = (parsed.hostname or '').lower().rstrip('.')
            if not hostname:
                continue
            host = '[' + hostname + ']' if ':' in hostname else hostname
            port = parsed.port
            if port and not (parsed.scheme.lower() == 'https' and port == 443):
                host += ':' + str(port)
            normalized = urlunsplit((parsed.scheme.lower(), host, parsed.path or '/', parsed.query, ''))
            aliases[row['kind'] + ':' + row['id']] = hashlib.sha256(normalized.encode()).hexdigest()
        except (TypeError, ValueError):
            continue
    return aliases


def audience_keys(job, aliases=None):
    from sls_nws_delivery_claims import _normalize_destination
    snapshot = job.get('payload', {}).get('routing_snapshot')
    if not isinstance(snapshot, dict):
        # Legacy work without a frozen audience is cancelled by delivery, but
        # cannot safely be admitted concurrently with another pending job.
        return {'*'}
    values = {f"area:{job.get('service')}:{job.get('group_id')}"}
    values.update('webhook-url:' + value for value in job.get('webhook_aliases', [])
                  if isinstance(value, str) and re.fullmatch('[a-f0-9]{64}', value))
    for field, kind in [('phones', 'phone'), ('desktops', 'desktop'), ('emails', 'email')]:
        for raw in snapshot.get(field, []):
            normalized = _normalize_destination(kind, raw)
            if not normalized:
                if str(raw).strip():
                    return {'*'}
                continue
            values.add(kind + ':' + normalized)
    for kind,key in [('voice','voice_recipient_ids'),('sms','sms_recipient_ids')]:
        for identifier in (snapshot.get('channels') or {}).get(key,[]):
            if not isinstance(identifier,str) or not re.fullmatch(kind+r'_[a-f0-9]{24}',identifier): return {'*'}
            values.add(kind+':'+identifier)
    for raw in snapshot.get('webhooks', []):
        kind, separator, identifier = str(raw).partition(':')
        normalized = _normalize_destination(kind, identifier)
        if not separator or kind not in {'discord', 'generic'} or not normalized:
            if str(raw).strip():
                return {'*'}
            continue
        values.add(kind + ':' + normalized)
        alias = (aliases or {}).get(kind + ':' + normalized)
        if alias:
            values.add('webhook-url:' + alias)
    return {hashlib.sha256(value.encode()).hexdigest() for value in values}


def audiences_overlap(first, second):
    return bool(first and second and ('*' in first or '*' in second or first.intersection(second)))


def choose_deliveries(data, now, free_slots, aliases=None):
    running_keys = set()
    for job in data['jobs'].values():
        if job.get('state') == 'running':
            running_keys.update(audience_keys(job, aliases))
    pending = sorted(((job['priority'], job['created_at'], job_id) for job_id, job in data['jobs'].items()
                      if job.get('state') == 'queued'))
    chosen = []
    older_keys = set()
    for _, _, job_id in pending:
        job = data['jobs'][job_id]
        if now - job['created_at'] >= QUEUE_MAX_AGE:
            job.update(state='expired', updated_at=now, deadline_at=job['created_at'] + QUEUE_MAX_AGE,
                       deadline_missed=True, detail='Queued weather delivery exceeded its one-hour deadline; it was not sent.')
            continue
        ready = True
        if job['service'] == 'nws' and not valid_nws(job, data, now):
            snapshot = data['snapshots'].get(job['group_id'], {})
            if 0 <= now - snapshot.get('observed_at', 0) <= FRESH_SECONDS:
                job.update(state='cancelled', updated_at=now,
                           detail='Fresh Weather observation no longer authorizes this delivery.')
                continue
            ready = False
        keys = audience_keys(job, aliases)
        if ready and len(chosen) < free_slots and not audiences_overlap(keys, running_keys | older_keys):
            chosen.append(job_id)
            running_keys.update(keys)
        # Do not allow a later job to leapfrog an older blocked job addressed
        # to the same audience; independent areas remain eligible.
        older_keys.update(keys)
    return chosen


def claim_delivery(data, job_id, now, deadline_at, aliases=None):
    job = data['jobs'][job_id]
    job['webhook_aliases'] = sorted({(aliases or {})[key] for key in job.get('payload', {}).get('routing_snapshot', {}).get('webhooks', []) if key in (aliases or {})})
    job.update(state='running', updated_at=now, started_at=now,
               queue_age_seconds=max(0, now - job['created_at']),
               deadline_at=min(now + DELIVERY_SECONDS, job['created_at'] + QUEUE_MAX_AGE, deadline_at),
               deadline_missed=False, claim_token=os.urandom(16).hex())
    job.pop('worker_pid', None); job.pop('worker_start_ticks', None)
    return copy.deepcopy(job)


def finish_delivery(job_id, claim_token, state, detail, deadline_missed=False):
    with state_lock() as data:
        job = data['jobs'].get(job_id, {})
        if job.get('state') != 'running' or job.get('claim_token') != claim_token:
            return False
        job.update(state=state, detail=detail, updated_at=time.time(), deadline_missed=deadline_missed)
        update_dispatch_metrics(data, time.time())
    return True


def start_delivery(job_id, job):
    return subprocess.Popen([sys.executable, str(Path(__file__).resolve()), 'deliver', job_id, job['claim_token']],
        stdin=subprocess.DEVNULL, start_new_session=True, close_fds=True,
        env=dict(os.environ, DATA_DIR=str(DATA), RUNTIME_DIR=str(RUNTIME)))


def deliver_claim(job_id, claim_token):
    """One isolated claim; its own alarm also survives coordinator failure."""
    if os.getsid(0) != os.getpid():
        os.setsid()
    identity = process_identity(os.getpid())
    with state_lock() as data:
        job = data['jobs'].get(job_id, {})
        if job.get('state') != 'running' or job.get('claim_token') != claim_token:
            return 1
        # The claim is single-use even if a duplicate launcher presents the
        # same token. A surviving worker is adopted, never replaced in flight.
        if job.get('worker_pid') and (job['worker_pid'] != os.getpid()
                or job.get('worker_start_ticks') not in (0, identity['start_ticks'])):
            return 1
        if time.time() >= job.get('deadline_at', 0):
            job.update(state='uncertain', updated_at=time.time(), deadline_missed=True,
                       detail='Delivery claim deadline passed before worker entry; automatic replay suppressed.')
            update_dispatch_metrics(data, time.time())
            return 1
        job.update(worker_pid=os.getpid(), worker_start_ticks=identity['start_ticks'])
        job = copy.deepcopy(job)
    def expired(signum, frame):
        raise WeatherDeliveryDeadline('Delivery process deadline reached')
    def interrupted(signum, frame):
        raise WeatherDeliveryInterrupted('Delivery coordinator interrupted the worker')
    old_alarm = signal.signal(signal.SIGALRM, expired)
    old_term = signal.signal(signal.SIGTERM, interrupted)
    signal.setitimer(signal.ITIMER_REAL, max(0.001, job['deadline_at'] - time.time()))
    deadline_missed = False
    try:
        if job['service'] == 'nws':
            state, detail = dispatch_nws(job_id, job)
        else:
            import sls_mass_notify_xweather_poll as lightning
            state, detail = lightning.deliver_queued_event(job['payload'])
    except WeatherDeliveryDeadline:
        state, detail, deadline_missed = 'uncertain', 'Delivery process deadline reached; automatic replay suppressed.', True
    except WeatherDeliveryInterrupted:
        state, detail = 'uncertain', 'Delivery worker interrupted; automatic replay suppressed.'
        deadline_missed = time.time() >= job['deadline_at']
    except BaseException as error:
        state, detail = 'uncertain', 'Delivery interrupted (' + type(error).__name__ + '); automatic replay suppressed.'
        deadline_missed = time.time() >= job['deadline_at']
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)
        signal.signal(signal.SIGTERM, signal.SIG_IGN)
        stop_delivery_session(os.getpid(), identity['start_ticks'], include_leader=False)
        signal.signal(signal.SIGALRM, old_alarm)
        signal.signal(signal.SIGTERM, old_term)
    if state not in {'complete', 'failed', 'cancelled', 'uncertain'}:
        state, detail = 'uncertain', 'Delivery worker returned an invalid outcome; automatic replay suppressed.'
    finish_delivery(job_id, claim_token, state, detail, deadline_missed)
    return 0 if state in {'complete', 'cancelled'} else 1


def dispatch():
    if not runtime_enabled():
        return 0
    with singleton('weather-dispatch-worker.lock') as acquired:
        if not acquired:
            return 0
        deadline = time.monotonic() + DISPATCH_SECONDS
        # Reserve the TERM/KILL cleanup grace inside the existing loop budget.
        deadline_at = time.time() + DISPATCH_SECONDS - DISPATCH_WORKERS * DELIVERY_STOP_GRACE - 1
        processes = {}
        next_metrics_at = 0
        while time.monotonic() < deadline:
            accepting = runtime_enabled()
            if not accepting and not processes:
                return 0
            now = time.time()
            with state_lock(write=False) as data:
                running = [(job_id, copy.deepcopy(job)) for job_id, job in data['jobs'].items() if job.get('state') == 'running']
            for job_id, job in running:
                process = processes.get(job_id)
                alive = process.poll() is None if process is not None else delivery_process_alive(job)
                expired = now >= job.get('deadline_at', 0) if job.get('deadline_at') else False
                if alive and not expired:
                    continue
                if job.get('worker_pid') and job.get('worker_start_ticks'):
                    stop_delivery_session(job['worker_pid'], job['worker_start_ticks'])
                if process is not None:
                    process.wait(timeout=DELIVERY_STOP_GRACE + 1)
                    processes.pop(job_id, None)
                finish_delivery(job_id, job.get('claim_token'), 'uncertain',
                    'Delivery deadline reached; automatic replay suppressed.' if expired else
                    'Previous delivery worker interrupted; automatic replay suppressed.', expired)
            # Reap finished children, whose terminal outcome was committed by
            # the child. A replacement coordinator can adopt surviving claims.
            for job_id, process in list(processes.items()):
                if process.poll() is not None:
                    processes.pop(job_id)
                    kick_external()
            aliases = webhook_audience_aliases()
            # Most ticks only observe children. Do not rewrite/fsync the entire
            # outbox while a slow delivery runs and no admission changed.
            before_states = {key: value.get('state') for key, value in data['jobs'].items()}
            active = sum(state == 'running' for state in before_states.values())
            proposed = choose_deliveries(data, time.time(), max(0, DISPATCH_WORKERS - active), aliases) if accepting and time.time() < deadline_at else []
            changed = any(value.get('state') != before_states[key] for key, value in data['jobs'].items())
            claims = []
            if proposed or changed or time.monotonic() >= next_metrics_at:
                # Revalidate and claim under the write lock; the read snapshot
                # used to avoid idle writes is never an admission authority.
                with state_lock() as data:
                    active = sum(job.get('state') == 'running' for job in data['jobs'].values())
                    selected = choose_deliveries(data, time.time(), max(0, DISPATCH_WORKERS - active), aliases) if runtime_enabled() and time.time() < deadline_at else []
                    claims = [(job_id, claim_delivery(data, job_id, time.time(), deadline_at, aliases)) for job_id in selected]
                    update_dispatch_metrics(data, time.time())
                next_metrics_at = time.monotonic() + 30
            for job_id, job in claims:
                process = None
                try:
                    process = start_delivery(job_id, job)
                    # Track the child before any subsequent state operation can
                    # fail. It registers its own identity before sending and
                    # retains its deadline if this coordinator exits.
                    processes[job_id] = process
                    identity = process_identity(process.pid)
                    with state_lock() as data:
                        current = data['jobs'].get(job_id, {})
                        if current.get('state') == 'running' and current.get('claim_token') == job['claim_token']:
                            current.update(worker_pid=process.pid,
                                           worker_start_ticks=identity['start_ticks'] if identity else 0)
                except Exception:
                    if process is not None:
                        # An identity-write failure does not prove that the
                        # sender failed to start. Keep its running reservation;
                        # polling here or a replacement coordinator adopts it.
                        # Never free its audience while it can still deliver.
                        continue
                    finish_delivery(job_id, job['claim_token'], 'uncertain',
                                    'Delivery worker could not start reliably; automatic replay suppressed.')
            with state_lock(write=False) as data:
                active = any(job.get('state') == 'running' for job in data['jobs'].values())
            if not active and not claims:
                # Recovery or a child's final commit may have changed the
                # earlier admission snapshot. Recheck this fresh snapshot so a
                # failed predecessor does not strand its queued successors.
                if time.time() < deadline_at and choose_deliveries(data, time.time(), DISPATCH_WORKERS, aliases):
                    continue
                return 0
            time.sleep(0.25)
        return 0


def runtime_enabled():
    path = Path(os.environ.get('CONFIG_FILE', str(DATA / 'mass-notifications.config')))
    return runtime_running(_config_crypto.read_config(path))


def services_enabled():
    path = Path(os.environ.get('CONFIG_FILE', str(DATA / 'mass-notifications.config')))
    fd = _open_directory(path.parent)
    try:
        config = read_json_at(fd, path.name, _config_crypto.MAX_FILE_BYTES)
    finally:
        os.close(fd)
    if not isinstance(config, dict) or not isinstance(config.get('xweather', {}), dict):
        raise ValueError('Invalid weather service configuration')
    enabled = lambda value: str(value).lower() in {'1', 'true', 'yes', 'on'}
    return enabled(config.get('enabled', '0')) or enabled(config.get('xweather', {}).get('enabled', '0'))


def cycle():
    if not runtime_enabled():
        return 0
    # A disabled fresh install needs no children or mutable weather state.
    # In particular, installer probes must not outlive their temporary directory.
    if not services_enabled():
        if any(DATA.glob('external-deliveries-*.json')) or (DATA / 'nws-external-deliveries.json').is_file() or (DATA / 'xweather-external-deliveries.json').is_file():
            kick_external()
        return 0
    # Child delivery does not inherit either observation lock or PHP session.
    observation_failed = False
    try:
        with singleton('weather-observation.lock') as acquired:
            if acquired:
                observe_nws()
    except Exception as exc:
        observation_failed = True
        print('Weather observation failed: ' + (str(exc) if isinstance(exc, WeatherQueueCapacityError)
              else type(exc).__name__), file=sys.stderr)
    finally:
        kick_external()
        subprocess.Popen([sys.executable, str(RUNTIME / 'sls_weather_queue.py'), 'dispatch'],
            stdin=subprocess.DEVNULL, start_new_session=True, close_fds=True)
    # The Lightning poller now enqueues deliveries, so its own lock covers only
    # observation/state changes, not TTS, paging or external receivers.
    result = subprocess.run([str(RUNTIME / 'sls_mass_notify_xweather_poll.py')],
        env=dict(os.environ, SLS_WEATHER_QUEUE_ENABLED='1'), timeout=180)
    subprocess.Popen([sys.executable, str(RUNTIME / 'sls_weather_queue.py'), 'dispatch'],
        stdin=subprocess.DEVNULL, start_new_session=True, close_fds=True)
    return 0 if not observation_failed and result.returncode in (0, 75) else 1


def cli():
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['cycle', 'dispatch', 'check', 'external', 'deliver'])
    parser.add_argument('job_id', nargs='?')
    parser.add_argument('claim_token', nargs='?')
    args = parser.parse_args()
    try:
        if args.action == 'deliver':
            if not re.fullmatch(r'[a-f0-9]{64}', args.job_id or '') or not re.fullmatch(r'[a-f0-9]{32}', args.claim_token or ''):
                return 1
            return deliver_claim(args.job_id, args.claim_token)
        if args.action == 'check':
            return 0 if re.fullmatch(r'[a-f0-9]{64}', args.job_id or '') and check_job(args.job_id) else 1
        if args.action == 'external':
            if not runtime_enabled():
                return 0
            with singleton('weather-external-worker.lock') as acquired:
                if acquired: retry_pending_external()
            return 0
        return cycle() if args.action == 'cycle' else dispatch()
    except Exception as exc:
        detail = str(exc) if isinstance(exc, WeatherQueueCapacityError) else type(exc).__name__ + '. Check protected queue state and API connectivity.'
        print('Weather queue failed: ' + detail, file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(cli())
