#!/usr/bin/python3 -I
"""Bound a Page origin/contact to its pre-admitted ticket before dialing."""
import os
import json
import subprocess
import hashlib
from pathlib import Path
import re
import select
import sys
import stat
import time

library = Path(__file__).resolve().parent
if not (library / 'sls_phone_admission.py').is_file():
    library = library / 'sls_mass_notify'
sys.path.insert(0, str(library))
from sls_phone_admission import PhoneAdmissionError, PhoneAdmissionStore, PhoneAmi, accountcode, operator_message, read_settings, check_current_outbound, checked_incident_response

ACK_PROMPT = library / 'external-acknowledgement.wav'
ACK_PROMPT_SHA256 = '6c3113fd43d1c4fbd0d1a76fcfcddb9ffe9f2549bc084613a35e5cf93d18bb95'


def check_ack_prompt():
    try:
        fd = os.open(ACK_PROMPT, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(fd, 'rb') as handle:
            info = os.fstat(handle.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_mode & 0o022 or info.st_size > 160044:
                raise ValueError('Unsafe acknowledgement prompt')
            if hashlib.sha256(handle.read(160045)).hexdigest() != ACK_PROMPT_SHA256:
                raise ValueError('Changed acknowledgement prompt')
    except (OSError, ValueError):
        raise PhoneAdmissionError('phone_ack_prompt_unavailable') from None


class PhoneAgi:
    def __init__(self, source=sys.stdin, target=sys.stdout):
        self.source = source
        self.target = target
        self.environment = {}
        for _ in range(128):
            line = self.source.readline(4097)
            if len(line) > 4096:
                raise PhoneAdmissionError('phone_agi_environment_invalid')
            if not line or line in ('\n', '\r\n'):
                break
            key, separator, value = line.partition(':')
            if separator:
                self.environment[key.strip()] = value.strip()
        else:
            raise PhoneAdmissionError('phone_agi_environment_invalid')

    @staticmethod
    def quote(value):
        value = str(value)
        if re.search(r'[\r\n\x00]', value) or len(value.encode()) > 65536:
            raise PhoneAdmissionError('phone_agi_value_invalid')
        return '"' + value.replace('\\', '\\\\').replace('"', '\\"') + '"'

    def command(self, value, timeout=2):
        self.target.write(value + '\n'); self.target.flush()
        ready, _, _ = select.select([self.source], [], [], timeout)
        if not ready:
            raise PhoneAdmissionError('phone_agi_response_timeout')
        result = self.source.readline(131073).rstrip('\r\n')
        if len(result) > 131072 or not result.startswith('200 result='):
            raise PhoneAdmissionError('phone_agi_response_invalid')
        return result

    def variable(self, expression):
        result = self.command('GET FULL VARIABLE ' + self.quote(expression))
        match = re.fullmatch(r'200 result=1 \((.*)\)', result)
        return match[1] if match else ''

    def set(self, name, value):
        if not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*(?:\([A-Za-z0-9_,]+\))?', name):
            raise PhoneAdmissionError('phone_agi_variable_invalid')
        if not self.command('SET VARIABLE ' + name + ' ' + self.quote(value)).startswith('200 result=1'):
            raise PhoneAdmissionError('phone_agi_set_failed')


def handle(mode, agi, store):
    token = agi.variable('${SLS_PHONE_TOKEN}')
    uid = agi.environment.get('agi_uniqueid', '')
    if mode == 'end':
        cause = agi.variable('${HANGUPCAUSE}')
        return store.ended(token, uid, int(cause) if cause.isdigit() else None)
    agi.set('SLS_PHONE_ALLOW', '0')
    recipient = agi.variable('${SLS_PHONE_RECIPIENT}')
    agi.set('CHANNEL(accountcode)', accountcode(token))
    channel = agi.environment.get('agi_channel', '')
    if mode == 'incident-response':
        target = store.outbound_target(token, recipient)
        binding = target.get('outbound', {}).get('incident_response')
        if binding is None:
            agi.set('SLS_PHONE_ALLOW', '1')
            return True
        settings = read_settings(store.directory)
        config = settings.get('enterprise_integrations', {})
        if (str(config.get('enabled', '0')) != '1'
                or str(config.get('responses', {}).get('enabled', '0')) != '1'):
            agi.set('SLS_PHONE_ALLOW', '1')
            return True
        checked_incident_response(binding, recipient)
        if (not store.outbound_answered(token, recipient, channel)
                or target.get('playback', {}).get('completed_at') is None):
            raise PhoneAdmissionError('phone_incident_response_unconfirmed')
        payload = {'binding_id': binding['binding_id'], 'token': binding['token'],
                   'recipient_id': recipient, 'event_id': 'pbx_' + uid, 'digit': ''}
        def submit(digit):
            payload['digit'] = digit
            helper = library / 'sls_mass_notify_incident_response.php'
            result = subprocess.run(['/usr/bin/php', str(helper)], input=json.dumps(payload),
                                    text=True, capture_output=True, timeout=8,
                                    env={'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8'}, cwd='/')
            if result.returncode != 0 or len(result.stdout) > 4096:
                raise PhoneAdmissionError('phone_incident_response_unconfirmed')
            value = json.loads(result.stdout)
            if not value.get('ok'):
                raise PhoneAdmissionError('phone_incident_response_unconfirmed')
            return value.get('state')
        if submit('') == 'disabled':
            agi.set('SLS_PHONE_ALLOW', '1')
            return True
        if target['outbound'].get('acknowledgement_required', False):
            digit = agi.variable('${SLS_ACK}') if agi.variable('${READSTATUS}') == 'OK' else ''
        else:
            check_ack_prompt()
            # Reuse the shipped receive-acknowledgement prompt: only key 1 is
            # offered, and the normal announcement audio has already completed.
            reply = agi.command('GET OPTION ' + agi.quote(str(ACK_PROMPT.with_suffix(''))) + ' "1" 10000', timeout=30)
            match = re.match(r'200 result=(-?[0-9]+)', reply)
            digit = '1' if match and match[1] == '49' else ''
        if digit == '1':
            submit('1')
        agi.set('SLS_PHONE_ALLOW', '1')
        return True
    if mode in {'playback-result', 'ack'}:
        if mode == 'playback-result':
            result = 'complete' if agi.variable('${PLAYBACKSTATUS}') == 'SUCCESS' else 'failed'
        else:
            status = agi.variable('${READSTATUS}')
            digit = agi.variable('${SLS_ACK}')
            result = ('acknowledged' if digit == '1' else 'other_key') if status == 'OK' and digit else ('timeout' if status in {'TIMEOUT', 'OK'} else 'prompt_failed')
        store.record_outbound_playback(token, recipient, channel, result)
        agi.set('SLS_PHONE_ALLOW', '1')
        return True
    if mode == 'playback':
        sound = agi.variable('${SLS_SOUND}')
        if not re.fullmatch(r'SLS_Mass_Notifications_Plugin/(?:tts|tones)/[A-Za-z0-9_-]{1,200}', sound):
            raise PhoneAdmissionError('phone_playback_identity_invalid')
        deadline = time.monotonic() + 5
        while not store.outbound_answered(token, recipient, channel):
            if time.monotonic() >= deadline:
                raise PhoneAdmissionError('phone_outbound_answer_unconfirmed')
            time.sleep(0.1)
        agi.set('SLS_SAFE_SOUND', sound)
        target = store.outbound_target(token, recipient)['outbound']
        required = target.get('acknowledgement_required', False)
        if required: check_ack_prompt()
        agi.set('SLS_ACK_REQUIRED', '1' if required else '0')
        agi.set('SLS_ACK_TIMEOUT', str(target.get('ack_timeout_seconds', 10)))
        agi.set('SLS_ACK_PROMPT', str(ACK_PROMPT.with_suffix('')))
    elif mode == 'outbound':
        from sls_outbound_routes import validate_route, channel_trunk_options, OutboundRouteError
        target = store.outbound_target(token, recipient)
        if target['outbound'].get('acknowledgement_required', False): check_ack_prompt()
        settings = read_settings(store.directory)
        check_current_outbound(settings, target['outbound'])
        try:
            with PhoneAmi(settings) as ami:
                proof = validate_route(ami, target['outbound'])
        except OutboundRouteError as error:
            raise PhoneAdmissionError(str(error)) from None
        if proof['fingerprint'] != target['route_proof']['fingerprint']:
            raise PhoneAdmissionError('outbound_route_changed')
        try:
            trunk_options = channel_trunk_options(proof)
        except OutboundRouteError as error:
            raise PhoneAdmissionError(str(error)) from None
        store.bind_origin(token, recipient, uid, channel)
        # Set only the SLS origin's local variable. Do not modify GLOBAL(), the
        # trunk's AstDB override or administrator predial hooks.
        agi.set('TRUNK_OPTIONS', trunk_options)
        for key in ('route_mode', 'trunk_id', 'number', 'caller_id'):
            agi.set('SLS_OUTBOUND_' + key.upper(), target['outbound'][key])
    elif mode == 'origin':
        dials = store.bind_origin(token, recipient, uid, channel)
        agi.set('SLS_DIAL', '&'.join(dials))
    elif mode == 'contact':
        endpoint = agi.variable('${CHANNEL(endpoint)}')
        uri = agi.variable('${CHANNEL(pjsip,target_uri)}')
        dial = 'PJSIP/' + endpoint + '/' + uri
        store.bind_contact(token, recipient, uid, channel, dial)
    else:
        raise PhoneAdmissionError('phone_agi_mode_invalid')
    agi.set('SLS_PHONE_ALLOW', '1')
    return True


def main():
    try:
        if len(sys.argv) != 2 or sys.argv[1] not in {'origin', 'contact', 'outbound', 'playback', 'playback-result', 'ack', 'incident-response', 'end'}:
            raise PhoneAdmissionError('phone_agi_mode_invalid')
        handle(sys.argv[1], PhoneAgi(), PhoneAdmissionStore())
        return 0
    except Exception as error:
        code = str(error) if isinstance(error, PhoneAdmissionError) else 'phone_runtime_unavailable'
        print('SLS phone admission: ' + operator_message(code), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
