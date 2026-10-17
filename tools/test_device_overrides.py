#!/usr/bin/env python3
"""Contact-scoped overrides must not overwrite an extension's other vendors."""
import configparser
import contextlib
import io
import importlib.util
from pathlib import Path
import sys
import unittest
from unittest import mock

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

    def test_recipient_and_override_identifiers_are_exact(self):
        self.assertEqual(notify.normalize_target_list('1000, 2000;003'), {'1000', '2000', '003'})
        for invalid in ('1000,12x34', '9' * 21):
            with self.assertRaises(ValueError):
                notify.normalize_target_list(invalid)
        self.assertEqual(notify.normalize_desktop_target_list('Gabe, desk.one'), {'gabe', 'desk.one'})
        for invalid in ('gabe,bad name', 'also+bad', 'a' * 49):
            with self.assertRaises(ValueError):
                notify.normalize_desktop_target_list(invalid)
        config = configparser.ConfigParser()
        config.read_dict({'endpoint_format_overrides': {
            '1000': 'poly', '10x01': 'cisco', '9' * 21: 'snom',
        }})
        self.assertEqual(notify.endpoint_format_overrides(config), {'1000': 'poly'})

    def test_invalid_explicit_phone_target_cannot_become_a_broadcast(self):
        config = configparser.ConfigParser(interpolation=None)
        config.read_dict({'logging': {'log_file': '/unused'}})
        with mock.patch.object(sys, 'argv', ['sls_notify.py', '--announcement', 'Fixture',
                                             '--targets', '1000,not-an-extension']), \
                mock.patch.object(notify, 'load_config', return_value=config), \
                mock.patch.object(notify, 'setup_logging'), \
                mock.patch.object(notify, 'push_announcement') as push, \
                contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(notify.main(), 1)
        push.assert_not_called()

    def test_many_brands_on_one_extension_keep_individual_formats(self):
        agents = ['Yealink SIP-T48G', 'Polycom VVX', 'Cisco CP-8841', 'Grandstream GXP2170',
                  'Fanvil X6', 'Snom D785', 'Mitel 6867', 'Unrecognized softphone']
        formats = ['yealink', 'poly', 'cisco', 'grandstream', 'fanvil', 'snom', 'aastra', 'unknown']
        events = [{'Event':'ContactList', 'Endpoint':'1000', 'Status':'Reachable',
                   'UserAgent':agent, 'Uri':f'sip:1000@192.0.2.{index+1};transport=tls'}
                  for index, agent in enumerate(agents)]
        class Ami:
            def action(self, *args): return {'Response':'Success'}, list(reversed(events))
        inventory = notify.get_registered_endpoint_info(Ami())
        self.assertEqual({row['contact']:row['format'] for row in inventory['1000']['contacts']},
                         {row['Uri']:fmt for row, fmt in zip(events, formats)})
        devices = notify.endpoint_format_summary(inventory)['1000']['devices']
        self.assertEqual(len(devices), 8)
        self.assertEqual(sum(row['format'] == 'unknown' for row in devices), 1)
        self.assertEqual(len({row['key'] for row in devices}), 8)

    def test_cli_order_cannot_assign_another_registered_phones_uri(self):
        event = {'Event':'ContactList','Endpoint':'1000','UserAgent':'Yealink T48G'}
        uris = ['sip:1000@192.0.2.1;transport=tls', 'sip:1000@192.0.2.2;transport=tcp']
        for index in [0,1]:
            self.assertEqual(notify.contact_uri_for_event(event, '1000', {'1000':uris}, index), '')
        self.assertEqual(notify.contact_uri_for_event(event, '1000', {'1000':uris[:1]}), uris[0])

    def test_transport_labels_are_not_invented(self):
        for uri, transport in [('sip:1000@192.0.2.1','udp'), ('sips:1000@192.0.2.1','tls'),
                               ('sip:1000@192.0.2.1;transport=tcp','tcp'), ('','unresolved'),
                               ('sip:1000@192.0.2.1;transport=wss','wss'),
                               ('sip:1000@192.0.2.1;transport=bogus','unresolved')]:
            self.assertEqual(notify.contact_transport(uri), transport)

    def test_contact_uri_is_bounded_printable_ascii(self):
        self.assertEqual(notify.registered_contact_uri('PJSIP/1000/sip:1000@192.0.2.1;transport=tls'),
                         'sip:1000@192.0.2.1;transport=tls')
        for value in ('sip:1000@host\x00.invalid', 'sip:1000@höst.invalid',
                      'sip:' + 'a' * 2049 + '@example.test'):
            self.assertEqual(notify.registered_contact_uri(value), '')


if __name__ == '__main__':
    unittest.main()
