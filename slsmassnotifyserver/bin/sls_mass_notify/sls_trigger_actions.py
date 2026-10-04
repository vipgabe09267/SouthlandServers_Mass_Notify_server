#!/usr/bin/python3
"""Explicit, bounded administrator-approved trigger actions. Never a shell command builder."""
import ctypes
import hashlib
import http.client
import ipaddress
import json
import os
from pathlib import Path
import re
import resource
import selectors
import signal
import socket
import ssl
import stat
import subprocess
import sys
import time
import urllib.parse


class ActionError(ValueError):
    pass


def script_descriptor(path):
    if not isinstance(path, str) or len(path) > 512 or not re.fullmatch(r'/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.(sh|js)', path):
        raise ActionError('Use an absolute .sh or .js path with ordinary filename characters.')
    parts = Path(path).parts[1:]
    if any(part in ('.', '..') for part in parts):
        raise ActionError('Script paths cannot contain relative components.')
    directory = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    descriptor = None
    try:
        for part in parts[:-1]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=directory)
            os.close(directory)
            directory = child
            metadata = os.fstat(directory)
            if metadata.st_uid != 0 or metadata.st_mode & 0o022:
                raise ActionError('The script and every parent directory must be root-owned and not writable by group or others.')
        descriptor = os.open(parts[-1], os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW, dir_fd=directory)
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_uid != 0 or metadata.st_mode & 0o022 or not 0 < metadata.st_size <= 262144:
            raise ActionError('Approved scripts must be root-owned regular files, at most 256 KiB, without writable groups, hard links or symbolic links.')
        content = os.read(descriptor, 262145)
        if len(content) != metadata.st_size:
            raise ActionError('Script changed while being read. Review it again.')
        digest = hashlib.sha256(content).hexdigest()
        interpreter = '/bin/bash' if path.endswith('.sh') else '/usr/bin/node'
        if not os.path.isfile(interpreter) or not os.access(interpreter, os.X_OK):
            raise ActionError('The selected script interpreter is missing: ' + interpreter + '. Install it through the operating system before enabling this action.')
        # Copy the reviewed bytes into a sealed, anonymous descriptor. Replacing
        # or modifying the approved path cannot alter the running program.
        sealed = os.memfd_create('sls-approved-action', os.MFD_ALLOW_SEALING)
        try:
            if os.write(sealed, content) != len(content):
                raise ActionError('Approved script could not be copied safely.')
            import fcntl
            fcntl.fcntl(sealed, fcntl.F_ADD_SEALS, fcntl.F_SEAL_WRITE | fcntl.F_SEAL_GROW | fcntl.F_SEAL_SHRINK | fcntl.F_SEAL_SEAL)
            os.lseek(sealed, 0, os.SEEK_SET)
            return sealed, interpreter, digest
        except BaseException:
            os.close(sealed)
            raise
    except OSError as error:
        raise ActionError('Cannot read the approved script safely. Check its path, ownership, permissions and interpreter.') from error
    finally:
        if descriptor is not None:
            os.close(descriptor)
        os.close(directory)


def limits():
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    resource.setrlimit(resource.RLIMIT_CPU, (10, 10))
    resource.setrlimit(resource.RLIMIT_FSIZE, (16777216, 16777216))
    resource.setrlimit(resource.RLIMIT_NOFILE, (64, 64))
    # Prevent setuid/file-capability privilege acquisition. Scripts still have
    # the runtime account's ordinary permissions; this is not a sandbox.
    if ctypes.CDLL(None, use_errno=True).prctl(38, 1, 0, 0, 0) != 0:
        raise OSError('Could not set no_new_privs')


def run_script(action, event, *, seconds=10):
    if os.geteuid() == 0:
        raise ActionError('Script actions must run as the PBX runtime account, never root.')
    descriptor, interpreter, digest = script_descriptor(action['path'])
    process = None
    try:
        if not isinstance(action.get('sha256'), str) or not __import__('hmac').compare_digest(action['sha256'], digest):
            raise ActionError('The script differs from its approved SHA-256. Review and approve the changed file before running it.')
        payload = (json.dumps(event, ensure_ascii=True, separators=(',', ':')) + '\n').encode()
        if len(payload) > 8192:
            raise ActionError('Script event exceeds the 8 KiB input limit.')
        command = [interpreter]
        if interpreter == '/usr/bin/node':
            command.append('--preserve-symlinks-main')
        command.append('/proc/self/fd/' + str(descriptor))
        process = subprocess.Popen(command, stdin=subprocess.PIPE,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, pass_fds=(descriptor,), cwd='/',
            env={'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', 'SLS_EVENT_SCHEMA': '1'},
            start_new_session=True, preexec_fn=limits)
        # Input is smaller than the pipe capacity; make it nonblocking to avoid
        # a script that refuses stdin holding the worker past its deadline.
        os.set_blocking(process.stdin.fileno(), False)
        os.set_blocking(process.stdout.fileno(), False)
        deadline = time.monotonic() + min(10, seconds)
        selector = selectors.DefaultSelector()
        selector.register(process.stdin, selectors.EVENT_WRITE)
        selector.register(process.stdout, selectors.EVENT_READ)
        offset = 0
        total = 0
        try:
            while True:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    return {'state': 'uncertain', 'detail': 'Script exceeded its 10-second deadline. Its process group was stopped; actions already performed cannot be undone.'}
                for key, _ in selector.select(min(remaining, .1)):
                    if key.fileobj is process.stdin:
                        try:
                            offset += os.write(key.fd, payload[offset:])
                        except BrokenPipeError:
                            offset = len(payload)
                        if offset == len(payload):
                            selector.unregister(process.stdin)
                            process.stdin.close()
                    else:
                        chunk = os.read(key.fd, 8192)
                        total += len(chunk)
                        if total > 65536:
                            return {'state': 'uncertain', 'detail': 'Script exceeded its 64 KiB output allowance. Its process group was stopped; inspect the approved script before retrying.'}
                        if not chunk:
                            selector.unregister(process.stdout)
                if process.poll() is not None:
                    return {'state': 'completed' if process.returncode == 0 else 'failed', 'exit_code': process.returncode,
                        'detail': 'Script exited successfully.' if process.returncode == 0 else 'Script exited with a nonzero result. Side effects may already have occurred; it will not run again automatically.'}
        finally:
            selector.close()
    finally:
        if process is not None:
            # Stop descendants even if their immediate parent already exited.
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            process.wait(timeout=2)
            for handle in (process.stdin, process.stdout):
                if handle and not handle.closed:
                    handle.close()
        os.close(descriptor)


