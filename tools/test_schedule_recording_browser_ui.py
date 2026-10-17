#!/usr/bin/env python3
"""Optional real Chromium checks for calendar and recording editors in disposable storage."""
import json
import os
import re
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import unittest

ROOT=Path(__file__).resolve().parents[1]
if os.environ.get('SLS_TEST_NAMESPACE')!='entered' or os.environ.get('SLS_TEST_PARENT_NET_NS') in (None, '', os.readlink('/proc/self/ns/net')):
    raise SystemExit('Use the isolated fixture runner; no browser was started.')
driver=os.environ.get('SLS_UI_PYTHON_PATH');browser=os.environ.get('SLS_UI_CHROMIUM')
if not driver or not browser or not Path(browser).is_file():
    print('SKIPPED optional calendar/recording browser checks: supply preinstalled SLS_UI_PYTHON_PATH and SLS_UI_CHROMIUM. No downloads or browser checks ran.')
    raise SystemExit(1 if os.environ.get('SLS_UI_REQUIRED') == '1' else 0)
sys.path.insert(0,driver)
from playwright.sync_api import sync_playwright

class CalendarRecordingBrowser(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workspace=tempfile.TemporaryDirectory(prefix='sls-calendar-browser-');cls.addClassCleanup(cls.workspace.cleanup);cls.root=Path(cls.workspace.name)
        sounds=cls.root/'sounds/custom';sounds.mkdir(parents=True)
        for index in range(205):(sounds/f'fixture-{index:03}.wav').write_bytes(b'RIFF fixture catalogue bytes')
        source=r'''<?php
if (!interface_exists('BMO')) { interface BMO {} }
require MODULE.'/Slsmassnotifyserver.class.php';
if (parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/modules/slsmassnotifyserver/views/audience_source_picker.js') {
 header('Content-Type: application/javascript');readfile(MODULE.'/views/audience_source_picker.js');return;
}
function load_view($path,$variables=[]){extract($variables);ob_start();include $path;return ob_get_clean();}
function weatherPage(){
 $settings=['enabled'=>'1','nws_zones'=>[],'discord_webhooks'=>[],'generic_webhooks'=>[],
  'nws_opening_tone'=>'recording_'.str_repeat('a',32),'nws_closing_tone'=>'recording_'.str_repeat('b',32),
  'location_directory'=>['schema'=>1,'default_site_id'=>'loc_'.str_repeat('e',24),'nodes'=>[['id'=>'loc_'.str_repeat('e',24),'type'=>'site','parent_id'=>'','name'=>'Fixture site','description'=>'','members'=>[]]]]];
 $available_tones=['opening_NWS_alert','closing_fixture',$settings['nws_opening_tone'],$settings['nws_closing_tone']];
 $weather_voice_recipients=[['id'=>'voice_'.str_repeat('c',24),'name'=>'Fixture call','enabled'=>true]];
 $weather_sms_recipients=[['id'=>'sms_'.str_repeat('d',24),'name'=>'Fixture SMS','enabled'=>true]];
 $csrf_token=str_repeat('b',64);$hero_image='';$show_test_section=false;$available_system_sounds=[];
 include MODULE.'/views/settings.php';
}
function lightningPage(){
 $settings=['enabled'=>'1','xweather'=>['provider'=>'xweather','adaptive_free_tier'=>'1','query_interval_minutes'=>2,'groups'=>[]],'nws_zones'=>[]];
 $hero_image='';$csrf_token=str_repeat('b',64);$available_tones=['opening_Lightning_alert'];
 include MODULE.'/views/lightning.php';
}
function setupPage(){
 $settings=['enabled'=>'0','xweather'=>['enabled'=>'1','provider'=>'xweather','adaptive_free_tier'=>'1','query_interval_minutes'=>2,'groups'=>[]],'nws_zones'=>[]];
 $hero_image='';$csrf_token=str_repeat('b',64);$available_tones=['opening_Lightning_alert'];
 include MODULE.'/views/setup.php';
}
function schedulingPage(){
 $settings=['announcement_webhooks'=>[]];$hero_image='';$csrf_token=str_repeat('b',64);$pbx_timezone='America/Chicago';
 $scheduled_announcements=[['id'=>'sched_fixture','name'=>'Browser-only fixture','enabled'=>true,
  'targets'=>['browser_recipient_ids'=>['sub_'.str_repeat('a',24)],'connector_ids'=>['conn_'.str_repeat('b',24)],'webhook_ids'=>['webhook_fixture']],
  'occurrences'=>[['run_at_utc'=>'2099-06-03T09:00:00+00:00']],'delivery'=>['audio_mode'=>'none']]];
 ?><style>.modal{display:none}.modal[aria-hidden=false]{display:block}</style><script>
 window.jQuery=function(selector){return {modal:function(){document.querySelector(selector).setAttribute('aria-hidden','false');}};};
 </script><?php include MODULE.'/views/scheduling.php';
}
function browserPage(){
$programmeSettings=\SLS\MassNotify\ScheduleProgrammes::normalize(file_exists(__DIR__.'/saved.json')?json_decode(file_get_contents(__DIR__.'/saved.json'),true):['enabled'=>true,'calendars'=>[
['id'=>'cal_'.str_repeat('a',24),'name'=>'A/B bell calendar','timezone'=>'America/Chicago','cycle_start'=>'2030-01-01','cycle_mode'=>'school_days','day_types'=>['A','B'],
 'date_types'=>[],'calendar'=>['until'=>'2030-12-31','weekdays'=>[1,2,3,4,5],'exclusions'=>[],'overrides'=>[]]]]]);
$schedule_programmes=$programmeSettings;$active_schedule_programmes=$programmeSettings;$csrf_token=str_repeat('b',64);$timezoneName='America/Chicago';
$labels=[];for($index=0;$index<205;$index++){$labels['custom/fixture-'.sprintf('%03d',$index)]='Fixture recording '.sprintf('%03d',$index);}
$recording_catalogue=\SLS\MassNotify\SystemRecordings::cataloguePage(__DIR__.'/sounds',$labels,'',0,100);$available_system_sounds=$recording_catalogue['items'];
?><!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>
*{box-sizing:border-box}body{margin:0;padding:14px;font:14px Arial,sans-serif}.shell{max-width:1100px;margin:auto}.btn{padding:6px 12px;display:inline-block;border:1px solid #ccc;border-radius:4px;cursor:pointer}.form-control{display:block;width:100%;height:34px;padding:6px 8px;border:1px solid #ccc;border-radius:4px;font:14px Arial}.help-block{line-height:1.5;color:#64748b}h5{margin:16px 0 8px}input[type=checkbox]{margin-right:5px}[hidden]{display:none!important}
</style></head><body><main class="shell"><?php include MODULE.'/views/schedule_programmes.php';?>
<form id="sls-schedule-form"><input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo $csrf_token;?>"><input type="hidden" id="sls-schedule-id" value="">
<label>Repeat<select id="sls-schedule-recurrence" class="form-control"><option value="none">Does not repeat</option><option value="calendar">Calendar</option></select></label>
<div id="sls-occurrence-list"><input class="form-control" name="schedule_occurrences[]" type="datetime-local" value="2030-06-03T09:00"></div>
<?php include MODULE.'/views/schedule_calendar.php';?><button type="submit" id="save-schedule" class="btn">Save schedule</button></form>
<label>Opening recording<select id="recording-select" class="form-control"><option value="">None</option><?php include MODULE.'/views/system_recording_options.php';?></select></label>
<script>document.getElementById('sls-schedule-form').addEventListener('submit',event=>event.preventDefault());</script>
</main></body></html><?php }
if ($_SERVER['REQUEST_METHOD']==='POST') {
 if (($_POST['slsmassnotifyserver_csrf']??'')!==str_repeat('b',64)) {http_response_code(403);echo '{}';return;}
 $action=$_POST['slsmassnotifyserver_action']??'';file_put_contents(__DIR__.'/action.json',json_encode($_POST));
 try {
  if($action==='save_schedule_programmes') {$configuration=\SLS\MassNotify\ScheduleProgrammes::normalize(json_decode($_POST['programmes_json'],true,32,JSON_THROW_ON_ERROR));file_put_contents(__DIR__.'/saved.json',json_encode($configuration));header('Location: /');return;}
  if($action==='recording_catalogue') {$labels=[];for($i=0;$i<205;$i++){$labels['custom/fixture-'.sprintf('%03d',$i)]='Fixture recording '.sprintf('%03d',$i);}$answer=['success'=>true]+\SLS\MassNotify\SystemRecordings::cataloguePage(__DIR__.'/sounds',$labels,$_POST['query'],(int)$_POST['offset'],100);}
  elseif($action==='preview_schedule_calendar') {
   $configuration=\SLS\MassNotify\ScheduleProgrammes::normalize(file_exists(__DIR__.'/saved.json')?json_decode(file_get_contents(__DIR__.'/saved.json'),true):['enabled'=>true,'calendars'=>[['id'=>'cal_'.str_repeat('a',24),'name'=>'Fixture','timezone'=>'America/Chicago','cycle_start'=>'2030-01-01','cycle_mode'=>'school_days','day_types'=>['A','B'],'date_types'=>[],'calendar'=>['until'=>'2030-06-14','weekdays'=>[1,2,3,4,5],'exclusions'=>[],'overrides'=>[]]]]]);
   $calendar=$_POST['schedule_programme_id']?\SLS\MassNotify\ScheduleProgrammes::snapshot($configuration,$_POST['schedule_programme_id'],$_POST['schedule_programme_types']):json_decode($_POST['schedule_calendar_json'],true);
   $class=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);$instance=$class->newInstanceWithoutConstructor();$resolver=$class->getMethod('resolveScheduleLocalDateTime');
   $answer=\SLS\MassNotify\ScheduleCalendar::build('sch_fixture',$_POST['schedule_occurrences'],$calendar,new DateTimeZone('America/Chicago'),[],0,PHP_INT_MAX,366,fn($local,$zone)=>$resolver->invoke($instance,$local,$zone));$answer['success']=!$answer['errors'];$answer['timezone']='America/Chicago';
  } else {throw new DomainException('Unexpected fixture request');}
 } catch(Throwable $error){$answer=['success'=>false,'message'=>$error->getMessage()];http_response_code(400);}
 header('Content-Type: application/json');echo json_encode($answer);return;
}if(($_GET['weather']??'')==='1'){weatherPage();}elseif(($_GET['lightning']??'')==='1'){lightningPage();}elseif(($_GET['setup']??'')==='1'){setupPage();}elseif(($_GET['scheduling']??'')==='1'){schedulingPage();}else{browserPage();}
'''.replace('MODULE',json.dumps(str(ROOT/'slsmassnotifyserver')))
        (cls.root/'index.php').write_text(source)
        subprocess.run(['php','-l',str(cls.root/'index.php')],check=True,capture_output=True)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        cls.url=f'http://127.0.0.1:{port}'
        cls.log=(cls.root/'php.log').open('w');cls.addClassCleanup(cls.log.close)
        cls.server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}',str(cls.root/'index.php')],cwd=cls.root,stdout=cls.log,stderr=cls.log)
        def stop_server():
            if cls.server.poll() is None:
                cls.server.terminate()
                try:cls.server.wait(timeout=5)
                except subprocess.TimeoutExpired:cls.server.kill();cls.server.wait(timeout=5)
        cls.addClassCleanup(stop_server)
        deadline=time.monotonic()+5
        while True:
            if cls.server.poll() is not None:
                cls.log.flush();raise RuntimeError('Disposable PHP UI server stopped during startup: '+(cls.root/'php.log').read_text()[-2000:])
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.2):break
            except OSError:
                if time.monotonic()>=deadline:raise RuntimeError('Disposable PHP UI server did not become ready within five seconds.')
                time.sleep(.05)
        cls.playwright=sync_playwright().start();cls.addClassCleanup(cls.playwright.stop)
        cls.browser=cls.playwright.chromium.launch(executable_path=browser,headless=True,args=['--no-sandbox','--disable-dev-shm-usage']);cls.addClassCleanup(cls.browser.close)
    def setUp(self):
        (self.root/'saved.json').unlink(missing_ok=True);(self.root/'action.json').unlink(missing_ok=True)
        self.context=self.browser.new_context(viewport={'width':1440,'height':1000});self.page=self.context.new_page();self.errors=[]
        self.page.on('pageerror',lambda error:self.errors.append(str(error)))
        self.page.goto(self.url);self.assertEqual(self.page.locator('#sls-shared-calendars').count(),1,self.page.content()[:1000])

    def test_weather_added_zones_serialize_sites_and_optional_channels(self):
        response=self.page.goto(self.url+'/?weather=1')
        self.assertEqual(response.status,200,self.page.content()[:2000])
        self.assertEqual(self.page.locator('.sls-nws-page').count(),1,self.page.content()[:2000])
        self.assertEqual(self.page.locator('#sls-zone-add').count(),1,(self.root/'php.log').read_text()[-2000:])
        self.page.locator('#sls-zone-manager').evaluate('(node)=>node.setAttribute("aria-hidden","false")')
        self.page.get_by_role('button',name='Add Zone Group',exact=True).click()
        editor=self.page.locator('#sls-zone-editor-list [data-zone-editor]')
        editor.locator('[data-zone-field="name"]').fill('Fixture zone')
        editor.locator('[data-zone-field="zone"]').fill('TXZ163')
        editor.locator('[data-zone-field="site_id"]').select_option('loc_'+'e'*24)
        editor.locator('summary').filter(has_text='SMS and external calls').click()
        editor.locator('[data-zone-voice]').check()
        editor.locator('[data-zone-sms]').check()
        values=editor.evaluate('(node)=>Object.fromEntries(new FormData(node.closest("form")))')
        self.assertEqual(values['nws_zones[0][site_id]'],'loc_'+'e'*24)
        self.assertEqual(values['nws_zones[0][voice_recipient_ids][]'],'voice_'+'c'*24)
        self.assertEqual(values['nws_zones[0][sms_recipient_ids][]'],'sms_'+'d'*24)
        self.assertEqual(self.errors,[])

    def test_weather_imported_tones_remain_selected(self):
        response=self.page.goto(self.url+'/?weather=1')
        self.assertEqual(response.status,200,self.page.content()[:2000])
        self.assertEqual(self.page.locator('.sls-nws-page').count(),1,self.page.content()[:2000])
        self.assertEqual(self.page.locator('#nws_opening_tone').count(),1,(self.root/'php.log').read_text()[-2000:])
        self.assertEqual(self.page.locator('#nws_opening_tone').input_value(),'recording_'+'a'*32)
        self.assertEqual(self.page.locator('#nws_closing_tone').input_value(),'recording_'+'b'*32)
        self.assertEqual(self.errors,[])

    def test_lightning_preserves_period_and_limits_provider_choices(self):
        self.page.goto(self.url+'/?lightning=1')
        period=self.page.locator('#sls-lightning-query-interval')
        self.assertEqual(period.input_value(),'2')
        self.page.locator('#sls-lightning-adaptive').locator('..').click()
        self.page.locator('#sls-lightning-adaptive').locator('..').click()
        self.assertEqual(period.input_value(),'2')
        self.assertEqual(period.locator('option:not([disabled])').count(),4)
        self.page.locator('#sls-lightning-provider').select_option('tempest')
        period.select_option('10')
        self.page.locator('#sls-lightning-provider').select_option('xweather')
        self.assertEqual(period.input_value(),'4')
        self.assertEqual(self.errors,[])

    def test_setup_polling_choices_match_the_selected_provider(self):
        self.page.goto(self.url+'/?setup=1')
        period=self.page.locator('[name="xweather[query_interval_minutes]"]')
        self.assertEqual(period.input_value(),'2')
        self.assertEqual(period.locator('option:not([disabled])').count(),4)
        self.page.locator('#sls-setup-lightning-provider').select_option('meteomatics')
        period.select_option('10')
        self.page.locator('#sls-setup-lightning-provider').select_option('xweather')
        self.assertEqual(period.input_value(),'4')
        self.assertEqual(self.errors,[])
    def test_new_schedule_clears_webhook_editor_and_reports_labs_recipients(self):
        self.page.goto(self.url+'/?scheduling=1')
        row=self.page.locator('[data-schedule-id="sched_fixture"]').filter(has=self.page.locator('td')).first
        self.assertIn('1 browser subscriber(s)',row.inner_text())
        self.assertIn('1 connector(s)',row.inner_text())
        self.page.locator('.sls-schedule-edit').click()
        selector=self.page.locator('#sls-schedule-webhook-ids')
        self.assertEqual(selector.locator('input[type=checkbox]:checked').count(),1)
        self.assertEqual(selector.locator('[data-webhook-selected]').inner_text(),'1 selected')
        self.page.locator('#sls-schedule-modal').evaluate('(node)=>node.setAttribute("aria-hidden","true")')
        self.page.locator('#sls-schedule-create').click()
        self.assertEqual(selector.locator('[data-webhook-unavailable]').inner_text(),'')
        self.assertEqual(selector.locator('input[type=checkbox]:checked').count(),0)
        self.assertEqual(selector.locator('[data-webhook-selected]').inner_text(),'0 selected')
        self.assertEqual(self.errors,[])
    def tearDown(self):self.context.close()
    def bounds(self):
        self.assertLessEqual(self.page.evaluate('document.documentElement.scrollWidth'),self.page.viewport_size['width'])
    def test_shared_calendar_editor_save_reopen_and_mobile_bounds(self):
        page=self.page
        for width in [1440,390]:
            page.set_viewport_size({'width':width,'height':1000});page.locator('#sls-shared-calendars').evaluate('(n)=>n.open=true')
            page.locator('.sls-programme-card').first.evaluate('(n)=>n.open=true')
            fields=page.locator('.sls-programme-grid input');fields.nth(0).fill('X'*80)
            for text in ['Add holiday ranges','Add whole-day substitutions','Add time overrides']:page.get_by_role('button',name=text,exact=True).click()
            self.bounds()
            tables=page.locator('.sls-programme-table')
            tables.nth(0).locator('input').nth(0).fill('2030-07-04');tables.nth(0).locator('input').nth(1).fill('2030-07-04');tables.nth(0).locator('input').nth(2).fill('Holiday')
            tables.nth(1).locator('input').nth(0).fill('2030-06-03');tables.nth(1).locator('input').nth(1).fill('B')
            tables.nth(2).locator('input').nth(0).fill('2030-06-04');tables.nth(2).locator('input').nth(1).fill('10:30')
            if width==1440:
                for table in [tables.nth(0),tables.nth(1),tables.nth(2)]:table.get_by_role('button',name=re.compile('Remove')).first.click()
        with page.expect_navigation():page.get_by_role('button',name='Save shared calendars',exact=True).click()
        saved=json.loads((self.root/'saved.json').read_text());row=saved['calendars'][0]
        self.assertEqual(row['name'],'X'*80);self.assertEqual(row['date_types'],[{'date':'2030-06-03','day_type':'B'}]);self.assertEqual(row['calendar']['overrides'][0]['time'],'10:30')
        page.locator('#sls-shared-calendars').evaluate('(n)=>n.open=true');page.locator('.sls-programme-card').evaluate('(n)=>n.open=true');self.bounds()
        self.assertEqual(page.locator('.sls-programme-table').nth(1).locator('input').nth(1).input_value(),'B');self.assertEqual(self.errors,[])
    def test_shared_calendar_names_render_as_inert_text(self):
        attack='<img src=x onerror="window.__slsProgrammeXss=1">'
        self.page.locator('#sls-shared-calendars > summary').click()
        card=self.page.locator('.sls-programme-card').first
        card.locator(':scope > summary').click()
        name=card.locator('.sls-programme-grid input').first
        self.assertTrue(name.is_visible())
        name.fill(attack)
        self.assertEqual(card.locator('summary').text_content(),attack)
        self.assertEqual(card.locator('summary img').count(),0)
        self.assertIsNone(self.page.evaluate('window.__slsProgrammeXss'))
        self.assertEqual(self.errors,[])
    def test_shared_selection_review_and_custom_rules(self):
        page=self.page;page.locator('#sls-schedule-recurrence').select_option('calendar')
        page.locator('#sls-calendar-programme').select_option('cal_'+'a'*24)
        self.assertFalse(page.locator('#sls-calendar-custom-rules').is_visible());self.assertTrue(page.locator('#sls-calendar-until').is_disabled())
        page.locator('#sls-calendar-programme-types input[value=B]').uncheck()
        page.locator('#sls-calendar-preview').click();page.locator('#sls-calendar-preview-results').wait_for()
        self.assertGreater(page.locator('#sls-calendar-preview-results li').count(),0)
        request=json.loads((self.root/'action.json').read_text());self.assertEqual(request['schedule_programme_types'],['A']);self.assertEqual(request['schedule_calendar_json'],'{}')
        page.locator('#sls-calendar-programme').select_option('');page.locator('#sls-calendar-until').fill('2030-06-14')
        page.locator('#sls-calendar-add-exclusion').click();row=page.locator('#sls-calendar-exclusions input');row.nth(0).fill('2030-06-04');row.nth(1).fill('2030-06-04')
        for width in [1440,390]:page.set_viewport_size({'width':width,'height':1000});self.bounds()
        page.locator('#sls-calendar-preview').click();page.locator('#sls-calendar-preview-results').wait_for()
        self.assertNotIn('2030-06-04',page.locator('#sls-calendar-preview-results').inner_text());self.assertEqual(self.errors,[])
    def test_recording_pagination_search_and_selection_preservation(self):
        page=self.page;page.locator('#recording-select').select_option('system:custom/fixture-042.wav')
        browser=page.locator('.sls-recording-browser');browser.evaluate('(n)=>n.open=true')
        browser.get_by_role('button',name='Load next 100',exact=True).click()
        page.wait_for_function("document.querySelector('#recording-select optgroup').children.length===200")
        browser.locator('input').fill('recording 204');browser.get_by_role('button',name='Search',exact=True).click()
        page.wait_for_function("document.querySelector('#recording-select optgroup').children.length===2")
        self.assertEqual(page.locator('#recording-select').input_value(),'system:custom/fixture-042.wav')
        self.assertEqual(page.locator('#recording-select option[value="system:custom/fixture-204.wav"]').count(),1)
        self.assertFalse(browser.get_by_role('button',name='Load next 100',exact=True).is_visible())
        for width in [1440,390]:page.set_viewport_size({'width':width,'height':1000});self.bounds()
        self.assertEqual(self.errors,[])

if __name__=='__main__':unittest.main()
