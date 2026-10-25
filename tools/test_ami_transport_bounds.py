#!/usr/bin/python3
"""Actual private AMI reads and deterministic total-action deadlines."""
import importlib.util
import io
from pathlib import Path
import socket
import signal
import threading
import time
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('bounded_ami_sender', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py')
sender = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sender)

class AmiBoundsTests(unittest.TestCase):
    def client(self, timeout=.15):
        return sender.AmiClient('127.0.0.1', 5038, 'fixture', 'fixture', timeout=timeout)

    def test_paging_health_discovery_is_read_only_and_rejects_missing_or_ambiguous_actions(self):
        required = ("MuteAudio", "PlayDTMF", "Setvar", "Getvar")
        good = {"Response": "Success", **{name: "Fixture synopsis [Privilege: call,system]" for name in required}}
        client = self.client()
        with patch.object(client, 'action', return_value=(good, [])) as action:
            self.assertEqual(sender.ami_live_paging_actions(client), {name: "authorized" for name in required})
            action.assert_called_once_with({"Action": "ListCommands"})
        bad_responses = [None, {}, {**good, "Response": "Error"}, {**good, "MuteAudio": []},
                         {**good, "MuteAudio": ""}, {**good, "MuteAudio": "x" * 4097},
                         {**good, "muteaudio": "duplicate"},
                         {**good, **{f"Synthetic{i}": "extra" for i in range(513)}}]
        for name in required:
            missing = dict(good); del missing[name]; bad_responses.append(missing)
        for response in bad_responses:
            with self.subTest(response_type=type(response).__name__), patch.object(client, 'action', return_value=(response, [])):
                with self.assertRaises(RuntimeError): sender.ami_live_paging_actions(client)

    def test_active_event_stream_cannot_extend_action_budget(self):
        client = self.client(1)
        clock = [0.0]
        messages = [{'Response':'Success'}]
        def read(*args, **kwargs):
            clock[0] += .25
            if clock[0] > 4:
                raise AssertionError('Action ignored its total observation budget')
            return messages.pop(0) if messages else {'Event':'ContactList'}
        with patch.object(sender.time, 'monotonic', lambda: clock[0]), patch.object(client, 'send'), patch.object(client, 'read_message', side_effect=read):
            with self.assertRaises((TimeoutError, RuntimeError)):
                client.action({'Action':'PJSIPShowContacts'}, 'ContactListComplete')
        self.assertLessEqual(clock[0], 1.25)

    def test_oversized_line_and_duplicate_identity_are_rejected(self):
        for raw in (
            'UserAgent: ' + 'x' * 100000 + '\r\n\r\n',
            'Response: Success\r\nresponse: Error\r\n\r\n',
            'Event: ContactList\r\nEndpoint: 1000\r\nendpoint: 2000\r\n\r\n',
        ):
            with self.subTest(raw=raw[:60]):
                client = self.client()
                client.file = io.StringIO(raw)
                with self.assertRaises(RuntimeError):
                    client.read_message()

    def test_repeated_variable_and_empty_contacts_remain_supported(self):
        client = self.client()
        client.file = io.StringIO('Response: Success\r\nVariable: a=1\r\nVariable: b=2\r\n\r\n')
        self.assertEqual(client.read_message(), {'Response':'Success', 'Variable':['a=1','b=2']})
        client.file = io.StringIO('Response: Error\r\nMessage: No contacts found.\r\n\r\n')
        with patch.object(client, 'send'):
            reply, events = client.action({'Action':'PJSIPShowContacts'}, 'ContactListComplete')
        self.assertTrue(sender.ami_response_is_empty_contact_inventory(reply))
        self.assertEqual(events, [])

    def test_response_count_and_message_size_are_bounded(self):
        client = self.client(2)
        client.file = io.StringIO('Response: Success\r\n\r\n' + 'Event: ContactList\r\n\r\n' * 5)
        with patch.object(client, 'MAX_ACTION_MESSAGES', 3), patch.object(client, 'send'):
            with self.assertRaisesRegex(RuntimeError, 'response limits'):
                client.action({'Action':'PJSIPShowContacts'}, 'ContactListComplete')
        client.file = io.StringIO('Value: ' + 'x' * 200 + '\r\n\r\n')
        with patch.object(client, 'MAX_MESSAGE_BYTES', 100):
            with self.assertRaisesRegex(RuntimeError, 'message exceeds'):
                client.read_message()

    def test_request_fields_and_bytes_are_bounded_before_socket_write(self):
        client = self.client()
        class Sink:
            def __init__(self): self.payloads = []
            def sendall(self, payload): self.payloads.append(payload)
        sink = Sink(); client.sock = sink
        client.send({'Action': 'Ping', 'Variable': ['one=1', 'two=2']})
        self.assertEqual(len(sink.payloads), 1)
        for fields in ({'Bad Field': 'value'}, {'Action': 'Ping\r\nAction: Logoff'},
                       {'Action': 'Ping', 'Variable': 'x' * sender.AmiClient.MAX_LINE_BYTES}):
            with self.subTest(fields=list(fields)), self.assertRaises(ValueError):
                client.send(fields)
        self.assertEqual(len(sink.payloads), 1)

    def test_actual_socket_handshake_contacts_and_next_action(self):
        receiving, sending = socket.socketpair()
        requests = []
        def server():
            try:
                sending.sendall(b'Asterisk Call Manager/5.0\r\n')
                stream = sending.makefile('rb')
                for reply in (
                    b'Response: Success\r\n\r\n',
                    b'Response: Success\r\n\r\nEvent: ContactList\r\nEndpoint: 1000\r\nContact: sip:1000@192.0.2.10\r\n\r\nEvent: ContactListComplete\r\n\r\n',
                    b'Response: Success\r\nPing: Pong\r\n\r\n',
                    b'Response: Success\r\nMuteAudio: Mute [Privilege: system]\r\nPlayDTMF: Play [Privilege: call]\r\nSetvar: Set [Privilege: call]\r\nGetvar: Read [Privilege: call]\r\n\r\n',
                ):
                    lines = []
                    while True:
                        line = stream.readline(8192)
                        if line == b'\r\n': break
                        if not line: return
                        lines.append(line)
                    requests.append(b''.join(lines))
                    sending.sendall(reply)
                stream.close()
            finally:
                sending.close()
        worker = threading.Thread(target=server, daemon=True)
        worker.start()
        client = self.client(1)
        try:
            with patch.object(sender.socket, 'create_connection', return_value=receiving):
                client.connect()
            reply, events = client.action({'Action':'PJSIPShowContacts'}, 'ContactListComplete')
            self.assertEqual(reply['Response'], 'Success')
            self.assertEqual([event['Event'] for event in events], ['ContactList','ContactListComplete'])
            reply, events = client.action({'Action':'Ping'})
            self.assertEqual(reply['Ping'], 'Pong')
            self.assertEqual(events, [])
            self.assertEqual(sender.ami_live_paging_actions(client), {name: "authorized" for name in ("MuteAudio", "PlayDTMF", "Setvar", "Getvar")})
        finally:
            client.close()
            worker.join(1)
        self.assertFalse(worker.is_alive())
        self.assertEqual(len(requests), 4)
        self.assertIn(b'Action: ListCommands', requests[-1])

    def test_private_trickle_read_is_bounded_by_total_deadline(self):
        receiving, sending = socket.socketpair()
        stop = threading.Event()
        def trickle():
            try:
                while not stop.wait(.02):
                    sending.sendall(b'x')
            except OSError:
                pass
        worker = threading.Thread(target=trickle, daemon=True)
        worker.start()
        client = self.client()
        receiving.settimeout(.5)
        started = time.monotonic()
        previous_alarm = signal.signal(signal.SIGALRM, lambda *_: (_ for _ in ()).throw(AssertionError('Trickle reads ignored the overall deadline')))
        signal.setitimer(signal.ITIMER_REAL, .75)
        try:
            with self.assertRaises((TimeoutError, RuntimeError)):
                with patch.object(sender.socket, 'create_connection', return_value=receiving):
                    client.connect()
            self.assertLess(time.monotonic() - started, 1)
        finally:
            signal.setitimer(signal.ITIMER_REAL, 0)
            signal.signal(signal.SIGALRM, previous_alarm)
            stop.set()
            client.close()
            sending.close()
            worker.join(1)

if __name__ == '__main__':
    unittest.main()
