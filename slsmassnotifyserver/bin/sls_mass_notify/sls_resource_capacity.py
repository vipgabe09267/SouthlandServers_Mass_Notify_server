#!/usr/bin/env python3
"""Read-only admission checks for provisional combined PBX/SLS resource tiers.

These engineering allocations do not certify delivery throughput. Desktop
transport retains its separate stream limit and requires JSON-poll fallback.
"""
import importlib.util
import argparse
import json
import os
from pathlib import Path

import re
import stat
import subprocess
import sys
import time

GIB = 1024 ** 3
MIB = 1024 ** 2
TIERS = ((50, 2, 4), (100, 3, 5), (250, 4, 6), (500, 6, 8), (1000, 8, 12))
REPORTING_PERCENT = 95
PHONE_BASELINE = 25
# 2026-10-02 private Asterisk: 25/50/100 PCM conference recipients used
# 0.058/0.133/0.242 cores and 35/41/52 MiB total RSS. Allow substantially more
# for SIP/SRTP, transcoding, caller setup bursts and normal PBX call traffic.
PHONE_RESOURCE_BASELINE = 100
PHONE_CONTACTS_PER_CPU = 200
PHONE_MEMORY_PER_CONTACT = 2 * MIB
PHONE_MEMORY_ROUNDING = 256 * MIB
# Reference measured 2026-10-02 on Debian12/CPython3.11/amd64 with every exact pin.
# The compressed wheel sizes are from the corresponding PyPI version JSON APIs.
PINNED_VENV_ALLOCATED = 217780224
PINNED_VENV_FILES = 4376
PINNED_WHEEL_BYTES = 77802965
PINNED_VERSIONS = {'pip': '26.2.1', 'setuptools': '84.0.0', 'wheel': '0.48.0',
                   'piper-tts': '1.8.0', 'onnxruntime': '1.30.0', 'numpy': '2.4.6',
                   'flatbuffers': '25.12.19', 'packaging': '26.3',
                   'pathvalidate': '3.3.1', 'protobuf': '7.36.2'}
VOICE_BYTES = {'en_US-lessac-low.onnx': 63201294, 'en_US-lessac-low.onnx.json': 4882,
               'en_US-lessac-medium.onnx': 63201294, 'en_US-lessac-medium.onnx.json': 4885,
               'en_US-amy-low.onnx': 63104526, 'en_US-amy-low.onnx.json': 4164,
               'en_US-ryan-low.onnx': 63104526, 'en_US-ryan-low.onnx.json': 4165,
               'es_ES-davefx-medium.onnx': 63201294,
               'es_ES-davefx-medium.onnx.json': 4817,
               'fr_FR-siwis-medium.onnx': 63201294,
               'fr_FR-siwis-medium.onnx.json': 4875,
               'de_DE-thorsten-low.onnx': 63104526,
               'de_DE-thorsten-low.onnx.json': 4159,
               'pt_BR-faber-medium.onnx': 63201294,
               'pt_BR-faber-medium.onnx.json': 4855}
MANAGED_TREES = {'runtime': '/usr/local/bin/sls_mass_notify',
                 'venv': '/usr/local/bin/sls_mass_notify/piper/venv',
                 'voices': '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices',
                 'data': '/var/lib/asterisk/SLS_Mass_Notifications_Plugin',
                 'config_backups': '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/config-backups',
                 'audio': '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds/tts',
                 'module': '/var/www/html/admin/modules/slsmassnotifyserver',
                 'web': '/var/www/html/sls_mass_notify',
                 'assets': '/var/www/html/sls_mass_notify/assets',
                 'sip_api': '/var/www/html/api/sipnotify',
                 'control_api': '/var/www/html/api/sls-mass-notify', 'operator_portal': '/var/www/html/mass-notify'}
STATE_FILES = {'phone_ledger': 'phone-admission.json', 'weather_ledger': 'weather-delivery.json',
               'schedule_ledger': 'schedule-executions.json', 'config': 'mass-notifications.config'}
RECOVERY_RECORDINGS = tuple('/var/lib/asterisk/sounds/en/custom/' + name + '.wav' for name in (
    'SLS_Mass_Notify_Paging_Tone_Opening', 'SLS_Mass_Notify_Paging_Tone_Closing',
    'SLS_Mass_Notify_NWS_Alert', 'SLS_Mass_Notify_Lightning_Alert',
    'Paging_Tone_Opening', 'Paging_Tone_Closing', 'NWS_alert', 'Lightning_alert')) + tuple(
    MANAGED_TREES['data'] + '/sounds/tones/' + name + '.wav' for name in (
    'opening_Paging_Tone_Opening', 'closing_Paging_Tone_Closing', 'opening_NWS_alert', 'opening_Lightning_alert'))
PERSISTENT_PATHS = ('/', '/var/lib/asterisk', '/usr/local', '/var/www', '/var/log')


def rounded(value, unit=4096):
    return (int(value) + unit - 1) // unit * unit


