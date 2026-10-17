#!/usr/bin/python3
"""Actual receipt readers reject ambiguous records and substituted FIFOs."""
import json
import os
from pathlib import Path
import pwd
import signal
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
READER = ROOT / 'slsmassnotifyserver/WeatherDeliveryReceipts.php'
ENVIRONMENT = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8'}


class ReceiptReaderTests(unittest.TestCase):
    def run_reader(self, raw, method='phoneState', swap=False, account=''):
        with tempfile.TemporaryDirectory() as name:
            path = Path(name) / 'state.json'
            self.assertEqual(path.write_bytes(raw), len(raw))
            path.chmod(0o600)
            code = r'''<?php
namespace SLS\MassNotify {
function fopen($path, $mode) {
    if (!empty($GLOBALS['swap'])) {
        $GLOBALS['swap'] = false;
        if (!\rename($path, $path.'.preserved') || !\posix_mkfifo($path, 0600)) {
            throw new \LogicException('receipt fixture swap failed');
        }
    }
    return \fopen($path, $mode);
}
}
namespace {
require $argv[1];
$GLOBALS['swap'] = $argv[4] === 'swap';
$method = new \ReflectionMethod(\SLS\MassNotify\WeatherDeliveryReceipts::class, $argv[3]);
try {
    $args = $argv[3] === 'phoneState'
        ? [$argv[2], posix_geteuid(), posix_getegid()]
        : [$argv[2], posix_geteuid(), posix_getegid(), true];
    $method->invokeArgs(null, $args);
    echo json_encode(['accepted'=>true], JSON_THROW_ON_ERROR);
} catch (\InvalidArgumentException|\RuntimeException|\JsonException $error) {
    echo json_encode(
        ['accepted'=>false, 'class'=>get_class($error), 'message'=>$error->getMessage()],
        JSON_THROW_ON_ERROR
    );
}
}
'''
            harness = Path(name) / 'reader.php'
            self.assertEqual(harness.write_text(code, encoding='utf-8'), len(code))
            reader = Path(name) / READER.name
            reader_bytes = READER.read_bytes()
            self.assertEqual(reader.write_bytes(reader_bytes), len(reader_bytes))
            security = Path(name) / 'api/sls-mass-notify/security.php'
            security.parent.mkdir(parents=True, mode=0o755)
            security.parent.chmod(0o755)
            security.parent.parent.chmod(0o755)
            security_bytes = (READER.parent/'api/sls-mass-notify/security.php').read_bytes()
            self.assertEqual(security.write_bytes(security_bytes), len(security_bytes))
            for source in (reader, security):
                source.chmod(0o644)
            command = ['/usr/bin/php', str(harness), str(reader), str(path), method, 'swap' if swap else 'normal']
            if account:
                user = pwd.getpwnam(account)
                for item in (Path(name), path, harness):
                    os.chown(item, user.pw_uid, user.pw_gid)
                command = ['/usr/sbin/runuser', '-u', account, '--', *command]
            command = ['/usr/bin/prlimit', '--fsize=65536:65536', '--', *command]
            stdout_path, stderr_path = Path(name) / 'stdout', Path(name) / 'stderr'
            with stdout_path.open('wb') as stdout, stderr_path.open('wb') as stderr:
                process = subprocess.Popen(command, stdout=stdout, stderr=stderr, env=ENVIRONMENT,
                                           start_new_session=True)
                try:
                    returncode = process.wait(timeout=2)
                except subprocess.TimeoutExpired:
                    try:
                        os.killpg(process.pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass
                    process.wait(timeout=2)
                    raise
            self.assertLessEqual(stdout_path.stat().st_size, 65536)
            self.assertLessEqual(stderr_path.stat().st_size, 65536)
            stdout = stdout_path.read_text(encoding='utf-8')
            stderr = stderr_path.read_text(encoding='utf-8')
            self.assertEqual(returncode, 0, stderr)
            self.assertEqual(stderr, '')
            preserved = path.with_name(path.name+'.preserved') if swap else path
            self.assertEqual(preserved.read_bytes(), raw)
            response = json.loads(stdout)
            self.assertEqual(set(response), {'accepted'} if response.get('accepted') is True else {'accepted', 'class', 'message'})
            return response

    def test_duplicate_members_and_overflow_are_not_receipt_evidence(self):
        for method, raw in (
            ('phoneState', b'{"schema":0,"schema":1,"batches":{}}'),
            ('phoneState', b'{"schema":1,"batches":{},"ignored":1e309}'),
            ('read', b'{"version":0,"version":1,"deliveries":{}}'),
            ('read', b'{"version":1,"deliveries":{},"ignored":1e309}'),
        ):
            with self.subTest(method=method, raw=raw):
                result = self.run_reader(raw, method)
                self.assertIs(result['accepted'], False)
                self.assertIn(result['class'], ('InvalidArgumentException', 'JsonException'))
                self.assertTrue(result['message'])

    def test_substituted_fifo_never_waits_for_a_writer(self):
        result = self.run_reader(b'{"schema":1,"batches":{}}', swap=True)
        self.assertIs(result['accepted'], False)
        self.assertEqual(result['class'], 'RuntimeException')
        self.assertIn('identity changed', result['message'])

    def test_ordinary_receipt_objects_remain_supported(self):
        self.assertIs(self.run_reader(b'{"schema":1,"batches":{}}')['accepted'], True)
        self.assertIs(self.run_reader(b'{"version":1,"deliveries":{}}', 'read')['accepted'], True)

    def test_service_owned_history_remains_readable_by_pbx_account(self):
        if os.geteuid() != 0:
            self.fail('The mandatory PBX-account receipt boundary requires a root qualification fixture.')
        self.assertIs(self.run_reader(b'{"schema":1,"batches":{}}', account='asterisk')['accepted'], True)
        refused = self.run_reader(b'{"schema":1,"batches":{}}', swap=True, account='asterisk')
        self.assertIs(refused['accepted'], False)
        self.assertEqual(refused['class'], 'RuntimeException')
        self.assertIn('identity changed', refused['message'])


if __name__ == '__main__':
    unittest.main()
