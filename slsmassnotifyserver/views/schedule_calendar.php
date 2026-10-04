<style>
.sls-calendar-panel{margin-top:16px;border-top:1px solid #dce3ea;padding-top:16px}
.sls-calendar-panel h5{font-size:15px;font-weight:600;margin:18px 0 10px}
.sls-calendar-weekdays{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 14px}
.sls-calendar-weekdays label{border:1px solid #ced7e2;border-radius:6px;padding:7px 10px;background:#fff;font-weight:400;margin:0;cursor:pointer}
.sls-calendar-weekdays input{margin-right:5px}
.sls-calendar-row{display:grid;grid-template-columns:minmax(140px,1fr) minmax(140px,1fr) minmax(130px,1.2fr) auto;gap:8px;align-items:end;margin:10px 0}
.sls-calendar-row label{font-size:12px;color:#475569;display:block;margin-bottom:4px}
.sls-calendar-row button{min-height:34px}
.sls-calendar-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:14px 0}
.sls-calendar-actions input{max-width:260px}
.sls-calendar-status{line-height:1.5;margin:10px 0;color:#475569}
.sls-calendar-status.sls-calendar-error{color:#a61b1b}
.sls-calendar-preview{max-height:240px;overflow:auto;border:1px solid #dce3ea;border-radius:6px;background:#fff;padding:12px}
.sls-calendar-preview ol{padding-left:23px;margin:0}
.sls-calendar-preview li{padding:3px 0;overflow-wrap:anywhere}
@media(max-width:650px){.sls-calendar-row{grid-template-columns:1fr 1fr}.sls-calendar-row .sls-calendar-reason{grid-column:1}.sls-calendar-row button{justify-self:end}.sls-calendar-actions{align-items:flex-start;flex-direction:column}}
</style>
<div class="sls-calendar-panel" id="sls-calendar-panel" hidden>
    <p class="help-block"><i class="fa fa-calendar-check-o" aria-hidden="true"></i> <?php echo _('Choose the weekdays and final date, then add holidays or temporary start times. Dates use'); ?> <strong id="sls-calendar-zone"><?php echo htmlspecialchars($timezoneName, ENT_QUOTES); ?></strong>. <?php echo _('Saving does not replay past dates or change an announcement already submitted.'); ?></p>
    <div class="form-group" style="max-width:260px"><label for="sls-calendar-until"><?php echo _('Last calendar date'); ?></label><input type="date" class="form-control" id="sls-calendar-until" disabled></div>
    <span id="sls-calendar-days-label"><?php echo _('Repeat on'); ?></span>
    <div class="sls-calendar-weekdays" role="group" aria-labelledby="sls-calendar-days-label">
        <?php foreach ([1=>_('Mon'),2=>_('Tue'),3=>_('Wed'),4=>_('Thu'),5=>_('Fri'),6=>_('Sat'),7=>_('Sun')] as $day=>$label) { ?>
        <label><input type="checkbox" value="<?php echo $day; ?>" data-calendar-day <?php echo $day <= 5 ? 'checked' : ''; ?> disabled><?php echo $label; ?></label>
        <?php } ?>
    </div>
    <h5><i class="fa fa-calendar-times-o" aria-hidden="true"></i> <?php echo _('Holidays and excluded dates'); ?></h5>
    <p class="help-block"><?php echo _('Both dates are inclusive. Use the same date twice to skip one day. Exclusions take priority over the weekday pattern.'); ?></p>
    <div id="sls-calendar-exclusions"></div>
    <button class="btn btn-default btn-sm" type="button" id="sls-calendar-add-exclusion"><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add holiday range'); ?></button>
    <h5><i class="fa fa-clock-o" aria-hidden="true"></i> <?php echo _('Late starts and date overrides'); ?></h5>
    <p class="help-block"><?php echo _('Replace the usual time on a date already included in this pattern. An override cannot re-enable a holiday or add a different weekday.'); ?></p>
    <div id="sls-calendar-overrides"></div>
    <button class="btn btn-default btn-sm" type="button" id="sls-calendar-add-override"><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add date override'); ?></button>
    <h5><i class="fa fa-upload" aria-hidden="true"></i> <?php echo _('Import holiday dates'); ?></h5>
    <p class="help-block"><?php echo _('Choose an all-day .ics calendar or a UTF-8 CSV with the header start,end,reason. Imports add exclusions to this editor for review; they do not save or send anything. Up to 100 ranges and 256 KiB. Expand recurring events into explicit dates before importing.'); ?></p>
    <div class="sls-calendar-actions"><input type="file" id="sls-calendar-file" accept=".ics,.csv" aria-label="<?php echo htmlspecialchars(_('Holiday calendar file'), ENT_QUOTES); ?>"><button type="button" class="btn btn-default" id="sls-calendar-import"><i class="fa fa-upload" aria-hidden="true"></i> <?php echo _('Read calendar'); ?></button></div>
    <div class="sls-calendar-actions"><button type="button" class="btn btn-primary" id="sls-calendar-preview"><i class="fa fa-list-ol" aria-hidden="true"></i> <?php echo _('Review planned dates'); ?></button><span class="help-block"><?php echo _('Up to 366 occurrences; no automatic extension beyond the saved end date.'); ?></span></div>
    <p class="sls-calendar-status" id="sls-calendar-status" role="status" aria-live="polite"></p>
    <div class="sls-calendar-preview" id="sls-calendar-preview-results" hidden><ol></ol></div>
    <input type="hidden" name="schedule_calendar_json" id="sls-calendar-json" disabled>
    <input type="hidden" name="schedule_calendar_complete" id="sls-calendar-complete" value="0" disabled>
</div>
<script>
(function(){
    'use strict';
    var panel=document.getElementById('sls-calendar-panel'),form=document.getElementById('sls-schedule-form');
    var recurrence=document.getElementById('sls-schedule-recurrence'),until=document.getElementById('sls-calendar-until');
    var exclusions=document.getElementById('sls-calendar-exclusions'),overrides=document.getElementById('sls-calendar-overrides');
    var status=document.getElementById('sls-calendar-status'),output=document.getElementById('sls-calendar-preview-results');
    var json=document.getElementById('sls-calendar-json'),complete=document.getElementById('sls-calendar-complete');
    var preview=document.getElementById('sls-calendar-preview'),importButton=document.getElementById('sls-calendar-import'),generation=0,busy=false;
    var defaultZone=<?php echo json_encode($timezoneName); ?>;
    function message(text,error){status.textContent=text;status.classList.toggle('sls-calendar-error',!!error);}
    function invalidate(){generation++;output.hidden=true;message('',false);}
    function values(){
        var data={until:until.value,weekdays:[],exclusions:[],overrides:[]};
        panel.querySelectorAll('[data-calendar-day]:checked').forEach(function(input){data.weekdays.push(Number(input.value));});
        [['exclusions',exclusions],['overrides',overrides]].forEach(function(pair){pair[1].querySelectorAll('.sls-calendar-row').forEach(function(row){var record={};row.querySelectorAll('[data-calendar-field]').forEach(function(input){record[input.dataset.calendarField]=input.value;});data[pair[0]].push(record);});});
        return data;
    }
    function serialize(){
        var data=values(),valid=!!data.until&&data.weekdays.length>0;
        panel.querySelectorAll('input[required]').forEach(function(input){if(!input.value||!input.checkValidity())valid=false;});
        json.value=JSON.stringify(data);complete.value=valid?'1':'0';return valid;
    }
    function field(row,key,label,type,value,required){
        var wrap=document.createElement('div'),caption=document.createElement('label'),input=document.createElement('input');
        if(key==='reason')wrap.className='sls-calendar-reason';
        input.id='sls-calendar-field-'+(++generation);input.type=type;input.className='form-control';input.dataset.calendarField=key;input.value=String(value||'');input.required=required;
        if(type==='text')input.maxLength=80;caption.htmlFor=input.id;caption.textContent=label;wrap.appendChild(caption);wrap.appendChild(input);row.appendChild(wrap);
    }
    function addRow(kind,data){
        var parent=kind==='exclusions'?exclusions:overrides;
        if(parent.children.length>=100){message(<?php echo json_encode(_('This editor is limited to 100 holiday ranges and 100 date overrides.')); ?>,true);return false;}
        var row=document.createElement('div');row.className='sls-calendar-row';data=data||{};
        if(kind==='exclusions'){
            field(row,'start',<?php echo json_encode(_('First excluded date')); ?>,'date',data.start,true);
            field(row,'end',<?php echo json_encode(_('Last excluded date')); ?>,'date',data.end,true);
        }else{
            field(row,'date',<?php echo json_encode(_('Date')); ?>,'date',data.date,true);
            field(row,'time',<?php echo json_encode(_('Replacement time')); ?>,'time',data.time,true);
        }
        field(row,'reason',<?php echo json_encode(_('Reason (optional)')); ?>,'text',data.reason,false);
        var remove=document.createElement('button');remove.type='button';remove.className='btn btn-danger btn-sm';
        var icon=document.createElement('i');icon.className='fa fa-times';icon.setAttribute('aria-hidden','true');remove.appendChild(icon);
        remove.appendChild(document.createTextNode(' '+<?php echo json_encode(_('Remove')); ?>));remove.addEventListener('click',function(){row.remove();invalidate();serialize();});row.appendChild(remove);
        parent.appendChild(row);invalidate();serialize();return true;
    }
    function toggle(){
        var enabled=recurrence.value==='calendar';panel.hidden=!enabled;
        panel.querySelectorAll('input,button').forEach(function(input){input.disabled=!enabled;});until.required=enabled;
        if(enabled){preview.disabled=busy;importButton.disabled=busy;serialize();}
    }
    function reset(calendar,zone){
        invalidate();exclusions.textContent='';overrides.textContent='';until.value=calendar&&calendar.until||'';
        document.getElementById('sls-calendar-file').value='';document.getElementById('sls-calendar-zone').textContent=zone||defaultZone;
        panel.querySelectorAll('[data-calendar-day]').forEach(function(input){input.checked=(calendar&&calendar.weekdays||[1,2,3,4,5]).indexOf(Number(input.value))>=0;});
        ['exclusions','overrides'].forEach(function(kind){(calendar&&calendar[kind]||[]).forEach(function(row){addRow(kind,row);});});toggle();
    }
    function request(data){return fetch('config.php?display=slsmassnotifyserver_scheduling',{method:'POST',credentials:'same-origin',cache:'no-store',body:data}).then(function(response){return response.json().catch(function(){throw new Error(<?php echo json_encode(_('The PBX returned an unreadable calendar response. Reload the page and check the PHP error log. HTTP status:')); ?>+' '+response.status);}).then(function(body){if(!response.ok||!body.success)throw new Error(body.message||(body.errors||[]).join(' ')||<?php echo json_encode(_('Calendar review failed. Reload the page and try again.')); ?>);return body;});});}
    function setBusy(value){busy=value;preview.disabled=value;importButton.disabled=value;panel.setAttribute('aria-busy',String(value));}
    function tokenData(action){var data=new FormData();data.set('slsmassnotifyserver_action',action);data.set('slsmassnotifyserver_csrf',form.querySelector('[name="slsmassnotifyserver_csrf"]').value);return data;}
    panel.addEventListener('input',function(){invalidate();serialize();});
    document.getElementById('sls-occurrence-list').addEventListener('input',invalidate);
    recurrence.addEventListener('change',function(){invalidate();toggle();});
    document.getElementById('sls-calendar-add-exclusion').addEventListener('click',function(){addRow('exclusions');});
    document.getElementById('sls-calendar-add-override').addEventListener('click',function(){addRow('overrides');});
    preview.addEventListener('click',function(){
        if(busy)return;if(!serialize()){message(<?php echo json_encode(_('Choose an end date, at least one weekday, and complete each calendar row.')); ?>,true);return;}
        var data=tokenData('preview_schedule_calendar'),epoch=generation;
        data.set('schedule_id',document.getElementById('sls-schedule-id').value);data.set('schedule_calendar_json',json.value);data.set('schedule_calendar_complete','1');
        form.querySelectorAll('[name="schedule_occurrences[]"]').forEach(function(input){data.append('schedule_occurrences[]',input.value);});
        setBusy(true);message(<?php echo json_encode(_('Checking the planned dates…')); ?>,false);
        request(data).then(function(body){
            if(epoch!==generation)return;if(!Array.isArray(body.occurrences)||body.occurrences.length>366)throw new Error(<?php echo json_encode(_('The PBX returned an invalid date list.')); ?>);
            var list=output.querySelector('ol');list.textContent='';body.occurrences.forEach(function(row){var li=document.createElement('li');li.textContent=String(row.local_datetime).replace('T',' ')+' '+body.timezone+' · '+row.run_at_utc;list.appendChild(li);});
            output.hidden=false;message(body.occurrences.length+' '+<?php echo json_encode(_('planned date(s). Review this list before saving. Editing any date invalidates this preview.')); ?>,false);
        }).catch(function(error){if(epoch===generation)message(error.message,true);}).finally(function(){setBusy(false);});
    });
    importButton.addEventListener('click',function(){
        if(busy)return;var file=document.getElementById('sls-calendar-file').files[0];
        if(!file||file.size>262144||!(/\.(ics|csv)$/i.test(file.name))){message(<?php echo json_encode(_('Choose an .ics or .csv file of at most 256 KiB.')); ?>,true);return;}
        var epoch=generation,data=tokenData('import_schedule_calendar');data.set('calendar_format',file.name.toLowerCase().endsWith('.ics')?'ics':'csv');
        setBusy(true);message(<?php echo json_encode(_('Reading holiday dates…')); ?>,false);
        file.arrayBuffer().then(function(bytes){data.set('calendar_text',new TextDecoder('utf-8',{fatal:true}).decode(bytes));return request(data);}).then(function(body){
            if(epoch!==generation)return;if(!Array.isArray(body.exclusions)||body.exclusions.length>100)throw new Error(<?php echo json_encode(_('The PBX returned an invalid holiday list.')); ?>);
            var saved=values().exclusions,keys=new Set(saved.map(function(row){return row.start+'/'+row.end;}));
            var added=body.exclusions.filter(function(row){return !keys.has(row.start+'/'+row.end);});
            if(saved.length+added.length>100)throw new Error(<?php echo json_encode(_('The combined calendar would exceed 100 holiday ranges. Remove unused ranges before importing.')); ?>);
            added.forEach(function(row){addRow('exclusions',row);});message(added.length+' '+<?php echo json_encode(_('holiday range(s) added for review. Exact duplicate ranges were kept once. Save the schedule to apply changes.')); ?>,false);
        }).catch(function(error){if(epoch===generation)message(error.message||<?php echo json_encode(_('The calendar must be a valid UTF-8 file.')); ?>,true);}).finally(function(){setBusy(false);});
    });
    form.addEventListener('submit',function(event){if(recurrence.value==='calendar'&&!serialize()){event.preventDefault();event.stopImmediatePropagation();message(<?php echo json_encode(_('Complete the calendar rules before saving.')); ?>,true);}},true);
    panel.slsCalendar={reset:reset,zone:function(){return document.getElementById('sls-calendar-zone').textContent;}};toggle();
})();
</script>
