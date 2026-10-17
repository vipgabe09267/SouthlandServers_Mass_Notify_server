#!/usr/bin/env python3
"""Exercise real response guards and an inert dashboard action in private PHP."""
import json
import os
from pathlib import Path
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[1]
HELPER=ROOT/'slsmassnotifyserver/AdminResponse.php'
PAGE=ROOT/'slsmassnotifyserver/page.slsmassnotifyserver.php'
OTHER=Path(os.environ.get('SLS_GENERAL_SETTINGS_FIXTURE_SOURCE',str(ROOT/'slsmassnotifyserver/page.slsmassnotifyserver_other.php')))


class AdminOutputBuffers(unittest.TestCase):
    def php(self,code):
        result=subprocess.run(['php','-r',code],capture_output=True,text=True,timeout=3)
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertEqual(result.stderr,'')
        return result.stdout

    def test_removable_nested_buffers_are_cleared(self):
        output=self.php('require '+json.dumps(str(HELPER))+r''';
            ob_start(); echo 'outer markup'; ob_start(); echo 'inner markup';
            $ok=\SLS\MassNotify\AdminResponse::clearBuffers();
            echo json_encode(['ok'=>$ok,'remaining'=>ob_get_level()]);
        ''')
        self.assertEqual(json.loads(output),{'ok':True,'remaining':0})

    def test_response_helper_does_not_load_control_api_security(self):
        output=self.php('require '+json.dumps(str(HELPER))+r''';
            echo json_encode([
                'helper'=>class_exists('SLS\\MassNotify\\AdminResponse',false),
                'api_security'=>class_exists('SLS\\MassNotify\\ApiSecurity',false),
            ]);
        ''')
        self.assertEqual(json.loads(output),{'helper':True,'api_security':False})

    def test_unremovable_buffer_is_detected_before_mutating_the_stack(self):
        output=self.php('require '+json.dumps(str(HELPER))+r''';
            ob_start(null,0,0); ob_start();
            $ok=\SLS\MassNotify\AdminResponse::clearBuffers();
            echo json_encode(['ok'=>$ok,'remaining'=>ob_get_level()]);
        ''')
        self.assertEqual(json.loads(output),{'ok':False,'remaining':2})

    def test_actual_dashboard_rejects_the_action_before_submission(self):
        code=r'''
            class FreePBX {
                public static function create() { return (object)['Slsmassnotifyserver'=>new class {
                    public function enforceOperatorPageAccess($page) {}
                    public function validateCsrfToken($token) { return true; }
                    public function sendSipNotifyAnnouncement(...$arguments) {
                        $GLOBALS['called']=true; return ['success'=>true];
                    }
                }]; }
            }
            $GLOBALS['called']=false; $_SESSION=[];
            $_SERVER=['REQUEST_METHOD'=>'POST']; $_POST=['slsmassnotifyserver_action'=>'send_announcement']; $_REQUEST=$_POST;
            register_shutdown_function(static function() { echo "\n".json_encode(['called'=>$GLOBALS['called'],'status'=>http_response_code()]); });
        '''
        for blocked in (False,True):
            with self.subTest(blocked=blocked):
                output=self.php(code+('ob_start(null,0,0);' if blocked else 'ob_start();')+'require '+json.dumps(str(PAGE))+';')
                response,state=map(json.loads,output.splitlines())
                self.assertEqual(state['called'],not blocked)
                if blocked:
                    self.assertEqual(state['status'],503)
                    self.assertEqual(response['error'],'response_buffer_unavailable')
                else:
                    self.assertTrue(response['success'])

    def test_actual_general_settings_json_discards_pbx_markup(self):
        for method in ('POST','GET'):
            with self.subTest(method=method):
                code=r'''
                    class FreePBX {
                        public static function create() { return (object)['Slsmassnotifyserver'=>new class {
                            public function enforceOperatorPageAccess($page) {}
                            public function validateCsrfToken($token) { return true; }
                            public function checkBulkvsSender() { return ['success'=>true,'message'=>'Inert sender check']; }
                            public function getManualUpdateProgress() { return ['state'=>'idle']; }
                        }]; }
                    }
                    $_SESSION=[]; $_SERVER=['REQUEST_METHOD'=>FIXTURE_METHOD_VALUE];
                    $_POST=['slsmassnotifyserver_action'=>'check_bulkvs_sender']; $_GET=['sls_update_status'=>'1'];
                    ob_start(); echo 'PBX page markup';
                '''.replace('FIXTURE_METHOD_VALUE',json.dumps(method))
                output=self.php(code+'require '+json.dumps(str(OTHER))+';')
                self.assertFalse(output.startswith('PBX page markup'),'PBX markup was prefixed to JSON: '+output[:120])
                response=json.loads(output)
                self.assertEqual(response,{'success':True,'message':'Inert sender check'} if method=='POST' else {'state':'idle'})

    def test_weather_lightning_and_calendar_responses_clear_before_work(self):
        pages = (
            ('nws', 'GET', 'cooldowns', 'cooldowns'),
            ('nws', 'POST', 'trigger_test', 'test'),
            ('nws', 'POST', 'test_delivery_status', 'receipt'),
            ('lightning', 'POST', 'test_lightning', 'test'),
            ('lightning', 'POST', 'test_delivery_status', 'receipt'),
            ('scheduling', 'POST', 'preview_schedule_calendar', 'calendar'),
        )
        stub = r'''
            class InertWeatherModule {
                public function enforceOperatorPageAccess($page) {}
                public function validateCsrfToken($token) { return true; }
                public function getCooldownState() { return ['fixture'=>0]; }
                public function getLightningTestCooldownState() { return ['fixture'=>0]; }
                public function triggerTest(...$args) { $GLOBALS['called']=true; return ['success'=>true,'kind'=>'test']; }
                public function triggerLightningTest(...$args) { return $this->triggerTest(); }
                public function getTestDeliveryStatus(...$args) { $GLOBALS['called']=true; return ['success'=>true,'kind'=>'receipt']; }
                public function previewScheduleCalendar(...$args) { $GLOBALS['called']=true; return ['success'=>true,'kind'=>'calendar']; }
            }
            class FreePBX {
                public static function create() { return (object)['Slsmassnotifyserver'=>new InertWeatherModule]; }
            }
            $GLOBALS['called']=false; $_SESSION=[];
            register_shutdown_function(static function() { echo "\n".json_encode(['called'=>$GLOBALS['called'],'status'=>http_response_code()]); });
        '''
        for page, method, action, expected in pages:
            for blocked in (False, True):
                with self.subTest(page=page, action=action, blocked=blocked):
                    setup = stub + '$_SERVER=["REQUEST_METHOD"=>' + json.dumps(method) + '];'
                    setup += '$_POST=["ajax"=>"1","slsmassnotifyserver_action"=>' + json.dumps(action) + '];$_REQUEST=$_POST;'
                    setup += 'ob_start(null,0,0);' if blocked else "ob_start();echo 'PBX page markup';"
                    setup += 'require ' + json.dumps(str(ROOT / ('slsmassnotifyserver/page.slsmassnotifyserver_' + page + '.php'))) + ';'
                    response, state = map(json.loads, self.php(setup).splitlines())
                    if blocked:
                        self.assertEqual(response['error'], 'response_buffer_unavailable')
                        self.assertEqual(state, {'called': False, 'status': 503})
                    else:
                        self.assertTrue(response['success'])
                        self.assertEqual(state['called'], expected != 'cooldowns')
                        if expected != 'cooldowns':
                            self.assertEqual(response['kind'], expected)


if __name__=='__main__': unittest.main()
