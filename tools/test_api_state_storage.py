#!/usr/bin/env python3
"""Exercise API filesystem failures in private files, without production access."""
import json
import fcntl
from pathlib import Path
import re
import subprocess
import tempfile
import threading
import unittest

ROOT = Path(__file__).resolve().parents[1]
DESKTOP = ROOT / 'slsmassnotifyserver/api/sipnotify/index.php'
CONTROL = ROOT / 'slsmassnotifyserver/api/sls-mass-notify/index.php'

WRAPPERS = r'''
function fopen($path, $mode, ...$args) {
    if (($GLOBALS['swap_fifo'] ?? '') === $path && $mode === 'r+b') {
        unset($GLOBALS['swap_fifo']); unlink($path); posix_mkfifo($path, 0600);
    }
    return \fopen($path, $mode, ...$args);
}
function fwrite($handle, $data, ...$args) {
    $uri = stream_get_meta_data($handle)['uri'] ?? '';
    if (strpos($uri, '/.sls-api-state-') !== false) {
        if (!empty($GLOBALS['fail_write'])) {
            if (!empty($GLOBALS['wrote_partial'])) { return false; }
            $GLOBALS['wrote_partial'] = true;
            return \fwrite($handle, substr($data, 0, 5));
        }
        if (!empty($GLOBALS['short_write'])) { return \fwrite($handle, substr($data, 0, 7)); }
    }
    return \fwrite($handle, $data, ...$args);
}
function fsync($handle) {
    if (!empty($GLOBALS['fail_sync'])) { return false; }
    if (!empty($GLOBALS['fail_directory_sync']) && is_dir(stream_get_meta_data($handle)['uri'] ?? '')) { return false; }
    return \fsync($handle);
}
function rename($from, $to, ...$args) {
    return !empty($GLOBALS['fail_rename']) ? false : \rename($from, $to, ...$args);
}
'''


class ApiStateStorageTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='sls-api-state-')
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.desktop = DESKTOP.read_text()
        self.control = CONTROL.read_text()

    def fixture(self, kind, body, endpoint=False, raw=False):
        source = self.desktop if kind == 'desktop' else self.control
        source = source.replace("__DIR__ . '/event-log.php'", json.dumps(str(ROOT / 'slsmassnotifyserver/api/sls-mass-notify/event-log.php')))
        helper = json.dumps(str(ROOT / 'slsmassnotifyserver/api/sls-mass-notify/security.php'))
        source = source.replace("dirname(__DIR__) . '/sls-mass-notify/security.php'", helper).replace("__DIR__ . '/security.php'", helper)
        for name in ['contract.php', 'config-crypto.php']:
            path = json.dumps(str(ROOT/'slsmassnotifyserver/api/sls-mass-notify'/name))
            source = source.replace("__DIR__ . '/" + name + "'", path)
            source = source.replace("dirname(__DIR__) . '/sls-mass-notify/" + name + "'", path)
        marker = '\n\n$endpoint =' if kind == 'desktop' else '\n$config = config();'
        prefix, suffix = source.split(marker, 1)
        prefix = re.sub(r'^<\?php\s*', '', prefix)
        prefix = prefix.replace('declare(strict_types=1);', '')
        paths = {'EVENTS_FILE': 'events.jsonl', 'SETTINGS_FILE': 'settings.json', 'CONFIG_FILE': 'settings.json',
                 'DESKTOP_AUTH_RATE_FILE': 'rate.json', 'CONTROL_API_RATE_FILE': 'rate.json',
                 'DESKTOP_LAST_SEEN_FILE': 'seen.json', 'DESKTOP_ACK_DIRECTORY': 'acks',
                 'CONTROL_API_AUDIT_HELPER': 'missing-helper.py'}
        for constant, name in paths.items():
            prefix = re.sub(r"const " + constant + r" = '[^']*';",
                            'const ' + constant + ' = ' + json.dumps(str(self.directory / name)) + ';', prefix)
        contents = '<?php\ndeclare(strict_types=1);\nnamespace Fixture;\nuse \\RuntimeException; use \\Throwable;\numask(0027);\n'
        contents += WRAPPERS + prefix + '\n' + body
        if endpoint:
            contents += marker + suffix
        path = self.directory / 'fixture.php'; path.write_text(contents)
        result = subprocess.run(['php', str(path)], capture_output=True, text=True, timeout=8)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return (result.stdout if raw else json.loads(result.stdout)), result.stderr

    def rate(self, kind):
        return ("desktop_auth_attempt_allowed('192.0.2.1','alice',true,1800000000)" if kind == 'desktop'
                else "control_rate_allowed(['rate_limit_enabled'=>1,'rate_limit_per_minute'=>2],'192.0.2.1')")

    def reset(self):
        for path in self.directory.iterdir():
            if path.is_file() or path.is_symlink() or path.name.endswith('.json'):
                path.unlink()

    def test_helpers_are_identical_in_both_deployed_api_files(self):
        start = 'function api_state_metadata_matches('
        first = self.desktop[self.desktop.index(start):self.desktop.index('function desktop_authenticated_capacity(')]
        second = self.control[self.control.index(start):self.control.index('function control_rate_allowed(')]
        self.assertEqual(first, second)

    def test_fresh_state_and_real_linux_file_directory_fsync(self):
        for kind in ('desktop', 'control'):
            self.reset()
            result, error = self.fixture(kind, 'echo json_encode(["allowed"=>' + self.rate(kind)
                + ',"error"=>$GLOBALS["sls_api_state_error"],"mode"=>fileperms(' + ('DESKTOP_AUTH_RATE_FILE' if kind == 'desktop' else 'CONTROL_API_RATE_FILE') + ')&0777]);')
            self.assertEqual(result, {'allowed': True, 'error': '', 'mode': 0o640})
            self.assertEqual(error, '')
            self.assertEqual(list(self.directory.glob('.sls-api-state-*')), [])

    def test_corrupt_empty_and_oversized_existing_state_fail_closed_unchanged(self):
        for kind in ('desktop', 'control'):
            for content in ('', '{broken', '{"bad":{"bucket":false,"count":0}}', 'x' * 524289):
                self.reset(); rate = self.directory / 'rate.json'; rate.write_text(content)
                result, _ = self.fixture(kind, 'echo json_encode(["allowed"=>' + self.rate(kind) + ',"error"=>$GLOBALS["sls_api_state_error"]]);')
                self.assertFalse(result['allowed'])
                self.assertIn(result['error'], ('state_invalid', 'state_oversized'))
                self.assertEqual(rate.read_text(), content)

    def test_failed_write_sync_and_rename_preserve_prior_budget(self):
        for kind in ('desktop', 'control'):
            for flag in ('fail_write', 'fail_sync', 'fail_rename'):
                self.reset()
                result, _ = self.fixture(kind, '$first=' + self.rate(kind) + ';'
                    + '$path=' + ('DESKTOP_AUTH_RATE_FILE' if kind == 'desktop' else 'CONTROL_API_RATE_FILE') + ';'
                    + '$before=file_get_contents($path); $GLOBALS[' + json.dumps(flag) + ']=true;'
                    + '$second=' + self.rate(kind) + '; echo json_encode(["first"=>$first,"second"=>$second,'
                    + '"preserved"=>file_get_contents($path)===$before,"error"=>$GLOBALS["sls_api_state_error"]]);')
                self.assertTrue(result['first']); self.assertFalse(result['second']); self.assertTrue(result['preserved'])
                self.assertTrue(result['error'].startswith('state_'))
                self.assertEqual(list(self.directory.glob('.sls-api-state-*')), [])

    def test_successful_partial_writes_are_completed(self):
        result, _ = self.fixture('desktop', '$GLOBALS["short_write"]=true; echo json_encode(["allowed"=>'
            + self.rate('desktop') + ',"data"=>json_decode(file_get_contents(DESKTOP_AUTH_RATE_FILE),true)]);')
        self.assertTrue(result['allowed']); self.assertEqual(len(result['data']), 1)

    def test_symlinks_hardlinks_fifo_and_substitution_preserve_victims(self):
        for kind in ('desktop', 'control'):
            for attack in ('symlink', 'hardlink', 'fifo', 'swap_fifo'):
                self.reset()
                rate = self.directory / 'rate.json'; victim = self.directory / 'victim'; victim.write_text('private')
                if attack == 'symlink': rate.symlink_to(victim)
                elif attack == 'hardlink': rate.hardlink_to(victim)
                elif attack == 'fifo':
                    import os
                    os.mkfifo(rate)
                else: rate.write_text('{}')
                setup = '$GLOBALS["swap_fifo"]=' + json.dumps(str(rate)) + ';' if attack == 'swap_fifo' else ''
                result, _ = self.fixture(kind, setup + '$start=microtime(true);$ok=' + self.rate(kind)
                    + '; echo json_encode(["ok"=>$ok,"duration"=>microtime(true)-$start,"error"=>$GLOBALS["sls_api_state_error"]]);')
                self.assertFalse(result['ok']); self.assertLess(result['duration'], 0.5)
                self.assertEqual(victim.read_text(), 'private')

    def test_sidecar_and_legacy_inode_contention_have_one_short_deadline(self):
        for kind in ('desktop', 'control'):
            for suffix in ('', '.lock'):
                self.reset()
                result, _ = self.fixture(kind, '$first=' + self.rate(kind) + '; $path='
                    + ('DESKTOP_AUTH_RATE_FILE' if kind == 'desktop' else 'CONTROL_API_RATE_FILE') + ';'
                    + '$held=\\fopen($path.' + json.dumps(suffix) + ',"r+b");flock($held,LOCK_EX);$before=file_get_contents($path);'
                    + '$start=microtime(true);$ok=' + self.rate(kind) + ';$duration=microtime(true)-$start;'
                    + 'echo json_encode(["ok"=>$ok,"duration"=>$duration,"error"=>$GLOBALS["sls_api_state_error"],"preserved"=>file_get_contents($path)===$before]);')
                self.assertFalse(result['ok']); self.assertTrue(result['preserved'])
                self.assertEqual(result['error'], 'state_locked'); self.assertLess(result['duration'], 0.5)

    def test_short_normal_contention_waits_then_succeeds(self):
        lock = self.directory / 'rate.json.lock'; lock.write_text('')
        with lock.open('r+b') as held:
            fcntl.flock(held, fcntl.LOCK_EX)
            release = threading.Timer(0.12, lambda: fcntl.flock(held, fcntl.LOCK_UN))
            release.start()
            try:
                result, _ = self.fixture('desktop', 'echo json_encode(["allowed"=>' + self.rate('desktop') + ',"error"=>$GLOBALS["sls_api_state_error"]]);')
            finally:
                release.join()
        self.assertTrue(result['allowed']); self.assertEqual(result['error'], '')

    def test_directory_sync_failure_does_not_reset_committed_budget(self):
        result, _ = self.fixture('control', '$GLOBALS["fail_directory_sync"]=true; $allowed=' + self.rate('control')
            + ';$reason=$GLOBALS["sls_api_state_error"];$data=json_decode(file_get_contents(CONTROL_API_RATE_FILE),true);'
            + 'echo json_encode(["allowed"=>$allowed,"reason"=>$reason,"count"=>array_values($data)[0]["count"]]);')
        self.assertFalse(result['allowed']); self.assertEqual(result['reason'], 'state_directory_sync_failed')
        self.assertEqual(result['count'], 1)

    def test_legacy_desktop_minute_keeps_budget_then_transitions(self):
        result, _ = self.fixture('desktop', r'''
            file_put_contents(DESKTOP_AUTH_RATE_FILE,json_encode([
                hash('sha256','ip:192.0.2.1')=>['bucket'=>gmdate('YmdHi',1800000000),'count'=>5000],
                hash('sha256','account:alice')=>['bucket'=>gmdate('YmdHi',1800000000),'count'=>60]]));
            $before=file_get_contents(DESKTOP_AUTH_RATE_FILE);
            $blocked=desktop_auth_failure_budget_available('192.0.2.1','alice',1800000000);
            $preserved=file_get_contents(DESKTOP_AUTH_RATE_FILE)===$before;
            $next=desktop_auth_attempt_allowed('192.0.2.1','alice',true,1800000060);
            echo json_encode(['blocked'=>$blocked,'preserved'=>$preserved,'next'=>$next,
                'keys'=>array_keys(json_decode(file_get_contents(DESKTOP_AUTH_RATE_FILE),true))]);
        ''')
        self.assertFalse(result['blocked']); self.assertTrue(result['preserved']); self.assertTrue(result['next'])
        self.assertTrue(result['keys'][0].startswith('ok:'))

    def test_presence_is_advisory_preserves_receipt_fields_and_prunes_revoked_clients(self):
        result, _ = self.fixture('desktop', r'''
            file_put_contents(DESKTOP_LAST_SEEN_FILE,json_encode(['alice'=>['client_id'=>'id1','ack_event_id'=>'event1','ack_at'=>'2030-01-01T00:00:00Z'],'removed'=>['seen_at'=>'old']]));
            $client=['username'=>'alice','client_id'=>'id1','name'=>'Alice'];
            $settings=['desktop_clients'=>[['username'=>'alice','enabled'=>true]]];
            update_desktop_seen($client,[],$settings);
            $data=json_decode(file_get_contents(DESKTOP_LAST_SEEN_FILE),true);
            $before=file_get_contents(DESKTOP_LAST_SEEN_FILE);$held=\fopen(DESKTOP_LAST_SEEN_FILE,'r+b');flock($held,LOCK_EX);
            $start=microtime(true);update_desktop_seen($client,[],$settings);$elapsed=microtime(true)-$start;
            echo json_encode(['data'=>$data,'duration'=>$elapsed,'preserved'=>file_get_contents(DESKTOP_LAST_SEEN_FILE)===$before]);
        ''')
        self.assertEqual(list(result['data']), ['alice']); self.assertEqual(result['data']['alice']['ack_event_id'], 'event1')
        self.assertTrue(result['preserved']); self.assertLess(result['duration'], 0.1)

    def test_client_report_is_bounded_advisory_and_bound_to_identity(self):
        result, _ = self.fixture('desktop', r'''
            $client=['username'=>'alice','client_id'=>'id1','name'=>'Alice'];
            $_SERVER=['HTTP_X_SLS_CLIENT_VERSION'=>'1.2.3-beta.1','HTTP_X_SLS_PAYLOAD_SCHEMA'=>'1','HTTP_X_SLS_SSE_PROTOCOL'=>'2'];
            update_desktop_seen($client,['ack_event_id'=>'event1']);
            $first=json_decode(file_get_contents(DESKTOP_LAST_SEEN_FILE),true)['alice'];
            $_SERVER=['HTTP_X_SLS_CLIENT_VERSION'=>'<script>bad</script>','HTTP_X_SLS_PAYLOAD_SCHEMA'=>['1'],'HTTP_X_SLS_SSE_PROTOCOL'=>'9999'];
            update_desktop_seen($client);
            $second=json_decode(file_get_contents(DESKTOP_LAST_SEEN_FILE),true)['alice'];
            $client['client_id']='id2'; update_desktop_seen($client);
            $third=json_decode(file_get_contents(DESKTOP_LAST_SEEN_FILE),true)['alice'];
            echo json_encode([$first,$second,$third]);
        ''')
        first, second, third = result
        self.assertEqual(first['client_report']['version'], '1.2.3-beta.1')
        self.assertEqual(first['client_report']['payload_schema'], 1)
        self.assertEqual(first['client_report'], second['client_report'])
        self.assertEqual(second['ack_event_id'], 'event1')
        self.assertNotIn('client_report', third)
        self.assertNotIn('ack_event_id', third)

    def test_desktop_endpoint_distinguishes_storage_error_from_throttling(self):
        result, _ = self.fixture('desktop', r'''
            register_shutdown_function(function(){file_put_contents(dirname(SETTINGS_FILE).'/response-code',strval(http_response_code()));});
            file_put_contents(SETTINGS_FILE,'{"desktop_clients":[]}');
            file_put_contents(DESKTOP_AUTH_RATE_FILE,'broken');
            $_SERVER=['REQUEST_URI'=>'/api/sipnotify/desktop','REQUEST_METHOD'=>'GET','REMOTE_ADDR'=>'127.0.0.1'];
        ''', endpoint=True)
        self.assertEqual(result['error'], 'rate_limit_storage_unavailable'); self.assertTrue(result['retryable'])
        self.assertEqual(result['reason'], 'state_invalid')
        self.assertEqual((self.directory / 'response-code').read_text(), '503')

    def test_control_endpoint_reports_503_before_authentication_or_actions(self):
        result, error = self.fixture('control', r'''
            register_shutdown_function(function(){file_put_contents(dirname(CONFIG_FILE).'/response-code',strval(http_response_code()));});
            file_put_contents(CONFIG_FILE,'{"control_api":{"enabled":true,"rate_limit_enabled":true}}');
            file_put_contents(CONTROL_API_RATE_FILE,'broken');
            $_SERVER=['REQUEST_METHOD'=>'POST','REMOTE_ADDR'=>'127.0.0.1'];
        ''', endpoint=True)
        self.assertEqual(result['error'], 'rate_limit_storage_unavailable'); self.assertTrue(result['retryable'])
        self.assertEqual((self.directory / 'response-code').read_text(), '503')
        self.assertNotIn('Fatal', error)

    def test_presence_contention_does_not_stall_authenticated_stream(self):
        output, error = self.fixture('desktop', r'''
            $key=random_bytes(32);$nonce=random_bytes(12);
            $cipher=openssl_encrypt('test-password','aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag);
            file_put_contents(SETTINGS_FILE,json_encode(['desktop_auth_key'=>base64_encode($key),'desktop_clients'=>[
                ['client_id'=>'id1','username'=>'alice','password_enc'=>'v1:'.base64_encode($nonce.$tag.$cipher),'enabled'=>'1']]]));
            file_put_contents(DESKTOP_LAST_SEEN_FILE,'{}');
            $held=\fopen(DESKTOP_LAST_SEEN_FILE,'r+b');flock($held,LOCK_EX);
            $_SERVER=['REQUEST_URI'=>'/api/sipnotify/desktop/stream','REQUEST_METHOD'=>'GET','REMOTE_ADDR'=>'127.0.0.1','PHP_AUTH_USER'=>'alice','PHP_AUTH_PW'=>'test-password'];
            $_GET=['stream_seconds'=>1];
        ''', endpoint=True, raw=True)
        self.assertIn('event: authenticated', output); self.assertIn(': keepalive ', output)
        self.assertIn('event: reconnect', output); self.assertNotIn('Fatal', error)


if __name__ == '__main__':
    unittest.main()
