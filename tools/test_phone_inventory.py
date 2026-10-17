#!/usr/bin/python3
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock
import threading
import time
sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_phone_inventory as inventory
import sls_phone_admission as admission

class CollectorHealthTests(unittest.TestCase):
    def test_real_health_classifier_preserves_admission_and_distinguishes_display(self):
        class Store:
            boot_id='current-boot'
            def __init__(self, directory): pass
            def validate(self,state): pass
            def monotonic(self): return 100
            def require_collector(self,state): return state['collector']
            def used_contacts(self,state): return 0
        state={'collector':{'generation':'a'*32,'boot_id':'current-boot','heartbeat_tick':99},'batches':{}}
        with mock.patch.object(admission,'PhoneAdmissionStore',Store),mock.patch.object(admission,'read_private_json',return_value=state):
            self.assertTrue(admission.collector_health('/private/fixture')['ok'])
            for heartbeat,code in [({},'phone_event_collector_never_connected'),
                                   (state['collector']|{'boot_id':'old-boot'},'phone_event_collector_rebooted'),
                                   (state['collector']|{'heartbeat_tick':84},'phone_event_collector_stale'),
                                   (state['collector']|{'heartbeat_tick':101},'phone_event_collector_clock_invalid')]:
                with mock.patch.object(admission,'read_private_json',return_value=state|{'collector':heartbeat}):
                    value=admission.collector_health('/private/fixture');self.assertFalse(value['ok']);self.assertEqual(value['failure_code'],code)
            with tempfile.TemporaryDirectory(prefix='sls-separate-inventories-') as temporary:
                root=Path(temporary);inventory.publish({},root,now=1000)
                with self.assertRaisesRegex(ValueError,'stale'):inventory.read(root,now=1100)
                self.assertTrue(admission.collector_health('/private/fixture')['ok'],'A stale display cache incorrectly blocked fresh authenticated admission.')
            for error,code in [(FileNotFoundError(),'phone_event_collector_state_missing'),(PermissionError(),'phone_event_collector_state_permission'),(admission.PhoneAdmissionError('phone_config_unsafe'),'phone_config_unsafe')]:
                with mock.patch.object(admission,'read_private_json',side_effect=error):self.assertEqual(admission.collector_health('/private/fixture')['failure_code'],code)