def storage_requirements(measured=None, missing_voices=None, allocation_units=None, temporary_directory='/tmp'):
    """Explain each byte reservation; retained history is not a fixed quota.

    Installation workspaces may overlap bounded state updates/compaction.
    Rendering and retained media require separate measured provisioning: there
    is no enforced global synthesis limit or total retained-media byte quota.
    """
    measured = measured or {}
    if temporary_directory not in ('/tmp', '/var/tmp'):
        raise ValueError('Unsupported installation workspace')
    units = allocation_units or {}
    size = lambda name: int(measured.get(name, {}).get('allocated_bytes', 0))
    unit = lambda path: max(4096, int(units.get(path, 4096)))
    # Accepted releases contain at most2000 entries and50MiB regular-file data.
    payload = lambda path: 50 * MIB + 2000 * unit(path)
    runtime, voices, data = (MANAGED_TREES[name] for name in ('runtime', 'voices', 'data'))
    reference_venv = PINNED_VENV_ALLOCATED + PINNED_VENV_FILES * (unit(runtime) - 4096)
    venv = max(reference_venv, size('venv'))
    voice_total = sum(rounded(value, unit(voices)) for value in VOICE_BYTES.values())
    model_temporary = rounded(max(VOICE_BYTES.values()), unit(voices))
    if missing_voices is None:
        missing_voices = voice_total
    # Phone/Weather 16 MiB; encrypted configuration 3 MiB; schedule reserve at least its10MiB backup
    #limit or its actual size. Up to25 queued jobs may write2MiB records.
    atomic_state = sum(max(limit * MIB, size(name)) for name, limit in
                       (('phone_ledger', 16), ('weather_ledger', 16), ('schedule_ledger', 10), ('config', 3))) + 25 * 2 * MIB
    # Retain 20 encrypted configuration backups, each bounded to 3 MiB.
    backup_growth = max(0, 20 * 3 * MIB - size('config_backups'))
    components = {
        runtime: {'replacement_runtime': max(size('runtime'), venv + payload(runtime))},
        voices: {'missing_or_replacement_voices': max(int(missing_voices), model_temporary)},
        data: {'bounded_atomic_state_writes': atomic_state},
        MANAGED_TREES['config_backups']: {'remaining_bounded_config_backups': backup_growth},
        MANAGED_TREES['module']: {'new_module_payload': payload(MANAGED_TREES['module'])},
        #Independent target mounts each need room for a legal package payload.
        MANAGED_TREES['web']: {'public_asset_payload': payload(MANAGED_TREES['web'])},
        MANAGED_TREES['sip_api']: {'sip_api_payload': payload(MANAGED_TREES['sip_api'])},
        MANAGED_TREES['control_api']: {'control_api_payload': payload(MANAGED_TREES['control_api'])},
        MANAGED_TREES['operator_portal']: {'operator_portal_payload': payload(MANAGED_TREES['operator_portal'])},
        #Maintenance compacts logs sequentially, using backup+retained temporary.
        '/var/log': {'event_log_backup_and_retained_copy': 2 * 64 * MIB},
        '/var/lib/sls-mass-notify-trust': {'new_authenticated_generation': payload('/var/lib/sls-mass-notify-trust'),
                                         'stock_and_release_manifests': 8 * MIB},
        temporary_directory: {'release_download_and_metadata': 52 * MIB + 16 * 1024 + 4096,
                 'extracted_release': payload(temporary_directory), 'failed_release_recovery': payload(temporary_directory),
                 'old_module_recovery': size('module'), 'protected_config_snapshot': 16 * MIB,
                 'reviewed_previous_and_candidate_sources': 2 * payload(temporary_directory),
                 'previous_release_download_and_metadata': 64 * MIB + 16 * 1024 + 4096,
                 # Two retained signed upstream packages and one active GPG
                 # verification's signed/plain copies; each bounded to64MiB.
                 'upstream_package_verification': 4 * 64 * MIB,
                 'prior_static_runtime_and_api_snapshot': sum(size(name) for name in
                     ('runtime', 'assets', 'sip_api', 'control_api', 'operator_portal', 'recovery_recordings')) + 32 * MIB,
                 'missing_signed_helper_repairs': payload(temporary_directory),
                 'quarantined_python_caches': 16 * MIB,
                 'pinned_wheel_downloads': rounded(PINNED_WHEEL_BYTES, unit(temporary_directory)),
                 'wheel_unpack_workspace': venv, 'pip_old_package_recovery': venv,
                 'one_voice_download': rounded(max(VOICE_BYTES.values()), unit(temporary_directory))},
    }
    budgets = {path: sum(parts.values()) for path, parts in components.items()}
    fixed_names = ('runtime', 'voices', 'module', 'assets', 'sip_api', 'control_api')
    fixed = sum(size(name) for name in fixed_names)
    mutable = max(0, size('data') - size('voices')) + max(0, size('web') - size('assets'))
    return {'basis': 'measured_managed_trees_pinned_catalog_and_authenticated_recovery_2026-10-05',
            'measured_fixed_allocation_bytes': fixed, 'measured_mutable_data_and_media_bytes': mutable,
            'measured_trees': measured, 'fresh_reference_voice_bytes': voice_total,
            'pinned_runtime_reference_bytes': reference_venv, 'pinned_wheel_download_bytes': PINNED_WHEEL_BYTES,
            'component_budgets_bytes': components, 'free_budgets_bytes': budgets,
            'temporary_directory': temporary_directory,
            'sls_persistent_free_bytes': sum(value for path, value in budgets.items() if path != temporary_directory),
            'temporary_free_bytes': budgets[temporary_directory],
            'additional_free_bytes': sum(budgets.values()),
            'steady_state_reserve_bytes': atomic_state + backup_growth + 2 * 64 * MIB,
            'retention_is_byte_bounded': False, 'synthesis_concurrency_is_enforced': False,
            'render_workspace_formula': {'raw_pcm_bytes': 'duration_seconds * voice_sample_rate * 2 + WAV header',
                                         'pbx_pcm_bytes': 'duration_seconds * 8000 * 2 + WAV header',
                                         'provisioning': 'Reserve raw audio, converted speech, final sequence and temporary sequence for each simultaneously rendering job. Add retained output separately.'},
            'retention_provisioning': 'Add measured retained-data growth over the chosen retention window and the peak bytes created between successful cleanup runs. Active references can extend retention; add raw+converted+sequence workspace for each additional simultaneous speech pipeline.'}


