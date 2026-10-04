(() => {
  'use strict';
  const root = document.getElementById('sls-integrations');
  if (!root) return;
  const data = JSON.parse(document.getElementById('sls-integration-data').textContent);
  let state = data.state;
  let config = structuredClone(state.config);
  let reviewedWarning = null;
  const requestId = () => { const bytes = new Uint8Array(16); crypto.getRandomValues(bytes); return [...bytes].map(value => value.toString(16).padStart(2, '0')).join(''); };
  const operationIds = { meeting: requestId(), ipaws: requestId() };
  const doorIds = new Map();
  const status = document.getElementById('sls-integration-status');
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
    const wrapper = node('label', label);
    const input = options?.choices ? node('select') : node('input');
    input.className = 'form-control';
    if (options?.choices) {
      options.choices.forEach(choice => {
        const option = node('option', choice.name ?? choice.label ?? choice.id);
        option.value = choice.id;
        input.append(option);
      });
    } else {
      input.type = options?.password ? 'password' : 'text';
      if (options?.password) {
        input.autocomplete = 'new-password';
        input.placeholder = 'Blank keeps saved credential';
      }
    }
    input.value = value ?? '';
    input.addEventListener('input', () => update(input.value));
    wrapper.append(input);
    parent.append(wrapper);
    return input;
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
    const result = node('div', undefined, 'sls-integration-row');
    const heading = node('div', undefined, 'sls-integration-row-heading');
    const button = node('button', 'Remove', 'btn btn-link text-danger');
    button.type = 'button';
    button.addEventListener('click', remove);
    heading.append(node('strong', label), button);
    result.append(heading);
    parent.append(result);
    return result;
  };
  async function post(action, payload) {
    const body = new URLSearchParams({ action, payload: JSON.stringify(payload), slsmassnotifyserver_csrf: data.csrf });
    const response = await fetch('config.php?display=slsmassnotifyserver_enterprise', { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const result = await response.json();
    if (!response.ok || result.success === false || result.ok === false) throw new Error(result.message ?? 'The operation could not confirm completion. Review history before retrying.');
    return result;
  }
  function renderLists() {
    const speakers = document.getElementById('sls-integration-speakers');
    const doors = document.getElementById('sls-integration-doors');
    const bindings = document.getElementById('sls-integration-bindings');
    speakers.replaceChildren(); doors.replaceChildren(); bindings.replaceChildren();
    config.speakers.devices.forEach((speaker, index) => {
      const card = row(speakers, speaker.name || 'New speaker', () => { config.speakers.devices.splice(index, 1); renderLists(); });
      const fields = node('div', undefined, 'sls-integration-fields'); card.append(fields);
      field(fields, 'Name', speaker.name, value => { speaker.name = value; });
      field(fields, 'Representative brand', speaker.brand, value => { speaker.brand = value; speaker.model = state.catalog[value].model; renderLists(); }, { choices: Object.entries(state.catalog).map(([id, entry]) => ({ id, name: entry.brand })) });
      field(fields, 'Model', speaker.model, value => { speaker.model = value; });
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
        const check = node('button', 'Read last report', 'btn btn-default btn-sm'); check.type = 'button';
        check.disabled = !speaker.id || speaker.enabled !== '1' || !speaker.telemetry_origin || config.speakers.enabled !== '1' || config.enabled !== '1';
        check.addEventListener('click', async () => {
          check.disabled = true;
          try { const result = await post('integrations_speaker_telemetry', { speaker_id: speaker.id }); feedback(result.detail + ' Device result: ' + (result.report?.SpeakerTestStatus ?? 'unconfirmed')); }
          catch (error) { feedback(error.message, true); } finally { renderLists(); }
        }); details.append(check);
      }
    });
    config.access_control.doors.forEach((door, index) => {
      const card = row(doors, door.name || 'New permitted door', () => { config.access_control.doors.splice(index, 1); renderLists(); });
      const fields = node('div', undefined, 'sls-integration-fields'); card.append(fields);
      field(fields, 'Door name', door.name, value => { door.name = value; });
      field(fields, 'Controller door token', door.token, value => { door.token = value; });
      ['read', 'lock', 'unlock'].forEach(operation => toggle(card, 'Allow ' + operation, door['allow_' + operation], value => { door['allow_' + operation] = value; }));
    });
    config.responses.bindings.forEach((binding, index) => {
      const card = row(bindings, 'Participant response route', () => { config.responses.bindings.splice(index, 1); renderLists(); });
      field(card, 'Incident participant', binding.person_id, value => { binding.person_id = value; }, { choices: [{ id: '', name: 'Select a participant' }, ...state.people] });
      field(card, 'Saved SMS recipient', binding.sms_recipient_id, value => { binding.sms_recipient_id = value; }, { choices: [{ id: '', name: 'No SMS reply' }, ...state.sms_recipients] });
      field(card, 'Saved voice recipient', binding.voice_recipient_id, value => { binding.voice_recipient_id = value; }, { choices: [{ id: '', name: 'No voice reply' }, ...state.voice_recipients] });
    });
  }
  function render() {
    root.querySelectorAll('[data-setting]').forEach(input => {
      const value = read(input.dataset.setting);
      if (input.type === 'checkbox') input.checked = value === '1' || value === true;
      else input.value = value ?? '';
    });
    renderLists();
    const health = document.getElementById('sls-integration-sensor-health'); health.replaceChildren();
    state.sensor_health.forEach(entry => health.append(node('p', `${entry.name}: ${entry.state} · ${new Date(entry.last_seen * 1000).toLocaleString()}`, 'sls-integration-activity')));
    if (!state.sensor_health.length) health.append(node('p', 'No enrolled sensor heartbeat has been received.', 'sls-integration-empty'));
    const history = document.getElementById('sls-integration-history'); history.replaceChildren();
    state.history.forEach(entry => {
      const item = node('div', undefined, 'sls-integration-activity'); item.append(node('strong', `${entry.kind} · ${entry.state}`), node('span', entry.detail ?? new Date(entry.created_at * 1000).toLocaleString()));
      if (entry.join_url) { const link = node('a', ' Open coordination meeting'); link.href = entry.join_url; link.target = '_blank'; link.rel = 'noopener noreferrer'; item.append(link); }
      history.append(item);
    });
    if (!state.history.length) history.append(node('p', 'No provider operations have been submitted.', 'sls-integration-empty'));
    renderOperations();
    if (state.storage_error) feedback(state.storage_error, true);
  }
  root.querySelectorAll('[data-setting]').forEach(input => input.addEventListener('input', () => {
    const path = input.dataset.setting;
    write(path, input.type === 'checkbox' ? (typeof read(path) === 'boolean' ? input.checked : input.checked ? '1' : '0') : input.type === 'number' ? Number(input.value) : input.value);
  }));
  root.querySelectorAll('[data-add]').forEach(button => button.addEventListener('click', () => {
    if (button.dataset.add === 'speaker') config.speakers.devices.push({ id: '', name: '', enabled: '0', brand: 'algo', model: state.catalog.algo.model, extension: '', telemetry_origin: '', telemetry_ipv4: '', telemetry_username: '', telemetry_password: '' });
    if (button.dataset.add === 'door') config.access_control.doors.push({ id: '', name: '', token: '', allow_read: true, allow_lock: false, allow_unlock: false });
    if (button.dataset.add === 'binding') config.responses.bindings.push({ person_id: '', sms_recipient_id: '', voice_recipient_id: '' });
    renderLists();
  }));
  document.getElementById('sls-integration-form').addEventListener('submit', async event => {
    event.preventDefault();
    const submit = event.submitter; if (submit) submit.disabled = true;
    try {
      if (config.enabled === '1' && config.access_control.enabled === '1' && config.access_control.doors.some(door => door.allow_lock || door.allow_unlock)) {
        if (!window.SlsLabsSafety || !await window.SlsLabsSafety.acknowledge()) return;
      }
      if (config.enabled === '1' && config.public_warning.enabled === '1' && config.public_warning.ipaws_enabled === '1') {
        if (!window.SlsLabsSafety || !await window.SlsLabsSafety.acknowledge()) return;
      }
      const clear = [...root.querySelectorAll('[data-clear]:checked')].map(input => input.dataset.clear);
      const result = await post('integrations_save', { revision: state.revision, config, clear_secrets: clear });
      state = result.state; config = structuredClone(state.config); render(); feedback(result.message ?? 'Integration settings saved.');
    } catch (error) { feedback(error.message, true); } finally { if (submit) submit.disabled = false; }
  });
  function renderOperations() {
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
    root.querySelectorAll('[data-operation-section]').forEach(button => { button.disabled = state.config.enabled !== '1' || state.config[button.dataset.operationSection].enabled !== '1'; });
    const doors = document.getElementById('sls-integration-door-actions'); doors.replaceChildren();
    state.config.access_control.doors.forEach(entry => {
      const card = node('div', undefined, 'sls-integration-row'); card.append(node('strong', entry.name));
      ['read', 'lock', 'unlock'].filter(operation => entry['allow_' + operation]).forEach(operation => {
        const button = node('button', `${operation[0].toUpperCase()}${operation.slice(1)}`, operation === 'read' ? 'btn btn-default btn-sm' : 'btn btn-warning btn-sm'); button.type = 'button';
        button.disabled = state.config.enabled !== '1' || state.config.access_control.enabled !== '1';
        const key = entry.id + ':' + operation; if (!doorIds.has(key)) doorIds.set(key, requestId());
        button.addEventListener('click', async () => {
          button.disabled = true;
          try { const result = await post('integrations_door', { request_id: doorIds.get(key), door_id: entry.id, operation }); feedback((result.detail ?? result.state) + (result.observed ? ' ' + Object.entries(result.observed).map(([key, value]) => `${key}: ${value}`).join(', ') : '')); }
          catch (error) { feedback(error.message, true); if (operation === 'read') button.disabled = false; }
        }); card.append(button);
      }); doors.append(card);
    });
    if (!doors.childNodes.length) doors.append(node('p', 'No saved door permissions.', 'sls-integration-empty'));
    document.getElementById('sls-integration-ipaws-send').disabled = !reviewedWarning || state.config.enabled !== '1' || state.config.public_warning.ipaws_enabled !== '1';
  }
  const localTime = seconds => { const date = new Date(seconds * 1000); return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16); };
  const start = document.getElementById('sls-integration-meeting-start'); start.value = localTime(Math.floor(Date.now() / 1000) + 120);
  document.getElementById('sls-integration-meeting').addEventListener('submit', async event => {
    event.preventDefault(); event.submitter.disabled = true;
    try {
      const result = await post('integrations_meeting', { incident_id: document.getElementById('sls-integration-meeting-incident').value, input: { request_id: operationIds.meeting, start_at: Math.floor(new Date(start.value).getTime() / 1000), duration_minutes: Number(document.getElementById('sls-integration-meeting-duration').value) } });
      feedback(result.detail ?? `Meeting operation: ${result.state}`);
      if (result.join_url) { const link = node('a', ' Open meeting'); link.href = result.join_url; link.target = '_blank'; link.rel = 'noopener noreferrer'; status.append(link); }
    } catch (error) { feedback(error.message, true); }
  });
  document.getElementById('sls-integration-speaker-send').addEventListener('submit', async event => {
    event.preventDefault(); event.submitter.disabled = true;
    try {
      const result = await post('integrations_speaker_send', { speaker_ids: [...document.querySelectorAll('#sls-integration-speaker-selection input:checked')].map(input => input.value), title: document.getElementById('sls-integration-speaker-title').value, message: document.getElementById('sls-integration-speaker-message').value, is_test: document.getElementById('sls-integration-speaker-test').checked });
      feedback(result.message ?? 'Speaker announcement queued. Review its delivery details.');
    } catch (error) { feedback(error.message, true); }
  });
  const capForm = document.getElementById('sls-integration-cap');
  capForm.elements.sent.value = localTime(Math.floor(Date.now() / 1000)); capForm.elements.expires.value = localTime(Math.floor(Date.now() / 1000) + 3600);
  capForm.addEventListener('input', () => { reviewedWarning = null; document.getElementById('sls-integration-ipaws-send').disabled = true; document.getElementById('sls-integration-cap-preview').hidden = true; });
  capForm.addEventListener('submit', async event => {
    event.preventDefault(); event.submitter.disabled = true;
    const values = new FormData(capForm); const warning = {};
    ['identifier', 'sender', 'status', 'msg_type', 'event', 'headline', 'description', 'instruction', 'area_description', 'polygon'].forEach(key => { warning[key] = values.get(key); });
    warning.sent = new Date(values.get('sent')).toISOString().replace('.000Z', 'Z'); warning.expires = new Date(values.get('expires')).toISOString().replace('.000Z', 'Z');
    warning.geocodes = String(values.get('geocodes')).trim().split(/\s+/).filter(Boolean); warning.ipaws_profile = values.has('ipaws_profile'); warning.references = [];
    if (warning.msg_type !== 'Alert') warning.references.push({ sender: warning.sender, identifier: values.get('reference_identifier'), sent: values.get('reference_sent') ? new Date(values.get('reference_sent')).toISOString().replace('.000Z', 'Z') : '' });
    try {
      const result = await post('integrations_cap_export', warning); reviewedWarning = structuredClone(warning); const preview = document.getElementById('sls-integration-cap-preview'); preview.textContent = result.xml; preview.hidden = false;
      const blob = new Blob([result.xml], { type: 'application/xml' }); const url = URL.createObjectURL(blob); const download = node('a', ' Download reviewed CAP XML'); download.href = url; download.download = warning.identifier + '.xml';
      feedback(result.detail); status.append(download); setTimeout(() => URL.revokeObjectURL(url), 60000); renderOperations();
    } catch (error) { feedback(error.message, true); } finally { event.submitter.disabled = state.config.enabled !== '1' || state.config.public_warning.enabled !== '1'; }
  });
  document.getElementById('sls-integration-ipaws-send').addEventListener('click', async event => {
    if (!reviewedWarning) return; event.currentTarget.disabled = true;
    try { const result = await post('integrations_ipaws', { request_id: operationIds.ipaws, warning: reviewedWarning, post_envelope: document.getElementById('sls-integration-ipaws-envelope').value }); feedback(result.detail ?? `IPAWS operation: ${result.state}`); }
    catch (error) { feedback(error.message, true); }
  });
  render();
})();
