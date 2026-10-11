#!/usr/bin/python3
"""Timestamped display inventory; never used to authorize or deliver a page."""
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import secrets
import stat
import sys
import time
import threading

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent))
from sls_storage_maintenance import DATA, open_directory

NAME = 'phone-endpoint-inventory.json'
MAX_BYTES = 4 * 1024 * 1024


def refresh(directory=DATA, *, settings_reader=None, ami_factory=None):
    from sls_phone_admission import PhoneAmi, PhoneAdmissionError, read_settings
    from sls_notify import get_registered_endpoint_info, saved_phone_format_overrides, ami_response_is_empty_contact_inventory
    class InventoryAmi(PhoneAmi):
        def action(self, fields, complete_event=None):
            if fields.get('Action') != 'PJSIPShowContacts':
                return super().action(fields, complete_event)
            action_id = secrets.token_hex(16)
            self.send({**fields, 'ActionID': action_id})
            deadline = time.monotonic() + 5
            response = None; rows = []
            for _ in range(25000):
                message = self.receive(deadline)
                if message.get('ActionID') != action_id:
                    continue
                if 'Response' in message:
                    if message.get('Response', '').lower() != 'success':
                        if ami_response_is_empty_contact_inventory(message):
                            return message, []
                        raise PhoneAdmissionError('phone_ami_action_rejected')
                    response = message
                elif 'Event' in message:
                    rows.append(message)
                    if message['Event'] == complete_event and response is not None:
                        return response, rows
            raise PhoneAdmissionError('phone_ami_response_oversized')
    settings = (settings_reader or (lambda: read_settings(directory)))()
    with (ami_factory or InventoryAmi)(settings) as ami:
        endpoints = get_registered_endpoint_info(ami, format_overrides=saved_phone_format_overrides(settings), resolve_contact_uris=True)
    publish(endpoints, directory)


def start_refresh_worker(directory=DATA, *, refresher=None):
    """One separate read-only AMI session; outcome collection never waits on it."""
    stop = threading.Event()
    def work():
        last_error = float('-inf')
        while not stop.is_set():
            try:
                (refresher or refresh)(directory)
            except Exception:
                if time.monotonic() - last_error >= 60:
                    print('SLS phone display inventory refresh failed; the display snapshot will expire. Check the collector journal and protected configuration.', file=sys.stderr)
                    last_error = time.monotonic()
            stop.wait(30)
    thread = threading.Thread(target=work, name='sls-phone-display', daemon=True)
    thread.start()
    return stop


def publish(endpoints, directory=DATA, now=None):
    now = time.time() if now is None else now
    if not isinstance(endpoints, dict) or len(endpoints) > 2000:
        raise ValueError('Phone display inventory exceeds its limit')
    for extension, info in endpoints.items():
        if not str(extension).isdigit() or len(str(extension)) > 20 or not isinstance(info, dict) or len(info.get('contacts', [])) > 1000:
            raise ValueError('Invalid phone display inventory')
    value = {'schema': 1, 'observed_at': datetime.fromtimestamp(now, timezone.utc).isoformat(),
             'expires_at': now + 90, 'display_only': True, 'endpoints': endpoints}
    raw = json.dumps(value, separators=(',', ':'), ensure_ascii=True).encode()
    if len(raw) > MAX_BYTES:
        raise ValueError('Phone display inventory exceeds its byte limit')
    directory_fd = open_directory(directory)
    temporary = '.phone-inventory-' + secrets.token_hex(12)
    try:
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640, dir_fd=directory_fd)
        with os.fdopen(fd, 'wb') as handle:
            handle.write(raw)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, NAME, src_dir_fd=directory_fd, dst_dir_fd=directory_fd)
        os.fsync(directory_fd)
    finally:
        try: os.unlink(temporary, dir_fd=directory_fd)
        except FileNotFoundError: pass
        os.close(directory_fd)


def read(directory=DATA, now=None):
    now = time.time() if now is None else now
    directory_fd = open_directory(directory)
    try:
        fd = os.open(NAME, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory_fd)
        with os.fdopen(fd, 'rb') as handle:
            meta = os.fstat(handle.fileno())
            if not stat.S_ISREG(meta.st_mode) or meta.st_nlink != 1 or meta.st_size > MAX_BYTES or meta.st_mode & 0o007:
                raise ValueError('Unsafe phone display inventory')
            value = json.loads(handle.read(MAX_BYTES + 1))
        if not isinstance(value, dict) or value.get('schema') != 1 or value.get('display_only') is not True or not isinstance(value.get('endpoints'), dict) or type(value.get('expires_at')) not in (int, float):
            raise ValueError('Invalid phone display inventory')
        if not now < value['expires_at'] <= now + 100:
            raise ValueError('Phone display inventory is stale')
        return value
    finally:
        os.close(directory_fd)


if __name__ == '__main__':
    try:
        print(json.dumps(read(), separators=(',', ':')))
    except (OSError, ValueError):
        print(json.dumps({'schema': 1, 'display_only': True, 'available': False, 'endpoints': {}}))
        raise SystemExit(1)