FREE_BUDGETS = storage_requirements()['free_budgets_bytes']


class ResourceProbeError(ValueError):
    """Only locally constructed, operator-safe diagnostics may reach the UI."""
    def __init__(self, code, message):
        super().__init__(message)
        self.code = code


def measure_tree(path, deadline, maximum_entries=50000):
    """Metadata only, without following directory/file links or printing names."""
    result = {'allocated_bytes': 0, 'logical_bytes': 0, 'files': 0, 'largest_file_bytes': 0}
    root = Path(path)
    if not root.exists():
        return result
    if root.is_symlink() or not root.is_dir():
        raise ValueError('An SLS storage root is not a real directory')
    seen, seen_directories, examined, pending = set(), set(), 0, [root]
    while pending:
        if time.monotonic() > deadline:
            raise ValueError('SLS storage measurement exceeded its time limit; review retained history and retry')
        directory = pending.pop()
        try:
            metadata = directory.lstat()
        except FileNotFoundError:
            # Another worker may retire an already enumerated terminal directory.
            continue
        identity = (metadata.st_dev, metadata.st_ino)
        if not stat.S_ISDIR(metadata.st_mode) or identity in seen_directories:
            continue
        seen_directories.add(identity)
        try:
            with os.scandir(directory) as entries:
                for entry in entries:
                    examined += 1
                    if examined > maximum_entries or time.monotonic() > deadline:
                        raise ValueError('SLS storage measurement exceeded its bounded scan; review retained history and retry')
                    try:
                        metadata = entry.stat(follow_symlinks=False)
                    except FileNotFoundError:
                        continue
                    if stat.S_ISDIR(metadata.st_mode):
                        pending.append(Path(entry.path))
                        continue
                    identity = (metadata.st_dev, metadata.st_ino)
                    if not stat.S_ISREG(metadata.st_mode) or identity in seen:
                        continue
                    seen.add(identity)
                    result['allocated_bytes'] += metadata.st_blocks * 512
                    result['logical_bytes'] += metadata.st_size
                    result['largest_file_bytes'] = max(result['largest_file_bytes'], metadata.st_size)
                    result['files'] += 1
        except PermissionError as error:
            relative = directory.relative_to(root).as_posix()
            detail = str(root)
            if re.fullmatch(r'[A-Za-z0-9_./-]{1,120}', relative) and relative != '.':
                detail += '/' + relative
            raise ResourceProbeError('storage_directory_unreadable',
                'The PBX account cannot read SLS storage at ' + detail +
                '. Run Repair Installation to restore directory traversal/read permissions, then retry the resource check.') from error
        except OSError as error:
            raise ValueError('Unable to measure an SLS storage directory safely') from error
    return result


