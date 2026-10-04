#!/usr/bin/env python3
"""Labs GPIO contact example. Explicit --send AND enabled config are required.

Use only an approved isolated input. This does not wire to or alter a fire panel.
gpiozero is an optional example dependency, not a server runtime requirement.
"""
import argparse
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import ssl
import stat
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, new_url):
        raise ValueError('HTTPS sensor redirects are refused')


def signed_request(rule_id, secret, body, timestamp):
    if not re.fullmatch(r'trg_[a-f0-9]{24}', rule_id) or not re.fullmatch(r'[a-f0-9]{64}', secret):
        raise ValueError('Use the saved trigger ID and literal enrollment secret')
    raw = json.dumps(body, separators=(',', ':'), ensure_ascii=False).encode()
    timestamp = str(int(timestamp))
    signature = hmac.new(secret.encode(), rule_id.encode() + b'.' + timestamp.encode() + b'.' + raw, hashlib.sha256).hexdigest()
    return raw, {'Content-Type': 'application/json', 'X-SLS-Timestamp': timestamp, 'X-SLS-Signature': signature}


class DebouncedContact:
    def __init__(self, seconds=0.05):
        self.seconds = seconds
        self.candidate = self.stable = None
        self.changed = 0.0

    def sample(self, value, now):
        value = bool(value)
        if value != self.candidate:
            self.candidate, self.changed = value, now
        if now - self.changed < self.seconds or self.stable == self.candidate:
            return False
        previous, self.stable = self.stable, self.candidate
        # Establish baseline on startup; a held contact does not generate a new incident.
        return previous is not None and self.stable


class SensorClient:
    def __init__(self, config, state_path, send=False, opener=None):
        self.config, self.path = config, Path(state_path)
        parsed = urllib.parse.urlsplit(config['url'])
        if (parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password
                or parsed.path != '/api/sls-mass-notify/trigger.php'
                or urllib.parse.parse_qs(parsed.query) != {'rule_id': [config['rule_id']]} or parsed.fragment):
            raise ValueError('Use the exact verified HTTPS trigger endpoint')
        self.send_enabled = send and config.get('enabled') is True
        context = ssl.create_default_context(cafile=config.get('ca_file') or None)
        self.opener = opener or urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=context))
        self.state = {'pending': None, 'last_activation': 0, 'last_heartbeat': 0}
        if self.path.exists():
            info = self.path.lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_mode & 0o077 or info.st_size > 8192:
                raise ValueError('Preserve the unsafe sensor state for review; do not reset replay identities')
            self.state = json.loads(self.path.read_text())

    def persist(self):
        temporary = self.path.with_name(self.path.name + '.' + uuid.uuid4().hex)
        fd = os.open(temporary, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
        try:
            with os.fdopen(fd, 'w') as handle:
                json.dump(self.state, handle, separators=(',', ':')); handle.flush(); os.fsync(handle.fileno())
            os.replace(temporary, self.path)
            parent = os.open(self.path.parent, os.O_RDONLY | os.O_DIRECTORY)
            try: os.fsync(parent)
            finally: os.close(parent)
        finally:
            if temporary.exists(): temporary.unlink()

    def body(self, operation, now):
        body = {'operation': operation, 'request_id': uuid.uuid4().hex, 'sent_at': now, 'expires_at': now + 300,
                'is_test': self.config.get('is_test', True)}
        if operation == 'activate':
            body.update(event=self.config['event'], message=self.config.get('message', 'Reviewed isolated contact changed'))
        return body

    def post(self, body, now):
        raw, headers = signed_request(self.config['rule_id'], self.config['secret'], body, now)
        if not self.send_enabled:
            return {'ok': True, 'state': 'dry_run'}
        request = urllib.request.Request(self.config['url'], raw, headers, method='POST')
        with self.opener.open(request, timeout=5) as response:
            result = response.read(8193)
            if len(result) > 8192: raise ValueError('Sensor response exceeded its bound')
            value = json.loads(result)
            if not value.get('ok'): raise ValueError('Sensor request was not accepted')
            return value

    def tick(self, activated, now):
        if activated and not self.state['pending'] and now - self.state['last_activation'] >= 10:
            self.state['pending'] = self.body('activate', now)
            self.persist()
        pending = self.state['pending']
        if pending:
            if now >= pending['expires_at']:
                # Keep evidence for manual review rather than inventing a fresh retry ID.
                raise ValueError('Pending sensor activation expired; inspect server history before reviewing client state')
            self.post(pending, now)
            self.state['last_activation'], self.state['pending'] = now, None
            self.persist()
        elif now - self.state['last_heartbeat'] >= 60:
            self.post(self.body('heartbeat', now), now)
            self.state['last_heartbeat'] = now
            self.persist()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    parser.add_argument('--state', required=True)
    parser.add_argument('--gpio', type=int, default=17)
    parser.add_argument('--send', action='store_true')
    args = parser.parse_args()
    from gpiozero import DigitalInputDevice
    config = json.loads(Path(args.config).read_text())
    client = SensorClient(config, args.state, send=args.send)
    contact = DigitalInputDevice(args.gpio, pull_up=True, bounce_time=None)
    debounce = DebouncedContact()
    while True:
        try: client.tick(debounce.sample(contact.value, time.monotonic()), int(time.time()))
        except (urllib.error.URLError, TimeoutError):
            # Retain exact pending bytes/ID. Neither secret nor provider response is logged.
            print('HTTPS request is unconfirmed; retaining the existing activation identifier')
            time.sleep(5)
        time.sleep(0.01)


if __name__ == '__main__': main()
