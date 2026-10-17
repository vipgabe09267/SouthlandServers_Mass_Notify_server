// Reviewed wording is entered by an operator; no translation service is called.
function languageEditor(parent, initial) {
    const section = node('section', {class: 'sls-inc-section'});
    const heading = node('h3');
    heading.append(node('i', {class: 'fa fa-language', 'aria-hidden': 'true'}), document.createTextNode(' Reviewed language variants'));
    section.append(heading, node('p', {class: 'sls-inc-help'},
        'Store up to six reviewed translations using the same required {{fields}} as the default message. Recipient language preferences select these versions; recipients without an available translation receive the launch language. English, Spanish, French, German and Portuguese can use matching installed speech voices. Other languages require text or tones. SLS does not translate messages.'));
    const rows = node('div', {class: 'sls-inc-resource-grid'});
    const add = node('button', {type: 'button', class: 'btn btn-default btn-sm'});
    add.append(node('i', {class: 'fa fa-plus', 'aria-hidden': 'true'}), document.createTextNode(' Add language'));
    section.append(rows, add); parent.append(section);
    const entries = [];
    function create(value) {
        if (entries.length >= 6) { status('A template supports up to six reviewed language variants.', true); return; }
        const card = node('div', {class: 'sls-inc-resource-card'});
        const label = field(card, 'Language name', input(value.label, 80, true));
        const locale = field(card, 'Language tag', input(value.locale, 35, true), 'For example: en-US, es-MX or fr-CA.');
        const title = field(card, 'Reviewed title', input(value.title, 80, true));
        const message = node('textarea', {maxlength: '500', required: 'required', rows: '4'});
        message.value = value.message || ''; field(card, 'Reviewed message', message);
        const note = field(card, 'Reviewer and revision', input(value.review_note, 100, true));
        const reviewed = check(card, 'A competent reviewer approved this wording', value.reviewed === true);
        reviewed.required = true;
        [label, locale, title, message, note].forEach(control => control.addEventListener('input', () => { reviewed.checked = false; }));
        const remove = node('button', {type: 'button', class: 'btn btn-default btn-sm', 'aria-label': 'Remove language variant'});
        remove.append(node('i', {class: 'fa fa-trash-o', 'aria-hidden': 'true'}), document.createTextNode(' Remove language'));
        const entry = {card, label, locale, title, message, note, reviewed};
        remove.addEventListener('click', () => { entries.splice(entries.indexOf(entry), 1); card.remove(); add.disabled = false; });
        card.append(remove); rows.append(card); entries.push(entry); add.disabled = entries.length >= 6;
    }
    add.addEventListener('click', () => create({}));
    (initial || []).forEach(create);
    return () => entries.map(entry => ({locale: entry.locale.value.trim(), label: entry.label.value.trim(),
        title: entry.title.value.trim(), message: entry.message.value.trim(),
        review_note: entry.note.value.trim(), reviewed: entry.reviewed.checked}));
}