def probe_storage():
    deadline = time.monotonic() + 2
    measured = {name: measure_tree(path, deadline) for name, path in MANAGED_TREES.items()}
    measured['recovery_recordings'] = {'allocated_bytes': 0}
    for name in RECOVERY_RECORDINGS:
        try:
            metadata = Path(name).lstat()
        except FileNotFoundError:
            continue
        if stat.S_ISREG(metadata.st_mode):
            measured['recovery_recordings']['allocated_bytes'] += metadata.st_blocks * 512
    for key, name in STATE_FILES.items():
        try:
            metadata = Path(MANAGED_TREES['data'], name).lstat()
        except FileNotFoundError:
            continue
        if stat.S_ISREG(metadata.st_mode):
            measured[key] = {'allocated_bytes': metadata.st_blocks * 512, 'logical_bytes': metadata.st_size}
    units = {}
    for path in FREE_BUDGETS:
        existing = Path(path)
        while not existing.exists():
            existing = existing.parent
        units[path] = os.statvfs(existing).f_frsize
    missing = 0
    for name, expected in VOICE_BYTES.items():
        path = Path(MANAGED_TREES['voices'], name)
        try:
            metadata = path.lstat()
        except FileNotFoundError:
            metadata = None
        if metadata is None or not stat.S_ISREG(metadata.st_mode) or metadata.st_size != expected:
            missing += rounded(expected, max(4096, units[MANAGED_TREES['voices']]))
    return storage_requirements(measured, missing, units)


def read_limit(path):
    try:
        return path.read_text(encoding='ascii').strip()
    except FileNotFoundError:
        return None


def cgroup_directories():
    """Inspect the current cgroup and all visible parents, including v1 hosts."""
    entries = []
    for line in Path('/proc/self/cgroup').read_text().splitlines():
        _, controllers, relative = line.split(':', 2)
        parts = Path(relative).parts
        if '..' in parts or not relative.startswith('/'):
            raise ValueError('Invalid cgroup membership')
        if not controllers:
            entries.append((Path('/sys/fs/cgroup'), relative, 'v2'))
        else:
            for controller in controllers.split(','):
                if controller in {'memory', 'cpu'}:
                    root = Path('/sys/fs/cgroup') / controller
                    if controller == 'cpu' and not root.exists():
                        root = Path('/sys/fs/cgroup/cpu,cpuacct')
                    entries.append((root, relative, controller))
    for root, relative, kind in entries:
        current = root / relative.lstrip('/')
        while True:
            if current.is_dir():
                yield current, kind
            if current == root:
                break
            current = current.parent


def probe_compute():
    cpu = float(len(os.sched_getaffinity(0)))
    memory_text = Path('/proc/meminfo').read_text(encoding='ascii')
    match = re.search(r'^MemTotal:\s+(\d+)\s+kB$', memory_text, re.M)
    if not match or cpu < 1:
        raise ValueError('CPU or physical memory information is unavailable')
    memory = int(match.group(1)) * 1024
    for directory, kind in cgroup_directories():
        memory_value = read_limit(directory / ('memory.max' if kind == 'v2' else 'memory.limit_in_bytes')) if kind in {'v2', 'memory'} else None
        if memory_value is not None and memory_value != 'max':
            limit = int(memory_value)
            if 0 < limit < 2 ** 60:
                memory = min(memory, limit)
        if kind == 'v2':
            value = read_limit(directory / 'cpu.max')
            if value:
                quota, period = value.split()
                if quota != 'max':
                    cpu = min(cpu, int(quota) / int(period))
        elif kind == 'cpu':
            quota = read_limit(directory / 'cpu.cfs_quota_us')
            period = read_limit(directory / 'cpu.cfs_period_us')
            if quota is not None and period is not None and int(quota) > 0:
                cpu = min(cpu, int(quota) / int(period))
    if memory <= 0 or cpu <= 0:
        raise ValueError('Invalid effective resource limits')
    return cpu, memory


def filesystem_types():
    mounts = []
    for line in Path('/proc/self/mountinfo').read_text().splitlines():
        before, after = line.split(' - ', 1)
        mount = re.sub(r'\\([0-7]{3})', lambda match: chr(int(match.group(1), 8)), before.split()[4])
        mounts.append((mount.rstrip('/') or '/', after.split()[0]))
    return sorted(mounts, key=lambda item: len(item[0]), reverse=True)


def probe_filesystems(free_budgets=None):
    free_budgets = FREE_BUDGETS if free_budgets is None else free_budgets
    mounts = filesystem_types()
    devices = {}
    for path in dict.fromkeys((*PERSISTENT_PATHS, *free_budgets)):
        existing = Path(path)
        while not existing.exists():
            existing = existing.parent
        actual = str(existing.resolve())
        metadata = existing.stat()
        usage = os.statvfs(existing)
        kind = next((kind for mount, kind in mounts if actual == mount or actual.startswith(mount.rstrip('/') + '/')), '')
        persistent = path in PERSISTENT_PATHS and kind not in {'tmpfs', 'ramfs', 'devtmpfs'}
        record = devices.setdefault(metadata.st_dev, {
            'device': str(metadata.st_dev), 'paths': [], 'total_bytes': usage.f_blocks * usage.f_frsize,
            'available_bytes': usage.f_bavail * usage.f_frsize, 'required_free_bytes': 0, 'persistent': False,
            'read_only': False, 'inodes_exhausted': False,
        })
        record['paths'].append(path)
        # Shared mounts and bind mounts must not count the same bytes twice.
        record['available_bytes'] = min(record['available_bytes'], usage.f_bavail * usage.f_frsize)
        record['total_bytes'] = min(record['total_bytes'], usage.f_blocks * usage.f_frsize)
        record['required_free_bytes'] += free_budgets.get(path, 0)
        if free_budgets.get(path, 0):
            record['read_only'] = record['read_only'] or bool(getattr(usage, 'f_flag', 0) & os.ST_RDONLY)
            # Filesystems without a fixed inode table may report zero for both.
            record['inodes_exhausted'] = record['inodes_exhausted'] or (
                getattr(usage, 'f_files', 0) > 0 and getattr(usage, 'f_favail', None) == 0)
        record['persistent'] = record['persistent'] or persistent
    return list(devices.values())


