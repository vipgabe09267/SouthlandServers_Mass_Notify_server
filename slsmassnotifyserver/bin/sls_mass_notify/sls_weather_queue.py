#!/usr/bin/python3
"""Fresh observations, bounded durable work, and one chronological weather dispatcher.

No delivery is performed under the observation lock. Running jobs are never
automatically replayed after interruption. Configuration is resolved at dispatch.
"""
import argparse
import concurrent.futures
from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
import time

sys.path.insert(0, str(Path(__file__).resolve().parent))
from sls_nws_delivery_claims import _open_directory, _validate_parent_directory, _secure_file_metadata
from sls_nws_status import mutate_status, reconcile_status

DATA = Path(os.environ.get('DATA_DIR', '/var/lib/asterisk/SLS_Mass_Notifications_Plugin'))
RUNTIME = Path(os.environ.get('RUNTIME_DIR', '/usr/local/bin/sls_mass_notify'))
MAX_BYTES = 16 * 1024 * 1024
MAX_JOBS = 1000
FRESH_SECONDS = 180


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
        return json.loads(raw)


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
def state_lock(directory=DATA):
    directory_fd = _open_directory(Path(directory))
    try:
        _validate_parent_directory(directory_fd)
        fd = os.open('weather-delivery.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o640, dir_fd=directory_fd)
        with os.fdopen(fd, 'a+') as handle:
            _secure_file_metadata(handle.fileno())
            fcntl.flock(handle, fcntl.LOCK_EX)
            try:
                data = read_json_at(directory_fd, 'weather-delivery.json')
            except FileNotFoundError:
                data = {'schema': 1, 'jobs': {}, 'snapshots': {}}
            if not isinstance(data, dict) or data.get('schema') != 1 or not isinstance(data.get('jobs'), dict) or not isinstance(data.get('snapshots'), dict):
                raise RuntimeError('Weather queue state is corrupt')
            yield data
            encoded = json.dumps(data, separators=(',', ':'), allow_nan=False).encode()
            if len(encoded) > MAX_BYTES:
                raise RuntimeError('Weather delivery queue capacity exceeded')
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
    if old.get('state') in {'running', 'complete', 'uncertain', 'failed'}:
        return job_id
    # Only unsent work is replaced by a newer observation of the same chain.
    data['jobs'] = {k: v for k, v in data['jobs'].items()
                    if v.get('state') in {'queued', 'running'} or float(v.get('updated_at', now)) > now - 7 * 86400}
    if job_id not in data['jobs'] and len(data['jobs']) >= MAX_JOBS:
        raise RuntimeError('Weather delivery queue is full')
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


def fetch_zone(zone):
    if not re.fullmatch(r'[A-Z]{2}[CZ][0-9]{3}', zone):
        raise ValueError('Invalid Weather zone')
    result = subprocess.run(['/usr/bin/curl', '-fsS', '--connect-timeout', '5', '--max-time', '20',
        '--retry', '1', '--retry-max-time', '45', '--max-filesize', '10485760',
        '-H', 'Accept: application/geo+json', '-H',
        'User-Agent: SLS-Mass-Notify/0.1.2-beta (https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server)',
        'https://api.weather.gov/alerts/active?zone=' + zone + '&status=actual'], capture_output=True, timeout=50, check=True)
    return validate_collection(json.loads(result.stdout))


def observe_nws():
    configured = groups()
    reconcile_status(DATA / 'status.json', configured)
    outcomes = {}
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        futures = {pool.submit(fetch_zone, zone): zone for zone in {row[2] for row in configured.values()}}
        for future in concurrent.futures.as_completed(futures):
            try:
                outcomes[futures[future]] = future.result()
            except Exception:
                outcomes[futures[future]] = None
    now = time.time()
    iso = datetime.now(timezone.utc).isoformat()
    for group_id, row in configured.items():
        features = outcomes.get(row[2])
        if features is None:
            mutate_status(DATA / 'status.json', group_id, row[1], row[2],
                          {'api_failure': {'at': iso, 'message': 'Weather.gov observation failed; queued alerts require a fresh observation.', 'threshold': 3}})
            continue
        by_chain = {}
        for feature in features:
            if actionable(feature, now):
                key = alert_key(feature)
                previous = by_chain.get(key)
                if previous is None or timestamp(feature['properties'].get('sent')) >= timestamp(previous['properties'].get('sent')):
                    by_chain[key] = feature
        active = list(by_chain.values())
        with state_lock() as data:
            data['snapshots'] = {k: v for k, v in data['snapshots'].items() if k in configured}
            data['snapshots'][group_id] = {'zone': row[2], 'observed_at': now,
                'active': {alert_key(feature): feature for feature in active}}
            for feature in sorted(active, key=priority):
                enqueue_locked(data, 'nws', group_id, alert_key(feature), {'zone': row[2], 'feature': feature}, now, priority(feature))
        events = {}
        for feature in active:
            event = feature['properties']['event']; events[event] = events.get(event, 0) + 1
        write_gate(group_id, row, events, now)
        mutate_status(DATA / 'status.json', group_id, row[1], row[2], {'reset_api': True, 'patch': {
            'last_poll_at': iso, 'last_poll_ok_at': iso, 'last_poll_status': 'ok',
            'last_poll_feature_count': len(features), 'last_poll_candidate_count': len(active),
            'last_poll_candidate_events': events, 'last_poll_events': events,
            'last_poll_message': f'Weather.gov observation complete: {len(active)} active alert(s); delivery runs separately.'}})
    return len(configured)


def valid_nws(job, data, now):
    snapshot = data['snapshots'].get(job['group_id'], {})
    if not 0 <= now - snapshot.get('observed_at', 0) <= FRESH_SECONDS:
        return False
    if snapshot.get('zone') != job['payload'].get('zone'):
        return False
    feature = snapshot.get('active', {}).get(job['key'])
    return bool(feature and actionable(feature, now))


def check_job(job_id, directory=DATA):
    with state_lock(directory) as data:
        job = data['jobs'].get(job_id, {})
        return job.get('state') == 'running' and (job.get('service') != 'nws' or valid_nws(job, data, time.time()))


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
        'STATUS_FILE': str(DATA / 'status.json'), 'LOCK_FILE': str(DATA / f'nws-poll-{group_id}.lock')}
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
    cycle_id = 'nws_' + os.urandom(16).hex()
    env = nws_environment(row, cycle_id)
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
        result = subprocess.run([str(RUNTIME / 'sls_mass_notify_nws_poll.sh')], env=env, timeout=5400)
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
    states = [(str(DATA / f'external-deliveries-{group_id}.json'), 'nws') for group_id in configured]
    states.append((str(DATA / 'xweather-external-deliveries.json'), 'xweather'))
    for path, source in states:
        if not Path(path).is_file():
            continue
        try:
            subprocess.run([sys.executable, str(RUNTIME / 'sls_notification_destinations.py'),
                os.environ.get('CONFIG_FILE', str(DATA / 'mass-notifications.config')), '--retry-state', path],
                env=dict(os.environ, SLS_NOTIFICATION_LIVE='1', SLS_NOTIFICATION_TEST='0',
                    SLS_NOTIFICATION_DRY_RUN='0', SLS_DESTINATION_SOURCE=source, SLS_EXTERNAL_RETRY_ONLY='1'), timeout=45)
        except subprocess.TimeoutExpired:
            print('External retry worker exceeded its deadline.', file=sys.stderr)


