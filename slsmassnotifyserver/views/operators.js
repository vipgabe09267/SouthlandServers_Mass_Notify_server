(() => {
  'use strict';
  const root = document.getElementById('sls-operator-access');
  if (!root) return;
  const data = JSON.parse(document.getElementById('sls-operator-data').textContent);
  const state = data.state;
  const keys = ['id','username','source','name','email','role','enabled','site_ids','location_ids','group_ids','personal_members','actions','channels'];
  const rows = state.access.accounts.map(row => ({...row, rebind:false}));
  const list = root.querySelector('#ops-accounts');
  const modal = root.querySelector('#ops-modal');
  const body = root.querySelector('#ops-editor');
  const error = root.querySelector('#ops-editor-error');
  const channels = {extensions:'Phone extensions',desktop_client_ids:'Desktop applications',voice_recipient_ids:'External calls',sms_recipient_ids:'SMS / MMS',email_recipient_ids:'Email',webhook_ids:'Webhooks'};
  const actions = {view:'View assigned work',send:'Send announcements and incident updates',schedule:'Create and manage own schedules',roll_call:'Record assigned human responses'};
  const presets = {viewer:['view'],sender:['view','send'],scheduler:['view','schedule'],warden:['view','roll_call'],administrator:Object.keys(actions)};
  let focus = null;
  let dirty = false;
  const resetModal = root.querySelector('#ops-reset-modal');
  let resetFocus = null;
  for (const id of ['ops-portal-enabled','ops-enforce']) root.querySelector('#'+id).addEventListener('change',() => { dirty = true; });

  async function request(action,payload) {
    const form = new URLSearchParams({slsmassnotifyserver_action:action,slsmassnotifyserver_csrf:data.csrf,payload:JSON.stringify(payload)});
    const response = await fetch(data.endpoint,{method:'POST',body:form,credentials:'same-origin',cache:'no-store'});
    const answer = await response.json();
    if (!response.ok || !answer.success) throw new Error(answer.message || 'The operator request was rejected.');
    return answer;
  }
  function closeReset() {
    resetModal.hidden = true;
    root.querySelector('#ops-reset-url').value = '';
    root.querySelector('#ops-reset-result').textContent = '';
    (resetFocus?.isConnected ? resetFocus : root.querySelector('#ops-save')).focus();
  }
  root.querySelectorAll('[data-reset-close]').forEach(button => button.addEventListener('click',closeReset));
  resetModal.addEventListener('keydown',event => {
    if(event.key==='Escape'){event.preventDefault();closeReset();}
    if(event.key!=='Tab') return;
    const controls=[...resetModal.querySelectorAll('input,button')];
    if(event.shiftKey&&document.activeElement===controls[0]){event.preventDefault();controls.at(-1).focus();}
    else if(!event.shiftKey&&document.activeElement===controls.at(-1)){event.preventDefault();controls[0].focus();}
  });
  root.querySelector('#ops-reset-copy').addEventListener('click',async () => {
    const input=root.querySelector('#ops-reset-url');
    try{await navigator.clipboard.writeText(input.value);root.querySelector('#ops-reset-result').textContent='Link copied. Share it privately.';}
    catch(error){input.focus();input.select();root.querySelector('#ops-reset-result').textContent='Select and copy the link above.';}
  });
  async function recovery(action,row,button) {
    const result=root.querySelector('#ops-result');
    if(dirty){result.textContent='Save account changes before managing reset links.';return;}
    resetFocus=button;button.disabled=true;
    try{
      const answer=await request(action,{id:row.id});
      Object.assign(row,answer.account);
      result.textContent=answer.message;render();
      if(action==='generate_reset_link'){
        root.querySelector('#ops-reset-url').value=answer.reset_url;
        root.querySelector('#ops-reset-expiry').textContent='Expires '+new Date(answer.expires_at).toLocaleString()+'. Closing this dialog removes the link from this page.';
        resetModal.hidden=false;root.querySelector('#ops-reset-url').focus();root.querySelector('#ops-reset-url').select();
      }
    }catch(error){result.textContent=error.message;button.disabled=false;}
  }

  function node(tag, text = '', attrs = {}) {
    const element = document.createElement(tag);
    element.textContent = text;
    Object.entries(attrs).forEach(([key,value]) => element.setAttribute(key,value));
    return element;
  }
  function field(parent, label, control, help = '') {
    const box = node('label',label,{class:'ops-field'});
    box.append(control);
    if (help) box.append(node('small',help,{class:'ops-muted'}));
    parent.append(box);
    return control;
  }
  function check(parent, label, selected) {
    const box = node('label','',{class:'ops-check'});
    const input = node('input','',{type:'checkbox'});
    input.checked = selected;
    box.append(input,node('span',label));
    parent.append(box);
    return input;
  }
  function scope(parent, label, choices, selected) {
    const box = node('details');
    box.append(node('summary',label));
    const scroll = node('div','',{class:'ops-scroll'});
    box.append(scroll);
    const items = choices.map(row => [row.id,check(scroll,row.label || row.name,selected.includes(row.id))]);
    if (!choices.length) scroll.append(node('p','None configured.',{class:'ops-muted'}));
    parent.append(box);
    return () => items.filter(([,input]) => input.checked).map(([id]) => id);
  }
  function close() {
    modal.hidden = true;
    modal.querySelector('form').onsubmit = null;
    // Clear an initial/reset password when the editor closes.
    body.querySelectorAll('input[type=password]').forEach(input => { input.value = ''; });
    if (focus) focus.focus();
  }
  root.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click',close));
  modal.addEventListener('keydown',event => {
    if (event.key === 'Escape') { event.preventDefault(); close(); }
    if (event.key !== 'Tab') return;
    const controls = [...modal.querySelectorAll('input,select,button,summary')].filter(input => !input.disabled && input.offsetParent !== null);
    if (!controls.length) return;
    if (event.shiftKey && document.activeElement === controls[0]) { event.preventDefault(); controls.at(-1).focus(); }
    else if (!event.shiftKey && document.activeElement === controls.at(-1)) { event.preventDefault(); controls[0].focus(); }
  });

  function edit(index) {
    const row = index < 0 ? {id:'',name:'',email:'',role:'viewer',enabled:true,site_ids:[],location_ids:[],group_ids:[],personal_members:{},actions:['view'],channels:Object.keys(channels),source:'portal',username:''} : rows[index];
    const local = row.source === 'portal';
    focus = document.activeElement;
    body.replaceChildren(); error.textContent = '';
    root.querySelector('#ops-editor-title').textContent = index < 0 ? 'Create operator login' : 'Edit operator account';
    const credentials = node('div','',{class:'ops-grid'});
    body.append(credentials);
    const username = field(credentials,'Username',node('input','',{maxlength:'80',required:'required',pattern:'[A-Za-z0-9_.@-]+',autocomplete:'off'}));
    username.value = row.username; username.disabled = index >= 0;
    const name = field(credentials,'Display name',node('input','',{maxlength:'100',autocomplete:'off'})); name.value = row.name;
    let password = null, reset = null, rebind = null, email = null;
    if (local) {
      email = field(credentials,'Recovery email (optional)',node('input','',{type:'email',maxlength:'254',autocomplete:'off'}),'Used by Forgot password. Two recovery emails or failed recovery attempts require administrator assistance.');
      email.value=row.email || '';
      password = field(body,index < 0 ? 'Initial password' : 'Reset password (optional)',node('input','',{type:'password',minlength:'15',maxlength:'128',autocomplete:'new-password'}), 'At least 15 characters. The operator changes this password at first sign-in. A reset signs out existing sessions.');
      password.required = index < 0;
      if (row.password) password.value = row.password;
      if (index >= 0) reset = check(body,'Reset authenticator enrollment and recovery codes',row.reset_totp || false);
      body.append(node('p',row.totp_enrolled ? 'Authenticator enrolled · '+row.recovery_remaining+' recovery code(s) remaining.' : 'Authenticator enrollment is mandatory at first sign-in.',{class:'ops-muted'}));
    } else {
      body.append(node('p','Legacy '+(row.source === 'database' ? 'PBX' : 'User Management')+' assignment. Create a dedicated operator login for password and authenticator setup.',{class:'ops-notice'}));
      rebind = check(body,'Approve the current PBX login identity',row.rebind || false);
    }
    const role = node('select');
    Object.keys(presets).forEach(value => role.append(node('option',value[0].toUpperCase()+value.slice(1),{value})));
    role.value = row.role;
    field(body,'Permission preset',role,'Choose a starting role, then select the allowed actions and delivery channels. Administrators have full SLS access.');
    const enabled = check(body,'Enable this login',row.enabled);
    const restricted = node('div'); body.append(restricted);
    const actionBox = node('div','',{class:'ops-permissions'}), channelBox = node('div','',{class:'ops-permissions'});
    actionBox.append(node('h3','Allowed actions')); channelBox.append(node('h3','Allowed channels'));
    const actionChecks = Object.entries(actions).map(([key,label]) => [key,check(actionBox,label,(row.actions || presets[row.role]).includes(key))]);
    actionChecks[0][1].checked = true; actionChecks[0][1].disabled = true;
    const channelChecks = Object.entries(channels).map(([key,label]) => [key,check(channelBox,label,(row.channels || Object.keys(channels)).includes(key))]);
    const permissionGrid = node('div','',{class:'ops-grid'}); permissionGrid.append(actionBox,channelBox); restricted.append(permissionGrid);
    restricted.append(node('h3','Who can receive notifications'));
    restricted.append(node('p','Site, location and audience grants are combined. Sites and locations include their descendants. Select personal devices alone to allow notifications only to this person.',{class:'ops-muted'}));
    const siteIds = scope(restricted,'Sites',state.sites,row.site_ids);
    const locationIds = scope(restricted,'Locations',state.locations || [],row.location_ids || []);
    const groupIds = scope(restricted,'Saved audiences',state.audiences,row.group_ids);
    const personal = node('details'); personal.append(node('summary','Personal devices')); restricted.append(personal);
    const deviceGetters = {};
    Object.keys(channels).forEach(key => {
      const choices = Object.values(state.devices?.[key] || {}).map(device => ({...device,label:device.label+(device.available ? '' : ' · unavailable')}));
      deviceGetters[key] = scope(personal,channels[key],choices,row.personal_members?.[key] || []);
    });
    function roleChanged(update) {
      restricted.hidden = role.value === 'administrator';
      if (update) actionChecks.forEach(([key,input]) => { input.checked = presets[role.value].includes(key); });
    }
    role.addEventListener('change',() => roleChanged(true)); roleChanged(false);
    modal.querySelector('form').onsubmit = event => {
      event.preventDefault();
      if (index < 0 && rows.some(item => item.username.toLowerCase() === username.value.toLowerCase())) { error.textContent = 'That username already exists.'; return; }
      if (reset?.checked && !password.value) { error.textContent = 'Set a new initial password when resetting an authenticator.'; password.focus(); return; }
      const admin = role.value === 'administrator';
      const members = Object.fromEntries(Object.keys(channels).map(key => [key,admin ? [] : deviceGetters[key]() ]));
      const next = {...row,id:row.id,username:username.value,source:row.source,name:name.value.trim(),email:email?.value.trim() || '',role:role.value,enabled:enabled.checked,
        site_ids:admin ? [] : siteIds(),location_ids:admin ? [] : locationIds(),group_ids:admin ? [] : groupIds(),personal_members:members,
        actions:admin ? Object.keys(actions) : actionChecks.filter(([,input]) => input.checked).map(([key]) => key),
        channels:admin ? Object.keys(channels) : channelChecks.filter(([,input]) => input.checked).map(([key]) => key),
        rebind:rebind?.checked || false,password:password?.value || '',reset_totp:reset?.checked || false};
      if (!next.channels.length) { error.textContent = 'Allow at least one delivery channel.'; return; }
      if (next.enabled && !admin && !next.site_ids.length && !next.location_ids.length && !next.group_ids.length && !Object.values(members).some(ids => ids.length)) { error.textContent = 'Assign a site, location, audience or personal device.'; return; }
      if (index < 0) rows.push(next); else rows[index] = next;
      dirty=true;
      close(); render(); root.querySelector('#ops-result').textContent = 'Unsaved account changes. Select Save accounts to apply.';
    };
    modal.hidden = false;
    (username.disabled ? name : username).focus();
  }
  function render() {
    list.replaceChildren();
    if (!rows.length) { list.append(node('p','No operator logins configured. Create a login to use the separate portal.',{class:'ops-muted'})); return; }
    rows.forEach((row,index) => {
      const box = node('div','',{class:'ops-account'}), heading = node('div','',{class:'ops-heading'});
      const title = node('div'); title.append(node('strong',row.name || row.username),node('p',row.username,{class:'ops-muted'}));
      heading.append(title,node('span',row.role+(row.enabled ? ' · enabled' : ' · disabled'),{class:'ops-badge'})); box.append(heading);
      const grants = row.role === 'administrator' ? 'All SLS actions and recipients' : [row.site_ids.length+' sites',(row.location_ids || []).length+' locations',row.group_ids.length+' audiences',Object.values(row.personal_members || {}).reduce((count,ids) => count+ids.length,0)+' personal devices'].join(' · ');
      box.append(node('p',grants,{class:'ops-muted'}));
      box.append(node('p',row.source === 'portal' ? ((row.totp_enrolled ? 'Authenticator enrolled' : 'Authenticator enrollment required')+(row.password_change_required ? ' · password change required' : '')) : 'Legacy PBX assignment',{class:'ops-muted'}));
      if(row.source==='portal'){
        const info=row.password_recovery || {};
        box.append(node('p',row.email ? 'Recovery email: '+row.email+' · '+(info.email_enabled===false?'Administrator reset required':(info.emails_remaining ?? 2)+' recovery email(s) remaining') : 'No recovery email registered.',{class:'ops-muted'}));
        if(info.last_delivery && info.last_delivery!=='none')box.append(node('p','Last recovery email: '+({accepted:'accepted by Postfix; inbox delivery is unconfirmed',failed:'handoff failed; not retried',unconfirmed:'handoff unconfirmed; not retried',reserved:'handoff reserved or interrupted'}[info.last_delivery] || 'unconfirmed'),{class:'ops-muted'}));
        if(info.reset_active)box.append(node('p','Active reset link expires '+new Date(info.reset_expires_at*1000).toLocaleString()+'.',{class:'ops-muted'}));
      }
      if (row.identity_current === false) box.append(node('p','PBX login identity or permission needs review.',{class:'ops-notice ops-error'}));
      const buttons = node('div','',{class:'ops-actions'});
      const editButton = node('button','Edit',{type:'button',class:'btn btn-default btn-sm'});
      const remove = node('button','Remove',{type:'button',class:'btn btn-default btn-sm'});
      editButton.addEventListener('click',() => edit(index));
      remove.addEventListener('click',() => { rows.splice(index,1);dirty=true; render(); root.querySelector('#ops-result').textContent = 'Account removal is pending. Save accounts to revoke it.'; });
      buttons.append(editButton,remove);
      if(row.source==='portal' && row.id){
        const generate=node('button','Generate 24-hour reset link',{type:'button',class:'btn btn-default btn-sm'});
        generate.disabled=!row.enabled || !state.access.portal_enabled || !row.totp_enrolled || row.password_change_required;
        generate.title=generate.disabled?'Save an enabled login and complete authenticator setup first.':'Requires the existing authenticator to save a new password.';
        generate.addEventListener('click',() => recovery('generate_reset_link',row,generate));buttons.append(generate);
        if(row.password_recovery?.reset_active){const revoke=node('button','Revoke reset link',{type:'button',class:'btn btn-default btn-sm'});revoke.addEventListener('click',()=>recovery('revoke_reset_link',row,revoke));buttons.append(revoke);}
      }
      box.append(buttons); list.append(box);
    });
  }
  root.querySelector('#ops-add').addEventListener('click',() => edit(-1));
  root.querySelector('#ops-save').addEventListener('click',async event => {
    const button = event.currentTarget, result = root.querySelector('#ops-result');
    button.disabled = true; result.textContent = 'Saving accounts…';
    try {
      const accounts = rows.map(row => Object.fromEntries([...keys,'rebind','password','reset_totp'].filter(key => row[key] !== undefined).map(key => [key,row[key]])));
      const payload = JSON.stringify({revision:state.revision,enabled:root.querySelector('#ops-enforce').checked,portal_enabled:root.querySelector('#ops-portal-enabled').checked,accounts});
      const maximum = data.endpoint.startsWith('/mass-notify/') ? 65536 : 131072;
      if (new TextEncoder().encode(payload).length > maximum) throw new Error('The account form exceeds '+(maximum/1024)+' KiB. Use site or audience grants for large device lists.');
      const form = new URLSearchParams({slsmassnotifyserver_action:'save_access',slsmassnotifyserver_csrf:data.csrf,
        payload});
      const response = await fetch(data.endpoint,{method:'POST',body:form,credentials:'same-origin',cache:'no-store'});
      const answer = await response.json();
      if (!response.ok || !answer.success) throw new Error(answer.message || 'Account save was rejected.');
      rows.forEach(row => { delete row.password; }); result.textContent = answer.message; location.reload();
    } catch (error) { result.textContent = error.message; button.disabled = false; }
  });
  render();
})();
