(function () {
    'use strict';
    const boot = JSON.parse(document.getElementById('sls-programme-data').textContent);
    const form = document.getElementById('sls-programmes-form');
    const list = document.getElementById('sls-programme-list');
    const entries = [];
    let serial = 0;
    function node(tag, text, cls) {
        const element = document.createElement(tag);
        if (text) element.textContent = text;
        if (cls) element.className = cls;
        return element;
    }
    function button(text, icon, action) {
        const element = node('button', '', 'btn btn-default btn-sm'); element.type = 'button';
        const glyph = node('i', '', 'fa ' + icon); glyph.setAttribute('aria-hidden', 'true');
        element.append(glyph, document.createTextNode(' ' + text)); element.addEventListener('click', action);
        return element;
    }
    function field(parent, label, type, value, hint) {
        const wrap = node('label', label, 'sls-programme-field');
        const control = node(type === 'select' ? 'select' : 'input', '', 'form-control');
        if (type !== 'select') control.type = type;
        control.id = 'programme-field-' + (++serial);
        if (type === 'select') ['school_days','weeks'].forEach((key)=>{
            const option = node('option', key === 'weeks' ? 'Change programme each week' : 'Advance each included school day'); option.value = key; control.append(option);
        });
        control.value = value || ''; control.required = true;
        if (type === 'text') control.maxLength = 80;
        wrap.append(control); if (hint) wrap.append(node('small', hint)); parent.append(wrap); return control;
    }
    function table(parent, title, columns, initial, limit) {
        parent.append(node('h5', title));
        const table = node('table', '', 'sls-programme-table'); const head = node('thead'); const tr = node('tr');
        columns.forEach((col)=>tr.append(node('th', col.label))); tr.append(node('th')); head.append(tr);
        const body = node('tbody'); table.append(head, body); parent.append(table);
        const records = [];
        function add(value) {
            if (records.length >= limit) return;
            const row = node('tr'); const fields = {};
            columns.forEach((col)=>{
                const cell = node('td'); const input = node('input', '', 'form-control'); input.type = col.type;
                input.value = value[col.key] || ''; input.required = col.required !== false;
                input.setAttribute('aria-label', title + ': ' + col.label); input.maxLength = 80;
                fields[col.key] = input; cell.append(input); row.append(cell);
            });
            const record = {row, fields}; const cell = node('td');
            const remove = button('', 'fa-times', ()=>{ records.splice(records.indexOf(record),1); row.remove(); });
            remove.setAttribute('aria-label', 'Remove ' + title.toLowerCase() + ' row'); cell.append(remove); row.append(cell);
            records.push(record); body.append(row);
        }
        (initial || []).forEach(add); parent.append(button('Add ' + title.toLowerCase(), 'fa-plus', ()=>add({})));
        return ()=>records.map((record)=>Object.fromEntries(Object.entries(record.fields).map(([key,input])=>[key,input.value.trim()])));
    }
    function empty() { document.getElementById('sls-programme-empty').hidden = entries.length > 0; }
    function create(value) {
        if (entries.length >= 25) return;
        const card = node('details', '', 'sls-programme-card'); const summary = node('summary', value.name || 'New shared calendar');
        const editor = node('div', '', 'sls-programme-editor'); const grid = node('div', '', 'sls-programme-grid');
        const name = field(grid, 'Calendar name', 'text', value.name);
        const zone = field(grid, 'Timezone', 'text', value.timezone || boot.timezone, 'Use an IANA name, such as America/Chicago.');
        const start = field(grid, 'Cycle starts on', 'date', value.cycle_start);
        const end = field(grid, 'Final calendar date', 'date', value.calendar && value.calendar.until);
        const mode = field(grid, 'Repeating cycle', 'select', value.cycle_mode || 'school_days');
        const types = field(grid, 'Day types, in order', 'text', (value.day_types || ['A','B']).join(', '), 'For example A, B, EARLY. Use a whole-day substitution for early dismissal.');
        name.addEventListener('input', ()=>{summary.textContent = name.value || 'New shared calendar';});
        editor.append(grid); const days = node('div', '', 'sls-programme-days'); const dayInputs = [];
        ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].forEach((label,index)=>{
            const wrap = node('label'); const input = node('input'); input.type = 'checkbox'; input.value = index+1;
            input.checked = (value.calendar && value.calendar.weekdays || [1,2,3,4,5]).includes(index+1);
            wrap.append(input, document.createTextNode(' ' + label)); days.append(wrap); dayInputs.push(input);
        }); editor.append(days);
        const holidays = table(editor, 'Holiday ranges', [{key:'start',label:'First date',type:'date'},{key:'end',label:'Last date',type:'date'},{key:'reason',label:'Reason',type:'text',required:false}], value.calendar && value.calendar.exclusions,100);
        const replacements = table(editor, 'Whole-day substitutions', [{key:'date',label:'Date',type:'date'},{key:'day_type',label:'Day type, or empty to skip',type:'text',required:false}],value.date_types,100);
        const timeChanges = table(editor, 'Time overrides', [{key:'date',label:'Date',type:'date'},{key:'time',label:'Replacement time',type:'time'},{key:'reason',label:'Reason',type:'text',required:false}],value.calendar && value.calendar.overrides,100);
        const id = value.id || 'cal_' + Array.from(crypto.getRandomValues(new Uint8Array(12)),(b)=>b.toString(16).padStart(2,'0')).join('');
        const entry = {card, value:()=>({id,name:name.value.trim(),timezone:zone.value.trim(),cycle_start:start.value,cycle_mode:mode.value,
            day_types:types.value.split(',').map((v)=>v.trim()).filter(Boolean),date_types:replacements(),
            calendar:{until:end.value,weekdays:dayInputs.filter((i)=>i.checked).map((i)=>Number(i.value)),exclusions:holidays(),overrides:timeChanges()}})};
        editor.append(node('p','Shared calendars do not edit dates already saved in schedules. Reopen affected schedules and review their new dates.','sls-programme-notice'));
        editor.append(button('Remove calendar','fa-trash-o',()=>{if (!window.confirm('Remove this shared calendar? Existing schedules keep their saved dates.')) return; entries.splice(entries.indexOf(entry),1);card.remove();empty();}));
        card.append(summary,editor); list.append(card); entries.push(entry); card.open = !value.id; empty();
    }
    boot.settings.calendars.forEach(create); empty();
    document.getElementById('sls-programme-add').addEventListener('click',()=>create({}));
    form.addEventListener('invalid',function(event){var panel=event.target.closest('details');if(panel)panel.open=true;},true);
    form.addEventListener('submit',()=>{
        document.getElementById('sls-programmes-json').value = JSON.stringify({schema:1,enabled:document.getElementById('sls-programmes-enabled').checked,calendars:entries.map((entry)=>entry.value())});
    });
})();