function editRecipientLanguages() {
    open('Recipient language preferences', 'Save preferences', 'Apply Config activates preferences for new incidents. Existing incidents keep their saved wording, voices and recipients.');
    const channels = [['extensions','Phone extension'],['desktop_clients','Desktop client'],['voice_recipient_ids','External voice'],['email_recipient_ids','Email'],['sms_recipient_ids','SMS'],['webhook_ids','Webhook'],
        ['browser_recipient_ids','Browser subscribers · Labs'],['connector_ids','Notification connectors · Labs']];
    const entries = (data.recipient_languages || []).map(row => Object.assign({},row));
    const recipientLabels = new Map(Object.entries(data.choices).map(([channel, rows]) =>
        [channel, new Map(rows.map(row => [row.id, row.name]))]));
    const languages = new Map();
    data.templates.forEach(template => (template.language_variants || []).forEach(row => languages.set(row.locale,row.label+' · '+row.locale)));
    entries.forEach(row => { if(!languages.has(row.locale))languages.set(row.locale,row.locale+' · no current reviewed variant'); });
    body.append(node('p',{class:'sls-inc-help'},'Preferences route only recipients already selected for an incident. They never add recipients or grant access. A missing reviewed translation uses the chosen fallback language.'));
    const grid=node('div',{class:'sls-inc-grid'});body.append(grid);
    const channel=field(grid,'Destination type',select(channels,'desktop_clients'));
    const destination=field(grid,'Destination',node('select'));
    const locale=field(grid,'Preferred reviewed language',select([['','Choose a language']].concat(Array.from(languages)),''));
    function destinations(selected) {
        destination.replaceChildren(node('option',{value:''},'Choose a destination'));
        (data.choices[channel.value] || []).forEach(row => destination.append(node('option',{value:row.id},row.name)));
        if(selected&&!Array.from(destination.options).some(row=>row.value===selected))destination.append(node('option',{value:selected},selected+' · unavailable saved destination'));
        destination.value=selected || '';
    }
    channel.addEventListener('change',()=>destinations(''));destinations('');
    const actions=node('div',{class:'sls-inc-actions'}),add=node('button',{type:'button',class:'btn btn-default'},'Add preference');
    add.prepend(node('i',{class:'fa fa-plus','aria-hidden':'true'}),document.createTextNode(' '));actions.append(add);body.append(actions);
    const search=field(body,'Find saved preferences',input('',120,false)),listing=node('div',{class:'sls-inc-table-wrap'}),pager=node('div',{class:'sls-inc-actions'});body.append(listing,pager);
    let page=0;
    function label(row) { return recipientLabels.get(row.channel)?.get(row.recipient_id) ?? row.recipient_id+' · unavailable'; }
    function render() {
        const matches=entries.filter(row=>(label(row)+' '+row.locale+' '+row.channel).toLowerCase().includes(search.value.toLowerCase()));
        page=Math.max(0,Math.min(page,Math.ceil(matches.length/40)-1));listing.replaceChildren();pager.replaceChildren();
        if(!matches.length)listing.append(node('p',{class:'sls-inc-help'},entries.length ? 'No matching preferences.' : 'No preferences saved. All recipients use the selected launch language.'));
        else {
            const table=node('table'),head=node('thead'),heading=node('tr'),rows=node('tbody');['Destination','Language','Actions'].forEach(text=>heading.append(node('th',{},text)));head.append(heading);table.append(head,rows);
            matches.slice(page*40,page*40+40).forEach(row=>{
                const tr=node('tr'),buttons=node('td'),edit=node('button',{type:'button',class:'btn btn-default btn-sm'},'Edit'),remove=node('button',{type:'button',class:'btn btn-default btn-sm'},'Remove');
                edit.addEventListener('click',()=>{channel.value=row.channel;destinations(row.recipient_id);locale.value=row.locale;destination.focus();});
                remove.addEventListener('click',()=>{entries.splice(entries.indexOf(row),1);render();});buttons.append(edit,document.createTextNode(' '),remove);tr.append(node('td',{},label(row)),node('td',{},row.locale),buttons);rows.append(tr);
            });listing.append(table);
        }
        const previous=node('button',{type:'button',class:'btn btn-default btn-sm'},'Previous'),next=node('button',{type:'button',class:'btn btn-default btn-sm'},'Next');
        previous.disabled=page===0;next.disabled=(page+1)*40>=matches.length;previous.addEventListener('click',()=>{page--;render();});next.addEventListener('click',()=>{page++;render();});pager.append(previous,node('span',{class:'sls-inc-muted'},matches.length+' shown · '+entries.length+' / 2000 saved'),next);
    }
    search.addEventListener('input',()=>{page=0;render();});
    add.addEventListener('click',()=>{
        if(!destination.value || !locale.value){status('Choose a destination and reviewed language before adding a preference.',true);return;}
        const existing=entries.findIndex(row=>row.channel===channel.value&&row.recipient_id===destination.value);
        if(existing<0&&entries.length>=2000){status('Recipient languages support up to 2000 device preferences.',true);return;}
        const value={channel:channel.value,recipient_id:destination.value,locale:locale.value};if(existing<0)entries.push(value);else entries[existing]=value;status('',true);render();
    });render();
    handler=async()=>{await call('save_languages',{recipient_languages:entries});location.reload();};
}

function reviewedUpdateLanguages(parent) {
    const locales=Array.from(new Set((data.incident_languages || []).filter(Boolean)));
    if(!locales.length)return()=>[];
    const section=node('section',{class:'sls-inc-section'});section.append(node('h3',{},'Optional reviewed translations'),node('p',{class:'sls-inc-help'},'Enter and approve each translated update separately. An omitted translation uses the new base message and its matching base speech voice. Initial translations are never reused for an update.'));parent.append(section);
    const entries=locales.map(locale=>{
        const details=node('details',{class:'sls-inc-resource-card'});details.append(node('summary',{},locale));
        const message=node('textarea',{maxlength:'500',rows:'4'});field(details,'Reviewed '+locale+' update',message);
        const note=field(details,'Reviewer and revision',input('',100,false)),reviewed=check(details,'A competent reviewer approved this update',false);
        message.addEventListener('input',()=>{reviewed.checked=false;note.required=message.value.trim()!=='';reviewed.required=note.required;});note.addEventListener('input',()=>reviewed.checked=false);section.append(details);return {locale,message,note,reviewed};
    });
    return()=>entries.filter(row=>row.message.value.trim()).map(row=>({locale:row.locale,message:row.message.value.trim(),review_note:row.note.value.trim(),reviewed:row.reviewed.checked}));
}