def trusted_temporary_directory(path):
    if path not in ('/tmp', '/var/tmp'):
        return False
    try:
        for directory in (Path(path), *Path(path).parents):
            info = directory.lstat()
            if (not stat.S_ISDIR(info.st_mode) or info.st_uid != 0
                    or (info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX)):
                return False
        return True
    except OSError:
        return False


def probe_storage_filesystems(storage, temporary_directory=None):
    """Use persistent scratch space when a small /tmp cannot hold the install.

    All reservations still apply to their actual filesystem. Moving scratch
    cannot make an insufficient data/runtime filesystem eligible.
    """
    choices = (temporary_directory,) if temporary_directory else ('/tmp', '/var/tmp')
    first = None
    for path in choices:
        if not trusted_temporary_directory(path):
            continue
        if path == '/tmp':
            candidate = storage
        else:
            unit = os.statvfs(path).f_frsize
            # Preserve measured target reservations; only scratch changes mount.
            candidate = dict(storage)
            budgets = dict(storage['free_budgets_bytes'])
            components = dict(storage['component_budgets_bytes'])
            scratch = dict(components.pop('/tmp'))
            scratch['extracted_release'] = scratch['failed_release_recovery'] = 50 * MIB + 2000 * max(4096, unit)
            scratch['pinned_wheel_downloads'] = rounded(PINNED_WHEEL_BYTES, max(4096, unit))
            scratch['one_voice_download'] = rounded(max(VOICE_BYTES.values()), max(4096, unit))
            del budgets['/tmp']
            budgets[path] = sum(scratch.values())
            components[path] = scratch
            candidate.update({'temporary_directory': path, 'temporary_free_bytes': budgets[path],
                              'free_budgets_bytes': budgets, 'component_budgets_bytes': components,
                              'additional_free_bytes': sum(budgets.values())})
        filesystems = probe_filesystems(candidate['free_budgets_bytes'])
        if first is None:
            first = (candidate, filesystems)
        if all(row['available_bytes'] >= row['required_free_bytes'] and not row.get('read_only')
               and not row.get('inodes_exhausted') for row in filesystems):
            return candidate, filesystems
    if first is None:
        raise ResourceProbeError('temporary_directory_unavailable',
            'Neither /tmp nor /var/tmp is a safe root-owned temporary directory. Restore the standard directory ownership and sticky permissions before installing.')
    return first


def configured_limits(path):
    """Read only the capacity fields; never echo configuration or credentials."""
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise ValueError('Configuration path must be absolute')
    parent = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for component in path.parts[1:-1]:
            child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
            os.close(parent)
            parent = child
        descriptor = os.open(path.name, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW, dir_fd=parent)
    finally:
        os.close(parent)
    with os.fdopen(descriptor, 'rb') as handle:
        metadata = os.fstat(handle.fileno())
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size > 16 * 1024 * 1024:
            raise ValueError('Configuration is not a bounded private regular file')
        raw = handle.read(16 * 1024 * 1024 + 1)
    if len(raw) > 16 * 1024 * 1024:
        raise ValueError('Configuration exceeded its size limit')
    value = json.loads(raw)
    if isinstance(value, dict) and any(field in value for field in ('format', 'ciphertext', 'key_id', 'nonce')):
        if value.get('format') != 'sls-mass-notify-config-aes256gcm-v1':
            raise ValueError('The protected configuration encryption format is unsupported or damaged')
        helper = Path(__file__).resolve().with_name('sls_config_crypto.py')
        if not helper.is_file():
            helper = Path('/usr/local/bin/sls_mass_notify/sls_config_crypto.py')
        for protected in [helper, *helper.parents][:-1]:
            metadata = protected.lstat()
            if metadata.st_uid != 0 or (metadata.st_mode & 0o022 and not (stat.S_ISDIR(metadata.st_mode) and metadata.st_mode & stat.S_ISVTX)) or stat.S_ISLNK(metadata.st_mode):
                raise ValueError('Installed encryption helper is not protected')
        sys.dont_write_bytecode = True
        spec = importlib.util.spec_from_file_location('sls_config_crypto', helper)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        value = module.decode_config(raw)
    if not isinstance(value, dict):
        raise ValueError('Configuration must be an object')
    capacities = {key: value.get(key, default) for key, default in
                  (('desktop_client_limit', 25), ('phone_device_limit', PHONE_BASELINE))}
    if any(type(limit) is not int or not 1 <= limit <= 1000 for limit in capacities.values()):
        raise ValueError('Invalid notification capacity')
    return capacities


