#!/usr/bin/env python3
"""Real Chromium submission to private PHP with its 1,000-input-variable limit."""
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import tempfile
import time

ROOT=Path(__file__).resolve().parents[1]
MODULE=ROOT/'slsmassnotifyserver'
if os.environ.get('SLS_TEST_NAMESPACE')!='entered' or os.environ.get('SLS_TEST_PARENT_NET_NS') in (None, '', os.readlink('/proc/self/ns/net')):
    raise SystemExit('Use the isolated fixture runner; no host listener is allowed.')
driver=os.environ.get('SLS_UI_PYTHON_PATH'); browser_path=os.environ.get('SLS_UI_CHROMIUM')
if not driver or not browser_path or not Path(browser_path).is_file():
    print('Browser recipient qualification unavailable: configure the preinstalled local driver and Chromium. No browser was downloaded.')
    raise SystemExit(1 if os.environ.get('SLS_UI_REQUIRED')=='1' else 0)
sys.path.insert(0,driver)
from playwright.sync_api import sync_playwright

view_source=Path(os.environ.get('SLS_RECIPIENT_VIEW_SOURCE',str(MODULE/'views/scheduling.php')))
source=view_source.read_text()
match=re.search(r"\$recipient_selection_keys = (\[[^\n;]+\]); include __DIR__ \. '/recipient_selection.php'",source)
if not match: raise SystemExit('The actual scheduling envelope declaration is missing.')
render='$recipient_selection_form_id="fixture";$recipient_selection_keys='+match[1]+';include '+json.dumps(str(MODULE/'views/recipient_selection.php'))+';'
partial=subprocess.check_output(['php','-r',render],text=True,timeout=5)
browser_ids=['sub_'+f'{i:024x}' for i in range(1,1001)]; connector='conn_'+'a'*24
controls=''.join('<input type="checkbox" checked name="schedule_browser_recipient_ids[]" value="'+identity+'">' for identity in browser_ids)
controls+='<input type="checkbox" checked name="schedule_connector_ids[]" value="'+connector+'">'
html='<form id="fixture" method="post"><input name="slsmassnotifyserver_action" value="save_scheduled_announcement" type="hidden"><button type="submit">Save fixture</button>'+partial+controls+'</form>'

with tempfile.TemporaryDirectory(prefix='sls-recipient-browser-') as temporary:
    root=Path(temporary); (root/'form.html').write_text(html)
    handler='''<?php
require HELPER;
if ($_SERVER['REQUEST_METHOD']!=='POST') { readfile(__DIR__.'/form.html'); exit; }
header('Content-Type: application/json');
try {
 $input=\\SLS\\MassNotify\\RecipientSelection::decode($_POST);
 echo json_encode(['inputs'=>count($_POST),'browsers'=>$input['schedule_browser_recipient_ids'],'connectors'=>$input['schedule_connector_ids']]);
} catch(Throwable $error) { http_response_code(400); echo json_encode(['error'=>$error->getMessage()]); }
'''.replace('HELPER',json.dumps(str(MODULE/'RecipientSelection.php')))
    (root/'index.php').write_text(handler)
    with socket.socket() as listener: listener.bind(('127.0.0.1',0)); port=listener.getsockname()[1]
    server=subprocess.Popen(['php','-d','max_input_vars=1000','-d','display_errors=0','-S','127.0.0.1:'+str(port),'-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        for unused in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1): break
            except OSError:
                if server.poll() is not None: raise RuntimeError('Private PHP listener failed')
                time.sleep(.02)
        else: raise RuntimeError('Private PHP listener did not start')
        with sync_playwright() as playwright:
            browser=playwright.chromium.launch(executable_path=browser_path,headless=True,
                args=['--no-sandbox','--disable-dev-shm-usage','--disable-background-networking'])
            try:
                page=browser.new_page(); page.goto('http://127.0.0.1:'+str(port),wait_until='load')
                with page.expect_response(lambda response:response.request.method=='POST') as pending:
                    page.get_by_role('button',name='Save fixture').click()
                response=pending.value; result=response.json()
                assert response.status==200,result
                assert result['browsers']==browser_ids and result['connectors']==[connector], \
                    'Native PHP lost selected recipients: '+str(len(result['browsers']))+' browsers, '+str(len(result['connectors']))+' connectors'
                assert result['inputs']<20,'Raw checked inputs escaped the bounded envelope'
            finally: browser.close()
    finally:
        server.terminate()
        try: server.wait(timeout=5)
        except subprocess.TimeoutExpired: server.kill(); server.wait(timeout=5)
print('Chromium preserved 1,000 scheduled browser recipients and a connector through native PHP input limits; no notification transport was used.')
