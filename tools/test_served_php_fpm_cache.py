#!/usr/bin/python3
"""Qualify cached PHP upgrades through a private FPM pool and installer helper."""
import json
import os
from pathlib import Path
import signal
import socket
import struct
import subprocess
import tempfile
import threading
import time
import unittest

if os.environ.get('SLS_TEST_NAMESPACE') != 'entered' or any(
    os.environ.get('SLS_TEST_PARENT_' + label + '_NS') in
    (None, '', os.readlink('/proc/self/ns/' + kind))
    for label, kind in [('MOUNT', 'mnt'), ('NET', 'net'), ('PID', 'pid')]
):
    raise SystemExit('Use the private fixture runner; no PHP-FPM processes were started.')

ROOT = Path(__file__).resolve().parents[1]


def record(kind, payload=b''):
    return struct.pack('!BBHHBB', 1, kind, 1, len(payload), 0, 0) + payload


def length(value):
    return bytes([value]) if value < 128 else struct.pack('!I', value | 0x80000000)


def request(address, script, timeout=3):
    values = {'SCRIPT_FILENAME': str(script), 'REQUEST_METHOD': 'GET',
              'REQUEST_URI': '/api/sls-mass-notify/?sls_installer_runtime=1',
              'QUERY_STRING': 'sls_installer_runtime=1', 'REMOTE_ADDR': '127.0.0.1',
              'SERVER_NAME': 'localhost', 'SERVER_PROTOCOL': 'HTTP/1.1',
              'GATEWAY_INTERFACE': 'CGI/1.1'}
    parameters = b''
    for key, value in values.items():
        key = key.encode(); value = value.encode()
        parameters += length(len(key)) + length(len(value)) + key + value
    with socket.socket(socket.AF_UNIX) as client:
        client.settimeout(timeout); client.connect(str(address))
        client.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, parameters) + record(4) + record(5))
        def read(count):
            output = b''
            while len(output) < count:
                part = client.recv(count - len(output))
                if not part: raise RuntimeError('Private FPM response ended early')
                output += part
            return output
        output = b''
        while True:
            version, kind, identity, size, padding, unused = struct.unpack('!BBHHBB', read(8))
            if version != 1 or identity != 1: raise RuntimeError('Unexpected FastCGI response')
            payload = read(size); read(padding)
            if kind == 6: output += payload
            if len(output) > 16384: raise RuntimeError('Private runtime response exceeds its bound')
            if kind == 3: break
        headers, body = output.split(b'\r\n\r\n', 1)
        if b'Status: 503' in headers: raise RuntimeError(body.decode())
        return json.loads(body)


