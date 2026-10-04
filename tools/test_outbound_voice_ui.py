#!/usr/bin/env python3
"""Render external recipient controls and exercise their actual submit serializers."""
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / 'slsmassnotifyserver'


def render(relative, variables):
    code = r'''
    set_error_handler(function($severity, $message) { throw new RuntimeException($message); });
    function load_view($path, $variables) { return ''; }
    extract(json_decode($argv[2], true));
    include $argv[1];
    '''
    return subprocess.check_output(['php', '-r', code, str(MODULE / relative), json.dumps(variables)], text=True)


def scripts(html):
    return re.findall(r'<script>(.*?)</script>', html, re.S)


recipient = {'id': 'voice_' + 'a' * 24, 'name': '<img src=x onerror=alert(1)>', 'number': '+15551234567', 'enabled': '1'}
settings_html = render('views/outbound_voice.php', {
    'settings': {'outbound_voice': {'enabled': '0', 'route_mode': 'pbx_routes', 'recipients': [recipient]}},
    'outbound_voice_trunks': [
        {'id': '1', 'name': 'Enabled PJSIP', 'enabled': True, 'tech': 'pjsip'},
        {'id': '2', 'name': 'Disabled PJSIP', 'enabled': False, 'tech': 'pjsip'},
        {'id': '3', 'name': 'Legacy SIP', 'enabled': True, 'tech': 'sip'},
    ], 'outbound_voice_dids': ['+15559876543'],
})
assert '<img src=x' not in settings_html and '&lt;img src=x' in settings_html
assert 'Enabled PJSIP' in settings_html and 'Disabled PJSIP' not in settings_html and 'Legacy SIP' not in settings_html
assert 'value="pbx_routes" selected' in settings_html
assert 'name="outbound_voice_complete" id="sls-outbound-voice-complete" value="0"' in settings_html
assert not re.search(r'data-voice-field="[a-z]+"[^>]*\bname=', settings_html)

dashboard_html = render('dashboard/views/sections/sls-mass-notify-announcement.php', {
    'setup_complete': True, 'outbound_voice_recipients': [recipient], 'csrf_token': 'fixture-token',
})
schedule_html = render('views/scheduling.php', {
    'outbound_voice_recipients': [recipient], 'hero_image': '', 'pbx_timezone': 'UTC', 'csrf_token': 'fixture-token',
})
assert 'name="voice_recipient_ids[]"' in dashboard_html
assert 'name="group_voice_recipient_ids[]"' in dashboard_html
assert 'name="schedule_voice_recipient_ids[]"' in schedule_html
assert 'dashboard-group-unavailable-voice' in dashboard_html and 'sls-schedule-unavailable-voice' in schedule_html
assert '<img src=x' not in dashboard_html + schedule_html
for html in [settings_html, dashboard_html, schedule_html]:
    for script in scripts(html):
        subprocess.run(['node', '--check'], input=script, text=True, check=True, capture_output=True)

editor_fixture = r'''
const vm = require('vm'), assert = require('assert');
const rows = [], listeners = {}, field = {value:''}, complete = {value:'0'}, error = {textContent:''};
const form = {addEventListener(event, callback, capture){assert(capture);listeners[event]=callback;}};
field.form=form;
function row(index) {
  const values = {id:'voice_'+index,name:'Recipient '+index,number:'+15551234567',enabled:'1'};
  return {querySelectorAll(){return Object.keys(values).map(key=>({type:key==='enabled'?'checkbox':'text',checked:index%2===0,value:values[key],getAttribute(){return key;}}));}};
}
for(let i=0;i<1000;i++)rows.push(row(i));
const table={querySelectorAll(){return rows;},addEventListener(){}};
const add={addEventListener(event,callback){this.click=callback;}};
const mode={value:'pbx_routes',addEventListener(event,callback){this.change=callback;}};
const wrap={}, trunk={};
const elements={'sls-add-voice-recipient':add,'sls-outbound-voice-json':field,'sls-outbound-voice-complete':complete,'sls-outbound-voice-error':error,'sls-outbound-voice-route':mode,'sls-outbound-voice-trunk-wrap':wrap,'sls-outbound-voice-trunk':trunk};
vm.runInNewContext(SCRIPT,{document:{querySelector(){return table;},getElementById(id){return elements[id];}}});
assert(wrap.hidden && !trunk.required);
mode.value='trunk';mode.change();assert(!wrap.hidden && trunk.required);
listeners.submit();
let saved=JSON.parse(field.value);
assert.equal(saved.length,1000);assert.equal(saved[999].name,'Recipient 999');
assert.equal(saved[0].enabled,'1');assert.equal(saved[1].enabled,'0');assert.equal(complete.value,'1');
add.click();assert.equal(rows.length,1000);assert(error.textContent.includes('1000'));
rows.length=0;listeners.submit();assert.equal(field.value,'[]');assert.equal(complete.value,'1');
console.log('External voice editor:1000 recipients,checked state,empty lists,route controls and capacity passed.');
'''.replace('SCRIPT', json.dumps(scripts(settings_html)[0]))
subprocess.run(['node', '-e', editor_fixture], check=True)

keys = ['announcement_extensions', 'announcement_groups', 'announcement_desktop_clients', 'announcement_webhooks', 'voice_recipient_ids']
selection_html = render('views/recipient_selection.php', {'recipient_selection_form_id': 'fixture-form', 'recipient_selection_keys': keys})
selection_fixture = r'''
const vm=require('vm'), assert=require('assert'), keys=KEYS;
const field={value:''}, complete={value:'0'}, timers=[], inputs={};
keys.forEach(key=>{ inputs[key]=Array.from({length:1000},(_,i)=>({value:key+'_'+i,checked:true,disabled:false})); });
const predisabled={value:'excluded',checked:true,disabled:true};inputs[keys[0]].push(predisabled);
const unchecked={value:'unchecked',checked:false,disabled:false};inputs[keys[0]].push(unchecked);
const unrelated={name:'announcement_message',disabled:false};
const form={querySelector(selector){return selector.includes('_json')?field:complete;},querySelectorAll(selector){const match=/name="(.+)\[\]"/.exec(selector);assert(match);return inputs[match[1]];},addEventListener(event,callback,capture){assert(capture);this.submit=callback;}};
vm.runInNewContext(SCRIPT,{document:{getElementById(id){assert.equal(id,'fixture-form');return form;}},window:{setTimeout(callback){timers.push(callback);}}});
form.submit();let selection=JSON.parse(field.value);
assert.equal(Object.keys(selection).length,keys.length);
keys.forEach(key=>{assert.equal(selection[key].length,1000);assert.equal(selection[key][999],key+'_999');assert(inputs[key].every(input=>input.disabled));});
assert.equal(complete.value,'1');assert(!unrelated.disabled);
timers.splice(0).forEach(callback=>callback());
assert(predisabled.disabled);assert(!unchecked.disabled);assert(!inputs[keys[0]][0].disabled);
keys.forEach(key=>{inputs[key].forEach(input=>input.checked=false);});form.submit();
keys.forEach(key=>assert.equal(JSON.parse(field.value)[key].length,0));
console.log('Mixed recipient selections:5000 selected values serialized as one field,disabled exclusions,cancel recovery and explicit empty lists passed.');
'''.replace('KEYS', json.dumps(keys)).replace('SCRIPT', json.dumps(scripts(selection_html)[0]))
subprocess.run(['node', '-e', selection_fixture], check=True)
print('External voice UI rendering,escaping,trunk scope,all rendered JavaScript and recipient forms passed.')