def rounded_phone_capacity(count):
    """Retain 25 for small installations, then round up in blocks of 50."""
    if type(count) is not int or count < 0:
        raise ValueError('Invalid detected phone count')
    if count > 1000:
        raise ResourceProbeError('phone_inventory_exceeds_limit',
            'More than 1000 internal phone contacts were detected. SLS supports at most 1000 simultaneous contacts; review the device inventory before installation.')
    return PHONE_BASELINE if count <= PHONE_BASELINE else rounded(count, 50)


def internal_phone_inventory(device_output, contact_output):
    """Count FreePBX PJSIP devices and their contacts, excluding trunk AORs.

    An offline configured device reserves one slot. Shared endpoint aliases do
    not count twice; several contacts on the same endpoint each count once.
    Complete CLI summaries are required so truncated output cannot undercount.
    Only aggregate counts leave this function, never addresses or device names.
    """
    lines = [line.strip() for line in device_output.splitlines() if line.strip()]
    rows = [line for line in lines if line.startswith('/DEVICE/')]
    if not lines or not re.fullmatch(r'[0-9]+ results found\.', lines[-1]) or int(lines[-1].split()[0]) != len(rows):
        raise ValueError('Incomplete FreePBX device inventory')
    endpoints = set()
    for line in rows:
        match = re.fullmatch(r'/DEVICE/[0-9]+/dial\s*:\s*(.*)', line)
        if not match or not match[1].startswith('PJSIP/'):
            continue
        endpoint = re.fullmatch(r'PJSIP/([A-Za-z0-9_.-]{1,80})', match[1])
        if not endpoint:
            raise ValueError('Unsupported internal PJSIP device route')
        endpoints.add(endpoint[1])
    if not endpoints:
        return {'configured_pjsip_devices': 0, 'registered_phone_contacts': 0, 'detected_phone_contacts': 0}
    contact_lines = [line.strip() for line in contact_output.splitlines() if line.strip()]
    summaries = [line for line in contact_lines if re.fullmatch(r'Objects found: [0-9]+', line)
                 or line in ('No objects found.', 'No Contacts found.')]
    if len(summaries) != 1:
        raise ValueError('Incomplete PJSIP contact inventory')
    expected = int(summaries[0].split()[-1]) if summaries[0].startswith('Objects found:') else 0
    contacts, counts = [], {endpoint: 0 for endpoint in endpoints}
    for line in contact_lines:
        if not line.startswith('Contact:'):
            continue
        fields = line.split()
        if len(fields) > 1 and fields[1].startswith('<'):
            continue
        if len(fields) != 5 or '/' not in fields[1] or not re.fullmatch(r'[a-fA-F0-9]{8,64}', fields[2]):
            raise ValueError('Unrecognized PJSIP contact inventory row')
        endpoint = fields[1].split('/', 1)[0]
        contacts.append((endpoint, fields[2]))
        if endpoint in counts:
            counts[endpoint] += 1
    if len(contacts) != expected or len(set(contacts)) != len(contacts):
        raise ValueError('Incomplete or duplicate PJSIP contact inventory')
    return {'configured_pjsip_devices': len(endpoints), 'registered_phone_contacts': sum(counts.values()),
            'detected_phone_contacts': sum(max(1, count) for count in counts.values())}


def detect_internal_phones():
    def command(value):
        result = subprocess.run(['/usr/sbin/asterisk', '-rx', value], capture_output=True,
                                text=True, timeout=3, check=False)
        if result.returncode != 0 or len(result.stdout) > 2 * MIB:
            raise ValueError('Asterisk inventory command failed')
        return result.stdout
    try:
        devices = command('database show DEVICE')
        # An empty DEVICE family needs no contact query. This also permits an
        # otherwise healthy new PBX whose PJSIP modules have no endpoints yet.
        empty = internal_phone_inventory(devices, 'Objects found: 0\n')
        return internal_phone_inventory(devices, command('pjsip show contacts')) if empty['configured_pjsip_devices'] else empty
    except (OSError, ValueError, subprocess.TimeoutExpired) as error:
        raise ResourceProbeError('phone_inventory_unavailable',
            'The internal phone inventory could not be verified. Confirm Asterisk is running and its DEVICE database and PJSIP contact commands are available, then retry installation. No phone capacity was guessed.') from error


