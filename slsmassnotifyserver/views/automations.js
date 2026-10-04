(function () {
    'use strict';
    const root = document.getElementById('sls-automations');
    if (!root) return;
    const data = JSON.parse(document.getElementById('sls-auto-data').textContent);
    const el = name => document.getElementById('sls-auto-' + name);
    const make = (tag, text, style) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (style) n.className = style; return n; };
    const clone = value => JSON.parse(JSON.stringify(value));
    const types = {panic: 'Panic identity · signed API', sensor: 'Sensor · signed JSON', cap: 'Trusted CAP 1.2 feed', emergency_call: 'Emergency-call observation', brightsign_udp: 'BrightSign · UDP presentation event', patlite_nhv: 'PATLITE NHV / NHB · signal tower', script: 'Approved local script'};
    let state = data.state, draft = null, category = '', dirty = false, busy = false;
    function status(message, ok) { el('status').textContent = message; el('status').className = 'alert ' + (ok ? 'alert-success' : 'alert-danger'); el('status').hidden = false; el('status').focus({preventScroll:true}); }
    function leave() { return !dirty || window.confirm('Discard your unsaved trigger/action changes?'); }
    function display() {
        el('pending').hidden = !state.pending;
        for (const key of ['rules', 'actions']) {
            const list = el(key); list.replaceChildren();
            for (const item of state.config[key]) {
                const row = make('div', undefined, 'sls-auto-item'), body = make('div'), edit = make('button', 'View / Edit', 'btn btn-default btn-sm');
                body.append(make('strong', item.name), make('p', types[item.kind]), make('p', item.enabled ? 'Enabled after Apply Config' : 'Disabled'));
                edit.type = 'button'; edit.addEventListener('click', () => { if (leave()) open(key, item); }); row.append(body, edit); list.append(row);
            }
            if (!list.children.length) list.append(make('p', key === 'rules' ? 'No triggers configured. Add a source when you are ready.' : 'No device or script actions configured.', 'sls-auto-empty'));
        }
        const history = el('history'); history.replaceChildren();
        if (state.storage_error) history.append(make('p', state.storage_error, 'alert alert-warning'));
        for (const health of Object.values(state.source_health || {})) { if (!health.ok) history.append(make('p', health.detail, 'alert alert-warning')); }
        for (const event of state.history) {
            const card = make('div', undefined, 'sls-auto-item'), body = make('div');
            body.append(make('strong', event.rule_name), make('p', new Date(event.created_at * 1000).toLocaleString() + ' · ' + event.state));
            for (const [key, result] of Object.entries(event.results)) {
                const line = make('div', undefined, 'sls-auto-result');
                line.append(make('p', (key === 'announcement' ? 'Announcement' : (state.config.actions.find(action => action.id === key)?.name || 'Worker')) + ': ' + (result.detail || result.message || result.state || 'Result recorded')));
                const incident = result.incident?.id || result.incident_id;
                if (typeof incident === 'string' && /^inc_[a-f0-9]{32}$/.test(incident)) {
                    const link = make('a', 'Open incident delivery details'); link.href = 'config.php?display=slsmassnotifyserver_incidents&incident_id=' + encodeURIComponent(incident); line.append(link);
                }
                body.append(line);
            }
            card.append(body); history.append(card);
        }
        if (!history.children.length) history.append(make('p', 'No trigger activity recorded.', 'sls-auto-empty'));
    }
    function field(key, label, help, options = {}) {
        const wrap = make('div', undefined, 'form-group' + (options.wide ? ' sls-auto-wide' : ''));
        const caption = make('label', label), control = make(options.choices ? 'select' : 'input', undefined, 'form-control');
        control.id = 'sls-auto-input-' + key; caption.htmlFor = control.id;
        if (options.choices) { for (const [value, text] of options.choices) { const option = make('option', text); option.value = value; control.append(option); } }
        else { control.type = options.type || 'text'; control.maxLength = options.max || 512; control.autocomplete = 'off'; }
        if (options.type === 'number') { control.min = options.min; control.max = options.max; control.step = 1; }
        control.value = draft[key] ?? ''; control.required = options.required !== false;
        control.addEventListener('input', () => { draft[key] = options.type === 'number' ? Number(control.value) : control.value; dirty = true; });
        if (options.change) control.addEventListener('change', options.change);
        wrap.append(caption, control);
        if (help) { const tip = make('p', help, 'help-block'); tip.id = control.id + '-help'; control.setAttribute('aria-describedby', tip.id); wrap.append(tip); }
        el('fields').append(wrap); return control;
    }
    function check(key, label, help) {
        const wrap = make('div', undefined, 'form-group sls-auto-wide'), caption = make('label', undefined, 'sls-location-check'), control = make('input');
        control.type = 'checkbox'; control.id = 'sls-auto-input-' + key; control.checked = draft[key] === true;
        control.addEventListener('change', () => { draft[key] = control.checked; dirty = true; });
        caption.append(control, make('span', label)); wrap.append(caption); if (help) wrap.append(make('p', help, 'help-block')); el('fields').append(wrap);
    }
    function configure() {
        el('fields').replaceChildren();
        field('name', 'Name', 'Use a recognizable source or action name.', {max:80});
        field('kind', category === 'rules' ? 'Source type' : 'Action type', '', {choices:Object.entries(types).filter(([key]) => (category === 'rules') === ['panic','sensor','cap','emergency_call'].includes(key)), change:() => {
            const next = defaults(category, draft.kind); draft = {...next, id:draft.id, name:draft.name, enabled:false}; dirty = true; configure();
        }});
        check('enabled', 'Enable after Apply Config', 'Review the complete routing and perform site acceptance before enabling an automatic source.');
        if (category === 'rules') {
            field('location_id', 'Assigned location', 'Required for enrolled panic identities and emergency-call sources.', {required:false, choices:[['','No assigned location'], ...state.locations.map(item => [item.id,item.name])]});
            field('template_id', 'Incident template', 'The template defines the message and all phone, desktop, SMS, email and webhook recipients. Create or edit it in Incidents and Drills.', {required:false, choices:[['','Actions only'], ...state.templates.map(item => [item.id,item.name])], change:() => { draft.fields = {}; configure(); }});
            for (const item of state.templates.find(item => item.id === draft.template_id)?.fields || []) {
                const key = 'binding_' + item.key; draft[key] = draft.fields[item.key] ?? '';
                field(key, item.label, 'Use fixed text or @location, @event, @message, @caller.', {max:200});
            }
            const actions = make('div', undefined, 'form-group sls-auto-wide'); actions.append(make('label', 'Additional actions'));
            const choices = make('div', undefined, 'sls-auto-checks');
            for (const action of state.config.actions) {
                const label = make('label', undefined, 'sls-location-check'), input = make('input'); input.type = 'checkbox'; input.checked = draft.action_ids.includes(action.id);
                input.addEventListener('change', () => { draft.action_ids = input.checked ? [...draft.action_ids,action.id] : draft.action_ids.filter(id => id !== action.id); dirty = true; }); label.append(input,make('span', action.name + (action.enabled ? '' : ' (disabled)'))); choices.append(label);
            }
            actions.append(choices, make('p', state.config.actions.length ? 'Select only the explicit device/script actions this source may run.' : 'Save an action first to assign it here. A template can be used on its own.', 'help-block')); el('fields').append(actions);
            if (['sensor','cap'].includes(draft.kind)) field('event','Exact event name','Only this exact source event name activates the rule.',{max:80});
            if (draft.kind === 'cap') {
                field('feed_url','Trusted CAP URL','Public HTTPS on port 443; direct CAP 1.2 or inline CAP Atom feed. Redirects and private addresses are rejected.',{max:2048});
                field('sender','Trusted CAP sender','Must exactly match the CAP sender field. Feed wording never changes recipients or severity.',{max:200});
            }
            if (draft.kind === 'emergency_call') {
                field('callers_text','Authorized internal callers','Comma-separated existing PJSIP extensions. Assign callers at one reviewed location.',{max:2100});
                field('numbers_text','Designated dialled numbers','Exact numbers to observe, separated by commas. Observation does not change call routing.',{max:2100});
            }
            if (draft.kind === 'panic') {
                field('dial_extension','Phone activation extension (optional)','Assign an unused internal number to a phone’s speed-dial key. The caller must hear the confirmation prompt and press 1.',{max:20,required:false});
                field('callers_text','Authorized phone extensions','Comma-separated existing PJSIP extensions. Caller ID alone never authorizes activation.',{max:2100,required:false});
                field('confirmation_prompt','Spoken confirmation','Generated with the selected announcement TTS voice during Apply Config. Explain the purpose and ask the caller to press 1. These calls create real alerts.',{max:300,wide:true});
            }
            if (draft.enrolled) check('rotate_secret','Rotate enrollment secret','The replacement appears once after saving and takes effect with Apply Config. Existing enrollment then stops working.');
            check('allow_tests','Accept explicit test events','Test events use this rule’s real configured recipients and actions. Test only with an approved test audience.');
            field('max_age_seconds','Maximum event age (seconds)','Late or expired source events are rejected; accepted work retains an absolute deadline.',{type:'number',min:30,max:600});
            field('cooldown_seconds','Minimum interval (seconds)','Limits repeated activation from this one source; duplicate event IDs remain idempotent.',{type:'number',min:10,max:3600});
        } else if (draft.kind === 'script') {
            field('path','Absolute script path','Use a root-owned .sh or .js file and protected parent directories, for example /usr/local/lib/sls-mass-notify/actions/alert.sh. Scripts run as asterisk, with its permissions, for at most 10 seconds.',{max:512,wide:true});
            check('approve_script','I reviewed and approve the current script file','SLS records the exact file fingerprint. Editing or replacing it requires approval again. Event JSON arrives on standard input; arbitrary shell command strings are not accepted.');
        } else {
            field('host','Device IPv4 address','Private unicast address on your managed device network.',{max:15});
            field('port','Port','Match the receiver configuration.',{type:'number',min:1,max:65535});
            if (draft.kind === 'brightsign_udp') field('message','Presentation event','The exact UDP event string configured in BrightAuthor. Receiving this string must select the intended presentation state.',{max:128,wide:true});
            else {
                field('scheme','Device protocol','HTTPS verifies the tower certificate. Use HTTP only where the local device network policy permits it.',{choices:[['https','HTTPS'],['http','HTTP'] ]});
                field('led','Five LED states','Red, amber, green, blue, white: 0 off, 1 steady, 2 flashing. Example: 10000.',{max:5});
                check('clear','Clear the tower instead','Sends the explicit clear command. It does not infer an incident all-clear or restore a previous state.');
            }
        }
    }
    function defaults(key, kind) {
        const common = {id:'',name:'',enabled:false,kind:kind || (key === 'rules' ? 'panic' : 'brightsign_udp')};
        if (key === 'rules') return {...common,location_id:'',template_id:'',fields:{},action_ids:[],allow_tests:false,max_age_seconds:300,cooldown_seconds:60,event:'',sender:'',feed_url:'',callers_text:'',numbers_text:'',dial_extension:'',confirmation_prompt:'Emergency alert activation. Press one to send the configured alert. Hang up to cancel.'};
        return common.kind === 'script' ? {...common,path:'',sha256:''} : {...common,host:'',port:common.kind === 'brightsign_udp' ? 5000 : 443,message:'',scheme:'https',led:'10000',clear:false};
    }
    function open(key, item) {
        category = key; draft = item ? clone(item) : defaults(key); dirty = false;
        if (['emergency_call','panic'].includes(draft.kind)) { draft.callers_text = (draft.callers || []).join(', '); if (draft.kind === 'emergency_call') draft.numbers_text = draft.numbers.join(', '); }
        el('title').textContent = (draft.id ? 'Edit ' : 'Add ') + (key === 'rules' ? 'trigger' : 'action'); el('remove').hidden = !draft.id;
        el('editor').hidden = false; configure(); el('editor').scrollIntoView({behavior:'smooth',block:'start'}); el('fields').querySelector('input').focus({preventScroll:true});
    }
    function payload() {
        const item = {id:draft.id,name:draft.name,enabled:draft.enabled,kind:draft.kind};
        const keys = category === 'rules' ? ['location_id','template_id','action_ids','allow_tests','max_age_seconds','cooldown_seconds'] : [];
        if (category === 'rules') {
            item.fields = {};
            for (const field of state.templates.find(t => t.id === draft.template_id)?.fields || []) item.fields[field.key] = draft['binding_' + field.key] ?? '';
            if (['sensor','cap'].includes(draft.kind)) keys.push('event');
            if (draft.kind === 'cap') keys.push('feed_url','sender');
            if (draft.kind === 'panic') { keys.push('dial_extension','confirmation_prompt'); item.callers=draft.callers_text.split(',').map(v=>v.trim()).filter(Boolean); }
            if (draft.kind === 'emergency_call') { for (const key of ['callers','numbers']) item[key] = draft[key + '_text'].split(',').map(v => v.trim()).filter(Boolean); }
        } else if (draft.kind === 'script') keys.push('path','sha256');
        else { keys.push('host','port'); keys.push(...(draft.kind === 'brightsign_udp' ? ['message'] : ['scheme','led','clear'])); }
        for (const key of keys) item[key] = draft[key];
        return {revision:state.revision,kind:category,item,rotate_secret:draft.rotate_secret === true,approve_script:draft.approve_script === true};
    }
    async function save(remove) {
        if (busy) return;
        busy = true; root.setAttribute('aria-busy','true'); const buttons = [...root.querySelectorAll('button')]; buttons.forEach(b => b.disabled = true);
        try {
            const body = new URLSearchParams({slsmassnotifyserver_csrf:data.csrf,payload:JSON.stringify({...payload(),remove})});
            const response = await fetch('config.php?display=slsmassnotifyserver_automations',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
            const result = await response.json(); if (!result.success) throw new Error(result.message || 'Settings save did not confirm completion. Reload before retrying.');
            state = result.state; dirty = false; draft = null; el('editor').hidden = true;
            el('secret').value = result.enrollment_secret || ''; el('enrollment').hidden = !result.enrollment_secret;
            display(); status(result.message,true);
        } catch (error) { status(error.message,false); }
        finally { busy = false; root.removeAttribute('aria-busy'); buttons.forEach(b => b.disabled = false); }
    }
    root.querySelectorAll('[data-auto-add]').forEach(button => button.addEventListener('click', () => { if (leave()) open(button.dataset.autoAdd); }));
    el('editor').addEventListener('submit', event => { event.preventDefault(); save(false); });
    el('close').addEventListener('click', () => { if (leave()) { dirty = false; draft = null; el('editor').hidden = true; } });
    el('remove').addEventListener('click', () => { if (window.confirm('Remove this saved item? Existing delivery history is retained.')) save(true); });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    display();
}());