class InventoryTests(unittest.TestCase):
    def test_producer_normalizes_saved_device_and_extension_overrides(self):
        import sls_notify
        with tempfile.TemporaryDirectory(prefix='sls-phone-inventory-') as temporary:
            root = Path(temporary)
            first = 'sip:1000@192.0.2.10:5060'; second = 'sips:1000@192.0.2.11:5061'
            key = sls_notify.device_registration_key('1000', first)
            settings = {'sipnotify': {'format_overrides': {'1000':'polycom'}, 'device_format_overrides': {key:{'format':'yealink_text'}}}}
            class Ami:
                def __init__(self, settings): pass
                def __enter__(self): return self
                def __exit__(self, *args): pass
                def action(self, fields, complete_event=None):
                    return {'Response':'Success'}, [
                        {'Event':'ContactList','Endpoint':'1000','Status':'Reachable','Contact':first,'UserAgent':'Yealink T48G'},
                        {'Event':'ContactList','Endpoint':'1000','Status':'Reachable','Contact':second,'UserAgent':'Poly VVX'},
                    ]
            with mock.patch.object(sls_notify, 'asterisk_contact_uri_inventory', return_value={}) as cli:
                inventory.refresh(root, settings_reader=lambda:settings, ami_factory=Ami)
            self.assertEqual(cli.call_args.args[0], set())
            row = inventory.read(root)['endpoints']['1000']
            self.assertEqual(row['formats'], ['yealink_text','poly'])
            self.assertEqual(row['contacts'][0]['device_key'], key)
            self.assertTrue(all(contact['override'] for contact in row['contacts']))
            settings['sipnotify']['format_overrides'] = {}; settings['sipnotify']['device_format_overrides'] = {}
            inventory.refresh(root, settings_reader=lambda:settings, ami_factory=Ami)
            self.assertEqual(inventory.read(root)['endpoints']['1000']['formats'], ['yealink','poly'])

    def test_slow_display_refresh_does_not_block_caller_and_failure_is_diagnostic(self):
        entered = threading.Event(); release = threading.Event()
        def slow(directory): entered.set(); release.wait(2)
        started = time.monotonic(); stop = inventory.start_refresh_worker('/private/fixture', refresher=slow)
        self.assertLess(time.monotonic()-started, .2)
        self.assertTrue(entered.wait(1));stop.set();release.set()
        failed = threading.Event()
        def broken(directory): failed.set(); raise ValueError('secret must not be logged')
        with mock.patch.object(inventory.sys, 'stderr') as output:
            stop = inventory.start_refresh_worker('/private/fixture', refresher=broken)
            self.assertTrue(failed.wait(1))
            for _ in range(100):
                if output.write.called: break
                time.sleep(.005)
            stop.set()
            logged=''.join(str(args.args[0]) for args in output.write.call_args_list)
            self.assertIn('snapshot will expire',logged);self.assertNotIn('secret',logged)

    def test_help_groups_mixed_models_and_escapes_agents_once_per_extension(self):
        import subprocess
        source = Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/views/detected_phones.php'
        script = """if(!function_exists('_')){function _($v){return $v;}}
$endpointDiagnostics=[['extension'=>'6767','devices'=>[
['format'=>'yealink','transport'=>'tls','user_agent'=>'Yealink T48G'],
['format'=>'grandstream','transport'=>'udp','user_agent'=>'Grandstream GXP'],
['format'=>'unknown','transport'=>'tcp','user_agent'=>'Acrobits <script>alert(1)</script>'],
['format'=>'yealink','transport'=>'tls','user_agent'=>'Yealink T48G']]]];
include $argv[1];"""
        result = subprocess.run(['php', '-r', script, str(source)], capture_output=True, text=True, check=True)
        self.assertEqual(result.stdout.count('<tr>'), 2)
        self.assertEqual(result.stdout.count('6767'), 1)
        self.assertEqual(result.stdout.count('>Yealink<'), 1)
        self.assertIn('>Grandstream<', result.stdout)
        self.assertIn('Acrobits (unknown)', result.stdout)
        self.assertNotIn('<script>', result.stdout)
        self.assertIn('&lt;script&gt;', result.stdout)

    def test_multiple_contacts_stable_empty_and_stale(self):
        with tempfile.TemporaryDirectory(prefix='sls-phone-inventory-') as temporary:
            root = Path(temporary)
            devices = {'1000': {'formats': ['yealink', 'polycom'], 'contacts': [{'format':'yealink'}, {'format':'polycom'}]}}
            inventory.publish(devices, root, now=1000)
            value = inventory.read(root, now=1030)
            self.assertTrue(value['display_only']);self.assertEqual(value['endpoints'], devices)
            with self.assertRaisesRegex(ValueError, 'stale'): inventory.read(root, now=1091)
            with self.assertRaisesRegex(ValueError, 'stale'): inventory.read(root, now=800)
            inventory.publish({}, root, now=1100)
            self.assertEqual(inventory.read(root, now=1101)['endpoints'], {})
    def test_links_and_oversized_invalid_sources_rejected(self):
        with tempfile.TemporaryDirectory(prefix='sls-phone-inventory-') as temporary:
            root = Path(temporary);outside=root/'outside';outside.write_text('{}')
            os.symlink(outside, root/inventory.NAME)
            with self.assertRaises(OSError): inventory.read(root)
            with self.assertRaises(ValueError): inventory.publish({'not-an-extension': {}}, root)
            self.assertEqual(outside.read_text(), '{}')

    def test_private_mode_and_replaced_staging_cleanup(self):
        with tempfile.TemporaryDirectory(prefix='sls-phone-inventory-') as temporary:
            root = Path(temporary); inventory.publish({}, root, now=1000)
            path = root / inventory.NAME; path.chmod(0o660)
            with self.assertRaisesRegex(ValueError, 'Unsafe'): inventory.read(root, now=1001)
            replacement = b'preserve substituted staging inode'
            def swap(source, destination, *, src_dir_fd, dst_dir_fd):
                os.unlink(source, dir_fd=src_dir_fd)
                fd = os.open(source, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=src_dir_fd)
                with os.fdopen(fd, 'wb') as handle: handle.write(replacement)
                raise OSError('injected replacement')
            with mock.patch.object(inventory.os, 'replace', side_effect=swap), self.assertRaisesRegex(OSError, 'injected'):
                inventory.publish({}, root, now=1002)
            leftovers = list(root.glob('.phone-inventory-*'))
            self.assertEqual(len(leftovers), 1)
            self.assertEqual(leftovers[0].read_bytes(), replacement)

if __name__ == '__main__': unittest.main()
