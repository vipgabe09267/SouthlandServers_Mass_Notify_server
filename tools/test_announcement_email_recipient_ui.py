#!/usr/bin/env python3
"""Render actual UI and check safe, complete recipient envelopes without a PBX."""
import json
from pathlib import Path
import re
import subprocess
root=Path(__file__).resolve().parents[1]/'slsmassnotifyserver'
recipient={'id':'email_'+'a'*24,'name':'<img src=x onerror=alert(1)>','address':'private-address@example.com','enabled':'1'}
variables={'announcement_email_recipients':[recipient], 'setup_complete':True,'hero_image':'','csrf_token':'fixture','pbx_timezone':'UTC'}
for path,fields in [('dashboard/views/sections/sls-mass-notify-announcement.php',['announcement_email_recipient_ids','group_email_recipient_ids']),('views/scheduling.php',['schedule_email_recipient_ids'])]:
    code='set_error_handler(function($s,$m){throw new RuntimeException($m);});function load_view($p,$v){return "";}extract(json_decode($argv[2],true));include $argv[1];'
    html=subprocess.check_output(['php','-r',code,str(root/path),json.dumps(variables)],text=True)
    assert '<img src=x' not in html and '&lt;img src=x' in html
    assert recipient['address'] not in html, 'Selector exposed raw address'
    for field in fields:
        assert 'name="'+field+'[]"' in html
        assert field in html and 'data-email-search' in html and 'data-email-selected' in html
    for script in re.findall(r'<script>(.*?)</script>',html,re.S):
        subprocess.run(['node','--check'],input=script,text=True,capture_output=True,check=True)
print('Actual dashboard/group/schedule email selectors render IDs/names only, escape hostile names and parse as JavaScript.')