def kick_external():
    subprocess.Popen([sys.executable, str(RUNTIME / 'sls_weather_queue.py'), 'external'],
        stdin=subprocess.DEVNULL, start_new_session=True, close_fds=True)


def dispatch():
    with singleton('weather-dispatch-worker.lock') as acquired:
        if not acquired:
            return 0
        with state_lock() as data:
            for job in data['jobs'].values():
                if job.get('state') == 'running':
                    job.update(state='uncertain', updated_at=time.time(), detail='Previous worker interrupted; automatic replay suppressed.')
        deadline = time.monotonic() + 3300
        while time.monotonic() < deadline:
            with state_lock() as data:
                eligible = []
                for job_id, job in data['jobs'].items():
                    if job.get('state') != 'queued':
                        continue
                    if time.time() - job['created_at'] > 3600:
                        job.update(state='expired', updated_at=time.time()); continue
                    if job['service'] == 'nws' and not valid_nws(job, data, time.time()):
                        # A stale observation waits for the next poll; known cancellation is terminal.
                        snapshot = data['snapshots'].get(job['group_id'], {})
                        if time.time() - snapshot.get('observed_at', 0) <= FRESH_SECONDS:
                            job.update(state='cancelled', updated_at=time.time())
                        continue
                    eligible.append((job['priority'], job['created_at'], job_id))
                job = None
                if eligible:
                    job_id = min(eligible)[2]
                    job = data['jobs'][job_id]
                    job.update(state='running', updated_at=time.time())
                    job = dict(job)
            if job is None:
                return 0
            try:
                if job['service'] == 'nws':
                    state, detail = dispatch_nws(job_id, job)
                else:
                    import sls_mass_notify_xweather_poll as lightning
                    state, detail = lightning.deliver_queued_event(job['payload'])
            except Exception as exc:
                state, detail = 'uncertain', 'Delivery interrupted (' + type(exc).__name__ + '); automatic replay suppressed.'
            with state_lock() as data:
                data['jobs'][job_id].update(state=state, updated_at=time.time(), detail=detail)
            kick_external()
        return 0


def services_enabled():
    path = Path(os.environ.get('CONFIG_FILE', str(DATA / 'mass-notifications.config')))
    fd = _open_directory(path.parent)
    try:
        config = read_json_at(fd, path.name, 2 * 1024 * 1024)
    finally:
        os.close(fd)
    if not isinstance(config, dict) or not isinstance(config.get('xweather', {}), dict):
        raise ValueError('Invalid weather service configuration')
    enabled = lambda value: str(value).lower() in {'1', 'true', 'yes', 'on'}
    return enabled(config.get('enabled', '0')) or enabled(config.get('xweather', {}).get('enabled', '0'))


def cycle():
    # A disabled fresh install needs no children or mutable weather state.
    # In particular, installer probes must not outlive their temporary directory.
    if not services_enabled():
        return 0
    # Child delivery does not inherit either observation lock or PHP session.
    observation_failed = False
    try:
        with singleton('weather-observation.lock') as acquired:
            if acquired:
                observe_nws()
    except Exception as exc:
        observation_failed = True
        print('Weather observation failed: ' + type(exc).__name__, file=sys.stderr)
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
    parser.add_argument('action', choices=['cycle', 'dispatch', 'check', 'external'])
    parser.add_argument('job_id', nargs='?')
    args = parser.parse_args()
    try:
        if args.action == 'check':
            return 0 if re.fullmatch(r'[a-f0-9]{64}', args.job_id or '') and check_job(args.job_id) else 1
        if args.action == 'external':
            with singleton('weather-external-worker.lock') as acquired:
                if acquired: retry_pending_external()
            return 0
        return cycle() if args.action == 'cycle' else dispatch()
    except Exception as exc:
        print('Weather queue failed: ' + type(exc).__name__ + '. Check protected queue state and API connectivity.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(cli())
