#!/usr/bin/env python3
"""Exercise the actual desktop editor JavaScript with disposable DOM fixtures."""
from pathlib import Path
import json
import re
import shutil
import subprocess

root = Path(__file__).resolve().parents[1]
source = (root / 'slsmassnotifyserver/views/other_settings.php').read_text()
start = source.index("\tvar table = document.querySelector('#desktop-client-table tbody');")
end = source.index('}());', start)
script = '(function(){\n' + source[start:end] + '}());'
node = shutil.which('node')
if not node:
    raise SystemExit('Node.js is required for the desktop editor regression fixture.')
fixture = r'''
const vm = require('vm'), assert = require('assert');
const code = SCRIPT;
const timers = [], rows = [];
function input(index, field, value, type = 'text', checked = true) {
  return {name:`desktop_clients[${index}][${field}]`, value, type, checked, disabled:false};
}
function row(index) {
  return {inputs:[input(index,'id',`desk_${index}`), input(index,'username',`desktop${index}`), input(index,'enabled','1','checkbox',index % 2 === 0)],
    querySelectorAll(){return this.inputs;}, setAttribute(){},
    set innerHTML(html) { this.html=html; this.inputs=Array.from(html.matchAll(/name="(desktop_clients\[\d+\]\[[a-z_]+\])"/g), match => ({name:match[1],value:'',type:'text',disabled:false})); }
  };
}
rows.push(row(0), row(2)); // Delete the middle row, retaining index2.
const table = {querySelectorAll(selector){return selector==='[name]' ? rows.flatMap(r=>r.inputs) : rows;}, addEventListener(){}, appendChild(value){rows.push(value);}};
const add = {addEventListener(event, callback){this.click=callback;}};
const capacity = {value:1000}, error = {textContent:''};
const form = {addEventListener(event, callback){this.submit=callback;}};
const field = {value:'',form};
const elements = {'add-desktop-client':add,'sls-desktop-limit':capacity,'sls-desktop-capacity-error':error,'sls-desktop-clients-json':field};
const document = {querySelector(){return table;},getElementById(id){return elements[id]||null;},createElement(){return row(-1);}};
vm.runInNewContext(code, {document, window:{setTimeout(callback){timers.push(callback);}}});
add.click();
assert(rows[2].inputs.every(field=>field.name.startsWith('desktop_clients[3]')),'delete/add reused an existing row index');
rows.length=0;
for(let i=0;i<1000;i++)rows.push(row(i));
form.submit();
const clients = JSON.parse(field.value);
assert.equal(clients.length,1000);
assert.equal(clients[999].username,'desktop999');
assert.equal(clients[0].enabled,'1');
assert.equal(clients[1].enabled,'0');
assert(rows.every(row=>row.inputs.every(field=>field.disabled)),'original fields still exceed max_input_vars');
timers.forEach(callback=>callback());
assert(rows.every(row=>row.inputs.every(field=>!field.disabled)),'cancelled submit left the editor disabled');
add.click();
assert.equal(rows.length,1000);
assert(error.textContent.includes('capacity reached'));
console.log('Desktop editor: sparse indexes,1000-client serialization,checkboxes,capacity and cancelled-submit recovery passed.');
'''.replace('SCRIPT', json.dumps(script))
subprocess.run([node, '-e', fixture], check=True)
form_start = source.index('id="sls-other-settings-form"')
form_end = source.index('</form>', form_start)
sentinel = source.index('name="sls_general_form_complete"', form_start)
assert sentinel < form_end and not re.search(r'\bname=', source[sentinel + len('name="sls_general_form_complete"'):form_end])
