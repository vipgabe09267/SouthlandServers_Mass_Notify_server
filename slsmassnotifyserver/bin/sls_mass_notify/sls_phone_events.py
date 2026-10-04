#!/usr/bin/python3
"""Single local AMI collector; reconnection is an explicit outcome-evidence gap."""
import fcntl
import json
import os
from pathlib import Path
import sys
import time

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent))
from sls_phone_admission import DATA, PhoneAdmissionError, PhoneAdmissionStore, PhoneAmi, read_settings, collector_health
from sls_emergency_observer import EmergencyObserver
from sls_nws_delivery_claims import _open_directory, _validate_parent_directory, _secure_file_metadata


def collect(store, settings, *, ami_factory=PhoneAmi, should_stop=lambda: False):
    observer = EmergencyObserver()
    def record(event):
        store.record_event(event)
        try:
            observer.record(event)
        except Exception:
            # Observing must never interrupt outcome collection or call routing.
            # Rate-limit diagnostics without logging raw AMI or configuration.
            if time.monotonic() - getattr(record, 'last_error', 0) >= 60:
                print('SLS emergency observation unavailable; review trigger journal/configuration.', file=sys.stderr)
                record.last_error = time.monotonic()
    generation = os.urandom(16).hex()
    store.mark_event_gap()
    try:
        with ami_factory(settings, events=True, callback=record) as ami:
            ami.action({'Action': 'Ping'})
            store.collector_heartbeat(generation)
            heartbeat = time.monotonic()
            while not should_stop():
                if time.monotonic() - heartbeat >= 5:
                    ami.action({'Action': 'Ping'})
                    store.collector_heartbeat(generation)
                    inventory = ami.inventory(generation, store.boot_id)
                    with store.locked() as state:
                        store.reconcile(state, inventory)
                    heartbeat = time.monotonic()
                try:
                    event = ami.receive(time.monotonic() + 1)
                except TimeoutError:
                    continue
                record(event)
    finally:
        store.mark_event_gap()


def main():
    if sys.argv[1:] == ['--health']:
        result = collector_health()
        print(json.dumps(result))
        return 0 if result['ok'] else 1
    if sys.argv[1:]:
        return 64
    directory_fd = _open_directory(DATA)
    try:
        _validate_parent_directory(directory_fd)
        descriptor = os.open('phone-events.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o640, dir_fd=directory_fd)
        with os.fdopen(descriptor, 'a+') as handle:
            _secure_file_metadata(handle.fileno())
            try:
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                return 0
            collect(PhoneAdmissionStore(), read_settings())
    except Exception:
        # Never print socket/config exceptions: these may contain credentials,
        # phone numbers, or arbitrary provider text. systemd records the exit.
        print('SLS phone event collection stopped. Check the local AMI connection and private admission-storage permissions. Active delivery outcomes may be unknown.', file=sys.stderr)
        return 1
    finally:
        os.close(directory_fd)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