def capacity_requirements(requested, phone_requested):
    if type(requested) is not int or not 1 <= requested <= 1000:
        raise ValueError('Desktop capacity must be between 1 and 1000')
    if type(phone_requested) is not int or not 1 <= phone_requested <= 1000:
        raise ValueError('Phone capacity must be between 1 and 1000')
    tier_limit, desktop_cpu, desktop_memory = next(tier for tier in TIERS if requested <= tier[0])
    extra_contacts = max(0, phone_requested - PHONE_RESOURCE_BASELINE)
    phone_cpu = (extra_contacts + PHONE_CONTACTS_PER_CPU - 1) // PHONE_CONTACTS_PER_CPU
    phone_memory = rounded(extra_contacts * PHONE_MEMORY_PER_CONTACT, PHONE_MEMORY_ROUNDING)
    return desktop_cpu + phone_cpu, desktop_memory * GIB + phone_memory, {
        'combined_baseline': {'cpu_count': 2, 'memory_bytes': 4 * GIB,
                              'desktop_clients': 50, 'phone_contacts': PHONE_RESOURCE_BASELINE},
        'desktop_growth': {'tier_limit': tier_limit, 'cpu_count': desktop_cpu - 2,
                           'memory_bytes': (desktop_memory - 4) * GIB},
        'phone_growth': {'contacts_over_baseline': extra_contacts, 'cpu_count': phone_cpu,
                         'memory_bytes': phone_memory},
    }


