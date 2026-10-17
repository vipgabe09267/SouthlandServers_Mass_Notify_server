#!/usr/bin/python3
"""Keep a small shared PHP pool responsive while desktops request live delivery."""
import base64
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import signal
import socket
import struct
import subprocess
import tempfile
import time
import unittest

from test_served_php_fpm_cache import record, length, request

ROOT = Path(__file__).resolve().parents[1]


def stream_request(address, script):
    values = {'SCRIPT_FILENAME': str(script), 'REQUEST_METHOD': 'GET',
              'REQUEST_URI': '/api/sipnotify/desktop/stream?stream_seconds=8',
              'QUERY_STRING': 'stream_seconds=8', 'REMOTE_ADDR': '192.0.2.1',
              'HTTPS': 'on', 'HTTP_HOST': 'pbx.example.test',
              'HTTP_AUTHORIZATION': 'Basic '+base64.b64encode(b'alice:test-password').decode(),
              'SERVER_NAME': 'pbx.example.test', 'SERVER_PROTOCOL': 'HTTP/1.1',
              'GATEWAY_INTERFACE': 'CGI/1.1'}
    parameters = b''
    for key, value in values.items():
        key, value = key.encode(), value.encode()
        parameters += length(len(key))+length(len(value))+key+value
    client = socket.socket(socket.AF_UNIX); client.settimeout(3)
    try:
        client.connect(str(address))
        client.sendall(record(1, struct.pack('!HB5x', 1, 0))+record(4, parameters)+record(4)+record(5))
        output = b''
        def read(size):
            body = b''
            while len(body)<size:
                chunk = client.recv(size-len(body))
                if not chunk: raise RuntimeError('Private stream ended early')
                body += chunk
            return body
        while len(output)<32768:
            version, kind, identity, size, padding, unused = struct.unpack('!BBHHBB', read(8))
            if version!=1 or identity!=1: raise RuntimeError('Unexpected FastCGI frame')
            body=read(size);read(padding)
            if kind==6: output += body
            if b'event: authenticated' in output or kind==3:
                if kind==3:client.close()
                return client, output
        raise RuntimeError('Private stream exceeded its response bound')
    except BaseException:
        client.close(); raise


class DesktopFpmCapacity(unittest.TestCase):
    def test_two_stream_requests_do_not_exhaust_a_shared_two_worker_pool(self):
        self.assertEqual(os.environ.get('SLS_TEST_NAMESPACE'), 'entered')
        self.assertTrue(Path('/usr/sbin/php-fpm8.2').is_file())
        with tempfile.TemporaryDirectory(prefix='sls-fpm-capacity-') as temporary:
            root=Path(temporary);root.chmod(0o755);account=pwd.getpwnam('asterisk')
            module=root/'module';shutil.copytree(ROOT/'slsmassnotifyserver',module)
            for directory, subdirectories, files in os.walk(module):
                Path(directory).chmod(0o755)
                for name in files:(Path(directory)/name).chmod(0o644)
            data=root/'data';data.mkdir(mode=0o750);os.chown(data,account.pw_uid,account.pw_gid)
            script=module/'api/sipnotify/index.php';source=script.read_text()
            for name, path in {'EVENTS_FILE':data/'events.jsonl','SETTINGS_FILE':data/'settings.json',
                    'DESKTOP_LAST_SEEN_FILE':data/'seen.json','DESKTOP_AUTH_RATE_FILE':data/'rate.json',
                    'DESKTOP_ACK_DIRECTORY':data/'acks'}.items():
                source,count=re.subn(r"const "+name+r" = '[^']*';",'const '+name+' = '+json.dumps(str(path))+';',source,count=1)
                self.assertEqual(count,1)
            script.write_text(source)
            prepare=root/'prepare.php';prepare.write_text('''<?php
            $key=random_bytes(32);$nonce=random_bytes(12);
            $cipher=openssl_encrypt('test-password','aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag);
            file_put_contents($argv[1],json_encode(['public_pbx_host'=>'pbx.example.test','desktop_auth_key'=>base64_encode($key),
                'desktop_clients'=>[['client_id'=>'device-a','username'=>'alice','enabled'=>'1',
                    'password_enc'=>'v1:'.base64_encode($nonce.$tag.$cipher)]]]));
            ''')
            subprocess.run(['php',str(prepare),str(data/'settings.json')],check=True,timeout=5)
            os.chown(data/'settings.json',account.pw_uid,account.pw_gid);(data/'settings.json').chmod(0o640)
            health=root/'health.php';health.write_text('<?php header("Content-Type: application/json");echo \'{"ok":true}\';');health.chmod(0o644)
            address=root/'fpm.sock';config=root/'fpm.conf'
            config.write_text(f'''[global]
pid = {root}/fpm.pid
error_log = {root}/fpm.log
daemonize = no
[isolated]
user = asterisk
group = asterisk
listen = {address}
listen.mode = 0600
pm = static
pm.max_children = 2
''')
            process=subprocess.Popen(['/usr/sbin/php-fpm8.2','--nodaemonize','--fpm-config',str(config),
                '-c','/etc/php/8.2/cli/php.ini'],env={**os.environ,'PHP_INI_SCAN_DIR':'/etc/php/8.2/cli/conf.d'},
                stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
            streams=[]
            try:
                for unused in range(60):
                    try:self.assertTrue(request(address,health)['ok']);break
                    except (OSError,RuntimeError):time.sleep(.05)
                else:self.fail('Private FPM pool did not become ready')
                replies=[]
                for unused in range(2):
                    client, reply=stream_request(address,script);streams.append(client);replies.append(reply)
                    self.assertTrue(b'event: authenticated' in reply or b'json_poll' in reply,reply[-1000:])
                began=time.monotonic()
                try:self.assertTrue(request(address,health,timeout=1)['ok'])
                except TimeoutError as error:
                    self.fail('Shared FPM pool stopped responding; stream responses: '+repr([reply[-1000:] for reply in replies]))
                self.assertLess(time.monotonic()-began,1)
                for reply in replies:self.assertIn(b'json_poll',reply)
            finally:
                for client in streams:client.close()
                if process.poll() is None:os.killpg(process.pid,signal.SIGTERM)
                try:process.wait(timeout=5)
                except subprocess.TimeoutExpired:os.killpg(process.pid,signal.SIGKILL);process.wait(timeout=3)


if __name__=='__main__':unittest.main()