class ServedFpmCache(unittest.TestCase):
    def setUp(self):
        if os.environ.get('SLS_TEST_NAMESPACE') != 'entered':
            self.fail('Use tools/run_isolated_tests.sh; no host FPM process may be used')

    def test_scoped_graceful_reload_replaces_cached_release(self):
        self.qualify_reload()

    def test_graceful_reload_with_an_active_request_and_default_control_timeout(self):
        self.qualify_reload(busy_seconds=7)

    def qualify_reload(self, busy_seconds=None):
        if not Path('/usr/sbin/php-fpm8.2').is_file():
            self.fail('This qualification needs the PHP 8.2 FPM binary; install it in the disposable CI runner')
        with tempfile.TemporaryDirectory(prefix='sls-fpm-cache-') as temporary:
            root=Path(temporary); root.chmod(0o755)
            script=root/'index.php'
            candidate=(ROOT/'slsmassnotifyserver/api/sls-mass-notify/index.php').read_text()
            script.write_text(candidate.replace("'module_version'=>'0.1.7-beta'", "'module_version'=>'0.1.6-beta'", 1)); script.chmod(0o644)
            address=root/'fpm.sock'; configuration=root/'fpm.conf'
            configuration.write_text(f'''[global]
pid = {root}/fpm.pid
error_log = {root}/fpm.log
daemonize = no
process_control_timeout = {0 if busy_seconds else '2s'}
[isolated]
user = asterisk
group = asterisk
listen = {address}
listen.mode = 0600
pm = static
pm.max_children = 1
catch_workers_output = yes
''')
            environment={**os.environ, 'PHP_INI_SCAN_DIR':'/etc/php/8.2/cli/conf.d'}
            process=subprocess.Popen(['/usr/sbin/php-fpm8.2','--nodaemonize','--fpm-config',str(configuration),
                '-c','/etc/php/8.2/cli/php.ini','-d','opcache.enable=1','-d','opcache.validate_timestamps=0',
                '-d','opcache.file_update_protection=0'],env=environment,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            busy_thread=None; busy_results=[]
            try:
                for unused in range(60):
                    try: before=request(address,script); break
                    except (OSError,RuntimeError):
                        if process.poll() is not None: self.fail((root/'fpm.log').read_text())
                        time.sleep(.05)
                else: self.fail('Private FPM did not start')
                self.assertTrue(before['ok']); self.assertEqual(before['php_sapi'],'fpm-fcgi')
                self.assertEqual(before['module_version'],'0.1.6-beta')
                script.write_text(candidate)
                self.assertEqual(request(address,script)['module_version'],'0.1.6-beta')
                if busy_seconds:
                    marker=root/'busy.entered'; marker.write_text(''); marker.chmod(0o666)
                    busy_script=root/'busy.php'
                    busy_script.write_text('<?php file_put_contents('+json.dumps(str(marker))+',"entered"); usleep('
                        +str(busy_seconds*1000000)+'); echo json_encode(["ok"=>true]);'); busy_script.chmod(0o644)
                    def run_busy():
                        try: busy_results.append(request(address,busy_script,timeout=busy_seconds+5))
                        except Exception as error: busy_results.append(error)
                    busy_thread=threading.Thread(target=run_busy); busy_thread.start()
                    for unused in range(100):
                        if marker.read_text()=='entered': break
                        time.sleep(.02)
                    else: self.fail('Private in-flight request did not start')
                trace=root/'reload.trace'; fake=root/'systemctl'
                fake.write_text('#!/bin/bash\nset -euo pipefail\n[ "$*" = "reload php8.2-fpm" ]\nprintf "%s\\n" "$*" >>'+repr(str(trace))+'\nkill -USR2 '+str(process.pid)+'\n')
                fake.chmod(0o700)
                installer=root/'installer.sh'
                installer.write_text((ROOT/'tools/install_release.sh').read_text().replace('/usr/bin/systemctl',str(fake)))
                result=subprocess.run(['bash','-c','source "$1"; SERVED_PHP_PROFILES=$\'8.2:apache2\\n8.2:fpm\\n8.2:fpm\'; refresh_served_php_workers',
                    'fixture',str(installer)],capture_output=True,text=True,timeout=5)
                self.assertEqual(result.returncode,0,result.stdout+result.stderr)
                self.assertEqual(trace.read_text().splitlines(),['reload php8.2-fpm'])
                for unused in range(80):
                    try:
                        after=request(address,script)
                        if after['module_version']=='0.1.7-beta': break
                    except (OSError,RuntimeError): pass
                    time.sleep(.05)
                else: self.fail('Reload did not replace cached PHP source')
                self.assertTrue(after['ok'])
                if busy_thread:
                    busy_thread.join(timeout=busy_seconds+5)
                    self.assertFalse(busy_thread.is_alive())
                    self.assertEqual(len(busy_results),1)
                    # FPM with its default process-control timeout may close an
                    # active request during reload. New served code is mandatory;
                    # preservation of that connection is not an FPM guarantee.
                    result=busy_results[0]
                    self.assertTrue(result=={'ok':True} or isinstance(result,(ConnectionResetError,BrokenPipeError)),
                        'Unexpected in-flight request failure: '+repr(result))
            finally:
                if process.poll() is None: process.send_signal(signal.SIGTERM)
                try: process.wait(timeout=5)
                except subprocess.TimeoutExpired: process.kill(); process.wait(timeout=5)
                if busy_thread: busy_thread.join(timeout=5)

    def test_complete_profile_validation_precedes_all_service_actions(self):
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary); fake=root/'systemctl'; trace=root/'trace'
            fake.write_text('#!/bin/bash\nprintf "%s\\n" "$*" >>'+repr(str(trace))+'\nexit 19\n'); fake.chmod(0o700)
            installer=root/'installer.sh'; installer.write_text((ROOT/'tools/install_release.sh').read_text().replace('/usr/bin/systemctl',str(fake)))
            def run(profiles):
                return subprocess.run(['bash','-c','source "$1"; SERVED_PHP_PROFILES="$2"; refresh_served_php_workers',
                    'fixture',str(installer),profiles],capture_output=True,text=True,timeout=5)
            self.assertEqual(run('8.2:apache2').returncode,0); self.assertFalse(trace.exists())
            for invalid in ['8.2:fpm\n8.2:unknown','8.2:fpm\n8.2-fpm;anything','8.2:fpm\n']:
                self.assertNotEqual(run(invalid).returncode,0); self.assertFalse(trace.exists())
            failure=run('8.2:fpm'); self.assertNotEqual(failure.returncode,0)
            self.assertIn('could not reload',failure.stdout+failure.stderr)
            self.assertEqual(trace.read_text().splitlines(),['reload php8.2-fpm'])


if __name__ == '__main__': unittest.main()