def evaluate(hardware, requested, phone_requested=PHONE_BASELINE):
    cpu, memory_bytes, components = capacity_requirements(requested, phone_requested)
    required_memory = memory_bytes * REPORTING_PERCENT // 100
    errors = []
    storage = hardware.get('storage', storage_requirements())

    def require(code, message, actual, required):
        if actual < required:
            errors.append({'code': code, 'message': message, 'actual': actual, 'required': required})

    require('cpu_insufficient',
            f'{cpu} effective CPU cores are required for {requested} desktops and {phone_requested} phone contacts; {hardware["effective_cpu_count"]:g} are allocated. Add CPU allocation or lower Desktop Capacity or Phone Capacity.',
            hardware['effective_cpu_count'], cpu)
    require('memory_insufficient',
            f'{memory_bytes / GIB:g} GiB RAM is required for {requested} desktops and {phone_requested} phone contacts; {hardware["effective_memory_bytes"] / GIB:.2f} GiB is usable. Add RAM or lower Desktop Capacity or Phone Capacity.',
            hardware['effective_memory_bytes'], required_memory)
    disks_ok = True
    for item in hardware['filesystems']:
        before = len(errors)
        destinations = [path for path in item['paths'] if storage['free_budgets_bytes'].get(path, 0)] or item['paths']
        location = ', '.join(destinations)
        if item.get('read_only'):
            errors.append({'code': 'storage_read_only', 'message': 'The filesystem containing ' + location + ' is read-only. Restore its writable mount before installing; available bytes cannot make a read-only target usable.', 'actual': None, 'required': None})
        if item.get('inodes_exhausted'):
            errors.append({'code': 'storage_inodes_exhausted', 'message': 'The filesystem containing ' + location + ' has no available file entries (inodes). Free file entries or expand that filesystem before installing.', 'actual': 0, 'required': 1})
        require('free_space_insufficient',
                f'{item["required_free_bytes"] / MIB:.2f} MiB of additional SLS free space is required on the filesystem containing {location}; {item["available_bytes"] / MIB:.2f} MiB is available. Free space or expand this filesystem.',
                item['available_bytes'], item['required_free_bytes'])
        disks_ok = disks_ok and len(errors) == before
    eligible = 0
    eligible_phone = 0
    if disks_ok:
        for maximum, _, _ in TIERS:
            tier_cpu, tier_memory, _ = capacity_requirements(maximum, phone_requested)
            if hardware['effective_cpu_count'] >= tier_cpu and hardware['effective_memory_bytes'] >= tier_memory * REPORTING_PERCENT // 100:
                eligible = maximum
        # Phone capacity is a continuous integer policy, not the desktop tiers.
        for phone_limit in range(1, 1001):
            phone_cpu, phone_memory, _ = capacity_requirements(requested, phone_limit)
            if hardware['effective_cpu_count'] >= phone_cpu and hardware['effective_memory_bytes'] >= phone_memory * REPORTING_PERCENT // 100:
                eligible_phone = phone_limit
    checks = {'cpu': {'ok': hardware['effective_cpu_count'] >= cpu,
                      'actual': hardware['effective_cpu_count'], 'required': cpu},
              'memory': {'ok': hardware['effective_memory_bytes'] >= required_memory,
                         'actual': hardware['effective_memory_bytes'], 'required': required_memory}}
    temporary = storage.get('temporary_directory', '/tmp')
    for name, paths in (('storage', set(storage['free_budgets_bytes']) - {temporary}), ('temporary', {temporary})):
        filesystems = [item for item in hardware['filesystems'] if paths.intersection(item['paths'])]
        covered = set(path for item in filesystems for path in item['paths'])
        checks[name] = {'ok': all(item['available_bytes'] >= item['required_free_bytes'] and not item.get('read_only')
                                and not item.get('inodes_exhausted') for item in filesystems)
                       if paths <= covered else None,
                       'filesystems': [{'paths': item['paths'], 'actual': item['available_bytes'],
                                        'required': item['required_free_bytes']} for item in filesystems]}
    return {
        'schema': 1, 'requested_limit': requested, 'eligible_limit': eligible, 'hardware': hardware,
        'requested_phone_limit': phone_requested, 'eligible_phone_limit': eligible_phone,
        'requirements': {'cpu_count': cpu, 'memory_bytes': memory_bytes,
                         'minimum_reported_memory_bytes': required_memory,
                         'components': components, 'disk_policy': 'additional_sls_free_headroom',
                         'sls_persistent_free_bytes': storage['sls_persistent_free_bytes'], 'temporary_free_bytes': storage['temporary_free_bytes'],
                         'free_budgets_bytes': storage['free_budgets_bytes'], 'storage': storage,
                         'reporting_allowance_percent': 100 - REPORTING_PERCENT},
        'assumptions': {'load_certified': False, 'phone_baseline_contacts': PHONE_BASELINE,
                        'additional_phone_contacts_per_cpu_core': PHONE_CONTACTS_PER_CPU,
                        'additional_phone_memory_bytes_per_contact': PHONE_MEMORY_PER_CONTACT,
                        'phone_memory_rounding_bytes': PHONE_MEMORY_ROUNDING, 'desktop_sse_limit': 32,
                        'included_phone_contacts': PHONE_RESOURCE_BASELINE,
                        'synthesis_concurrency_limited_by_this_check': False,
                        'whole_volume_size_is_enforced': False},
        'errors': errors,
        'resource_checks': checks,
        'warnings': ['Provisional combined PBX/SLS allocation; not load-certified. Phone growth reserves capacity for registered contacts and Local/Page overhead, not measured phones-per-core throughput. This check does not limit concurrent speech synthesis.',
                     f'SLS measured fixed files occupy {storage["measured_fixed_allocation_bytes"] / MIB:.1f} MiB. Additional installation and bounded-state workspace requires {storage["additional_free_bytes"] / MIB:.1f} MiB across the listed filesystems, including {storage["steady_state_reserve_bytes"] / MIB:.1f} MiB for bounded writes/compaction. Retention and concurrent speech require additional measured provisioning; these are not storage quotas or a guarantee for an arbitrary workload.',
                     'Desktop clients above the stream limit require cursor-aware JSON polling. Existing PBX calls, codecs, recording, voicemail, backups, and long retention need separate resource budgets.'],
    }


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    parser.add_argument('--config', type=Path)
    parser.add_argument('--desktop-limit', type=int)
    parser.add_argument('--temporary-directory', choices=('/tmp', '/var/tmp'))
    phone_options = parser.add_mutually_exclusive_group()
    phone_options.add_argument('--phone-limit', type=int)
    phone_options.add_argument('--auto-phone-limit', action='store_true',
                               help='Detect internal phones on a fresh install; retain saved capacities on upgrades')
    options = parser.parse_args(argv)
    requested = options.desktop_limit
    phone_requested = options.phone_limit
    stage = 'configuration'
    selection = None
    try:
        saved = configured_limits(options.config) if options.config else {}
        if requested is None:
            requested = saved.get('desktop_client_limit', 25)
        if phone_requested is None:
            phone_requested = saved.get('phone_device_limit', PHONE_BASELINE)
        if options.auto_phone_limit and not saved:
            stage = 'internal phone inventory'
            selection = detect_internal_phones()
            phone_requested = rounded_phone_capacity(selection['detected_phone_contacts'])
            selection.update({'source': 'internal_pjsip_inventory', 'rounded_phone_capacity': phone_requested})
        stage = 'CPU and memory'
        cpu, memory = probe_compute()
        stage = 'SLS storage'
        storage = probe_storage()
        stage = 'filesystem free space'
        storage, filesystems = probe_storage_filesystems(storage, options.temporary_directory)
        result = evaluate({'effective_cpu_count': cpu, 'effective_memory_bytes': memory,
                           'filesystems': filesystems, 'storage': storage}, requested, phone_requested)
        if selection is not None:
            result['auto_phone_selection'] = selection
    except (OSError, ValueError, ZeroDivisionError, StopIteration) as error:
        code = error.code if isinstance(error, ResourceProbeError) else 'resource_probe_failed'
        message = str(error) if isinstance(error, ResourceProbeError) else (
            'The ' + stage + ' resource check could not be completed. Check the protected configuration and storage permissions, then run Repair Installation before increasing capacity.')
        result = {'schema': 1, 'requested_limit': requested, 'eligible_limit': 0, 'hardware': {}, 'requirements': {},
                  'requested_phone_limit': phone_requested, 'eligible_phone_limit': 0,
                  'errors': [{'code': code, 'message': message, 'actual': None, 'required': None}], 'warnings': []}
        print(json.dumps(result, sort_keys=True))
        return 3
    print(json.dumps(result, sort_keys=True))
    return 2 if result['errors'] else 0


if __name__ == '__main__':
    sys.exit(main())
