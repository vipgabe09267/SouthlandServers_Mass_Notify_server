#!/usr/bin/python3
"""Render the footer and exercise result transitions without sending a page."""
import re
import subprocess
import sys
from pathlib import Path

root = Path(__file__).resolve().parents[1]
path = Path(sys.argv[1]) if len(sys.argv) > 1 else root / 'slsmassnotifyserver/dashboard/views/sections/sls-mass-notify-announcement.php'
source = path.read_text()
assert 'Review destinations' not in source and 'id="sls-announcement-preview"' not in source
assert 'remaining > 0 || requestInFlight || deliveryOutcomeUnknown || activeJob' in source
assert 'class="btn btn-primary sls-send-button"' in source
assert 'error && error.message ? error.message' not in source[source.index("form.addEventListener('submit'"):]
render = subprocess.run(['php', '-r', '$setup_complete=true; $csrf_token="fixture"; include $argv[1];', str(path)], capture_output=True, text=True, check=True)
assert not render.stderr, render.stderr
scripts = re.findall(r'<script[^>]*>(.*?)</script>', render.stdout, re.S)
assert scripts
for script in scripts:
    subprocess.run(['node', '--check'], input=script, text=True, capture_output=True, check=True)
def function(name, next_name):
    return source[source.index('\tfunction ' + name):source.index('\tfunction ' + next_name)]
code = r'''
const assert = require('assert');
function node() { return {style:{}, attrs:{}, children:[], className:'', textContent:'',
 setAttribute(k,v){this.attrs[k]=v;}, appendChild(c){this.children.push(c);},
 set innerHTML(v){assert.equal(v,''); this.children=[];}}; }
var result=node(); var document={createElement:node};
function instanceActive(){return true;} function scheduleDashboardLayout(){}
'''
code += function('setAnnouncementStatus', 'renderMessageCount')
code += function('renderDeliveryStatus', 'rememberJob')
code += r'''
for (const data of [{success:true, queued:true}, {success:true,state:'queued'}, {success:true,state:'running'}]) {
 renderDeliveryStatus(data); assert(result.className.includes('sls-status-info'));
 assert.equal(result.attrs['aria-busy'],'true'); assert(!result.children[0].className.includes('check'));
}
renderDeliveryStatus({success:true,state:'submitted',sender:'<img src=x onerror=alert(1)>'});
assert(result.className.includes('sls-status-success'));
assert(result.children[0].className.includes('check-circle'));
assert.equal(result.children[1].textContent,'Announcement submitted · Sent by <img src=x onerror=alert(1)>');
assert.equal(result.attrs['aria-busy'],'false');
renderDeliveryStatus({success:false,state:'partial_or_failed',message:'One channel needs attention'});
assert(result.className.includes('sls-status-warning'));
assert(!result.children[0].className.includes('check'));
'''
subprocess.run(['node'], input=code, text=True, capture_output=True, check=True)
print('Dashboard render/JavaScript, no preview button, duplicate-submit guard, pending/success/failure and safe sender text passed.')
