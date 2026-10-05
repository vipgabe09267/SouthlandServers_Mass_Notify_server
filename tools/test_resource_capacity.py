#!/usr/bin/env python3
"""Resource admission calculations use fixtures; no PBX operations are invoked."""
import contextlib
import importlib.util
import io
import json
import os
import stat
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_resource_capacity.py'
SPEC = importlib.util.spec_from_file_location('resource_capacity_fixture', SOURCE)
RESOURCE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RESOURCE)
GIB = RESOURCE.GIB


def hardware(cpu=2, memory=4, total=60, available=4):
    return {'effective_cpu_count': cpu, 'effective_memory_bytes': memory * GIB,
            'filesystems': [{'device': '1', 'paths': ['/'], 'total_bytes': total * GIB,
                             'available_bytes': available * GIB, 'required_free_bytes': 4 * GIB,
                             'persistent': True}]}


class ResourceCapacityTests(unittest.TestCase):
    def test_small_tmp_moves_scratch_without_weakening_target_mount_checks(self):
        storage = RESOURCE.storage_requirements()
        def filesystems(budgets):
            scratch = '/tmp' if '/tmp' in budgets else '/var/tmp'
            return [{'device': 'data', 'paths': [p for p in budgets if p != scratch],
                     'available_bytes': 2 * GIB, 'required_free_bytes': sum(v for p, v in budgets.items() if p != scratch)},
                    {'device': 'scratch', 'paths': [scratch], 'available_bytes': (64 * RESOURCE.MIB if scratch == '/tmp' else GIB),
                     'required_free_bytes': budgets[scratch]}]
        with patch.object(RESOURCE, 'trusted_temporary_directory', return_value=True), \
             patch.object(RESOURCE, 'probe_filesystems', side_effect=filesystems), \
             patch.object(RESOURCE.os, 'statvfs', return_value=SimpleNamespace(f_frsize=4096)):
            selected, mounts = RESOURCE.probe_storage_filesystems(storage)
            self.assertEqual(selected['temporary_directory'], '/var/tmp')
            self.assertNotIn('/tmp', selected['free_budgets_bytes'])
            self.assertEqual(selected['sls_persistent_free_bytes'], storage['sls_persistent_free_bytes'])
            self.assertTrue(all(row['available_bytes'] >= row['required_free_bytes'] for row in mounts))
            # A staged install keeps its selected filesystem rather than moving recovery files.
            selected, mounts = RESOURCE.probe_storage_filesystems(storage, '/tmp')
            self.assertEqual(selected['temporary_directory'], '/tmp')
            self.assertTrue(any(row['available_bytes'] < row['required_free_bytes'] for row in mounts))
        def insufficient(budgets):
            return [{'device': '1', 'paths': list(budgets), 'available_bytes': 64 * RESOURCE.MIB,
                     'required_free_bytes': sum(budgets.values())}]
        with patch.object(RESOURCE, 'trusted_temporary_directory', return_value=True), \
             patch.object(RESOURCE, 'probe_filesystems', side_effect=insufficient), \
             patch.object(RESOURCE.os, 'statvfs', return_value=SimpleNamespace(f_frsize=4096)):
            selected, mounts = RESOURCE.probe_storage_filesystems(storage)
            self.assertEqual(selected['temporary_directory'], '/tmp')
            self.assertIn('free_space_insufficient', [r['code'] for r in RESOURCE.evaluate(
                {'effective_cpu_count':2, 'effective_memory_bytes':4 * GIB, 'filesystems':mounts, 'storage':selected}, 25)['errors']])

    def test_dedicated_mounts_do_not_require_unused_parent_volume_headroom(self):
        budgets = {RESOURCE.MANAGED_TREES['runtime']: 250 * RESOURCE.MIB,
                   RESOURCE.MANAGED_TREES['data']: 600 * RESOURCE.MIB, '/tmp': 800 * RESOURCE.MIB}
        with patch.object(RESOURCE, 'filesystem_types', return_value=[('/', 'ext4')]), \
             patch.object(RESOURCE.Path, 'exists', return_value=True), \
             patch.object(RESOURCE.Path, 'resolve', lambda path: path), \
             patch.object(RESOURCE.Path, 'stat', lambda path: SimpleNamespace(st_dev=2 if str(path) in budgets else 1)), \
             patch.object(RESOURCE.os, 'statvfs', side_effect=lambda path: SimpleNamespace(
                 f_blocks=4000, f_bavail=2000 if str(path) in budgets else 8, f_frsize=RESOURCE.MIB)):
            mounts = RESOURCE.probe_filesystems(budgets)
        parents = next(row for row in mounts if row['device'] == '1')
        self.assertEqual(parents['required_free_bytes'], 0)
        self.assertEqual(RESOURCE.evaluate({'effective_cpu_count':2, 'effective_memory_bytes':4 * GIB,
            'filesystems':mounts}, 25)['errors'], [])
        installer = (ROOT/'tools/install_release.sh').read_text()
        platform = installer.split('preflight_platform() {',1)[1].split('\npreflight_python()',1)[0]
        self.assertNotIn('df -Pk', platform)

    def test_temporary_directory_validation_rejects_links_and_writable_nonsticky_roots(self):
        for mode in (stat.S_IFLNK | 0o777, stat.S_IFDIR | 0o777):
            with patch.object(RESOURCE.Path,'lstat',return_value=SimpleNamespace(st_uid=0,st_mode=mode)):
                self.assertFalse(RESOURCE.trusted_temporary_directory('/var/tmp'))
        self.assertFalse(RESOURCE.trusted_temporary_directory('/home/asterisk'))

    def test_permission_failure_identifies_the_unreadable_storage_root(self):
        with tempfile.TemporaryDirectory(prefix='sls-capacity-private-cache-') as name:
            cache = Path(name) / '__pycache__'
            cache.mkdir()
            original = RESOURCE.os.scandir
            def scan(path):
                if Path(path) == cache:
                    raise PermissionError(13, 'fixture permission denied', str(path))
                return original(path)
            with patch.object(RESOURCE.os, 'scandir', scan):
                with self.assertRaises(RESOURCE.ResourceProbeError) as caught:
                    RESOURCE.measure_tree(name, RESOURCE.time.monotonic() + 2)
            self.assertEqual(caught.exception.code, 'storage_directory_unreadable')
            self.assertIn(str(cache), str(caught.exception))
            self.assertIn('Repair Installation', str(caught.exception))
            output = io.StringIO()
            with patch.object(RESOURCE, 'probe_compute', return_value=(4, 8 * GIB)), \
                 patch.object(RESOURCE, 'probe_storage', side_effect=caught.exception), contextlib.redirect_stdout(output):
                status = RESOURCE.main(['--check'])
            report = json.loads(output.getvalue())
            self.assertEqual(status, 3)
            self.assertEqual(report['errors'][0]['code'], 'storage_directory_unreadable')
            self.assertIn(str(cache), report['errors'][0]['message'])
            self.assertEqual(report['eligible_limit'], 0)

    def test_probe_stage_is_actionable_without_raw_exception_disclosure(self):
        output = io.StringIO()
        with patch.object(RESOURCE, 'probe_compute', side_effect=ValueError('sensitive value')), contextlib.redirect_stdout(output):
            self.assertEqual(RESOURCE.main(['--check']), 3)
        message = json.loads(output.getvalue())['errors'][0]['message']
        self.assertIn('CPU and memory', message)
        self.assertNotIn('sensitive value', message)

    def test_storage_reference_matches_all_exact_dependency_pins(self):
        pins = {}
        for line in (SOURCE.parent / 'piper-requirements.txt').read_text().splitlines():
            if line and not line.startswith('#'):
                name, version = line.split('==')
                pins[name] = version
        self.assertEqual(RESOURCE.PINNED_VERSIONS, pins, 'Re-measure pinned wheels/runtime when requirements change.')
        self.assertEqual(RESOURCE.PINNED_WHEEL_BYTES, 77802965)

    def test_fresh_and_installed_storage_budgets_explain_every_component(self):
        fresh = RESOURCE.storage_requirements()
        measured = {'runtime': {'allocated_bytes': 167628800}, 'venv': {'allocated_bytes': 166916096},
                    'voices': {'allocated_bytes': 189452288}, 'module': {'allocated_bytes': 4317184},
                    'config_backups': {'allocated_bytes': 188416}}
        installed = RESOURCE.storage_requirements(measured, missing_voices=0)
        voice_path = RESOURCE.MANAGED_TREES['voices']
        self.assertEqual(fresh['free_budgets_bytes'][voice_path], sum(RESOURCE.rounded(value) for value in RESOURCE.VOICE_BYTES.values()))
        self.assertEqual(installed['free_budgets_bytes'][voice_path], RESOURCE.rounded(max(RESOURCE.VOICE_BYTES.values())))
        self.assertEqual(installed['measured_fixed_allocation_bytes'], sum(measured[key]['allocated_bytes'] for key in ('runtime', 'voices', 'module')))
        for report in (fresh, installed):
            self.assertEqual(report['additional_free_bytes'], sum(report['free_budgets_bytes'].values()))
            self.assertEqual(report['additional_free_bytes'], report['sls_persistent_free_bytes'] + report['temporary_free_bytes'])
            for path, parts in report['component_budgets_bytes'].items():
                self.assertEqual(report['free_budgets_bytes'][path], sum(parts.values()))
            self.assertFalse(report['retention_is_byte_bounded'])
            self.assertFalse(report['synthesis_concurrency_is_enforced'])
            self.assertNotIn('one_audio_pipeline', str(report['component_budgets_bytes']))

    def test_larger_managed_runtime_and_state_expand_recovery_space(self):
        baseline = RESOURCE.storage_requirements()
        large = RESOURCE.storage_requirements({'runtime': {'allocated_bytes': 3 * GIB},
                'venv': {'allocated_bytes': 2 * GIB}, 'schedule_ledger': {'allocated_bytes': 20 * RESOURCE.MIB}})
        self.assertEqual(large['free_budgets_bytes'][RESOURCE.MANAGED_TREES['runtime']], 3 * GIB)
        self.assertGreater(large['temporary_free_bytes'], baseline['temporary_free_bytes'])
        self.assertEqual(large['free_budgets_bytes'][RESOURCE.MANAGED_TREES['data']] - baseline['free_budgets_bytes'][RESOURCE.MANAGED_TREES['data']], 10 * RESOURCE.MIB)
        large_blocks = RESOURCE.storage_requirements(allocation_units={RESOURCE.MANAGED_TREES['runtime']: 65536})
        self.assertGreater(large_blocks['pinned_runtime_reference_bytes'], baseline['pinned_runtime_reference_bytes'])

    def test_storage_metadata_scan_is_bounded_and_does_not_follow_links(self):
        import time
        with tempfile.TemporaryDirectory(prefix='sls-storage-measure-') as name:
            root = Path(name)
            (root / 'file').write_bytes(b'private-content-not-returned')
            (root / 'link').symlink_to('/etc')
            os.mkfifo(root / 'pipe')
            report = RESOURCE.measure_tree(root, time.monotonic() + 2)
            self.assertEqual(report['files'], 1)
            self.assertEqual(report['logical_bytes'], len(b'private-content-not-returned'))
            self.assertNotIn('private', json.dumps(report))
            with self.assertRaises(ValueError):
                RESOURCE.measure_tree(root, time.monotonic() - 1)
            with self.assertRaises(ValueError):
                RESOURCE.measure_tree(root, time.monotonic() + 2, maximum_entries=1)
            with self.assertRaises(ValueError):
                RESOURCE.measure_tree(root / 'link', time.monotonic() + 2)

    def test_actual_managed_mounts_get_independent_headroom(self):
        voices = RESOURCE.MANAGED_TREES['voices']
        budgets = {voices: 100, '/tmp': 200}
        with patch.object(RESOURCE, 'filesystem_types', return_value=[(voices, 'ext4'), ('/', 'ext4')]), \
             patch.object(RESOURCE.Path, 'exists', return_value=True), \
             patch.object(RESOURCE.Path, 'resolve', lambda path: path), \
             patch.object(RESOURCE.Path, 'stat', lambda path: SimpleNamespace(st_dev=2 if str(path) == voices else 1)), \
             patch.object(RESOURCE.os, 'statvfs', return_value=SimpleNamespace(f_blocks=80, f_bavail=30, f_frsize=GIB)):
            filesystems = RESOURCE.probe_filesystems(budgets)
        self.assertEqual({item['device']: item['required_free_bytes'] for item in filesystems}, {'1': 200, '2': 100})

    def test_tiers_and_intermediate_requests(self):
        for maximum, cpu, memory in RESOURCE.TIERS:
            with self.subTest(maximum=maximum):
                report = RESOURCE.evaluate(hardware(cpu, memory), maximum)
                self.assertEqual(report['errors'], [])
                self.assertEqual(report['eligible_limit'], maximum)
                if maximum < 1000:
                    self.assertTrue(RESOURCE.evaluate(hardware(cpu, memory), maximum + 1)['errors'])
        self.assertEqual(RESOURCE.evaluate(hardware(4, 6), 101)['requirements']['memory_bytes'], 6 * GIB)
        self.assertEqual(RESOURCE.evaluate(hardware(), 1)['eligible_limit'], 50)

    def test_nominal_memory_reporting_allowance_is_bounded(self):
        minimum = 4 * GIB * 95 // 100
        measured = hardware()
        measured['effective_memory_bytes'] = minimum
        self.assertEqual(RESOURCE.evaluate(measured, 50)['errors'], [])
        measured['effective_memory_bytes'] -= 1
        report = RESOURCE.evaluate(measured, 50)
        self.assertEqual(report['errors'][0]['code'], 'memory_insufficient')
        self.assertEqual(report['eligible_limit'], 0)

    def test_sls_headroom_is_enforced_without_a_total_volume_floor(self):
        # A smaller existing PBX volume is acceptable when SLS has its own headroom.
        report = RESOURCE.evaluate(hardware(total=20, available=4), 50)
        self.assertEqual(report['errors'], [])
        self.assertFalse(report['assumptions']['whole_volume_size_is_enforced'])
        reference = RESOURCE.storage_requirements()
        self.assertEqual(report['requirements']['sls_persistent_free_bytes'], reference['sls_persistent_free_bytes'])
        self.assertEqual(report['requirements']['temporary_free_bytes'], reference['temporary_free_bytes'])
        measured = hardware(total=60, available=4)
        measured['filesystems'][0]['available_bytes'] -= 1
        report = RESOURCE.evaluate(measured, 50)
        self.assertIn('free_space_insufficient', [error['code'] for error in report['errors']])
        self.assertEqual((report['eligible_limit'], report['eligible_phone_limit']), (0, 0))

    def test_phone_growth_formula_and_continuous_eligibility(self):
        for phones, cpu, ram in ((1,2,4),(25,2,4),(50,2,4),(100,2,4),(101,3,4.25),(150,3,4.25),
                                (228,3,4.25),(229,3,4.5),(300,3,4.5),(301,4,4.5),(500,4,5),(1000,7,6)):
            report=RESOURCE.evaluate(hardware(cpu,ram),50,phones)
            self.assertEqual(report['errors'],[],(phones,report))
            self.assertEqual(report['requirements']['cpu_count'],cpu)
            self.assertEqual(report['requirements']['memory_bytes'],ram*GIB)
        self.assertEqual(RESOURCE.evaluate(hardware(3,4.25),50)['eligible_phone_limit'],228)
        self.assertEqual(RESOURCE.evaluate(hardware(3,4.5),50)['eligible_phone_limit'],300)
        self.assertEqual(RESOURCE.evaluate(hardware(4,5),50)['eligible_phone_limit'],500)
        self.assertEqual(RESOURCE.evaluate(hardware(7,6),50)['eligible_phone_limit'],1000)
        self.assertEqual(RESOURCE.evaluate(hardware(),50)['eligible_phone_limit'],100)

    def test_phone_and_desktop_capacities_share_the_resource_budget(self):
        report=RESOURCE.evaluate(hardware(4,5.8),250,101)
        self.assertEqual({error['code'] for error in report['errors']},{'cpu_insufficient','memory_insufficient'})
        self.assertEqual((report['eligible_limit'],report['eligible_phone_limit']),(100,100))
        combined=RESOURCE.evaluate(hardware(13,14),1000,1000)
        self.assertEqual(combined['errors'],[])
        parts=combined['requirements']['components']
        self.assertEqual(sum(x['cpu_count'] for x in parts.values()),13)
        self.assertEqual(sum(x['memory_bytes'] for x in parts.values()),14*GIB)
        self.assertFalse(combined['assumptions']['load_certified'])

    def test_phone_detection_rounding_and_limit(self):
        for count, limit in ((0,25),(24,25),(25,25),(26,50),(50,50),(51,100),(79,100),(89,100),
                             (100,100),(101,150),(125,150),(150,150),(151,200),(999,1000),(1000,1000)):
            self.assertEqual(RESOURCE.rounded_phone_capacity(count),limit)
        for value in (True,'79',-1,1001):
            with self.assertRaises(ValueError):
                RESOURCE.rounded_phone_capacity(value)

    def test_phone_inventory_offline_contacts_aliases_and_trunk_exclusion(self):
        devices = ('/DEVICE/1000/dial : PJSIP/1000\n/DEVICE/1001/dial : PJSIP/1001\n'
                   '/DEVICE/1002/dial : PJSIP/1000\n/DEVICE/1003/dial : PJSIP/desk-a\n'
                   '/DEVICE/1004/dial : SIP/1004\n/DEVICE/trunk/dial : PJSIP/trunk\n6 results found.\n')
        contacts = ('Contact: <Aor/ContactUri........> <Hash> <Status> <RTT(ms)>\n'
                    'Contact: 1000/sip:fixture-one 0123456789 Avail 1.000\n'
                    'Contact: 1000/sip:fixture-one abcdef0123 NonQual nan\n'
                    'Contact: desk-a/sip:fixture-two 0123abcdef Unavail nan\n'
                    'Contact: trunk/sip:provider ff01234567 Avail 2.000\nObjects found: 4\n')
        self.assertEqual(RESOURCE.internal_phone_inventory(devices,contacts),
                         {'configured_pjsip_devices':3,'registered_phone_contacts':3,'detected_phone_contacts':4})
        for bad_devices, bad_contacts in ((devices.replace('6 results','5 results'),contacts),
                  (devices.replace('PJSIP/desk-a','PJSIP/desk-a&arbitrary'),contacts),
                  (devices,contacts.replace('Objects found: 4','Objects found: 5')),
                  (devices,contacts.replace('abcdef0123','0123456789')),(devices,'No such command')):
            with self.assertRaises(ValueError):
                RESOURCE.internal_phone_inventory(bad_devices,bad_contacts)
        self.assertEqual(RESOURCE.internal_phone_inventory('0 results found.\n','')['detected_phone_contacts'],0)
        self.assertEqual(RESOURCE.internal_phone_inventory(devices,'No objects found.\n')['detected_phone_contacts'],3)

    def test_resource_indicators_match_actual_admission_and_shared_disks(self):
        measured=hardware()
        measured['filesystems'][0]['paths']=list(RESOURCE.FREE_BUDGETS)
        report=RESOURCE.evaluate(measured,50,100)
        self.assertTrue(all(check['ok'] is True for check in report['resource_checks'].values()))
        measured['effective_cpu_count']=1.9
        measured['effective_memory_bytes']=4*GIB*95//100-1
        measured['filesystems'][0]['available_bytes']=measured['filesystems'][0]['required_free_bytes']-1
        report=RESOURCE.evaluate(measured,50,100)
        self.assertTrue(all(check['ok'] is False for check in report['resource_checks'].values()))
        self.assertEqual({row['code'] for row in report['errors']},{'cpu_insufficient','memory_insufficient','free_space_insufficient'})
        # Separate temporary storage must not mark adequate persistent mounts red.
        measured['filesystems'][0]['paths'].remove('/tmp')
        measured['filesystems'][0]['available_bytes']=4*GIB
        measured['filesystems'].append({'device':'2','paths':['/tmp'],'available_bytes':1,
            'total_bytes':GIB,'required_free_bytes':100,'persistent':False})
        checks=RESOURCE.evaluate(measured,50,100)['resource_checks']
        self.assertTrue(checks['storage']['ok']);self.assertFalse(checks['temporary']['ok'])
        measured['filesystems'].pop()
        self.assertIsNone(RESOURCE.evaluate(measured,50,100)['resource_checks']['temporary']['ok'])

    def test_auto_detection_cli_preserves_saved_limits_and_rejects_unsafe_rounding(self):
        for count, cpu, expected_status, expected_limit in ((79,2,0,100),(89,2,0,100),(125,3,0,150),(125,2,2,150)):
            output=io.StringIO()
            with patch.object(RESOURCE,'detect_internal_phones',return_value={'detected_phone_contacts':count}), \
                 patch.object(RESOURCE,'probe_compute',return_value=(cpu,8*GIB)), \
                 patch.object(RESOURCE,'probe_storage',return_value=RESOURCE.storage_requirements()), \
                 patch.object(RESOURCE,'probe_filesystems',return_value=hardware()['filesystems']),contextlib.redirect_stdout(output):
                status=RESOURCE.main(['--check','--auto-phone-limit'])
            report=json.loads(output.getvalue())
            self.assertEqual((status,report['requested_phone_limit']),(expected_status,expected_limit))
            self.assertEqual(report['auto_phone_selection']['rounded_phone_capacity'],expected_limit)
        with patch.object(RESOURCE,'configured_limits',return_value={'desktop_client_limit':25,'phone_device_limit':79}), \
             patch.object(RESOURCE,'detect_internal_phones',side_effect=AssertionError('Upgrade must not re-detect')), \
             patch.object(RESOURCE,'probe_compute',return_value=(4,8*GIB)), \
             patch.object(RESOURCE,'probe_storage',return_value=RESOURCE.storage_requirements()), \
             patch.object(RESOURCE,'probe_filesystems',return_value=hardware()['filesystems']),contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(RESOURCE.main(['--check','--config','/fixture/config','--auto-phone-limit']),0)
        self.assertEqual(json.loads(output.getvalue())['requested_phone_limit'],79)

    def test_phone_inventory_failure_is_actionable_and_redacted(self):
        for error in (OSError('private endpoint secret'),subprocess.TimeoutExpired('private route',3)):
            with patch.object(RESOURCE.subprocess,'run',side_effect=error):
                with self.assertRaises(RESOURCE.ResourceProbeError) as raised:
                    RESOURCE.detect_internal_phones()
                self.assertEqual(raised.exception.code,'phone_inventory_unavailable')
                self.assertNotIn('private',str(raised.exception))

    def test_invalid_phone_capacity_is_never_clamped(self):
        for phones in (True, '25', 0, 1001):
            with self.assertRaises(ValueError):
                RESOURCE.evaluate(hardware(), 50, phones)

    def test_cgroup_cpu_and_memory_limits_override_large_host(self):
        values = {'/cg/leaf/memory.max': str(12 * GIB), '/cg/leaf/cpu.max': '800000 100000',
                  '/cg/memory.max': str(8 * GIB), '/cg/cpu.max': '350000 100000'}
        with patch.object(RESOURCE.os, 'sched_getaffinity', return_value=set(range(32))), \
             patch.object(RESOURCE.Path, 'read_text', return_value='MemTotal:       134217728 kB\n'), \
             patch.object(RESOURCE, 'cgroup_directories', return_value=[(Path('/cg/leaf'), 'v2'), (Path('/cg'), 'v2')]), \
             patch.object(RESOURCE, 'read_limit', side_effect=lambda path: values.get(str(path))):
            self.assertEqual(RESOURCE.probe_compute(), (3.5, 8 * GIB))

    def test_affinity_remains_binding_when_cgroup_is_unlimited(self):
        with patch.object(RESOURCE.os, 'sched_getaffinity', return_value={0, 1}), \
             patch.object(RESOURCE.Path, 'read_text', return_value='MemTotal:       8388608 kB\n'), \
             patch.object(RESOURCE, 'cgroup_directories', return_value=[(Path('/cg'), 'v2')]), \
             patch.object(RESOURCE, 'read_limit', side_effect=lambda path: 'max' if path.name == 'memory.max' else 'max 100000'):
            self.assertEqual(RESOURCE.probe_compute(), (2.0, 8 * GIB))

    def test_cgroup_ancestor_limits_are_included(self):
        with patch.object(RESOURCE.Path, 'read_text', return_value='0::/service/worker\n'), \
             patch.object(RESOURCE.Path, 'is_dir', return_value=True):
            paths = [str(path) for path, kind in RESOURCE.cgroup_directories()]
        self.assertEqual(paths, ['/sys/fs/cgroup/service/worker', '/sys/fs/cgroup/service', '/sys/fs/cgroup'])

    def test_same_filesystem_is_not_double_counted(self):
        usage = SimpleNamespace(f_blocks=80, f_bavail=13, f_frsize=GIB)
        with patch.object(RESOURCE, 'filesystem_types', return_value=[('/', 'ext4')]), \
             patch.object(RESOURCE.Path, 'exists', return_value=True), \
             patch.object(RESOURCE.Path, 'resolve', lambda path: path), \
             patch.object(RESOURCE.Path, 'stat', return_value=SimpleNamespace(st_dev=1)), \
             patch.object(RESOURCE.os, 'statvfs', return_value=usage):
            filesystems = RESOURCE.probe_filesystems()
        self.assertEqual(len(filesystems), 1)
        self.assertEqual(filesystems[0]['total_bytes'], 80 * GIB)
        self.assertEqual(filesystems[0]['required_free_bytes'], sum(RESOURCE.FREE_BUDGETS.values()))

    def test_tmpfs_is_not_counted_as_persistent_storage(self):
        with patch.object(RESOURCE, 'filesystem_types', return_value=[('/tmp', 'tmpfs'), ('/', 'ext4')]), \
             patch.object(RESOURCE.Path, 'exists', return_value=True), \
             patch.object(RESOURCE.Path, 'resolve', lambda path: path), \
             patch.object(RESOURCE.Path, 'stat', lambda path: SimpleNamespace(st_dev=2 if str(path) == '/tmp' else 1)), \
             patch.object(RESOURCE.os, 'statvfs', return_value=SimpleNamespace(f_blocks=80, f_bavail=30, f_frsize=GIB)):
            filesystems = RESOURCE.probe_filesystems()
        self.assertEqual(sum(item['total_bytes'] for item in filesystems if item['persistent']), 80 * GIB)
        self.assertEqual([item['required_free_bytes'] for item in filesystems], [sum(value for path, value in RESOURCE.FREE_BUDGETS.items() if path != '/tmp'), RESOURCE.FREE_BUDGETS['/tmp']])

    def test_protected_config_reads_only_valid_capacity(self):
        with tempfile.TemporaryDirectory(prefix='sls-capacity-config-') as name:
            path = Path(name) / 'config'
            for value in ({'secret': 'must-not-print'}, {'desktop_client_limit': 1000, 'phone_device_limit': 75}):
                path.write_text(json.dumps(value))
                self.assertEqual(RESOURCE.configured_limits(path), {
                    'desktop_client_limit': value.get('desktop_client_limit', 25),
                    'phone_device_limit': value.get('phone_device_limit', 25)})
            for key in ('desktop_client_limit', 'phone_device_limit'):
                for value in (True, '100', 0, 1001):
                    path.write_text(json.dumps({key: value}))
                    with self.assertRaises(ValueError):
                        RESOURCE.configured_limits(path)
            path.unlink()
            os.mkfifo(path)
            with self.assertRaises(ValueError):
                RESOURCE.configured_limits(path)
            path.unlink()
            sentinel = Path(name) / 'sentinel'
            sentinel.write_text('{}')
            path.symlink_to(sentinel)
            with self.assertRaises(OSError):
                RESOURCE.configured_limits(path)

    def test_probe_failure_does_not_expose_config_exception(self):
        output = io.StringIO()
        with patch.object(RESOURCE, 'configured_limits', side_effect=ValueError('secret-token')), contextlib.redirect_stdout(output):
            status = RESOURCE.main(['--check', '--config', '/fixture/config'])
        self.assertEqual(status, 3)
        self.assertNotIn('secret-token', output.getvalue())
        self.assertEqual(json.loads(output.getvalue())['eligible_limit'], 0)

    def test_damaged_encryption_never_uses_default_capacity(self):
        envelopes = [
            {'format': 'unsupported', 'ciphertext': 'private-provider-secret'},
            {'format': None}, {'ciphertext': None}, {'key_id': 'missing'}, {'nonce': None},
        ]
        with tempfile.TemporaryDirectory(prefix='sls-capacity-envelope-') as name:
            path = Path(name) / 'config'
            for value in envelopes:
                with self.subTest(value=value):
                    path.write_text(json.dumps(value))
                    with self.assertRaisesRegex(ValueError, 'unsupported or damaged'):
                        RESOURCE.configured_limits(path)
                    output = io.StringIO()
                    with patch.object(RESOURCE, 'probe_compute', side_effect=AssertionError('Invalid configuration must stop admission')), \
                         contextlib.redirect_stdout(output):
                        self.assertEqual(RESOURCE.main(['--check', '--config', str(path)]), 3)
                    report = json.loads(output.getvalue())
                    self.assertEqual(report['eligible_limit'], 0)
                    self.assertNotIn('private-provider-secret', output.getvalue())

    @unittest.skipUnless(os.geteuid() == 0, 'Protected encryption helper fixtures require root')
    def test_authenticated_encrypted_capacity_and_tamper(self):
        with tempfile.TemporaryDirectory(prefix='sls-capacity-encrypted-') as name:
            folder = Path(name)
            keyring = folder / 'keys/config-keys.json'
            helper = folder / 'sls_config_crypto.py'
            helper.write_text((SOURCE.parent / helper.name).read_text().replace(
                'KEYRING_PATH = Path("/etc/sls-mass-notify/config-keys.json")',
                'KEYRING_PATH = Path(' + repr(str(keyring)) + ')'))
            helper.chmod(0o600)
            spec = importlib.util.spec_from_file_location('capacity_crypto_fixture', helper)
            crypto = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(crypto)
            crypto.initialize_keyring(keyring, os.getgid())
            path = folder / 'mass-notifications.config'
            path.write_bytes(crypto.encode_config({'desktop_client_limit': 100, 'phone_device_limit': 150}))
            path.chmod(0o640)
            with patch.object(RESOURCE, '__file__', str(folder / SOURCE.name)):
                self.assertEqual(RESOURCE.configured_limits(path), {'desktop_client_limit': 100, 'phone_device_limit': 150})
                damaged = json.loads(path.read_bytes())
                damaged['nonce'] = 'AAAAAAAAAAAAAAAA'
                path.write_text(json.dumps(damaged))
                with self.assertRaises(ValueError):
                    RESOURCE.configured_limits(path)

    def test_cli_override_preserves_the_other_saved_capacity(self):
        for arguments, expected in ((['--desktop-limit', '100'], (100, 75)),
                                    (['--phone-limit', '90'], (250, 90)),
                                    ([], (250, 75))):
            output = io.StringIO()
            with patch.object(RESOURCE, 'configured_limits', return_value={'desktop_client_limit': 250, 'phone_device_limit': 75}), \
                 patch.object(RESOURCE, 'probe_compute', return_value=(36, 48 * GIB)), \
                 patch.object(RESOURCE, 'probe_filesystems', return_value=hardware()['filesystems']), contextlib.redirect_stdout(output):
                status = RESOURCE.main(['--check', '--config', '/fixture/config', *arguments])
            report = json.loads(output.getvalue())
            self.assertEqual(status, 0)
            self.assertEqual((report['requested_limit'], report['requested_phone_limit']), expected)

    def test_standalone_bootstrap_matches_packaged_helper(self):
        installer = (ROOT / 'tools/install_release.sh').read_text()
        embedded = installer.split("<<'SLS_RESOURCE_CAPACITY_PY'\n", 1)[1].split('\nSLS_RESOURCE_CAPACITY_PY\n', 1)[0]
        self.assertEqual(embedded, SOURCE.read_text().rstrip())
        main = installer.split('main() {\n', 1)[1]
        self.assertLess(main.index('preflight_hardware_requirements'), main.index('require_freepbx'))
        self.assertLess(main.index('verify_staged_hardware_requirements'), main.index('activate_staged_module'))

    def test_installer_cannot_bypass_failed_preflight(self):
        with tempfile.TemporaryDirectory(prefix='sls-capacity-main-') as name:
            script = r'''
source "$1"
LOG_FILE="$2/log"
ensure_installer_log_prerequisites() { :; }
preflight_hardware_requirements() { return 2; }
record_install_failure() { :; }
require_freepbx() { printf 'unexpected\n' >"$2/forbidden"; }
main
'''
            result = subprocess.run(['bash', '-c', script, '_', str(ROOT / 'tools/install_release.sh'), name],
                                    capture_output=True, text=True, timeout=10)
            self.assertEqual(result.returncode, 2, result.stderr)
            self.assertFalse((Path(name) / 'forbidden').exists())

    def test_installer_checks_saved_capacity_before_dependencies(self):
        with tempfile.TemporaryDirectory(prefix='sls-capacity-saved-') as name:
            fixture = Path(name)
            (fixture / 'config').write_text('{"desktop_client_limit":1000}')
            script = r'''
source "$1"
CONFIG_FILE="$2/config"
LOG_FILE="$2/log"
bootstrap_resource_capacity() {
  [[ "$*" == "--check --config $CONFIG_FILE" ]] || return 99
  printf '{"schema":1,"requested_limit":1000,"errors":[{"code":"cpu_insufficient"}]}\n'
  return 2
}
preflight_hardware_requirements
'''
            result = subprocess.run(['bash', '-c', script, '_', str(ROOT / 'tools/install_release.sh'), name],
                                    capture_output=True, text=True, timeout=10)
            self.assertEqual(result.returncode, 2, result.stderr)
            self.assertIn('"requested_limit":1000', result.stdout)

    def test_installer_carries_only_verified_fresh_rounding(self):
        with tempfile.TemporaryDirectory(prefix='sls-capacity-fresh-') as name:
            script=r'''
source "$1"
CONFIG_FILE="$2/absent.config"
LOG_FILE="$2/log"
bootstrap_resource_capacity() {
  [[ "$*" == "--check --desktop-limit 25 --auto-phone-limit" ]] || return 99
  printf '%s\n' "$FIXTURE_REPORT"
}
preflight_hardware_requirements || exit $?
[[ "$FRESH_PHONE_CAPACITY" == "$FIXTURE_LIMIT" ]]
'''
            for count,limit in ((4,25),(79,100),(89,100),(125,150)):
                report={'requested_phone_limit':limit,'auto_phone_selection':{'detected_phone_contacts':count,
                        'rounded_phone_capacity':limit},'errors':[]}
                result=subprocess.run(['bash','-c',script,'fixture',str(ROOT/'tools/install_release.sh'),name],
                    env={**os.environ,'FIXTURE_REPORT':json.dumps(report),'FIXTURE_LIMIT':str(limit)},
                    capture_output=True,text=True,timeout=10)
                self.assertEqual(result.returncode,0,result.stdout+result.stderr)
            report['requested_phone_limit']=100
            result=subprocess.run(['bash','-c',script,'fixture',str(ROOT/'tools/install_release.sh'),name],
                env={**os.environ,'FIXTURE_REPORT':json.dumps(report),'FIXTURE_LIMIT':'100'},
                capture_output=True,text=True,timeout=10)
            self.assertNotEqual(result.returncode,0)
            self.assertFalse((Path(name)/'absent.config').exists())

    def test_privileged_probe_ignores_writable_working_directory_modules(self):
        with tempfile.TemporaryDirectory(prefix='sls-capacity-imports-') as name:
            fixture = Path(name)
            marker = fixture / 'unexpected-import'
            (fixture / 'json.py').write_text('from pathlib import Path\nPath(' + repr(str(marker)) + ').touch()\nraise RuntimeError("unsafe import")\n')
            result = subprocess.run(['bash', '-c', 'source "$1"; bootstrap_resource_capacity --check', '_',
                                     str(ROOT / 'tools/install_release.sh')], cwd=fixture,
                                    env={**os.environ, 'PYTHONPATH': str(fixture)}, capture_output=True, text=True, timeout=10)
            self.assertFalse(marker.exists())
            self.assertEqual(json.loads(result.stdout)['schema'], 1)


if __name__ == '__main__':
    unittest.main()
