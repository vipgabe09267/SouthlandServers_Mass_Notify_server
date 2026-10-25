#!/usr/bin/env python3
"""Desktop API regressions using private fixture data, without FreePBX bootstrap."""

import json
import os
import re
import selectors
import subprocess
import tempfile
import time
import unittest
from datetime import datetime
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
API = ROOT / "slsmassnotifyserver/api/sipnotify/index.php"


class DesktopApiDeliveryTests(unittest.TestCase):
    def setUp(self):
        self.fixture = tempfile.TemporaryDirectory(prefix="sls-desktop-api-")
        self.directory = Path(self.fixture.name)
        source = API.read_text(encoding="utf-8")
        source = source.replace("dirname(__DIR__) . '/sls-mass-notify/security.php'", json.dumps(str(API.parent.parent / 'sls-mass-notify/security.php')))
        source = source.replace("dirname(__DIR__) . '/sls-mass-notify/config-crypto.php'", json.dumps(str(API.parent.parent / 'sls-mass-notify/config-crypto.php')))
        source = source.replace("dirname(__DIR__) . '/sls-mass-notify/desktop-feed-cache.php'", json.dumps(str(API.parent.parent / 'sls-mass-notify/desktop-feed-cache.php')))
        source = source.replace("dirname(__DIR__, 2) . '/EnterpriseLabsConfig.php'", json.dumps(str(ROOT / 'slsmassnotifyserver/EnterpriseLabsConfig.php')))
        self.prefix = source.split("\n\n$endpoint =", 1)[0]
        paths = {
            "EVENTS_FILE": self.directory / "events.jsonl",
            "DESKTOP_AUTH_RATE_FILE": self.directory / "rate.json",
            "DESKTOP_ACK_DIRECTORY": self.directory / "acks",
            "DESKTOP_LAST_SEEN_FILE": self.directory / "seen.json",
            "SETTINGS_FILE": self.directory / "settings.json",
        }
        for name, path in paths.items():
            self.prefix, count = re.subn(
                rf"const {name} = '[^']*';",
                f"const {name} = {json.dumps(str(path))};",
                self.prefix,
                count=1,
            )
            self.assertEqual(count, 1)
        self.endpoint = source.split("\n\n$endpoint =", 1)[1]

    def tearDown(self):
        self.fixture.cleanup()

    def php(self, statements, endpoint=False):
        harness = self.directory / "harness.php"
        contents = self.prefix + "\numask(0027);\n" + statements
        if endpoint:
            contents += "\n$endpoint =" + self.endpoint
        harness.write_text(contents, encoding="utf-8")
        result = subprocess.run(
            ["php", str(harness)], capture_output=True, text=True, timeout=30, check=False
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(result.stderr, "", result.stderr)
        return json.loads(result.stdout)

    def authenticated_endpoint_setup(self, endpoint="desktop", method="GET"):
        return """
            $key = random_bytes(32);
            $nonce = random_bytes(12);
            $cipher = openssl_encrypt('test-password', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            file_put_contents(SETTINGS_FILE, json_encode(['desktop_auth_key' => base64_encode($key), 'desktop_clients' => [
                ['client_id' => 'device-a', 'username' => 'alice', 'password_enc' => 'v1:' . base64_encode($nonce . $tag . $cipher), 'enabled' => '1'],
            ]]));
            chmod(SETTINGS_FILE, 0640);
            $_SERVER = ['REMOTE_ADDR' => '192.0.2.1', 'HTTPS' => 'on', 'PHP_AUTH_USER' => 'alice', 'PHP_AUTH_PW' => 'test-password'];
        """ + "\n$_SERVER['REQUEST_URI'] = " + json.dumps("/api/sipnotify/" + endpoint) + ";\n" + "$_SERVER['REQUEST_METHOD'] = " + json.dumps(method) + ";\n"

    def test_failed_known_username_cannot_exhaust_another_sources_budget(self):
        result = self.php("""
            for ($i = 0; $i < 150; $i++) {
                desktop_auth_attempt_allowed('192.0.2.1', 'alice', false, 1800000000);
            }
            echo json_encode([
                'failed' => desktop_auth_attempt_allowed('192.0.2.1', 'alice', false, 1800000000),
                'valid_same_ip' => desktop_auth_attempt_allowed('192.0.2.1', 'alice', true, 1800000000),
                'valid_other_ip' => desktop_auth_attempt_allowed('192.0.2.2', 'alice', true, 1800000000),
                'exhausted_source_precheck' => desktop_auth_failure_budget_available('192.0.2.1', 'alice', 1800000000),
                'other_source_precheck' => desktop_auth_failure_budget_available('192.0.2.2', 'alice', 1800000000),
            ]);
        """)
        self.assertEqual(result, {"failed": False, "valid_same_ip": True, "valid_other_ip": True, "exhausted_source_precheck": False, "other_source_precheck": True})

    def test_browser_display_master_is_strict_and_ordinary_desktops_are_independent(self):
        result = self.php("""
            $config=['enterprise_integrations'=>['enabled'=>'1','browser_display'=>['enabled'=>'1']]];
            $_SERVER['HTTP_X_SLS_BROWSER_DISPLAY']='1';$browser=[];$ordinary=[];
            foreach([null,false,true,'1',1] as $master){
                $settings=$config;if($master!==null){$settings['enterprise_labs']=['enabled'=>$master];}
                $browser[]=desktop_browser_display_permitted($settings);
                unset($_SERVER['HTTP_X_SLS_BROWSER_DISPLAY']);$ordinary[]=desktop_browser_display_permitted($settings);
                $_SERVER['HTTP_X_SLS_BROWSER_DISPLAY']='1';
            }
            $config['enterprise_labs']=['enabled'=>true];$config['enterprise_integrations']['browser_display']['enabled']='0';
            echo json_encode(['browser'=>$browser,'ordinary'=>$ordinary,'child_disabled'=>desktop_browser_display_permitted($config)]);
        """)
        self.assertEqual(result, {'browser': [False, False, True, False, False], 'ordinary': [True]*5, 'child_disabled': False})

    def test_browser_poll_requires_master_but_old_receipts_still_persist(self):
        setup=self.authenticated_endpoint_setup()+"""
            $settings=json_decode(file_get_contents(SETTINGS_FILE),true);
            $settings['enterprise_integrations']=['enabled'=>'1','browser_display'=>['enabled'=>'1']];
            file_put_contents(SETTINGS_FILE,json_encode($settings));
            $_SERVER['HTTP_X_SLS_BROWSER_DISPLAY']='1';
        """
        result=self.php(setup,endpoint=True)
        self.assertEqual(result, {'ok':False,'error':'browser_display_disabled'})
        result=self.php(setup+"$settings['enterprise_labs']=['enabled'=>true];file_put_contents(SETTINGS_FILE,json_encode($settings));",endpoint=True)
        self.assertTrue(result['ok'])
        self.endpoint=self.endpoint.replace("file_get_contents('php://input', false, null, 0, 4097)","json_encode(['event_id'=>'routed-event'])")
        result=self.php(self.authenticated_endpoint_setup('desktop/ack','POST')+"""
            $_SERVER['HTTP_X_SLS_BROWSER_DISPLAY']='1';
            file_put_contents(EVENTS_FILE,json_encode(['id'=>'routed-event','created_at'=>gmdate('c'),
                'kind'=>'announcement','title'=>'Fixture','message'=>'Fixture receipt',
                'desktop_recipients'=>['alice'],'desktop_client_ids'=>['alice'=>'device-a']]) . "\\n");
        """,endpoint=True)
        self.assertEqual(result,{'ok':True,'event_id':'routed-event','receipt_type':'client_acknowledgement'})

    def test_existing_browser_stream_closes_when_master_is_disabled(self):
        setup=self.authenticated_endpoint_setup('desktop/stream')+"""
            $settings=json_decode(file_get_contents(SETTINGS_FILE),true);
            $settings['enterprise_labs']=['enabled'=>true];
            $settings['enterprise_integrations']=['enabled'=>'1','browser_display'=>['enabled'=>'1']];
            file_put_contents(SETTINGS_FILE,json_encode($settings));
            $_SERVER['HTTP_X_SLS_BROWSER_DISPLAY']='1';$_GET['stream_seconds']='8';
        """
        harness=self.directory/'stream.php'
        harness.write_text(self.prefix+"\numask(0027);\n"+setup+"\n$endpoint ="+self.endpoint,encoding='utf-8')
        process=subprocess.Popen(['php',str(harness)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        initial=b''
        try:
            with selectors.DefaultSelector() as selector:
                selector.register(process.stdout,selectors.EVENT_READ)
                deadline=time.monotonic()+4
                while b'event: authenticated' not in initial and time.monotonic()<deadline:
                    for key,_ in selector.select(max(0,deadline-time.monotonic())):
                        chunk=os.read(key.fileobj.fileno(),8192)
                        if not chunk:break
                        initial+=chunk
            self.assertIn(b'event: authenticated',initial)
            path=self.directory/'settings.json';settings=json.loads(path.read_text());settings['enterprise_labs']['enabled']=False
            replacement=self.directory/'updated-settings.json';replacement.write_text(json.dumps(settings));replacement.chmod(0o640);os.replace(replacement,path)
            remaining,errors=process.communicate(timeout=10)
            self.assertEqual(process.returncode,0,errors.decode())
            self.assertEqual(errors,b'')
            self.assertIn(b'event: revoked\ndata: {"ok":false,"error":"browser_display_disabled"}',initial+remaining)
            result=self.php("$slots=desktop_stream_slot('alice');echo json_encode(['held'=>count($slots)]);desktop_stream_release_slots($slots);")
            self.assertEqual(result['held'],2,'Revoked browser stream leaked capacity slots')
        finally:
            if process.poll() is None:process.kill();process.communicate(timeout=5)

    def test_shared_nat_clients_have_independent_authenticated_budgets(self):
        result = self.php("""
            $allowed = true;
            for ($client = 0; $client < 1000; $client++) {
                for ($request = 0; $request < 2; $request++) {
                    $allowed = desktop_auth_attempt_allowed('192.0.2.1', 'client-' . $client, true, 1800000000) && $allowed;
                }
            }
            for ($i = 0; $i < 118; $i++) {
                desktop_auth_attempt_allowed('192.0.2.1', 'client-0', true, 1800000000);
            }
            echo json_encode([
                'shared_nat_allowed' => $allowed,
                'overactive_allowed' => desktop_auth_attempt_allowed('192.0.2.1', 'client-0', true, 1800000000),
                'another_client_allowed' => desktop_auth_attempt_allowed('192.0.2.1', 'client-999', true, 1800000000),
                'next_minute' => desktop_auth_attempt_allowed('192.0.2.1', 'client-0', true, 1800000060),
            ]);
        """)
        self.assertEqual(result, {"shared_nat_allowed": True, "overactive_allowed": False, "another_client_allowed": True, "next_minute": True})

    def test_registered_authenticated_reads_share_registry_without_bypassing_writers(self):
        result = self.php("""
            $now=1800000000;
            $initial=desktop_auth_attempt_allowed('192.0.2.1','alice',true,$now);
            $before=file_get_contents(DESKTOP_AUTH_RATE_FILE);
            $lease=fopen(DESKTOP_AUTH_RATE_FILE.'.lock','r+b');flock($lease,LOCK_SH);
            $authenticated=desktop_auth_attempt_allowed('192.0.2.1','alice',true,$now);
            $precheck=desktop_auth_failure_budget_available('192.0.2.1','alice',$now);
            $failed=desktop_auth_attempt_allowed('192.0.2.1','alice',false,$now);
            $failureReason=$GLOBALS['sls_api_state_error'];
            $newClient=desktop_auth_attempt_allowed('192.0.2.1','bob',true,$now);
            $unchanged=file_get_contents(DESKTOP_AUTH_RATE_FILE)===$before;
            flock($lease,LOCK_UN);fclose($lease);
            $newClientRetry=desktop_auth_attempt_allowed('192.0.2.1','bob',true,$now);
            echo json_encode(compact('initial','authenticated','precheck','failed','failureReason','newClient','unchanged','newClientRetry'));
        """)
        self.assertEqual(result, {'initial':True,'authenticated':True,'precheck':True,
                                  'failed':False,'failureReason':'state_locked',
                                  'newClient':False,'unchanged':True,'newClientRetry':True})

    def test_registry_write_upgrade_reloads_a_concurrent_committed_change(self):
        self.prefix=self.prefix.replace('function api_state_lock(', 'function fixture_api_state_lock(',1)
        result=self.php("""
            function api_state_lock($handle,float $deadline,int $operation=LOCK_EX): bool {
                if($operation===LOCK_EX && !empty($GLOBALS['intervening_writer'])){
                    $GLOBALS['intervening_writer']=false;
                    if(!api_state_transaction(DESKTOP_AUTH_RATE_FILE,4096,static fn($data)=>[
                        'allowed'=>true,'write'=>true,'data'=>$data+['concurrent'=>true]])){
                        throw new RuntimeException('Shared lease was not released before the write upgrade');
                    }
                }
                return fixture_api_state_lock($handle,$deadline,$operation);
            }
            file_put_contents(DESKTOP_AUTH_RATE_FILE,'{"prior":true}');
            $GLOBALS['intervening_writer']=true;$invocations=0;
            $ok=api_state_transaction(DESKTOP_AUTH_RATE_FILE,4096,static function($data)use(&$invocations){
                $invocations++;
                return ['allowed'=>true,'write'=>true,'data'=>$data+['requested'=>true]];
            },250,true);
            echo json_encode(['ok'=>$ok,'invocations'=>$invocations,'data'=>json_decode(file_get_contents(DESKTOP_AUTH_RATE_FILE),true)]);
        """)
        self.assertEqual(result, {'ok':True,'invocations':2,
                                  'data':{'prior':True,'concurrent':True,'requested':True}})

    def test_ack_retry_after_busy_lock_retains_all_exact_receipts(self):
        result = self.php("""
            $client=['client_id'=>'device-a','username'=>'alice'];
            $first=['id'=>'first'];$second=['id'=>'second'];$now=1800000000;
            $initial=record_desktop_acknowledgement([],$client,$first,$now);
            $path=DESKTOP_ACK_DIRECTORY.'/'.hash('sha256','id:device-a').'.json';
            $before=file_get_contents($path);
            $lease=fopen($path.'.lock','r+b');flock($lease,LOCK_EX);
            $busy=record_desktop_acknowledgement([],$client,$second,$now);
            $unchanged=file_get_contents($path)===$before;
            flock($lease,LOCK_UN);fclose($lease);
            $retry=record_desktop_acknowledgement([],$client,$second,$now);
            $duplicate=record_desktop_acknowledgement([],$client,$second,$now+1);
            $ids=array_column(json_decode(file_get_contents($path),true)['acknowledgements'],'event_id');
            echo json_encode(compact('initial','busy','unchanged','retry','duplicate','ids'));
        """)
        self.assertEqual(result, {'initial':True,'busy':False,'unchanged':True,
                                  'retry':True,'duplicate':True,'ids':['first','second']})

    def test_authentication_decrypts_only_the_matching_enabled_username(self):
        self.prefix = self.prefix.replace(
            "function decrypt_desktop_password(", "function fixture_decrypt_desktop_password(", 1
        )
        result = self.php("""
            function decrypt_desktop_password(string $encoded, array $settings): string {
                $GLOBALS['decrypt_count'] = ($GLOBALS['decrypt_count'] ?? 0) + 1;
                return fixture_decrypt_desktop_password($encoded, $settings);
            }
            $key = random_bytes(32); $nonce = random_bytes(12);
            $cipher = openssl_encrypt('test-password', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            $settings = ['desktop_auth_key' => base64_encode($key), 'desktop_clients' => []];
            for ($i = 0; $i < 1000; $i++) {
                $settings['desktop_clients'][] = ['username' => 'client-' . $i, 'enabled' => '1',
                    'password_enc' => 'v1:' . base64_encode($nonce . $tag . $cipher)];
            }
            $client = authorized_desktop_client($settings, 'client-999', 'test-password');
            $unknown = authorized_desktop_client($settings, 'unknown', 'test-password');
            $settings['desktop_clients'][999]['enabled'] = '0';
            $disabled = authorized_desktop_client($settings, 'client-999', 'test-password');
            echo json_encode(['username' => $client['username'], 'unknown' => $unknown,
                'disabled' => $disabled, 'decrypt_count' => $GLOBALS['decrypt_count']]);
        """)
        self.assertEqual(result, {"username": "client-999", "unknown": [], "disabled": [], "decrypt_count": 1})

    def test_presence_coalesces_identical_polls_but_persists_identity_and_receipt_changes(self):
        result = self.php("""
            $_SERVER['REMOTE_ADDR']='192.0.2.1';
            $client=['username'=>'alice','client_id'=>'first-device','name'=>'Desktop'];
            update_desktop_seen($client);clearstatcache(true,DESKTOP_LAST_SEEN_FILE);$first=fileinode(DESKTOP_LAST_SEEN_FILE);
            update_desktop_seen($client);clearstatcache(true,DESKTOP_LAST_SEEN_FILE);$same=fileinode(DESKTOP_LAST_SEEN_FILE)===$first;
            update_desktop_seen($client,['ack_event_id'=>'event-a','ack_at'=>gmdate('c')]);
            $ack=json_decode(file_get_contents(DESKTOP_LAST_SEEN_FILE),true)['alice'];
            $client['client_id']='replacement-device';update_desktop_seen($client);
            $replacement=json_decode(file_get_contents(DESKTOP_LAST_SEEN_FILE),true)['alice'];
            echo json_encode(['coalesced'=>$same,'ack'=>$ack['ack_event_id'],'replacement'=>$replacement['client_id'],'old_receipt_retained'=>isset($replacement['ack_event_id'])]);
        """)
        self.assertEqual(result, {'coalesced': True, 'ack': 'event-a', 'replacement': 'replacement-device', 'old_receipt_retained': False})

    def test_presence_report_timestamp_does_not_force_identical_app_poll_writes(self):
        result=self.php("""
            $_SERVER=['REMOTE_ADDR'=>'192.0.2.1','HTTP_X_SLS_CLIENT_VERSION'=>'1.0.12-beta',
                'HTTP_X_SLS_PAYLOAD_SCHEMA'=>'1','HTTP_X_SLS_SSE_PROTOCOL'=>'2'];
            $client=['username'=>'alice','client_id'=>'first-device','name'=>'Desktop'];
            update_desktop_seen($client);
            $saved=json_decode(file_get_contents(DESKTOP_LAST_SEEN_FILE),true);
            $older=gmdate('c',time()-10);$saved['alice']['client_report']['reported_at']=$older;
            file_put_contents(DESKTOP_LAST_SEEN_FILE,json_encode($saved));
            clearstatcache(true,DESKTOP_LAST_SEEN_FILE);$inode=fileinode(DESKTOP_LAST_SEEN_FILE);
            update_desktop_seen($client);clearstatcache(true,DESKTOP_LAST_SEEN_FILE);
            $coalesced=fileinode(DESKTOP_LAST_SEEN_FILE)===$inode;
            $same=json_decode(file_get_contents(DESKTOP_LAST_SEEN_FILE),true)['alice'];
            $_SERVER['HTTP_X_SLS_CLIENT_VERSION']='1.0.13-beta';update_desktop_seen($client);
            clearstatcache(true,DESKTOP_LAST_SEEN_FILE);$changed=fileinode(DESKTOP_LAST_SEEN_FILE)!==$inode;
            $new=json_decode(file_get_contents(DESKTOP_LAST_SEEN_FILE),true)['alice'];
            echo json_encode(['coalesced'=>$coalesced,'timestamp_retained'=>$same['client_report']['reported_at']===$older,
                'changed'=>$changed,'version'=>$new['client_report']['version']]);
        """)
        self.assertEqual(result,{'coalesced':True,'timestamp_retained':True,'changed':True,'version':'1.0.13-beta'})

    def test_poll_wire_budget_includes_duplicate_latest_and_metadata(self):
        result=self.php("""
            $events=[];for($i=0;$i<4;$i++){$events[]=['id'=>'event-'.$i,'message'=>str_repeat('x',262000)];}
            $batch=['last_event_id'=>'event-3','has_more'=>false,'cursor_gap'=>false,'window_truncated'=>false,'eligible_count'=>4];
            $page=desktop_bound_poll_page($events,$batch,true);
            $wire=$page+['latest'=>end($page['events']),'metadata'=>str_repeat('x',8192)];
            echo json_encode(['bytes'=>strlen(json_encode($wire,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),'count'=>count($page['events']),'more'=>$page['has_more']]);
        """)
        self.assertLess(result['bytes'],1024*1024)
        self.assertEqual(result['count'],2)
        self.assertTrue(result['more'])

    def test_poll_wire_budget_paginates_forward_and_preserves_snapshot_tail(self):
        result=self.php("""
            $events=[];for($index=0;$index<100;$index++){$events[]=['id'=>'event-'.$index,'message'=>str_repeat('猫',10000)];}
            $batch=['last_event_id'=>'event-99','has_more'=>false,'cursor_gap'=>false,'window_truncated'=>false,'eligible_count'=>100];
            $forward=desktop_bound_poll_page($events,$batch,true);
            $snapshot=desktop_bound_poll_page($events,$batch,false);
            echo json_encode(['count'=>count($forward['events']),'last'=>$forward['last_event_id'],
                'actual_last'=>end($forward['events'])['id'],'more'=>$forward['has_more'],
                'bytes'=>strlen(json_encode($forward['events'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
                'snapshot_last'=>end($snapshot['events'])['id'],'snapshot_cursor'=>$snapshot['last_event_id'],
                'snapshot_more'=>$snapshot['has_more'],'snapshot_truncated'=>$snapshot['window_truncated']]);
        """)
        self.assertTrue(0<result['count']<100);self.assertEqual(result['last'],result['actual_last'])
        self.assertTrue(result['more']);self.assertLess(result['bytes'],786432)
        self.assertEqual(result['snapshot_last'],'event-99');self.assertEqual(result['snapshot_cursor'],'event-99')
        self.assertFalse(result['snapshot_more']);self.assertTrue(result['snapshot_truncated'])

    def test_personalized_feed_does_not_disclose_other_desktop_recipients(self):
        result=self.php("""
            $event=['id'=>'event-a','desktop_all'=>true,'desktop_recipients'=>['alice','bob'],
                'desktop_client_ids'=>['alice'=>'first','bob'=>'second'],'created_at'=>'2026-10-23T00:00:00Z'];
            echo json_encode(desktop_event_public_payload($event,[],['username'=>'alice']));
        """)
        self.assertEqual(result['desktop_recipients'],['alice']);self.assertFalse(result['desktop_all'])
        self.assertNotIn('bob',json.dumps(result));self.assertNotIn('desktop_client_ids',result)
        self.assertEqual(result['created_at'],'2026-10-23T00:00:00Z')

    def test_authenticated_requests_reuse_registry_and_preserve_preupgrade_counter(self):
        result=self.php("""
            $bucket=gmdate('YmdHi',1800000000);$id='ok:'.hash('sha256','authenticated:alice');
            file_put_contents(DESKTOP_AUTH_RATE_FILE,json_encode([$id=>['bucket'=>$bucket,'count'=>119]]));chmod(DESKTOP_AUTH_RATE_FILE,0640);
            $first=desktop_auth_attempt_allowed('192.0.2.1','alice',true,1800000000);
            clearstatcache(true,DESKTOP_AUTH_RATE_FILE);$inode=fileinode(DESKTOP_AUTH_RATE_FILE);
            $second=desktop_auth_attempt_allowed('192.0.2.1','alice',true,1800000000);
            clearstatcache(true,DESKTOP_AUTH_RATE_FILE);
            echo json_encode(['last_allowed'=>$first,'over_budget'=>$second,'registry_unchanged'=>$inode===fileinode(DESKTOP_AUTH_RATE_FILE)]);
        """)
        self.assertEqual(result, {'last_allowed': True, 'over_budget': False, 'registry_unchanged': True})

    def test_limiter_storage_capacity_tracks_configured_and_existing_fleet(self):
        result = self.php("""
            $capacities = [desktop_authenticated_capacity([]),
                desktop_authenticated_capacity(['desktop_client_limit' => 1000]),
                desktop_authenticated_capacity(['desktop_clients' => array_fill(0, 50, [])]),
                desktop_authenticated_capacity(['desktop_client_limit' => 100000])];
            $allowed = true;
            for ($i = 0; $i < 25; $i++) {
                $allowed = desktop_auth_attempt_allowed('192.0.2.1', 'client-' . $i, true, 1800000000, false, 25) && $allowed;
            }
            echo json_encode(['capacities' => $capacities, 'allowed' => $allowed,
                'over_capacity' => desktop_auth_attempt_allowed('192.0.2.1', 'new-client', true, 1800000000, false, 25),
                'enrolled_preserved' => desktop_auth_attempt_allowed('192.0.2.1', 'client-0', true, 1800000000, false, 25)]);
        """)
        self.assertEqual(result, {"capacities": [25, 1000, 50, 1000], "allowed": True, "over_capacity": False, "enrolled_preserved": True})

    def test_poll_and_ack_burst_fit_one_authenticated_client_budget(self):
        result = self.php("""
            $allowed = true;
            // Twelve five-second polls, 100 receipts, and two reconnects.
            for ($i = 0; $i < 114; $i++) {
                $allowed = desktop_auth_attempt_allowed('192.0.2.1', 'alice', true, 1800000000) && $allowed;
            }
            echo json_encode(['allowed' => $allowed]);
        """)
        self.assertTrue(result["allowed"])

    def test_date_no_cache_and_retry_after_use_real_utc_bucket_boundaries(self):
        result = self.php("""
            echo json_encode(['headers' => desktop_response_headers(1800000000),
                'retry' => [desktop_rate_retry_after(1800000000), desktop_rate_retry_after(1800000020), desktop_rate_retry_after(1800000059)]]);
        """)
        self.assertEqual(result["headers"], ["Date: Fri, 15 Jan 2027 08:00:00 GMT", "Cache-Control: private, no-store, max-age=0, must-revalidate", "Vary: Authorization"])
        self.assertEqual(result["retry"], [60, 40, 1])

    def test_full_failure_table_does_not_evict_budgets_or_block_authenticated_namespace(self):
        result = self.php("""
            $data = [];
            for ($i = 0; $i < 2048; $i++) {
                $data['failed:' . hash('sha256', (string)$i)] = ['bucket' => gmdate('YmdHi', 1800000000), 'count' => 121];
            }
            file_put_contents(DESKTOP_AUTH_RATE_FILE, json_encode($data));
            $failed = desktop_auth_attempt_allowed('192.0.2.99', 'new-name', false, 1800000000);
            $valid = true;
            for ($i = 0; $i < 1000; $i++) {
                $valid = desktop_auth_attempt_allowed('192.0.2.99', 'real-client-' . $i, true, 1800000000) && $valid;
            }
            $saved = json_decode(file_get_contents(DESKTOP_AUTH_RATE_FILE), true);
            echo json_encode(['failed' => $failed, 'valid' => $valid, 'count' => count($saved), 'original_preserved' => isset($saved[array_key_first($data)]), 'within_size_bound' => filesize(DESKTOP_AUTH_RATE_FILE) <= 524288]);
        """)
        self.assertEqual(result, {"failed": False, "valid": True, "count": 3048, "original_preserved": True, "within_size_bound": True})

    def test_cursor_polling_pages_backlog_without_skipping_expired_records(self):
        result = self.php("""
            $events = [];
            for ($i = 0; $i < 8; $i++) {
                $events[] = ['id' => 'event-' . $i, 'kind' => 'alert', 'desktop_recipients' => ['alice'], 'desktop_client_ids' => ['alice'=>'device-a'], 'expires' => $i % 2 ? '2027-01-16T09:00:00Z' : '2000-01-01T00:00:00Z'];
            }
            $first = desktop_poll_events_after_cursor($events, 'alice', '@sls:empty', 2, 1800000000);
            $second = desktop_poll_events_after_cursor($events, 'alice', $first['last_event_id'], 2, 1800000000);
            $third = desktop_poll_events_after_cursor($events, 'alice', $second['last_event_id'], 2, 1800000000);
            echo json_encode([$first, $second, $third]);
        """)
        self.assertEqual([event["id"] for event in result[0]["events"]], ["event-1", "event-3"])
        self.assertEqual(result[0]["last_event_id"], "event-3")
        self.assertTrue(result[0]["has_more"])
        self.assertEqual([event["id"] for event in result[1]["events"]], ["event-5", "event-7"])
        self.assertFalse(result[1]["has_more"])
        self.assertEqual(result[2]["events"], [])

    def test_event_identifier_boundary_matches_writer_poll_cursor_and_ack(self):
        result = self.php("""
            $identifier = str_repeat('a', MAX_EVENT_ID_BYTES);
            $event = ['id' => $identifier, 'kind' => 'announcement', 'created_at' => '2027-01-15T08:00:00+00:00',
                'desktop_recipients' => ['alice'], 'desktop_client_ids' => ['alice' => 'device-a']];
            $batch = desktop_poll_events_after_cursor([$event], 'alice', '@sls:empty', 1, 1800000000, 'device-a');
            $ack = record_desktop_acknowledgement(['log_retention_days' => 90],
                ['username' => 'alice', 'client_id' => 'device-a'], $event, 1800000000);
            echo json_encode(['delivered_length' => strlen($batch['events'][0]['id'] ?? ''), 'cursor' => desktop_event_id_valid($identifier),
                'too_long' => desktop_event_id_valid($identifier . 'a'), 'control' => desktop_event_id_valid("bad\nvalue"),
                'padded' => desktop_event_id_valid(' padded'), 'ack' => $ack]);
        """)
        self.assertEqual(result, {"delivered_length": 2048, "cursor": True, "too_long": False,
                                  "control": False, "padded": False, "ack": True})

    def test_cursor_polling_preserves_all_250_burst_events_and_snapshot_reports_truncation(self):
        result = self.php("""
            $events = [];
            for ($i = 0; $i < 250; $i++) {
                $events[] = ['id' => 'event-' . $i, 'kind' => 'announcement',
                    'created_at' => '2027-01-15T08:00:00+00:00', 'desktop_recipients' => ['alice'], 'desktop_client_ids' => ['alice'=>'device-a'],
                    'display_timeout_seconds' => 300, 'display_expires_at' => '2027-01-15T08:05:00+00:00'];
                $events[] = ['id' => 'private-' . $i, 'kind' => 'announcement', 'desktop_recipients' => ['bob']];
            }
            $snapshot = desktop_poll_snapshot($events, 'alice', 100, 1800000000);
            $cursor = '@sls:empty'; $received = []; $counts = [];
            do {
                $page = desktop_poll_events_after_cursor($events, 'alice', $cursor, 100, 1800000000);
                $counts[] = count($page['events']);
                $received = array_merge($received, $page['events']);
                $cursor = $page['last_event_id'];
            } while ($page['has_more']);
            $repeat = desktop_poll_snapshot($events, 'alice', 100, 1800000001);
            echo json_encode(['snapshot' => $snapshot, 'counts' => $counts,
                'ids' => array_column($received, 'id'), 'stable_payloads' => $snapshot['events'] === $repeat['events']]);
        """)
        self.assertEqual(result["counts"], [100, 100, 50])
        self.assertEqual(result["ids"], [f"event-{index}" for index in range(250)])
        self.assertTrue(result["stable_payloads"])
        self.assertTrue(result["snapshot"]["window_truncated"])
        self.assertFalse(result["snapshot"]["has_more"])
        self.assertEqual(result["snapshot"]["eligible_count"], 250)
        self.assertEqual(result["snapshot"]["events"][0]["id"], "event-150")

    def test_delivery_expiry_does_not_delete_audit_record(self):
        result = self.php("""
            $event = ['id' => 'expired', 'kind' => 'announcement', 'created_at' => gmdate('c', time() - 60),
                'desktop_recipients' => ['alice'], 'desktop_client_ids' => ['alice'=>'device-a'], 'display_timeout_seconds' => 30,
                'display_expires_at' => gmdate('c', time() - 30)];
            file_put_contents(EVENTS_FILE, json_encode($event) . "\n");
            $journal = retained_events([]);
            $snapshot = desktop_poll_snapshot($journal, 'alice', 100);
            $saved = json_decode(trim(file_get_contents(EVENTS_FILE)), true);
            echo json_encode(['delivered' => $snapshot['events'], 'retained' => count($journal),
                'unchanged' => $saved === $event]);
        """)
        self.assertEqual(result, {"delivered": [], "retained": 1, "unchanged": True})

    def test_fractional_timestamp_contract_accepts_real_dates_and_rejects_ambiguity(self):
        valid = ['2027-01-15T08:00:00Z', '2027-01-15T08:00:00.1Z',
                 '2027-01-15T08:00:00.123Z', '2027-01-15T08:00:00.473064-05:00',
                 '2027-01-15T08:00:00.123456789+14:00', '2027-01-15T08:00:00.000001-13:59']
        invalid = ['2027-02-30T08:00:00.123456Z', '2027-01-15T08:00:60.123Z',
                   '2027-01-15T08:00:00.1234567890Z', '2027-01-15T08:00:00.123',
                   '2027-01-15T08:00:00.Z', '2027-01-15T08:00:00.123+14:01',
                   '2027-01-15T08:00:00.123-00:00', '2027-01-15T08:00:00.123Z\n']
        values = json.dumps(json.dumps(valid + invalid))
        result = self.php('echo json_encode(array_map("desktop_timestamp", json_decode(' + values + ', true)));')
        self.assertEqual(result, [int(datetime.fromisoformat(value.replace('Z', '+00:00')).timestamp())
                                  for value in valid] + [None] * len(invalid))

    def test_malformed_expiry_fails_closed_and_impossible_created_at_is_corrupt(self):
        result = self.php("""
            $announcement=['kind'=>'announcement','display_timeout_seconds'=>30,
                'display_expires_at'=>'2027-02-30T08:00:00Z'];
            $alert=['kind'=>'alert','expires'=>'2027-02-30T08:00:00Z'];
            file_put_contents(EVENTS_FILE,json_encode(['id'=>'bad-date','created_at'=>'2027-02-30T08:00:00Z'])."\\n");
            ob_start(); retained_events([]);
        """)
        self.assertEqual(result["error"], "journal_corrupt")

        result = self.php("""
            echo json_encode(['announcement'=>announcement_display_expired(
                ['kind'=>'announcement','display_timeout_seconds'=>30,'display_expires_at'=>'2027-02-30T08:00:00Z']),
                'alert'=>desktop_event_display_expired(['kind'=>'alert','expires'=>'2027-02-30T08:00:00Z'])]);
        """)
        self.assertEqual(result, {"announcement": True, "alert": True})

    def test_malformed_routing_cannot_expand_or_crash_authorization(self):
        result = self.php("""
            $events=[
                ['id'=>'all-string','kind'=>'announcement','desktop_all'=>'1','desktop_client_ids'=>['alice'=>'device-a']],
                ['id'=>'bad-bindings','kind'=>'announcement','desktop_all'=>true,'desktop_client_ids'=>'device-a'],
                ['id'=>'bad-recipients','kind'=>'announcement','desktop_recipients'=>'alice','desktop_client_ids'=>['alice'=>'device-a']],
                ['id'=>['array-id'],'kind'=>'announcement','desktop_all'=>true,'desktop_client_ids'=>['alice'=>'device-a']],
            ];
            echo json_encode(desktop_poll_snapshot($events,'alice',100,null,'device-a'));
        """)
        self.assertEqual(result["events"], [])
        self.assertEqual(result["eligible_count"], 0)

    def test_journal_ack_and_stream_slots_reject_writable_parent(self):
        self.directory.chmod(0o777)
        result = self.php("""
            $atomic=atomic_replace_journal(EVENTS_FILE,"{}\\n");
            $ack=record_desktop_acknowledgement([],['client_id'=>'device-a','username'=>'alice'],['id'=>'event-a']);
            echo json_encode(['atomic'=>$atomic,'ack'=>$ack,'events'=>file_exists(EVENTS_FILE),
                'acks'=>is_dir(DESKTOP_ACK_DIRECTORY)]);
        """)
        self.assertEqual(result, {"atomic": False, "ack": False, "events": False, "acks": False})
        result = self.php("desktop_stream_slot('alice');")
        self.assertEqual(result["error"], "stream_capacity_unavailable")

    def test_stream_capacity_preserves_bound_and_advertises_poll_fallback(self):
        result = self.php("""
            $slots = [];
            for ($i = 0; $i < 32; $i++) {
                $slots[] = desktop_stream_slot('client-' . $i);
            }
            desktop_stream_slot('client-33');
        """)
        self.assertEqual(result["error"], "stream_capacity_reached")
        self.assertEqual(result["fallback"]["transport"], "json_poll")
        self.assertEqual(result["fallback"]["cursor_parameter"], "last_event_id")

    def test_stream_slot_directory_created_by_another_request_is_accepted(self):
        self.prefix = self.prefix.replace(
            "!@mkdir($directory, 0750)", "!@fixture_raced_mkdir($directory, 0750)", 1
        )
        result = self.php("""
            function fixture_raced_mkdir(string $path, int $mode): bool {
                // A second request wins creation between is_dir() and mkdir().
                mkdir($path, $mode);
                return false;
            }
            $slots = desktop_stream_slot('alice');
            $held = count($slots); desktop_stream_release_slots($slots);
            echo json_encode(['held'=>$held, 'released'=>count($slots)]);
        """)
        self.assertEqual(result, {'held': 2, 'released': 0})

    def test_stream_directory_race_still_rejects_a_linked_replacement(self):
        self.prefix = self.prefix.replace(
            "!@mkdir($directory, 0750)", "!@fixture_raced_mkdir($directory, 0750)", 1
        )
        result = self.php("""
            function fixture_raced_mkdir(string $path, int $mode): bool {
                $replacement=dirname($path).'/replacement';mkdir($replacement,$mode);
                symlink($replacement,$path);
                return false;
            }
            desktop_stream_slot('alice');
        """)
        self.assertEqual(result, {'ok': False, 'error': 'stream_capacity_unavailable'})

    def test_stream_slots_skip_special_or_linked_files_without_changing_them(self):
        result = self.php("""
            $directory = dirname(EVENTS_FILE) . '/stream-slots';
            mkdir($directory, 0750);
            $target = dirname(EVENTS_FILE) . '/unrelated';
            file_put_contents($target, 'preserve'); chmod($target, 0600);
            $client = $directory . '/client-' . hash('sha256', 'alice');
            link($target, $client . '-0.lock');
            symlink($target, $directory . '/global-0.lock');
            posix_mkfifo($directory . '/global-1.lock', 0600);
            $slots = desktop_stream_slot('alice');
            $held = count($slots); desktop_stream_release_slots($slots);
            clearstatcache(true, $target);
            echo json_encode(['held' => $held, 'released' => count($slots),
                'data' => file_get_contents($target), 'mode' => fileperms($target) & 0777,
                'special_preserved' => filetype($directory . '/global-1.lock') === 'fifo']);
        """)
        self.assertEqual(result, {"held": 2, "released": 0, "data": "preserve",
                                  "mode": 0o600, "special_preserved": True})

    def test_legacy_json_endpoint_filters_expiry_without_reordering_history(self):
        result = self.php(self.authenticated_endpoint_setup() + """
            $events = [
                ['id' => 'old', 'kind' => 'alert', 'expires' => '2000-01-01T00:00:00Z', 'desktop_all' => true, 'desktop_client_ids' => ['alice'=>'device-a']],
                ['id' => 'first', 'kind' => 'announcement', 'desktop_all' => true, 'desktop_client_ids' => ['alice'=>'device-a']],
                ['id' => 'second', 'kind' => 'announcement', 'desktop_recipients' => ['alice'], 'desktop_client_ids' => ['alice'=>'device-a']],
                ['id' => 'private', 'kind' => 'announcement', 'desktop_recipients' => ['bob']],
            ];
            file_put_contents(EVENTS_FILE, implode("\n", array_map('json_encode', $events)) . "\n");
            $_GET = ['limit' => '1'];
        """, endpoint=True)
        self.assertTrue(result["ok"])
        self.assertEqual([item["id"] for item in result["events"]], ["second"])
        self.assertEqual(result["latest"]["id"], "second")
        self.assertEqual(result["last_event_id"], "second")
        self.assertTrue(result["window_truncated"])
        self.assertEqual(result["eligible_count"], 2)
        self.assertEqual(result["limit"], 1)
        self.assertEqual(result["cursor_parameter"], "last_event_id")

    def test_resumable_json_endpoint_advances_expired_cursor_and_preserves_backlog(self):
        result = self.php(self.authenticated_endpoint_setup() + """
            $events = [
                ['id' => 'old', 'kind' => 'alert', 'expires' => '2000-01-01T00:00:00Z', 'desktop_all' => true, 'desktop_client_ids' => ['alice'=>'device-a']],
                ['id' => 'first', 'kind' => 'announcement', 'desktop_all' => true, 'desktop_client_ids' => ['alice'=>'device-a']],
                ['id' => 'second', 'kind' => 'announcement', 'desktop_recipients' => ['alice'], 'desktop_client_ids' => ['alice'=>'device-a']],
            ];
            file_put_contents(EVENTS_FILE, implode("\n", array_map('json_encode', $events)) . "\n");
            $_GET = ['limit' => '1', 'last_event_id' => 'old'];
        """, endpoint=True)
        self.assertEqual([item["id"] for item in result["events"]], ["first"])
        self.assertEqual(result["last_event_id"], "first")
        self.assertTrue(result["has_more"])

    def test_endpoint_valid_credentials_survive_failed_attempt_exhaustion(self):
        result = self.php(self.authenticated_endpoint_setup() + """
            for ($i = 0; $i < 150; $i++) {
                desktop_auth_attempt_allowed('192.0.2.2', 'alice', false);
            }
            file_put_contents(EVENTS_FILE, '');
        """, endpoint=True)
        self.assertTrue(result["ok"])
        self.assertEqual(result["client"]["client_id"], "device-a")

    def test_endpoint_blocks_exhausted_source_even_if_next_guess_is_correct(self):
        result = self.php(self.authenticated_endpoint_setup() + """
            for ($i = 0; $i < 20; $i++) {
                desktop_auth_attempt_allowed('192.0.2.1', 'alice', false);
            }
            file_put_contents(EVENTS_FILE, '');
        """, endpoint=True)
        self.assertEqual(result["error"], "rate_limited")
        self.assertIn(result["retry_after_seconds"], range(1, 61))

    def test_missing_cursor_rebaselines_without_replaying_missed_events(self):
        result = self.php("""
            $events = [
                ['id'=>'missed-a','kind'=>'announcement','desktop_recipients'=>['alice'],'desktop_client_ids'=>['alice'=>'device-a']],
                ['id'=>'missed-b','kind'=>'announcement','desktop_recipients'=>['alice'],'desktop_client_ids'=>['alice'=>'device-a']],
            ];
            echo json_encode(desktop_poll_events_after_cursor($events,'alice','expired-cursor',100,null,'device-a'));
        """)
        self.assertEqual(result["events"], [])
        self.assertTrue(result["cursor_gap"])
        self.assertEqual(result["last_event_id"], "missed-b")

    def test_ack_is_durable_per_event_client_and_idempotent(self):
        result = self.php("""
            $client = ['client_id' => 'device-a', 'username' => 'alice'];
            $other = ['client_id' => 'device-b', 'username' => 'bob'];
            $first = ['id' => 'event-a', 'kind' => 'announcement', 'created_at' => '2027-01-15T08:00:00Z'];
            $second = ['id' => 'event-b', 'kind' => 'alert'];
            $ok = record_desktop_acknowledgement([], $client, $first, 1800000000)
                && record_desktop_acknowledgement([], $client, $second, 1800000001)
                && record_desktop_acknowledgement([], $client, $first, 1800000100)
                && record_desktop_acknowledgement([], $other, $first, 1800000101);
            $stores = [];
            foreach (glob(DESKTOP_ACK_DIRECTORY . '/*.json') as $path) {
                $data = json_decode(file_get_contents($path), true);
                $stores[$data['username']] = $data;
            }
            echo json_encode(['ok' => $ok, 'stores' => $stores]);
        """)
        self.assertTrue(result["ok"])
        alice = result["stores"]["alice"]["acknowledgements"]
        bob = result["stores"]["bob"]["acknowledgements"]
        self.assertEqual(len(alice), 2)
        self.assertEqual(len(bob), 1)
        original = next(item for item in alice.values() if item["event_id"] == "event-a")
        self.assertEqual(original["ack_at"], "2027-01-15T08:00:00+00:00")
        self.assertEqual(original["receipt_type"], "client_acknowledgement")

    def test_ack_corrupt_history_is_preserved_and_failure_is_reported(self):
        result = self.php("""
            mkdir(DESKTOP_ACK_DIRECTORY, 0750);
            $path = DESKTOP_ACK_DIRECTORY . '/' . hash('sha256', 'id:device-a') . '.json';
            file_put_contents($path, '{corrupt');
            $ok = record_desktop_acknowledgement([], ['client_id' => 'device-a', 'username' => 'alice'], ['id' => 'event-a']);
            echo json_encode(['ok' => $ok, 'raw' => file_get_contents($path)]);
        """)
        self.assertEqual(result, {"ok": False, "raw": "{corrupt"})

    def test_ack_rejects_linked_storage_without_modifying_target(self):
        result = self.php("""
            mkdir(DESKTOP_ACK_DIRECTORY, 0750);
            $path = DESKTOP_ACK_DIRECTORY . '/' . hash('sha256', 'id:device-a') . '.json';
            $target = dirname(DESKTOP_ACK_DIRECTORY) . '/unrelated.json';
            file_put_contents($target, '{"preserve":true}');
            symlink($target, $path);
            $linked = record_desktop_acknowledgement([], ['client_id' => 'device-a', 'username' => 'alice'], ['id' => 'event-a']);
            unlink($path);
            link($target, $path);
            $hardlinked = record_desktop_acknowledgement([], ['client_id' => 'device-a', 'username' => 'alice'], ['id' => 'event-a']);
            echo json_encode(['linked' => $linked, 'hardlinked' => $hardlinked, 'raw' => file_get_contents($target)]);
        """)
        self.assertEqual(result, {"linked": False, "hardlinked": False, "raw": '{"preserve":true}'})

    def test_ack_retention_and_capacity_are_bounded(self):
        result = self.php("""
            mkdir(DESKTOP_ACK_DIRECTORY, 0750);
            $path = DESKTOP_ACK_DIRECTORY . '/' . hash('sha256', 'id:device-a') . '.json';
            $acks = ['old' => ['event_id' => 'old', 'ack_at' => gmdate('c', 1800000000 - 86401)]];
            for ($i = 0; $i < 1000; $i++) {
                $acks[hash('sha256', 'event-' . $i)] = ['event_id' => 'event-' . $i, 'ack_at' => gmdate('c', 1800000000)];
            }
            file_put_contents($path, json_encode(['schema' => 1, 'acknowledgements' => $acks]));
            $ok = record_desktop_acknowledgement(['log_retention_days' => 1], ['client_id' => 'device-a', 'username' => 'alice'], ['id' => 'new'], 1800000001);
            $data = json_decode(file_get_contents($path), true)['acknowledgements'];
            echo json_encode(['ok' => $ok, 'count' => count($data), 'old' => isset($data['old']), 'first' => isset($data[hash('sha256', 'event-0')]), 'new' => isset($data[hash('sha256', 'new')])]);
        """)
        self.assertEqual(result, {"ok": True, "count": 1000, "old": False, "first": False, "new": True})

    def test_ack_endpoint_rejects_another_clients_event(self):
        # CLI cannot populate php://input directly; a substituted input reader
        # still exercises authorization, routing, persistence, and response code.
        self.endpoint = self.endpoint.replace(
            "file_get_contents('php://input', false, null, 0, 4097)",
            "json_encode(['event_id' => 'private-event'])",
        )
        result = self.php(self.authenticated_endpoint_setup("desktop/ack", "POST") + """
            file_put_contents(EVENTS_FILE, json_encode(['id' => 'private-event', 'kind' => 'announcement', 'desktop_recipients' => ['bob'], 'created_at' => gmdate('c')]) . "\n");
        """, endpoint=True)
        self.assertEqual(result["error"], "unknown_or_unauthorized_event")
        self.assertFalse((self.directory / "acks").exists())

    def test_ack_endpoint_persists_routed_event_before_success(self):
        self.endpoint = self.endpoint.replace(
            "file_get_contents('php://input', false, null, 0, 4097)",
            "json_encode(['event_id' => 'routed-event'])",
        )
        result = self.php(self.authenticated_endpoint_setup("desktop/ack", "POST") + """
            file_put_contents(EVENTS_FILE, json_encode(['id' => 'routed-event', 'kind' => 'announcement', 'desktop_recipients' => ['alice'], 'desktop_client_ids' => ['alice'=>'device-a'], 'created_at' => gmdate('c')]) . "\n");
        """, endpoint=True)
        self.assertTrue(result["ok"])
        stored = list((self.directory / "acks").glob("*.json"))
        self.assertEqual(len(stored), 1)
        acks = json.loads(stored[0].read_text())["acknowledgements"]
        self.assertEqual([item["event_id"] for item in acks.values()], ["routed-event"])


if __name__ == "__main__":
    unittest.main()