def private_target(action):
    try:
        address = ipaddress.IPv4Address(action['host'])
        networks = ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16')
        if not any(address in ipaddress.IPv4Network(net) for net in networks) or int(str(address).split('.')[-1]) in (0, 255):
            raise ValueError()
        port = action['port']
        if type(port) is not int or not 1 <= port <= 65535:
            raise ValueError()
        return str(address), port
    except (ValueError, KeyError, TypeError) as error:
        raise ActionError('Device target must be a configured private unicast IPv4 address and valid port.') from error


def execute(action, event):
    if action.get('enabled') is not True:
        raise ActionError('This action is disabled.')
    if action.get('kind') == 'script':
        return run_script(action, event)
    host, port = private_target(action)
    if action.get('kind') == 'brightsign_udp':
        message = action.get('message')
        if not isinstance(message, str) or not re.fullmatch(r'[A-Za-z0-9_.: -]{1,128}', message):
            raise ActionError('BrightSign requires an exact configured presentation event, at most 128 characters.')
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as connection:
            connection.settimeout(3)
            size = connection.sendto(message.encode('ascii'), (host, port))
        if size != len(message):
            return {'state': 'uncertain', 'detail': 'UDP submission could not be confirmed.'}
        return {'state': 'submitted', 'detail': 'Presentation event sent by UDP. Player receipt and screen content are unverified.'}
    if action.get('kind') != 'patlite_nhv':
        raise ActionError('Unknown device action type.')
    led = action.get('led')
    if not isinstance(led, str) or not re.fullmatch(r'[012]{5}', led) or type(action.get('clear')) is not bool:
        raise ActionError('PATLITE requires five LED digits (0 off, 1 steady, 2 flashing) and an explicit clear flag.')
    scheme = action.get('scheme')
    if scheme not in ('http', 'https'):
        raise ActionError('PATLITE protocol must be HTTP or HTTPS.')
    query = {'clear': '1'} if action['clear'] else {'led': led}
    connection = http.client.HTTPSConnection(host, port, timeout=5, context=ssl.create_default_context()) if scheme == 'https' else http.client.HTTPConnection(host, port, timeout=5)
    try:
        connection.request('GET', '/api/control?' + urllib.parse.urlencode(query), headers={'Accept': 'text/plain', 'Connection': 'close'})
        response = connection.getresponse()
        body = response.read(8193)
        if len(body) > 8192:
            return {'state': 'uncertain', 'detail': 'Device response exceeded 8 KiB. The command may have reached the tower.'}
        if response.status != 200:
            return {'state': 'failed', 'http_status': response.status, 'detail': 'PATLITE rejected the command or returned an unsupported response. Redirects are not followed.'}
        return {'state': 'submitted', 'detail': 'PATLITE HTTP endpoint returned 200. Physical lamp state is unverified.'}
    finally:
        connection.close()


def main():
    if sys.argv[1:2] == ['--inspect'] and len(sys.argv) == 3:
        descriptor, interpreter, digest = script_descriptor(sys.argv[2])
        os.close(descriptor)
        print(json.dumps({'ok': True, 'sha256': digest, 'interpreter': interpreter}))
        return 0
    if sys.argv[1:]:
        raise ActionError('Unsupported action worker arguments.')
    raw = sys.stdin.buffer.read(16385)
    if len(raw) > 16384:
        raise ActionError('Action worker input exceeds 16 KiB.')
    value = json.loads(raw)
    if set(value) != {'action', 'event'}:
        raise ActionError('Action worker input must contain only action and event.')
    print(json.dumps(execute(value['action'], value['event'])))
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (ActionError, ValueError, KeyError, TypeError) as error:
        print(json.dumps({'state': 'failed', 'detail': str(error) if isinstance(error, ActionError) else 'Action configuration or event input is invalid.'}))
        sys.exit(2)
    except (OSError, http.client.HTTPException) as error:
        print(json.dumps({'state': 'uncertain', 'detail': 'Device or script transport failed. Check connectivity, certificate trust and the saved action. No automatic retry was attempted.'}))
        sys.exit(1)
