#!/usr/bin/env python3
"""Contact-scoped overrides must not overwrite an extension's other vendors."""
import configparser
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('notify_devices', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py')
notify = importlib.util.module_from_spec(spec)
spec.loader.exec_module(notify)


class DeviceTests(unittest.TestCase):
    def test_override_one_contact_and_preserve_other_vendor(self):
        events = [
            {'Event':'ContactList', 'Endpoint':'1000', 'Status':'Reachable', 'UserAgent':'Yealink T48G', 'Uri':'sip:1000@192.0.2.1;transport=tls'},
            {'Event':'ContactList', 'Endpoint':'1000', 'Status':'Reachable', 'UserAgent':'Polycom VVX', 'Uri':'sip:1000@192.0.2.2;transport=udp'},
        ]
        class Ami:
            def action(self, *args): return {'Response':'Success'}, events
        key = notify.device_registration_key('1000', events[0]['Uri'])
        inventory = notify.get_registered_endpoint_info(Ami(), format_overrides={'device:'+key:'yealink_text'})
        self.assertEqual([row['format'] for row in inventory['1000']['contacts']], ['yealink_text', 'poly'])
        summary = notify.endpoint_format_summary(inventory)['1000']['devices']
        self.assertEqual([row['transport'] for row in summary], ['tls','udp'])
        self.assertNotIn('contact', summary[0])
        self.assertEqual(summary[0]['capability'], 'vendor_template')
        # A new address must not inherit a former contact's explicit binding.
        events[0]['Uri'] = 'sip:1000@192.0.2.3;transport=tls'
        changed = notify.get_registered_endpoint_info(Ami(), format_overrides={'device:'+key:'yealink_text'})
        self.assertEqual(changed['1000']['contacts'][0]['format'], 'yealink')

    def test_device_override_precedes_extension_override(self):
        config = configparser.ConfigParser()
        config.read_dict({'endpoint_format_overrides':{'1000':'poly'}, 'device_format_overrides':{'a'*32:'yealink_text'}})
        values = notify.endpoint_format_overrides(config)
        self.assertEqual(values, {'1000':'poly','device:'+'a'*32:'yealink_text'})

    def test_transport_labels_are_not_invented(self):
        for uri, transport in [('sip:1000@192.0.2.1','udp'), ('sips:1000@192.0.2.1','tls'),
                               ('sip:1000@192.0.2.1;transport=tcp','tcp'), ('','unresolved'),
                               ('sip:1000@192.0.2.1;transport=wss','wss')]:
            self.assertEqual(notify.contact_transport(uri), transport)


if __name__ == '__main__':
    unittest.main()
