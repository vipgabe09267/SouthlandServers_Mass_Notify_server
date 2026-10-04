#!/usr/bin/python3
"""Submit one frozen announcement destination to the local MTA; never retry.

The caller owns durable intent, current authorization and destination checks.
The optional cluster guard reads protected policy before MTA submission; no
destination credentials or external mail provider are used by this helper.
"""
import json
import importlib.util
import os
import re
import signal
import stat
import subprocess
import sys
from datetime import datetime
from email.message import EmailMessage
from email.policy import SMTP
from email.utils import format_datetime, formataddr
from pathlib import Path

sys.dont_write_bytecode = True
_cluster_spec = importlib.util.spec_from_file_location('sls_cluster_guard', Path(__file__).resolve().with_name('sls_cluster_guard.py'))
_cluster_guard = importlib.util.module_from_spec(_cluster_spec)
_cluster_spec.loader.exec_module(_cluster_guard)

sys.path.insert(0, str(Path(__file__).resolve().parent))
from sls_branded_email import (LOGO_PATHS, build_announcement_html,
                               normalized_sender_domain, valid_recipient)

MAX_INPUT = 131072
MAX_BODY = 65536
MAX_LOGO = 262144
MAX_MIME = 1048576
SENDMAIL = '/usr/sbin/sendmail'
TIMEOUT = 5.0
FIELDS = {'recipient_id', 'address', 'sender', 'title', 'message', 'is_test',
          'severity', 'message_id', 'created_at'}


class InputError(ValueError):
    pass


def checked_text(value, maximum, *, single_line=False, nonempty=True):
    if not isinstance(value, str) or (nonempty and not value.strip()):
        raise InputError('Required text is missing or has the wrong type.')
    if any(ord(char) < 32 and char not in ('\n', '\r', '\t') for char in value):
        raise InputError('Text contains unsupported control characters.')
    if single_line and any(char in value for char in ('\n', '\r', '\t')):
        raise InputError('A header contains unsupported control characters.')
    try:
        size = len(value.encode('utf-8'))
    except UnicodeError as error:
        raise InputError('Text is not valid Unicode.') from error
    if size > maximum:
        raise InputError('Announcement text exceeds its size limit.')
    return value


def validate(payload):
    if not isinstance(payload, dict) or set(payload) != FIELDS:
        raise InputError('The announcement envelope has missing or unknown fields.')
    if not isinstance(payload['recipient_id'], str) or not re.fullmatch(r'email_[a-f0-9]{24}', payload['recipient_id']):
        raise InputError('The saved email recipient ID is invalid.')
    address = checked_text(payload['address'], 254, single_line=True)
    if address != address.strip() or not valid_recipient(address):
        raise InputError('The destination email address is invalid.')
    sender = payload['sender']
    if not isinstance(sender, dict) or set(sender) != {'name', 'address'}:
        raise InputError('The sender identity is invalid.')
    checked_text(sender['name'], 320, single_line=True)
    checked_text(sender['address'], 254, single_line=True)
    if sender['address'] != sender['address'].strip() or not valid_recipient(sender['address']):
        raise InputError('The sender email address is invalid.')
    checked_text(payload['title'], 1024, single_line=True)
    checked_text(payload['message'], MAX_BODY)
    if type(payload['is_test']) is not bool or payload['severity'] not in ('info', 'warning', 'critical'):
        raise InputError('Explicit test and severity metadata is invalid.')
    identity = payload['message_id']
    if not isinstance(identity, str) or len(identity) > 340:
        raise InputError('The stable Message-ID is invalid.')
    match = re.fullmatch(r'<sls-([a-f0-9]{32})-([a-f0-9]{24})@([a-z0-9.-]+)>', identity)
    if not match or match.group(2) != payload['recipient_id'][6:] or normalized_sender_domain(match.group(3)) != match.group(3):
        raise InputError('The stable Message-ID does not identify this recipient.')
    stamp = checked_text(payload['created_at'], 40, single_line=True)
    if not re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})', stamp):
        raise InputError('Publication time must contain an explicit timezone.')
    try:
        created = datetime.fromisoformat(stamp.replace('Z', '+00:00'))
        format_datetime(created)
    except (ValueError, OverflowError) as error:
        raise InputError('Publication time is invalid.') from error
    return created


def read_logo(paths):
    # Branding is optional; malformed/oversized assets never become attachments.
    for path in paths:
        descriptor = None
        try:
            descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
            before = os.fstat(descriptor)
            if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or not 8 <= before.st_size <= MAX_LOGO:
                continue
            body = bytearray()
            while len(body) <= MAX_LOGO:
                chunk = os.read(descriptor, min(65536, MAX_LOGO + 1 - len(body)))
                if not chunk:
                    break
                body.extend(chunk)
            after = os.fstat(descriptor)
            if ((before.st_size, before.st_mtime_ns, before.st_ctime_ns) !=
                    (after.st_size, after.st_mtime_ns, after.st_ctime_ns)):
                continue
            if len(body) == before.st_size and body.startswith(b'\x89PNG\r\n\x1a\n'):
                return bytes(body)
        except OSError:
            continue
        finally:
            if descriptor is not None:
                os.close(descriptor)
    return None


