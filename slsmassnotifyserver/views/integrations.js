(() => {
  'use strict';
  const root = document.getElementById('sls-integrations');
  if (!root) return;
  const data = JSON.parse(document.getElementById('sls-integration-data').textContent);
  let state = data.state;
  let config = structuredClone(state.config);
  let reviewedWarning = null;
  let inflight = false;
  let settingsDirty = false;
  const markSettingsDirty = () => {
    settingsDirty = true;
    window.SlsSaveState?.set(document.getElementById('sls-enterprise'), 'unsaved');
  };
  const meetingProviders = {zoom:"Zoom",google_meet:"Google Meet",webex:"Webex"};
  const tokenField = provider => provider === "google_meet" ? "google_access_token" : provider + "_access_token";
  const requestId = () => { const bytes = new Uint8Array(16); crypto.getRandomValues(bytes); return [...bytes].map(value => value.toString(16).padStart(2, '0')).join(''); };
  const operationIds = { meeting: requestId(), ipaws: requestId() };
  const doorIds = new Map();
  let expandedRows = new Set();
  let fieldSequence = 0;
  const status = document.getElementById('sls-integration-status');
  const categories = {
    audio: ['speakers', 'radios'],
    communications: ['meetings', 'mail_evidence', 'browser_display', 'connectors', 'responses'],
    physical: ['access_control', 'sensors', 'public_warning']
  };
  let currentCategory = 'audio';
  Object.entries(categories).forEach(([category, names]) => names.forEach(name => {
    root.querySelector(`[data-setting="${name}.enabled"]`).closest('.sls-integration-card').dataset.providerSection = category;
  }));
  function showCategory(category) {
    currentCategory = category;
    root.querySelectorAll('[data-provider-section]').forEach(card => { card.hidden = category !== 'all' && card.dataset.providerSection !== category; });
    root.querySelectorAll('[data-provider-category]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.providerCategory === category)));
  }
  root.querySelectorAll('[data-provider-category]').forEach(button => button.addEventListener('click', () => showCategory(button.dataset.providerCategory)));
  showCategory(currentCategory);
  const read = path => path.split('.').reduce((value, key) => value?.[key], config);
  const write = (path, value) => {
    const keys = path.split('.');
    const field = keys.pop();
    keys.reduce((row, key) => row[key], config)[field] = value;
  };
  const node = (tag, text, className) => {
    const result = document.createElement(tag);
    if (text !== undefined) result.textContent = text;
    if (className) result.className = className;
    return result;
  };
  const feedback = (message, error = false) => {
    status.textContent = message;
    status.dataset.error = String(error);
    status.hidden = false;
    status.focus();
  };
  const field = (parent, label, value, update, options) => {
    const wrapper = node('div', undefined, 'sls-integration-field');
    const caption = node('label',label);
    if (options?.help) {
      const tip = node('span', undefined, 'sls-field-help'); tip.tabIndex = 0; tip.dataset.help = options.help;
      tip.setAttribute('aria-label', options.help); const icon = node('span', '?'); icon.setAttribute('aria-hidden','true'); tip.append(icon); caption.append(document.createTextNode(' '),tip);
    }
    const input = options?.choices ? node('select') : options?.multiline ? node('textarea') : node('input');
    input.className = 'form-control';
    input.id = 'sls-provider-field-' + (++fieldSequence); caption.htmlFor = input.id;
    if (options?.choices) {
      options.choices.forEach(choice => {
        const option = node('option', choice.name ?? choice.label ?? choice.id);
        option.value = choice.id;
        input.append(option);
      });
    } else if (options?.multiline) {
      input.rows = 4; input.spellcheck = false;
    } else {
      input.type = options?.password ? 'password' : options?.number ? 'number' : 'text';
      if (options?.number) { input.min = String(options.min ?? 1); input.max = String(options.max ?? 60); }
      if (options?.password) {
        input.autocomplete = 'new-password';
        input.placeholder = 'Blank keeps saved credential';
      }
    }
    if (options?.choices && value && !options.choices.some(choice => choice.id === value)) {
      const saved = node('option', `Saved value (review): ${value}`); saved.value = value; input.append(saved);
    }
    input.value = value ?? '';
    if (options?.placeholder) input.placeholder = options.placeholder;
    if (options?.required) input.required = true;
    input.addEventListener('input', () => update(input.value));
    wrapper.append(caption,input);
    parent.append(wrapper);
    return input;
  };
  const tlsEditor = (parent, value, change) => {
    const details = node('details'), fields = node('div', undefined, 'sls-integration-fields');
    details.append(node('summary', 'Certificate trust for this integration'), fields);
    const policy = { ...(value || {}) };
    field(fields, 'Private CA certificates (PEM, optional)', policy.ca_pem || '', ca => { policy.ca_pem = ca; change(policy); }, { multiline: true });
    field(fields, 'Public-key pin (sha256//…, optional)', policy.public_key_pin || '', pin => { policy.public_key_pin = pin; change(policy); });
    details.append(node('p', 'Blank uses the system CA store. Hostname and certificate checks remain required. Trust saved here applies only to this integration.', 'help-block'));
    parent.append(details);
  };
  const toggle = (parent, label, enabled, update) => {
    const wrapper = node('label');
    wrapper.className = 'sls-integration-switch';
    const input = node('input');
    input.type = 'checkbox';
    input.checked = enabled === true || enabled === '1';
    input.addEventListener('change', () => update(input.checked));
    wrapper.append(input, document.createTextNode(label));
    parent.append(wrapper);
  };
  const row = (parent, label, remove) => {
    const result = node('details', undefined, 'sls-integration-row');
    result.dataset.profileKey = parent.id + ':' + parent.children.length;
    result.open = expandedRows.has(result.dataset.profileKey) || label.startsWith('New ');
    const heading = node('summary', undefined, 'sls-integration-row-heading');
    const button = node('button', 'Remove', 'btn btn-link text-danger');
    button.type = 'button';
    button.addEventListener('click', event => { event.preventDefault(); markSettingsDirty(); remove(); });
    heading.append(node('strong', label), button);
    result.append(heading);
    parent.append(result);
    return result;
  };
  async function post(action, payload) {
    if (inflight) throw new Error('An integration request is already in progress. Wait for its result.');
    inflight = true;
    const controls = [...root.querySelectorAll('input,select,textarea,button')], disabled = controls.map(control => control.disabled);
    controls.forEach(control => { control.disabled = true; });
    const controller = new AbortController(), timer = window.setTimeout(() => controller.abort(), 25000);
    try {
      const body = new URLSearchParams({ action, payload: JSON.stringify(payload), slsmassnotifyserver_csrf: data.csrf });
      const response = await fetch('config.php?display=slsmassnotifyserver_enterprise', { method: 'POST', body, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal });
      let result;
      try { result = await response.json(); }
      catch (error) { throw new Error('The PBX returned an invalid integration response. Review the web-server log before retrying.'); }
      if (!response.ok || !result || typeof result !== 'object' || Array.isArray(result) || result.success === false || result.ok === false || (result.success !== true && result.ok !== true)) {
        throw new Error(result?.message ?? 'The operation could not confirm completion. Review history before retrying.');
      }
      return result;
    } catch (error) {
      if (controller.signal.aborted || error instanceof TypeError) throw new Error('The integration request was not confirmed within 25 seconds. Review saved settings and the operation history before retrying. Unconfirmed actions are not retried automatically.');
      throw error;
    } finally {
      window.clearTimeout(timer); inflight = false;
      controls.forEach((control, index) => { if (control.isConnected) control.disabled = disabled[index]; });
    }
  }
  function renderLists() {
    expandedRows = new Set([...root.querySelectorAll('.sls-integration-row[open]')].map(entry => entry.dataset.profileKey));
    const speakers = document.getElementById('sls-integration-speakers');
    const radios=document.getElementById('sls-integration-radios');radios.replaceChildren();
    const doors = document.getElementById('sls-integration-doors');
    const bindings = document.getElementById('sls-integration-bindings');
    const connectors = document.getElementById('sls-integration-connectors');
    speakers.replaceChildren(); doors.replaceChildren(); bindings.replaceChildren(); connectors.replaceChildren();
    config.radios.devices.forEach((radio,index)=>{
      const card=row(radios,radio.name||'New radio gateway',()=>{config.radios.devices.splice(index,1);renderLists();});
      const fields=node('div',undefined,'sls-integration-fields');card.append(fields);
      field(fields,'Name',radio.name,value=>{radio.name=value;});
      field(fields,'Existing PJSIP extension',radio.extension,value=>{radio.extension=value;},{choices:[{id:'',name:'Select an extension'},...state.extensions.map(id=>({id,name:id}))]});
      field(fields,'Gateway model / SIP profile',radio.model,value=>{radio.model=value;},{choices:[{id:'',name:'Select a gateway'},...(state.radio_catalog||[{model:'Generic SIP-to-radio gateway'}]).map(entry=>({id:entry.model,name:entry.model}))],required:true,help:'These devices expose SIP audio interfaces. SLS calls the registered PJSIP extension; the gateway must control auto-answer and PTT. Selecting a model does not configure the radio or certify RF operation.'});
      field(fields,'Radio channel label',radio.channel_label,value=>{radio.channel_label=value;},{placeholder:'Maintenance — channel 2',help:'A descriptive label for the channel already configured on the gateway. SLS does not change radio frequency or talkgroup.'});
      field(fields,'Maximum complete audio (seconds)',radio.maximum_audio_seconds,value=>{radio.maximum_audio_seconds=Number(value);},{number:true,min:5,max:120});
      toggle(card,'Enabled',radio.enabled,value=>{radio.enabled=value?'1':'0';});
    });
    config.speakers.devices.forEach((speaker, index) => {
      const card = row(speakers, speaker.name || 'New speaker', () => { config.speakers.devices.splice(index, 1); renderLists(); });
      const fields = node('div', undefined, 'sls-integration-fields'); card.append(fields);
      field(fields, 'Name', speaker.name, value => { speaker.name = value; });
      field(fields, 'Brand', speaker.brand, value => { speaker.brand = value; speaker.model = state.catalog[value].model; renderLists(); }, { choices: Object.entries(state.catalog).map(([id, entry]) => ({ id, name: entry.brand })) });
      field(fields, 'Speaker model', speaker.model, value => { speaker.model = value; }, {choices:(state.catalog[speaker.brand].models||[state.catalog[speaker.brand].model]).map(model=>({id:model,name:model})),help:'Documented SIP-capable models use the existing FreePBX audio path. Register the device, enable auto-answer and verify its firmware and volume before enabling it.'});
      field(fields, 'Installed firmware', speaker.firmware, value => { speaker.firmware = value; });
      field(card, 'Local acceptance notes', speaker.acceptance_notes, value => { speaker.acceptance_notes = value; });
      card.append(node('p', 'Confirm registration, auto-answer, volume and the complete audio sequence on this model and firmware. Offline and busy results must be reviewed in delivery details.', 'help-block'));
      field(fields, 'Existing PJSIP extension', speaker.extension, value => { speaker.extension = value; }, { choices: [{ id: '', name: 'Select an extension' }, ...state.extensions.map(id => ({ id, name: id }))] });
      toggle(card, 'Enabled', speaker.enabled, value => { speaker.enabled = value ? '1' : '0'; });
      const docs = node('a', 'Vendor setup instructions'); docs.href = state.catalog[speaker.brand].docs; docs.target = '_blank'; docs.rel = 'noopener noreferrer'; card.append(docs);
      if (speaker.brand === 'axis') {
        const details = node('details'); details.append(node('summary', 'Read-only last speaker test report')); card.append(details);
        const telemetry = node('div', undefined, 'sls-integration-fields'); details.append(telemetry);
        field(telemetry, 'HTTPS device origin', speaker.telemetry_origin, value => { speaker.telemetry_origin = value; });
        field(telemetry, 'Pinned private IPv4', speaker.telemetry_ipv4, value => { speaker.telemetry_ipv4 = value; });
        field(telemetry, 'Device user', speaker.telemetry_username, value => { speaker.telemetry_username = value; });
        field(telemetry, 'Device password', '', value => { speaker.telemetry_password = value; }, { password: true });
        tlsEditor(details, speaker.telemetry_tls, value => { speaker.telemetry_tls = value; });
        const check = node('button', 'Read last report', 'btn btn-default btn-sm'); check.type = 'button';
        check.disabled = !speaker.id || speaker.enabled !== '1' || !speaker.telemetry_origin || config.speakers.enabled !== '1' || config.enabled !== '1';
        check.addEventListener('click', async () => {
          if (settingsDirty) { feedback('Save integration settings before reading the saved device report.',true); return; }
          check.disabled = true;
          try { const result = await post('integrations_speaker_telemetry', { speaker_id: speaker.id }); feedback(result.detail + ' Device result: ' + (result.report?.SpeakerTestStatus ?? 'unconfirmed')); }
          catch (error) { feedback(error.message, true); } finally { renderLists(); }
        }); details.append(check);
      }
    });
    const accessTrust = document.getElementById('sls-integration-access-tls');
    if (accessTrust) { accessTrust.replaceChildren(); tlsEditor(accessTrust, config.access_control.tls, value => { config.access_control.tls = value; }); }
    config.access_control.doors.forEach((door, index) => {
      const card = row(doors, door.name || 'New permitted door', () => { config.access_control.doors.splice(index, 1); renderLists(); });
      const fields = node('div', undefined, 'sls-integration-fields'); card.append(fields);
      field(fields, 'Door name', door.name, value => { door.name = value; });
      field(fields, 'Controller door token', door.token, value => { door.token = value; });
      ['read', 'lock', 'unlock', 'access'].forEach(operation => toggle(card, 'Allow ' + operation, door['allow_' + operation], value => { door['allow_' + operation] = value; }));
      field(card, 'Maximum controller access time (seconds)', door.maximum_access_seconds ?? 10, value => { door.maximum_access_seconds = Number(value); }, { number: true, max: 60 });
    });
    config.connectors.destinations.forEach((destination, index) => {
      const card = row(connectors, destination.name || 'New connector', () => { config.connectors.destinations.splice(index, 1); renderLists(); });
      const fields = node('div', undefined, 'sls-integration-fields'); card.append(fields);
      field(fields, 'Name', destination.name, value => { destination.name = value; });
      field(fields, 'Service', destination.provider, value => { destination.provider = value; destination.topic = ''; renderLists(); }, { choices: [{ id: 'ntfy', name: 'ntfy' }, { id: 'gotify', name: 'Gotify' }, { id: 'matrix', name: 'Matrix · Labs' }] });
      field(fields, 'HTTPS origin', destination.origin, value => { destination.origin = value; });
      field(fields, 'Pinned private IPv4', destination.pinned_ipv4, value => { destination.pinned_ipv4 = value; });
      field(fields, 'Scoped token', destination.token ?? '', value => { destination.token = value; }, { password: true });
      if (destination.provider === 'ntfy') field(fields, 'Topic', destination.topic, value => { destination.topic = value; });
      if (destination.provider === 'matrix') {
        field(fields, 'Room ID (!room:server)', destination.topic, value => { destination.topic = value; });
        card.append(node('p', 'Use a dedicated bot already joined to an unencrypted room. Encrypted Matrix rooms are not supported. SLS sends plain text with a stable transaction ID.', 'help-block'));
      }
      tlsEditor(card, destination.tls, value => { destination.tls = value; });
      toggle(card, 'Enabled', destination.enabled, value => { destination.enabled = value ? '1' : '0'; });
    });
    config.responses.bindings.forEach((binding, index) => {
      const card = row(bindings, 'Participant response route', () => { config.responses.bindings.splice(index, 1); renderLists(); });
      field(card, 'Incident participant', binding.person_id, value => { binding.person_id = value; }, { choices: [{ id: '', name: 'Select a participant' }, ...state.people] });
      field(card, 'Saved SMS recipient', binding.sms_recipient_id, value => { binding.sms_recipient_id = value; }, { choices: [{ id: '', name: 'No SMS reply' }, ...state.sms_recipients] });
      field(card, 'Saved voice recipient', binding.voice_recipient_id, value => { binding.voice_recipient_id = value; }, { choices: [{ id: '', name: 'No voice reply' }, ...state.voice_recipients] });
    });
  }
  function render() {
    const enabled = state.config.enabled === '1';
    const count = Object.values(categories).flat().filter(name => state.config[name].enabled === '1').length;
    const badge = root.querySelector('#sls-provider-activation');
    badge.textContent = enabled ? `${count} integration${count === 1 ? '' : 's'} enabled` : 'Disabled';
    badge.dataset.enabled = String(enabled && count > 0);
    root.querySelectorAll('[data-provider-status]').forEach(label => {
      const on = state.config.enabled === '1' && state.config[label.dataset.providerStatus].enabled === '1';
      label.textContent = on ? 'On' : 'Off'; label.dataset.enabled = String(on);
    });
    root.querySelectorAll('[data-setting]').forEach(input => {
      const value = read(input.dataset.setting);
      if (input.type === 'checkbox') input.checked = value === '1' || value === true;
      else input.value = value ?? '';
    });
    renderLists();
    root.querySelectorAll('[data-clear]').forEach(input => { input.checked = false; });
    root.querySelectorAll('[data-oauth-expiry]').forEach(input => {
      const profile = config.meetings.oauth_profiles[input.dataset.oauthExpiry];
      input.value = profile.oauth_expires_at ? localTime(profile.oauth_expires_at) : '';
    });
    root.querySelectorAll('[data-meeting-account-status]').forEach(label => {
      const provider=label.dataset.meetingAccountStatus;
      const saved=state.config.meetings[tokenField(provider)+'_configured'];
      const expires=state.config.meetings.oauth_profiles[provider].oauth_expires_at;
      label.textContent=saved ? (expires && expires<=Date.now()/1000 ? 'Token expired' : 'Token saved') : 'Not configured';
    });
    settingsDirty = false;
    const usage = document.getElementById('sls-integration-journal-usage'); usage.replaceChildren();
    Object.entries(state.journal_usage ?? {}).forEach(([name, entry]) => {
      const allocation = entry.bytes ? ` · ${(entry.bytes / 1048576).toFixed(2)} / ${(entry.maximum_bytes / 1048576).toFixed(2)} MiB` : '';
      const shards = entry.shards ? ` · ${entry.shards} protected shard${entry.shards === 1 ? '' : 's'}` : '';
      usage.append(node('p', `${name}: ${entry.used} / ${entry.maximum}${allocation}${shards}${entry.needs_attention ? (name.startsWith('archive_') || name.startsWith('tombstone_') ? ' · retained evidence needs review' : ' · archive completed history soon') : ''}`, entry.needs_attention ? 'text-warning' : 'help-block'));
    });
    const health = document.getElementById('sls-integration-sensor-health'); health.replaceChildren();
    state.sensor_health.forEach(entry => health.append(node('p', `${entry.name}: ${entry.state} · ${new Date(entry.last_seen * 1000).toLocaleString()}`, 'sls-integration-activity')));
    if (!state.sensor_health.length) health.append(node('p', 'No enrolled sensor heartbeat has been received.', 'sls-integration-empty'));
    const history = document.getElementById('sls-integration-history'); history.replaceChildren();
    state.history.forEach(entry => {
      const item = node('div', undefined, 'sls-integration-activity'); item.append(node('strong', `${entry.kind} · ${entry.state}`), node('span', entry.detail ?? new Date(entry.created_at * 1000).toLocaleString()));
      if (entry.join_url) { const link = node('a', ' Open coordination meeting'); link.href = entry.join_url; link.target = '_blank'; link.rel = 'noopener noreferrer'; item.append(link); }
      if (entry.state === 'uncertain') {
        const details = node('details'); details.append(node('summary', 'Record reviewed provider evidence'));
        details.append(node('p', 'Wait at least five minutes, verify the provider or device outside SLS, then record the evidence. This closes local review and never repeats the original request.', 'help-block'));
        const form = node('form'), outcomeLabel = node('label', 'Reviewed outcome'), outcome = node('select'), referenceLabel = node('label', 'Evidence reference'), reference = node('input'), submit = node('button', 'Close reviewed uncertainty', 'btn btn-default btn-sm');
        outcome.id = `sls-integration-resolution-${entry.request_id}`; outcomeLabel.htmlFor = outcome.id;
        reference.id = `sls-integration-evidence-${entry.request_id}`; referenceLabel.htmlFor = reference.id;
        outcome.className = reference.className = 'form-control';
        [{ id: 'confirmed_completed', name: 'Provider evidence confirms completion' }, { id: 'confirmed_not_completed', name: 'Provider evidence confirms no completion' }, { id: 'abandoned_unconfirmed', name: 'Completion remains unknown; close review' }].forEach(choice => { const option = node('option', choice.name); option.value = choice.id; outcome.append(option); });
        reference.type = 'text'; reference.required = true; reference.maxLength = 256; reference.placeholder = 'Provider receipt, ticket, device log, or review reference'; submit.type = 'submit';
        form.append(outcomeLabel, outcome, referenceLabel, reference, submit);form.addEventListener('submit', async event => { event.preventDefault();submit.disabled = true;try { const result = await post('integrations_resolve', { request_id: entry.request_id, resolution: outcome.value, evidence_reference: reference.value });state = result.state;config = structuredClone(state.config);render();feedback(result.message); } catch (error) { feedback(error.message, true); } finally { if (submit.isConnected) submit.disabled = false; } });
        details.append(form);item.append(details);
      }
      history.append(item);
    });
    if (!state.history.length) history.append(node('p', 'No provider operations have been submitted.', 'sls-integration-empty'));
    renderOperations();
    if (Object.values(state.runtime_dependencies ?? {}).some(value => value === false)) feedback('Some optional provider dependencies are missing in the web PHP runtime. Enable cURL, OpenSSL and DOM before activating the corresponding integration.', true);
    if (state.storage_error) feedback(state.storage_error, true);
  }
  root.querySelectorAll('[data-setting]').forEach(input => input.addEventListener('input', () => {
    const path = input.dataset.setting;
    write(path, input.type === 'checkbox' ? (typeof read(path) === 'boolean' ? input.checked : input.checked ? '1' : '0') : input.type === 'number' ? Number(input.value) : input.value);
  }));
  root.querySelectorAll('[data-add]').forEach(button => button.addEventListener('click', () => {
    markSettingsDirty();
    if(button.dataset.add==='radio')config.radios.devices.push({id:'',name:'',enabled:'0',extension:'',model:'',channel_label:'',maximum_audio_seconds:30});
    if (button.dataset.add === 'speaker') config.speakers.devices.push({ id: '', name: '', enabled: '0', brand: 'algo', model: state.catalog.algo.model, extension: '', telemetry_origin: '', telemetry_ipv4: '', telemetry_username: '', telemetry_password: '' });
    if (button.dataset.add === 'door') config.access_control.doors.push({ id: '', name: '', token: '', allow_read: true, allow_lock: false, allow_unlock: false, allow_access: false, maximum_access_seconds: 10 });
    if (button.dataset.add === 'connector') config.connectors.destinations.push({ id: '', name: '', enabled: '0', provider: 'ntfy', origin: '', pinned_ipv4: '', token: '', topic: '' });
    if (button.dataset.add === 'binding') config.responses.bindings.push({ person_id: '', sms_recipient_id: '', voice_recipient_id: '' });
    renderLists();
  }));
  document.getElementById('sls-integration-form').addEventListener('submit', async event => {
    event.preventDefault();
    const submit = event.submitter; if (submit) submit.disabled = true;
    try {
      if (config.enabled === '1' && config.access_control.enabled === '1' && config.access_control.doors.some(door => door.allow_lock || door.allow_unlock || door.allow_access)) {
        if (!window.SlsLabsSafety || !await window.SlsLabsSafety.acknowledge()) return;
      }
      if (config.enabled === '1' && config.public_warning.enabled === '1' && config.public_warning.ipaws_enabled === '1') {
        if (!window.SlsLabsSafety || !await window.SlsLabsSafety.acknowledge()) return;
      }
      const clear = [...root.querySelectorAll('[data-clear]:checked')].map(input => input.dataset.clear);
      const result = await post('integrations_save', { revision: state.revision, config, clear_secrets: clear });
      state = result.state; config = structuredClone(state.config); render(); feedback(result.message ?? 'Integration settings saved.');
      window.SlsLabsActivation?.('enterprise_integrations',config); window.SlsSaveState?.set(document.getElementById('sls-enterprise'),'saved');
    } catch (error) { feedback(error.message, true); } finally { if (submit) submit.disabled = false; }
  });
  document.getElementById('sls-integration-form').addEventListener('input', markSettingsDirty);
  function renderOperations() {
    const providerChoice=document.getElementById('sls-integration-meeting-provider'),previousProvider=providerChoice.value;
    providerChoice.replaceChildren();
    const empty=node('option','Select a saved provider account');empty.value='';providerChoice.append(empty);
    Object.entries(meetingProviders).forEach(([provider,label])=>{
      if (!state.config.meetings[tokenField(provider)+'_configured']) return;
      const option=node('option',label);option.value=provider;providerChoice.append(option);
    });
    providerChoice.value=previousProvider;
    if(!providerChoice.value)providerChoice.value='';
    const incident = document.getElementById('sls-integration-meeting-incident');
    const chosen = incident.value; incident.replaceChildren();
    [{ id: '', name: 'Select an open incident' }, ...(state.incidents ?? [])].forEach(entry => {
      const option = node('option', entry.name); option.value = entry.id; incident.append(option);
    }); incident.value = chosen;
    const speakers = document.getElementById('sls-integration-speaker-selection'); speakers.replaceChildren();
    state.config.speakers.devices.filter(entry => entry.enabled === '1').forEach(entry => {
      const label = node('label'); const check = node('input'); check.type = 'checkbox'; check.value = entry.id;
      label.append(check, document.createTextNode(`${entry.name} · extension ${entry.extension}`)); speakers.append(label);
    });
    if (!speakers.childNodes.length) speakers.append(node('p', 'Save an enabled speaker before paging.', 'sls-integration-empty'));
    const radios=document.getElementById('sls-integration-radio-selection');radios.replaceChildren();
    state.config.radios.devices.filter(entry=>entry.enabled==='1').forEach(entry=>{
      const label=node('label'),check=node('input');check.type='checkbox';check.value=entry.id;
      label.append(check,document.createTextNode(`${entry.name} · extension ${entry.extension} · ${entry.maximum_audio_seconds}s maximum`));radios.append(label);
    });
    if(!radios.childNodes.length)radios.append(node('p','Save an enabled radio gateway before paging.','sls-integration-empty'));

    root.querySelectorAll('[data-operation-section]').forEach(button => { button.disabled = state.config.enabled !== '1' || state.config[button.dataset.operationSection].enabled !== '1'; });
    for (const [containerId, entries] of [['sls-integration-browser-selection', state.browser_people ?? []], ['sls-integration-connector-selection', state.config.connectors.destinations.filter(entry => entry.enabled === '1')]]) {
      const container = document.getElementById(containerId); container.replaceChildren();
      entries.forEach(entry => { const label = node('label'); const check = node('input'); check.type = 'checkbox'; check.value = entry.id; label.append(check, document.createTextNode(entry.name)); container.append(label); });
      if (!entries.length) container.append(node('p', containerId.includes('browser') ? 'No enabled browser subscribers. Configure them in Enterprise Labs → Directory and subscribers.' : 'No enabled connectors.', 'sls-integration-empty'));
    }
    const doors = document.getElementById('sls-integration-door-actions'); doors.replaceChildren();
    state.config.access_control.doors.forEach(entry => {
      const card = node('div', undefined, 'sls-integration-row'); card.append(node('strong', entry.name));
      ['read', 'lock', 'unlock', 'access'].filter(operation => entry['allow_' + operation]).forEach(operation => {
        const button = node('button', `${operation[0].toUpperCase()}${operation.slice(1)}`, operation === 'read' ? 'btn btn-default btn-sm' : 'btn btn-warning btn-sm'); button.type = 'button';
        button.disabled = state.config.enabled !== '1' || state.config.access_control.enabled !== '1';
        const key = entry.id + ':' + operation; if (!doorIds.has(key)) doorIds.set(key, requestId());
        button.addEventListener('click', async () => {
          button.disabled = true;
          try { const result = await post('integrations_door', { request_id: doorIds.get(key), door_id: entry.id, operation }); feedback((result.detail ?? result.state) + (result.observed ? ' ' + Object.entries(result.observed).map(([key, value]) => `${key}: ${value}`).join(', ') : '')); }
          catch (error) { feedback(error.message, true); }
          finally { if (operation === 'read') button.disabled = false; }
        }); card.append(button);
      }); doors.append(card);
    });
    if (!doors.childNodes.length) doors.append(node('p', 'No saved door permissions.', 'sls-integration-empty'));
    document.getElementById('sls-integration-cap-validate').disabled = !reviewedWarning || !['Test', 'Exercise'].includes(reviewedWarning.status);
    document.getElementById('sls-integration-ipaws-send').disabled = !reviewedWarning || state.config.enabled !== '1' || state.config.public_warning.ipaws_enabled !== '1';
  }
  const localTime = seconds => { const date = new Date(seconds * 1000); return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16); };
  const start = document.getElementById('sls-integration-meeting-start'); start.value = localTime(Math.floor(Date.now() / 1000) + 120);
  document.getElementById('sls-integration-meeting').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const result = await post('integrations_meeting', { incident_id: document.getElementById('sls-integration-meeting-incident').value, input: { provider: document.getElementById('sls-integration-meeting-provider').value, request_id: operationIds.meeting, start_at: Math.floor(new Date(start.value).getTime() / 1000), duration_minutes: Number(document.getElementById('sls-integration-meeting-duration').value) } });
      feedback(result.detail ?? `Meeting operation: ${result.state}`);
      if (result.join_url) { const link = node('a', ' Open meeting'); link.href = result.join_url; link.target = '_blank'; link.rel = 'noopener noreferrer'; status.append(link); }
    } catch (error) { feedback(error.message, true); }
  });
  document.getElementById('sls-integration-radio-send').addEventListener('submit',async event=>{
    event.preventDefault();const button=event.submitter;if(button)button.disabled=true;
    try{
      const result=await post('integrations_radio_send',{radio_ids:[...document.querySelectorAll('#sls-integration-radio-selection input:checked')].map(input=>input.value),title:document.getElementById('sls-integration-radio-title').value,message:document.getElementById('sls-integration-radio-message').value,is_test:document.getElementById('sls-integration-radio-test').checked});
      feedback(result.message??'Radio audio submitted to the PBX queue. Review per-extension delivery evidence.');
    }catch(error){feedback(error.message,true);}finally{if(button)button.disabled=state.config.enabled!=='1'||state.config.radios.enabled!=='1';}
  });
  document.getElementById('sls-integration-speaker-send').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const result = await post('integrations_speaker_send', { speaker_ids: [...document.querySelectorAll('#sls-integration-speaker-selection input:checked')].map(input => input.value), title: document.getElementById('sls-integration-speaker-title').value, message: document.getElementById('sls-integration-speaker-message').value, is_test: document.getElementById('sls-integration-speaker-test').checked });
      feedback(result.message ?? 'Speaker announcement queued. Review its delivery details.');
    } catch (error) { feedback(error.message, true); }
  });
  root.querySelectorAll('[data-oauth-expiry]').forEach(input => input.addEventListener('input', event => {
    config.meetings.oauth_profiles[input.dataset.oauthExpiry].oauth_expires_at = event.target.value ? Math.floor(new Date(event.target.value).getTime()/1000) : 0;
  }));
  root.querySelectorAll('[data-oauth-action]').forEach(button => button.addEventListener('click', async () => {
    if(settingsDirty){feedback('Save integration settings before renewing or removing an account.',true);return;}
    if(button.dataset.oauthAction==='revoke'&&!window.confirm('Remove the saved '+meetingProviders[button.dataset.provider]+' account and request revocation? Other meeting accounts remain available.'))return;
    button.disabled=true;
    try {
      const result=await post('integrations_oauth_'+button.dataset.oauthAction,{provider:button.dataset.provider});
      state=result.state;config=structuredClone(state.config);render();
      feedback(result.message+(result.provider_revocation?.detail?' '+result.provider_revocation.detail:''));
    }catch(error){feedback(error.message,true);}finally{button.disabled=false;}
  }));
  document.getElementById('sls-integration-labs-send').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const selected = id => [...document.querySelectorAll('#' + id + ' input:checked')].map(input => input.value);
      const result = await post('integrations_labs_send', { browser_recipient_ids: selected('sls-integration-browser-selection'), connector_ids: selected('sls-integration-connector-selection'), title: document.getElementById('sls-integration-labs-title').value, message: document.getElementById('sls-integration-labs-message').value, is_test: document.getElementById('sls-integration-labs-test').checked });
      feedback(result.message ?? 'Announcement queued. Review its delivery details.');
    } catch (error) { feedback(error.message, true); }
  });
  document.getElementById('sls-integration-continuity').addEventListener('click', async event => {
    const button = event.currentTarget; button.disabled = true;
    try {
      const result = await post('integrations_continuity', {}), container = document.getElementById('sls-integration-continuity-report'); container.replaceChildren();
      container.append(node('p', result.report.qualification, 'help-block'));
      const table = node('table', undefined, 'table table-striped'); const heading = node('tr'); ['Channel', 'Saved state', 'Requirements and outage behavior'].forEach(label => heading.append(node('th', label))); const head = node('thead'); head.append(heading); table.append(head); const body = node('tbody');
      result.report.channels.forEach(channel => {
        const row = node('tr');
        row.append(node('td', channel.name), node('td', channel.state_label ?? (channel.enabled ? 'Configured; acceptance not verified' : 'Disabled')));
        const detail = node('td');
        detail.append(node('p', channel.requires), node('p', channel.offline_behavior, 'help-block'));
        if (channel.missing_prerequisites?.length) detail.append(node('p', 'Complete setup: ' + channel.missing_prerequisites.join(' '), 'text-danger'));
        if (channel.configured_destinations) detail.append(node('p', channel.accepted_destinations + ' of ' + channel.configured_destinations + ' saved destination(s) have a current passed operator observation.', 'help-block'));
        row.append(detail);
        body.append(row);
      });
      table.append(body);
      container.append(table);
      container.append(node('p', `Local portal administrators enrolled: ${result.report.local_recovery.enrolled_local_administrators}. Portal ${result.report.local_recovery.portal_enabled ? 'enabled' : 'disabled'}. ${result.report.local_recovery.required_drill}`, 'help-block'));
    } catch (error) { feedback(error.message, true); } finally { button.disabled = false; }
  });
  document.getElementById('sls-integration-report').addEventListener('click', async event => {
    const button = event.currentTarget; button.disabled = true;
    try { const result = await post('integrations_report', {}); const url = URL.createObjectURL(new Blob([JSON.stringify(result.report, null, 2)], { type: 'application/json' })); const link = node('a'); link.href = url; link.download = 'sls-provider-activity.json'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); }
    catch (error) { feedback(error.message, true); } finally { button.disabled = false; }
  });
  document.getElementById('sls-integration-archive').addEventListener('submit', async event => {
    event.preventDefault(); const submit = event.submitter; if (submit) submit.disabled = true;
    try { const result = await post('integrations_archive', { retention_days: Number(event.target.elements.retention_days.value), evidence_retention_days: Number(event.target.elements.evidence_retention_days.value) }); feedback(`Archived ${result.archived.operations} provider operations and ${result.archived.responses} responses. Reduced ${result.archived.tombstoned_operations} old operations and ${result.archived.tombstoned_responses} old responses to replay tombstones. Pending and uncertain work was preserved.`); }
    catch (error) { feedback(error.message, true); } finally { if (submit) submit.disabled = false; }
  });
  document.getElementById('sls-integration-cap-validate').addEventListener('click', async event => {
    event.currentTarget.disabled = true;
    try { const result = await post('integrations_cap_validate', { warning: reviewedWarning, post_envelope: document.getElementById('sls-integration-ipaws-envelope').value }); feedback(result.message); }
    catch (error) { feedback(error.message, true); } finally { renderOperations(); }
  });
  const capForm = document.getElementById('sls-integration-cap');
  capForm.elements.sent.value = localTime(Math.floor(Date.now() / 1000)); capForm.elements.expires.value = localTime(Math.floor(Date.now() / 1000) + 3600);
  capForm.addEventListener('input', () => { document.getElementById('sls-integration-cap-validate').disabled = true; reviewedWarning = null; document.getElementById('sls-integration-ipaws-send').disabled = true; document.getElementById('sls-integration-cap-preview').hidden = true; });
  capForm.addEventListener('submit', async event => {
    event.preventDefault(); const submit = event.submitter; if (submit) submit.disabled = true;
    try {
      const values = new FormData(capForm); const warning = {};
      ['identifier', 'sender', 'status', 'msg_type', 'event', 'headline', 'description', 'instruction', 'area_description', 'polygon'].forEach(key => { warning[key] = values.get(key); });
      warning.sent = new Date(values.get('sent')).toISOString().replace('.000Z', 'Z'); warning.expires = new Date(values.get('expires')).toISOString().replace('.000Z', 'Z');
      warning.geocodes = String(values.get('geocodes')).trim().split(/\s+/).filter(Boolean); warning.ipaws_profile = values.has('ipaws_profile'); warning.references = [];
      if (warning.msg_type !== 'Alert') warning.references.push({ sender: warning.sender, identifier: values.get('reference_identifier'), sent: values.get('reference_sent') ? new Date(values.get('reference_sent')).toISOString().replace('.000Z', 'Z') : '' });
      const result = await post('integrations_cap_export', warning); reviewedWarning = structuredClone(warning); const preview = document.getElementById('sls-integration-cap-preview'); preview.textContent = result.xml; preview.hidden = false;
      const blob = new Blob([result.xml], { type: 'application/xml' }); const url = URL.createObjectURL(blob); const download = node('a', ' Download reviewed CAP XML'); download.href = url; download.download = warning.identifier + '.xml';
      feedback(result.detail); status.append(download); setTimeout(() => URL.revokeObjectURL(url), 60000); renderOperations();
    } catch (error) { feedback(error.message, true); } finally { if (submit) submit.disabled = state.config.enabled !== '1' || state.config.public_warning.enabled !== '1'; }
  });
  document.getElementById('sls-integration-ipaws-send').addEventListener('click', async event => {
    if (!reviewedWarning) return; event.currentTarget.disabled = true;
    try { const result = await post('integrations_ipaws', { request_id: operationIds.ipaws, warning: reviewedWarning, post_envelope: document.getElementById('sls-integration-ipaws-envelope').value }); feedback(result.detail ?? `IPAWS operation: ${result.state}`); }
    catch (error) { feedback(error.message, true); }
  });
  render();
})();
