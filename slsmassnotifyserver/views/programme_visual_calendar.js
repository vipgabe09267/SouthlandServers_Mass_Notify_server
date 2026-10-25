function slsProgrammeVisualCalendar(parent, draft, substitutions, timeOverrides, csrf) {
    'use strict';
    const node = (tag, text, className) => { const e = document.createElement(tag); if (text !== undefined) e.textContent = text; if (className) e.className = className; return e; };
    const section = node('section', undefined, 'sls-programme-visual');
    const heading = node('h4', 'Calendar preview'), controls = node('div', undefined, 'sls-programme-actions');
    const previous = node('button', 'Previous', 'btn btn-default btn-sm'), next = node('button', 'Next', 'btn btn-default btn-sm');
    const refresh = node('button', 'Preview current draft', 'btn btn-default btn-sm');
    const focusLabel = node('label', 'Date '), focus = node('input'); focus.type = 'date'; focus.className = 'form-control'; focusLabel.append(focus);
    const modeLabel = node('label', 'View '), mode = node('select'); mode.className = 'form-control'; mode.setAttribute('aria-label','Calendar view');
    for (const [value, name] of [['month','Month'],['week','Week'],['list','Accessible list']]) { const option = node('option', name); option.value = value; mode.append(option); }
    modeLabel.append(mode); controls.append(previous, focusLabel, modeLabel, next, refresh);
    const status = node('p', 'Preview uses the current draft. Changes stay in this editor until you save shared calendars and apply configuration.', 'sls-programme-notice'); status.setAttribute('role', 'status');
    const grid = node('div'), editing = node('div', undefined, 'sls-programme-date-editor'); editing.hidden = true;
    section.append(heading, controls, status, grid, editing); parent.append(section);
    let snapshot = '', generation = 0;
    const dateValue = date => date.toISOString().slice(0,10);
    const dateObject = date => new Date(date+'T12:00:00Z');
    const button = (title, action) => { const e = node('button', title, 'btn btn-default btn-sm'); e.type = 'button'; e.addEventListener('click', action); return e; };
    [previous,next,refresh].forEach(e => e.type = 'button');
    function outdated() {
        snapshot = ''; generation++; editing.hidden = true;
        grid.querySelectorAll('button').forEach(e => e.disabled = true);
        status.textContent = 'Draft changed. Preview again before editing a date. Nothing has been applied.';
    }
    function edit(day, result) {
        if (snapshot !== JSON.stringify(draft())) { outdated(); return; }
        editing.replaceChildren(); editing.hidden = false;
        editing.append(node('h5', 'Edit ' + day.date + ' · ' + result.timezone));
        const layout = node('div', undefined, 'sls-programme-grid'); editing.append(layout);
        const typeLabel = node('label', 'Day programme'), type = node('select'); type.className = 'form-control'; type.setAttribute('aria-label','Day programme');
        for (const [value,label] of [['@default','Use repeating cycle'],['','Skip this date'],...result.day_types.map(value=>[value,value])]) { const option=node('option',label);option.value=value;type.append(option); }
        type.value = substitutions().find(row=>row.date===day.date)?.day_type ?? '@default';typeLabel.append(type);layout.append(typeLabel);
        const timeLabel = node('label', 'Temporary replacement time'), time = node('input');time.type='time';time.className='form-control';time.setAttribute('aria-label','Temporary replacement time');time.value=day.time_override;timeLabel.append(time);layout.append(timeLabel);
        editing.append(node('p','Leave the time blank to remove this date’s time override. Removing an override restores the saved repeating cycle; other dates remain unchanged.','sls-programme-notice'));
        editing.append(button('Update draft', () => {
            if (snapshot !== JSON.stringify(draft())) { outdated(); return; }
            try {
                if(type.value!=='@default'&&!substitutions.canUpsert('date',day.date))throw Error('The substitution list is full. Remove an unused row first.');
                if(time.value&&!timeOverrides.canUpsert('date',day.date))throw Error('The time override list is full. Remove an unused row first.');
                if(type.value==='@default')substitutions.remove('date',day.date);else substitutions.upsert('date',day.date,{date:day.date,day_type:type.value});
                if(time.value)timeOverrides.upsert('date',day.date,{date:day.date,time:time.value,reason:'Calendar date override'});else timeOverrides.remove('date',day.date);
                editing.hidden=true;load();
            }catch(error){status.textContent=error.message;}
        }),button('Cancel date edit',()=>{editing.hidden=true;}));
        type.focus();
    }
    function render(result) {
        grid.replaceChildren();editing.hidden=true;
        heading.textContent='Calendar preview · '+result.timezone;
        previous.disabled=mode.value==='week'?result.days[0].date<=result.minimum:focus.value.slice(0,7)<=result.minimum.slice(0,7);
        next.disabled=mode.value==='week'?result.days[result.days.length-1].date>=result.maximum:focus.value.slice(0,7)>=result.maximum.slice(0,7);
        if(mode.value==='list') {
            const table=node('table',undefined,'sls-programme-table'), head=node('thead'), header=node('tr'), body=node('tbody');
            ['Date','Programme','Status / override','Edit'].forEach(label=>header.append(node('th',label)));head.append(header);table.append(head,body);
            for(const day of result.days){const row=node('tr');row.append(node('td',day.date),node('td',day.day_type===null?'—':day.day_type||'Skipped'),node('td',[day.status,day.time_override,day.reason].filter(Boolean).join(' · ')));const cell=node('td');if(day.editable)cell.append(button('Edit date',()=>edit(day,result)));row.append(cell);body.append(row);}grid.append(table);
        } else {
            const calendar=node('div',undefined,'sls-programme-month');
            ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].forEach(label=>calendar.append(node('div',label,'sls-programme-weekday')));
            for(const day of result.days){
                const cell=node(day.editable?'button':'div',undefined,'sls-programme-day');cell.dataset.status=day.status;
                cell.append(node('strong',day.date.slice(8)),node('span',day.day_type===null?day.reason||day.status:day.day_type||'Skipped'));
                if(day.time_override)cell.append(node('small',day.time_override));
                if(day.editable){cell.type='button';cell.setAttribute('aria-label',day.date+' · '+(day.day_type||'skipped')+' · edit date');cell.addEventListener('click',()=>edit(day,result));}
                calendar.append(cell);
            }grid.append(calendar);
        }
        status.textContent='Preview only · '+result.days.filter(day=>day.status==='active').length+' active date(s) shown. Save shared calendars and apply configuration to activate changes; existing saved schedule occurrences retain their reviewed dates.';
    }
    async function load() {
        const value=draft(), serial=++generation; editing.hidden=true;
        if(!focus.value)focus.value=value.cycle_start || dateValue(new Date());
        focus.min=value.cycle_start;focus.max=value.calendar.until;
        const before=JSON.stringify(value);status.textContent='Reviewing calendar dates…';refresh.disabled=true;
        const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),8000);
        try {
            const response=await fetch('config.php?display=slsmassnotifyserver_scheduling',{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,
                headers:{Accept:'application/json'},body:new URLSearchParams({slsmassnotifyserver_action:'preview_programme_calendar',slsmassnotifyserver_csrf:csrf,programme_json:before,focus:focus.value,view:mode.value})});
            const result=await response.json();if(serial!==generation)return;
            if(!response.ok||!result.success||!Array.isArray(result.days)||result.days.length>42)throw Error(result.message||'Calendar preview was not accepted. Check dates, day types and exclusions.');
            if(JSON.stringify(draft())!==before){outdated();return;}snapshot=before;render(result);
        }catch(error){if(serial===generation){snapshot='';grid.replaceChildren();status.textContent=error.name==='AbortError'?'Calendar preview timed out. Your draft is unchanged.':error.message;}}
        finally{clearTimeout(timer);refresh.disabled=false;}
    }
    refresh.addEventListener('click',load);mode.addEventListener('change',()=>{if(snapshot)load();});
    function move(direction){const value=draft();let date=dateObject(focus.value||value.cycle_start);if(!Number.isFinite(date.getTime())){status.textContent='Enter the programme start date first.';return;}if(mode.value==='week')date.setUTCDate(date.getUTCDate()+7*direction);else{date.setUTCDate(1);date.setUTCMonth(date.getUTCMonth()+direction);}focus.value=dateValue(date);load();}
    previous.addEventListener('click',()=>move(-1));next.addEventListener('click',()=>move(1));focus.addEventListener('change',()=>{if(snapshot)load();});
    parent.addEventListener('input',event=>{if(!section.contains(event.target))outdated();});
    parent.addEventListener('sls-programme-draft-change',outdated);
    return section;
}