def build_message(payload, *, logo_paths=LOGO_PATHS):
    created = validate(payload)
    message = EmailMessage(policy=SMTP.clone(max_line_length=998))
    message['Subject'] = ('[TEST] ' if payload['is_test'] else '') + payload['title']
    message['From'] = formataddr((payload['sender']['name'], payload['sender']['address']))
    message['To'] = payload['address']
    message['Date'] = format_datetime(created)
    message['Message-ID'] = payload['message_id']
    message['X-SLS-Is-Test'] = 'true' if payload['is_test'] else 'false'
    message['X-SLS-Severity'] = payload['severity']
    message.set_content(payload['message'], subtype='plain', charset='utf-8')
    message.add_alternative(build_announcement_html(payload['title'], payload['message'],
                            is_test=payload['is_test'], severity=payload['severity']), subtype='html', charset='utf-8')
    logo = read_logo(logo_paths)
    if logo is not None:
        message.get_payload()[1].add_related(logo, maintype='image', subtype='png',
                    cid='<sls-mass-notify-logo>', filename='Southland-Servers-Group.png', disposition='inline')
    wire = message.as_bytes()
    if len(wire) > MAX_MIME:
        raise InputError('Rendered email exceeds the one MiB message limit.')
    return wire


def outcome(payload, state, code, detail, retryable=False):
    return {'ok': state == 'accepted', 'state': state, 'error_code': code,
            'message': detail, 'retryable': bool(retryable),
            'recipient_id': payload['recipient_id'], 'message_id': payload['message_id']}


def submit(payload, *, sendmail=SENDMAIL, timeout=TIMEOUT, logo_paths=LOGO_PATHS):
    wire = build_message(payload, logo_paths=logo_paths)
    claim = None
    try:
        created_at = int(datetime.fromisoformat(payload['created_at'].replace('Z', '+00:00')).timestamp())
        claim = _cluster_guard.begin('email', payload['recipient_id'], payload,
                                     delivery_id='email-' + __import__('hashlib').sha256(payload['message_id'].encode()).hexdigest(),
                                     created_at=created_at)
        process = subprocess.Popen([str(sendmail), '-oi', '-f', payload['sender']['address'], '--', payload['address']],
                    stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                    start_new_session=True, close_fds=True, env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C'})
    except _cluster_guard.ClusterFenced:
        return outcome(payload, 'rejected', 'cluster_authority_unavailable', 'Cluster exclusive authority is unavailable; no email process was started.', False)
    except OSError:
        return outcome(payload, 'rejected', 'sendmail_unavailable', 'The PBX mail submission program could not start. Check local mail service installation and permissions.', True)
    try:
        process.communicate(wire, timeout=timeout)
    except (subprocess.TimeoutExpired, OSError):
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        if process.stdin is not None:
            try:
                process.stdin.close()
            except OSError:
                pass
        try:
            process.wait(timeout=2)
        except subprocess.TimeoutExpired:
            pass
        return outcome(payload, 'uncertain', 'submission_unconfirmed', 'The PBX mail service did not confirm acceptance before submission stopped. Do not automatically resend.')
    if process.returncode == 0:
        try:
            _cluster_guard.finish(claim, uncertain=False, category='local_mta_accepted')
        except _cluster_guard.ClusterFenced:
            return outcome(payload, 'uncertain', 'cluster_receipt_unconfirmed', 'PBX mail acceptance was observed but the replicated receipt is uncertain. Do not resend.')
        return outcome(payload, 'accepted', '', 'Accepted by PBX mail service. This does not confirm inbox delivery or that a person read it.')
    if process.returncode < 0:
        return outcome(payload, 'uncertain', 'submission_interrupted', 'The PBX mail submission process was interrupted before acceptance could be confirmed. Do not automatically resend.')
    # One envelope per process: a nonzero local sendmail exit rejects submission.
    return outcome(payload, 'rejected', 'sendmail_temporary_failure' if process.returncode == 75 else 'sendmail_rejected',
                   'The PBX mail service rejected this submission' + (' temporarily.' if process.returncode == 75 else '.') + ' Review the PBX mail log.', process.returncode == 75)


def strict_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise InputError('The envelope contains duplicate fields.')
        result[key] = value
    return result


def main():
    try:
        if len(sys.argv) != 1:
            raise InputError('Supply one JSON envelope on standard input; command arguments are unsupported.')
        body = sys.stdin.buffer.read(MAX_INPUT + 1)
        if len(body) > MAX_INPUT:
            raise InputError('The announcement envelope exceeds its input limit.')
        payload = json.loads(body.decode('utf-8'), object_pairs_hook=strict_object,
                             parse_constant=lambda _: (_ for _ in ()).throw(InputError('Non-finite JSON values are unsupported.')))
        result = submit(payload)
    except InputError as error:
        print(json.dumps({'ok': False, 'state': 'rejected', 'error_code': 'invalid_envelope',
                          'message': str(error) + ' No email was submitted.', 'retryable': False}))
        return 2
    except (UnicodeError, ValueError, TypeError, RecursionError):
        print(json.dumps({'ok': False, 'state': 'rejected', 'error_code': 'invalid_envelope',
                          'message': 'The announcement email envelope is invalid or exceeds its limits. No email was submitted.', 'retryable': False}))
        return 2
    except Exception:
        # Never print input, addresses, message content, config or subprocess errors.
        print(json.dumps({'ok': False, 'state': 'uncertain', 'error_code': 'sender_internal_error',
                          'message': 'The email sender stopped without a confirmed result. Do not automatically resend.', 'retryable': False}))
        return 1
    print(json.dumps(result, separators=(',', ':')))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
