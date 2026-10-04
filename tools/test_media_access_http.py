#!/usr/bin/python3
"""Real isolated Apache/mod_php requests; fixture data, no production network."""
import ast
import base64
import http.client
import json
import os
from pathlib import Path
import pwd
import shutil
import socket
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
API = ROOT / 'slsmassnotifyserver/api/sls-mass-notify'


class MediaHTTP(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        parent = os.environ.get('SLS_TEST_PARENT_NET_NS') or os.environ.get('SLS_BUILD_PARENT_NET')
        if not parent or parent == os.readlink('/proc/self/ns/net'):
            raise RuntimeError('Run this test in the isolated test/build wrapper; no network changes made.')
        if os.geteuid() != 0 or not Path('/usr/lib/apache2/modules/libphp8.2.so').exists():
            raise RuntimeError('Media HTTP qualification requires root, isolated test wrapper, Apache and mod_php 8.2.')
        subprocess.run(['/usr/sbin/ip', 'link', 'set', 'lo', 'up'], check=True, capture_output=True)
        cls.temp = tempfile.TemporaryDirectory(prefix='sls-media-http-')
        cls.root = Path(cls.temp.name); cls.root.chmod(0o755)
        cls.web = cls.root / 'web'; cls.web.mkdir(mode=0o755)
        cls.media = cls.web / 'sls_mass_notify'; cls.media.mkdir(mode=0o755)
        cls.config = cls.root / 'fixture.config'
        cls.uid = pwd.getpwnam('asterisk').pw_uid; cls.gid = pwd.getpwnam('asterisk').pw_gid
        cls.png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGxkAAAAASUVORK5CYII=')
        cls.image = cls.media / 'announcement_fixture.png'; cls.image.write_bytes(cls.png)
        cls.xml = cls.media / 'phone_payload_fixture.xml'; cls.xml.write_text('<?xml version="1.0"?><YealinkIPPhoneTextScreen><Text>Fixture</Text></YealinkIPPhoneTextScreen>')
        for file in (cls.image, cls.xml): os.chown(file, cls.uid, cls.gid); file.chmod(0o644)
        (cls.media / 'assets').mkdir(mode=0o755)
        (cls.media / 'assets/logo.png').write_bytes(cls.png); (cls.media / 'assets/logo.png').chmod(0o644)
        target = cls.web / 'api/sls-mass-notify'; target.mkdir(parents=True, mode=0o755)
        target.parent.chmod(0o755)
        for name in ('index.php', 'security.php', 'config-crypto.php', 'media-policy.php', 'media.php', '.htaccess'):
            text = (API / name).read_text()
            if name == 'media.php':
                text = text.replace('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config', str(cls.config))
                text = text.replace('/var/www/html/sls_mass_notify', str(cls.media))
            (target / name).write_text(text); (target / name).chmod(0o644)
        tree = ast.parse((ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_privileged_install.py').read_text())
        cls.apache_block = next(ast.literal_eval(n.value) for n in tree.body if isinstance(n, ast.Assign) and any(isinstance(t, ast.Name) and t.id == 'APACHE' for t in n.targets))
        cls.apache_block = cls.apache_block.replace('/var/www/html', str(cls.web))
        for directory in cls.web.rglob('*'):
            if directory.is_dir(): directory.chmod(0o755)
        cls.web.chmod(0o755)

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def setUp(self):
        self.policy({})
        stamp = int(time.time()); os.utime(self.image, (stamp, stamp))
        self.child = None

    def tearDown(self):
        if self.child is not None:
            self.child.terminate()
            try: self.child.wait(timeout=5)
            except subprocess.TimeoutExpired: self.child.kill(); self.child.wait(timeout=5)

    def policy(self, value, proxies=None):
        self.config.write_text(json.dumps({'media_access': value, 'api_network': {'trusted_proxy_cidrs': proxies or []}}))
        os.chown(self.config, self.uid, self.gid); self.config.chmod(0o640)

    def start(self, alias=False):
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); self.port = sock.getsockname()[1]
        modules = ['mpm_prefork', 'authz_core', 'authz_host', 'unixd', 'dir', 'mime', 'setenvif', 'rewrite', 'alias']
        # unixd is compiled in on Debian; load only actual module DSOs.
        lines = [f'LoadModule {m}_module /usr/lib/apache2/modules/mod_{m}.so' for m in modules if Path('/usr/lib/apache2/modules/mod_' + m + '.so').exists()]
        lines += ['LoadModule php_module /usr/lib/apache2/modules/libphp8.2.so', f'ServerRoot "{self.root}"',
            f'PidFile "{self.root}/apache.pid"', f'DefaultRuntimeDir "{self.root}"', f'Listen 127.0.0.1:{self.port}',
            'ServerName fixture.invalid', 'User asterisk', 'Group asterisk', f'DocumentRoot "{self.web}"',
            f'ErrorLog "{self.root}/error.log"', 'LogLevel warn', 'TypesConfig /etc/mime.types',
            '<FilesMatch "\\.php$">', 'SetHandler application/x-httpd-php', '</FilesMatch>',
            f'<Directory "{self.web}">', 'Options FollowSymLinks', 'Require all granted', 'AllowOverride None', '</Directory>',
            self.apache_block]
        if alias: lines.append(f'Alias /api/sls-mass-notify "{self.web}/api/sls-mass-notify/index.php"')
        conf = self.root / 'apache.conf'; conf.write_text('\n'.join(lines) + '\n')
        self.child = subprocess.Popen(['/usr/sbin/apache2', '-f', str(conf), '-DFOREGROUND'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                      start_new_session=True)
        for _ in range(50):
            if self.child.poll() is not None: self.fail((self.root / 'error.log').read_text()[-3000:])
            try:
                with socket.create_connection(('127.0.0.1', self.port), timeout=.1): return
            except OSError: time.sleep(.02)
        self.fail('Private Apache did not become ready')

    def request(self, path='/sls_mass_notify/announcement_fixture.png', method='GET', headers=None):
        conn = http.client.HTTPConnection('127.0.0.1', self.port, timeout=3)
        try:
            conn.request(method, path, headers=headers or {}); response = conn.getresponse()
            return response.status, dict((k.lower(), v) for k, v in response.getheaders()), response.read()
        finally: conn.close()

    def test_real_routes(self):
        self.exercise(False)

    def test_legacy_prefix_alias(self):
        self.exercise(True)

    def exercise(self, alias):
        with self.subTest(alias=alias):
                self.start(alias)
                for path in ('/sls_mass_notify/announcement_fixture.png', '/sls_mass_notify/announcement_fixture.png?file=missing.png',
                             '/api/sls-mass-notify/media.php?file=announcement_fixture.png'):
                    status, headers, body = self.request(path)
                    self.assertEqual(status, 200, (body, (self.root / 'error.log').read_text()[-2000:]))
                    self.assertEqual(body, self.png); self.assertEqual(headers['x-sls-media-policy'], '1')
                    self.assertEqual(headers['cache-control'], 'no-store, private')
                    self.assertEqual(headers['content-type'], 'image/png')
                status, headers, body = self.request(method='HEAD')
                self.assertEqual((status, body, int(headers['content-length'])), (200, b'', len(self.png)))
                self.assertEqual(self.request('/sls_mass_notify/phone_payload_fixture.xml')[2], self.xml.read_bytes())
                self.assertEqual(self.request('/sls_mass_notify/assets/logo.png')[2], self.png)
                (self.media / '.htaccess').write_text('RewriteEngine Off\nRequire all granted\n')
                (self.media / '.htaccess').chmod(0o644)
                self.policy({'network_restricted': True, 'allowed_cidrs': ['192.0.2.0/24'], 'max_age_minutes': 0})
                for path in ('/sls_mass_notify/announcement_fixture.png', '/api/sls-mass-notify/media.php?file=announcement_fixture.png'):
                    self.assertEqual(self.request(path, headers={'X-Forwarded-For': '192.0.2.1', 'X-Forwarded-Proto': 'https'})[0], 403)
                for path in ('/sls_mass_notify/announcement_fixture.png/extra', '/sls_mass_notify/.htaccess',
                             '/sls_mass_notify/assets/../announcement_fixture.png', '/api/sls-mass-notify/media.php?file=../fixture.config'):
                    status, headers, body = self.request(path)
                    self.assertIn(status, (403,404)); self.assertNotIn(self.png, body)
                self.policy({'network_restricted': True, 'allowed_cidrs': ['192.0.2.0/24'], 'max_age_minutes': 0}, ['127.0.0.1'])
                self.assertEqual(self.request(headers={'X-Forwarded-For': '192.0.2.1', 'X-Forwarded-Proto': 'https'})[0], 200)
                self.assertEqual(self.request()[0], 403)
                self.policy({'max_age_minutes': 10}); stamp = int(time.time()) - 601; os.utime(self.image, (stamp, stamp))
                self.assertEqual(self.request()[0], 410)
                self.assertEqual(self.request(method='POST')[0], 405)
                self.config.write_text('{invalid')
                status, headers, body = self.request()
                self.assertEqual(status, 503); self.assertEqual(headers['retry-after'], '5')
                self.assertNotIn(str(self.config).encode(), body)
                self.child.terminate(); self.child.wait(timeout=5); self.child = None
                self.policy({}); stamp = int(time.time()); os.utime(self.image, (stamp, stamp))


if __name__ == '__main__': unittest.main()
