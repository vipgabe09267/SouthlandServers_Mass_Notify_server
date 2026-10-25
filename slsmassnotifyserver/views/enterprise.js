(() => {
  'use strict';
  const root = document.getElementById('sls-enterprise');
  if (!root) return;
  const data = JSON.parse(document.getElementById('sls-enterprise-data').textContent);
  const help = document.createElement('div');
  help.id = 'sls-labs-field-tooltip'; help.className = 'sls-labs-field-tooltip';
  help.setAttribute('role', 'tooltip'); help.hidden = true; document.body.append(help);
  let helpTarget;
  function hideHelp() {
    if (helpTarget) helpTarget.removeAttribute('aria-describedby');
    helpTarget = null; help.hidden = true;
  }
  function showHelp(target) {
    if (!target || !root.contains(target) || !target.dataset.help) return;
    hideHelp(); helpTarget = target; help.textContent = target.dataset.help;
    target.setAttribute('aria-describedby', help.id); help.hidden = false;
    const anchor = target.getBoundingClientRect(), box = help.getBoundingClientRect();
    help.style.left = `${Math.max(8, Math.min(anchor.left, window.innerWidth - box.width - 8))}px`;
    help.style.top = `${Math.max(8, anchor.bottom + box.height + 16 <= window.innerHeight ? anchor.bottom + 8 : anchor.top - box.height - 8)}px`;
  }
  root.addEventListener('mouseover', event => { const target = event.target.closest?.('.sls-field-help'); if (target && target !== helpTarget) showHelp(target); });
  root.addEventListener('mouseout', event => {
    if (helpTarget && !helpTarget.contains(event.relatedTarget) && !helpTarget.contains(document.activeElement)) hideHelp();
  });
  root.addEventListener('focusin', event => showHelp(event.target.closest?.('.sls-field-help')));
  root.addEventListener('focusout', event => { if (event.target === helpTarget) hideHelp(); });
  root.addEventListener('click', event => {
    const target = event.target.closest?.('.sls-field-help');
    if (target) { event.preventDefault(); showHelp(target); } else hideHelp();
  });
  root.addEventListener('keydown', event => { if (event.key === 'Escape') hideHelp(); });
  window.addEventListener('resize', hideHelp); window.addEventListener('scroll', hideHelp, true);
  const features = data.feature_settings || {};
  let manager = data.feature_manager;
  let labsEnabled = manager?.config?.enabled === true;
  window.SlsEnterpriseMasterEnabled = () => labsEnabled;
  function guardFeatureControls() {
    const controls = root.querySelectorAll('[data-labs-master-required], #sls-cluster-config input[name="enabled"], #sls-cluster-config input[name="mirroring_enabled"], #sls-cluster-config input[name="remote_enabled"], #sls-cluster-config input[name="offline_cache_enabled"], #sls-integration-form input[data-setting="enabled"], #sls-integration-form input[data-setting$=".enabled"], #sls-integration-form input[data-setting="public_warning.ipaws_enabled"], #sls-operations-enabled, [data-enable-operation]');
    controls.forEach(control => {
      if (!labsEnabled && !control.disabled) { control.disabled = true; control.dataset.labsDisabledByMaster = '1'; control.title = 'Enable Enterprise Labs in Feature manager first.'; }
      else if (labsEnabled && control.dataset.labsDisabledByMaster === '1') { control.disabled = false; delete control.dataset.labsDisabledByMaster; control.removeAttribute('title'); }
    });
  }
  function activation(section, config) {
    if (section) features[section] = { enabled: config.enabled === true || config.enabled === '1' };
    const active = labsEnabled;
    const status = document.getElementById('sls-labs-activation');
    status.querySelector('span').className = 'label ' + (active ? 'label-success' : 'label-danger');
    status.querySelector('span').textContent = active ? 'Active' : 'Inactive';
    status.querySelector('small').textContent = active ? 'Enterprise Labs is enabled.' : 'Enterprise Labs is disabled.';
    const sections = { continuity: ['enterprise_cluster'], identity: ['directory_sync', 'subscriber_browser'], integrations: ['enterprise_integrations'], operations: ['enterprise_operations'] };
    root.querySelectorAll('[data-labs-tab]').forEach(link => {
      let badge = link.querySelector('[data-labs-tab-status]');
      if (!badge) { badge = document.createElement('span'); badge.dataset.labsTabStatus = ''; link.append(badge); }
      const on = active && sections[link.dataset.labsTab].some(key => features[key]?.enabled);
      badge.textContent = on ? 'On' : 'Off'; badge.dataset.enabled = String(on);
    });
    guardFeatureControls();
  }
  window.SlsLabsActivation = activation;
  activation();
  const state = data.operations, config = structuredClone(state.config);
  const endpoint = 'config.php?display=slsmassnotifyserver_enterprise';
  const feedback = message => { const box = root.querySelector('#sls-enterprise-status'); box.textContent = message; box.hidden = false; box.focus(); };
  const node = (tag, text, className) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (className) n.className = className; return n; };
  let requestBusy = false;
  const post = async (action, input = {}) => {
    if (requestBusy) throw Error('Another Enterprise Labs request is already in progress. Wait for its result before trying again.');
    requestBusy = true;
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),25000);
    try {
      const body = new FormData(); body.set('slsmassnotifyserver_csrf', data.csrf); body.set('payload', JSON.stringify({ action, input }));
      const response = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' },signal:controller.signal });
      let result; try { result = await response.json(); } catch { throw Error('The server did not return a valid result. Reload saved state before retrying.'); }
      if (!result || typeof result !== 'object' || Array.isArray(result)) throw Error('The server did not return a valid structured result. Reload saved state before retrying.');
      if (!response.ok || result.success !== true) {
        const serverMessage = typeof result.message === 'string' && result.message.trim() !== '' ? result.message : '';
        throw Error(serverMessage || (response.ok ? 'The server did not confirm this request. Reload saved state before retrying.' : `The request failed (HTTP ${response.status}).`));
      }
      const sections = {operations_save:'enterprise_operations',directory_save:'directory_sync',subscriber_save:'subscriber_browser'};
      if (sections[action] && input.config) activation(sections[action], input.config);
      return result;
    } catch(error) {
      if(controller.signal.aborted)throw Error('The PBX did not confirm this request within 25 seconds. Reload saved state before retrying; the request was not retried.');
      throw error;
    } finally { clearTimeout(timer); requestBusy = false; }
  };
  function tab(key, update = false) {
    hideHelp();
    const keys = ['continuity', 'identity', 'integrations', 'operations']; if (!keys.includes(key)) key = keys[0];
    root.querySelectorAll('[data-labs-panel]').forEach(n => n.hidden = n.dataset.labsPanel !== key);
    root.querySelectorAll('[data-labs-tab]').forEach(n => { if (n.dataset.labsTab === key) n.setAttribute('aria-current', 'page'); else n.removeAttribute('aria-current'); });
    if (update) history.replaceState(null, '', `#labs-${key}`);
  }
  root.querySelectorAll('[data-labs-tab]').forEach(n => n.addEventListener('click', event => { event.preventDefault(); tab(n.dataset.labsTab, true); }));
  tab(location.hash.replace(/^#labs-/, '')); window.addEventListener('hashchange', () => tab(location.hash.replace(/^#labs-/, '')));
  const featureDialog = root.querySelector('#sls-labs-feature-manager');
  const featureForm = root.querySelector('#sls-labs-features-form');
  const featureMaster = root.querySelector('#sls-labs-master');
  const featureChoices = root.querySelector('#sls-labs-feature-choices');
  const featureAgree = root.querySelector('#sls-labs-feature-agree');
  const featureSave = root.querySelector('#sls-labs-feature-save');
  const featureError = root.querySelector('#sls-labs-feature-error');
  const featureParents = { mirroring: 'cluster', remote_sites: 'cluster', offline_cache: 'cluster', browser_push: 'subscribers', ipaws: 'public_warning' };
  let featureReady = false, featureSaving = false, featureRequest = 0;
  const featureInputs = () => Array.from(featureChoices.querySelectorAll('[data-labs-feature]'));
  function updateFeatureControls() {
    featureMaster.disabled = featureSaving || !featureReady;
    featureChoices.querySelectorAll('fieldset').forEach(group => { group.disabled = featureSaving || !featureReady || !featureMaster.checked; });
    featureAgree.disabled = featureSaving || !featureReady;
    featureSave.disabled = featureSaving || !featureReady || !featureAgree.checked;
    featureDialog.querySelectorAll('[data-close-feature-manager]').forEach(button => { button.disabled = featureSaving; });
  }
  function showFeatureError(message) { featureError.textContent = message; featureError.hidden = false; featureError.focus(); featureError.scrollIntoView({ block: 'nearest' }); }
  function populateFeatureManager(value) {
    if (!value || typeof value.revision !== 'string' || !/^[a-f0-9]{64}$/.test(value.revision) || !Array.isArray(value.catalog) || typeof value.config?.enabled !== 'boolean' || !value.config.features) throw Error('The feature settings could not be read. Reload Enterprise Labs.');
    manager = value; featureMaster.checked = value.config.enabled; featureChoices.replaceChildren();
    const groups = new Map();
    value.catalog.forEach(feature => {
      if (!/^[a-z][a-z0-9_]{0,63}$/.test(feature.id) || typeof value.config.features[feature.id] !== 'boolean') throw Error('The feature catalog is invalid. Reload Enterprise Labs.');
      let group = groups.get(feature.group);
      if (!group) { const fieldset = node('fieldset', undefined, 'sls-feature-group'); fieldset.append(node('legend', feature.group)); group = node('div'); fieldset.append(group); featureChoices.append(fieldset); groups.set(feature.group, group); }
      const label = node('label', undefined, 'sls-feature-choice'), control = node('input'); control.type = 'checkbox'; control.dataset.labsFeature = feature.id; control.checked = value.config.features[feature.id];
      const description = node('span'); description.append(node('strong', feature.label), node('small', feature.description)); label.append(control, description); group.append(label);
    });
  }
  featureMaster.addEventListener('change', () => { if (!featureMaster.checked) featureInputs().forEach(control => { control.checked = false; }); updateFeatureControls(); });
  featureAgree.addEventListener('change', updateFeatureControls);
  featureChoices.addEventListener('change', event => {
    const control = event.target.closest('[data-labs-feature]'); if (!control) return;
    const key = control.dataset.labsFeature;
    featureInputs().forEach(input => {
      if (control.checked && input.dataset.labsFeature === featureParents[key]) input.checked = true;
      if (!control.checked && featureParents[input.dataset.labsFeature] === key) input.checked = false;
    });
  });
  root.querySelector('#sls-labs-configure').addEventListener('click', async () => {
    const request = ++featureRequest; featureReady = false; featureAgree.checked = false; featureError.hidden = true; featureChoices.replaceChildren(); featureDialog.showModal(); updateFeatureControls();
    try {
      const result = await post('labs_features_state');
      if (!featureDialog.open || request !== featureRequest) return;
      populateFeatureManager(result.manager); labsEnabled = manager.config.enabled; activation(); featureReady = true; updateFeatureControls();
    } catch (error) { if (featureDialog.open && request === featureRequest) showFeatureError(error.message); }
  });
  featureDialog.querySelectorAll('[data-close-feature-manager]').forEach(button => button.addEventListener('click', () => featureDialog.close()));
  featureDialog.addEventListener('cancel', event => { if (featureSaving) event.preventDefault(); });
  featureDialog.addEventListener('close', () => { featureRequest++; });
  featureForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (!featureReady || featureSaving || !featureAgree.checked || !featureForm.reportValidity()) return;
    if (['unsaved', 'editor'].includes(root.dataset.saveState)) { showFeatureError('Save your page edits first, or reload the page to discard them before changing enabled features.'); return; }
    featureSaving = true; featureError.hidden = true; updateFeatureControls();
    try {
      const selection = Object.fromEntries(featureInputs().map(control => [control.dataset.labsFeature, control.checked]));
      const result = await post('labs_features_save', { revision: manager.revision, enabled: featureMaster.checked, features: selection, warning_revision: data.safety_revision, agree: true });
      populateFeatureManager(result.manager); labsEnabled = manager.config.enabled; activation(); window.SlsSaveState?.set(root, 'saved'); featureDialog.close(); location.reload();
    } catch (error) { featureReady = false; showFeatureError(error.message + ' Close and reopen Feature manager to check saved choices before retrying.'); }
    finally { featureSaving = false; updateFeatureControls(); }
  });

  const acknowledge = () => window.SlsLabsSafety.acknowledge();
  const clusterForm = root.querySelector('#sls-cluster-config');
  const clusterValues = clusterForm ? Object.fromEntries(['enabled', 'mirroring_enabled', 'mode', 'role'].map(name => {
    const control = clusterForm.elements[name]; return [name, control.type === 'checkbox' ? control.checked : control.value];
  })) : {};
  if (clusterForm) clusterForm.addEventListener('change', async event => {
    if (!['enabled', 'mirroring_enabled', 'mode', 'role'].includes(event.target.name)) return;
    const controls = clusterForm.elements;
    const previous = clusterValues[event.target.name];
    const remember = () => { clusterValues[event.target.name] = event.target.type === 'checkbox' ? event.target.checked : event.target.value; };
    if (!controls.enabled.checked || !(controls.mirroring_enabled.checked || controls.mode.value === 'notification_ha' || controls.role.value === 'witness')) { remember(); return; }
    try { if (await acknowledge()) { remember(); return; } }
    catch (error) { feedback(error.message); }
    if (event.target.type === 'checkbox') event.target.checked = previous; else event.target.value = previous;
  });
  window.SlsEnterprise = { post, feedback };

  const editor = root.querySelector('#sls-enterprise-editor'), form = root.querySelector('#sls-enterprise-editor-form');
  const body = root.querySelector('#sls-enterprise-editor-body'), submit = root.querySelector('#sls-enterprise-editor-submit');
  let handler;
  editor.addEventListener('close', () => root.dispatchEvent(new CustomEvent('sls:editor-closed')));
  function open(title, label = 'Save in editor') { body.replaceChildren(); root.querySelector('#sls-enterprise-editor-title').textContent = title; submit.textContent = label; handler = null; root.dispatchEvent(new CustomEvent('sls:editor-opened')); editor.showModal(); }
  root.querySelectorAll('[data-close-enterprise]').forEach(n => n.addEventListener('click', () => editor.close()));
  form.addEventListener('submit', async event => { event.preventDefault(); if (!handler || !form.reportValidity()) return; submit.disabled = true; try { const close = await handler(); if (close !== false) editor.close(); } catch (error) { feedback(error.message); } finally { submit.disabled = false; } });
  const input = (type, value = '') => { const n = node('input'); n.type = type; n.value = value; return n; };
  function field(label, control, tip) { const wrap = node('label', label); wrap.append(control); if (tip) wrap.append(node('small', tip)); body.append(wrap); return control; }
  function select(choices, value, multiple = false) { const n = node('select'); n.multiple = multiple; choices.forEach(row => { const option = node('option', row.name || row.label || row.id); option.value = row.id; option.selected = multiple ? (value || []).includes(row.id) : row.id === value; n.append(option); }); return n; }
  const values = control => Array.from(control.selectedOptions, option => option.value);
  const id = prefix => `${prefix}_${Array.from(crypto.getRandomValues(new Uint8Array(12)), n => n.toString(16).padStart(2, '0')).join('')}`;
  const requestId = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), n => n.toString(16).padStart(2, '0')).join('');
  const lists = { floorplans: 'plans', drills: 'assignments', shift_routing: 'routes', dual_approval: 'policies' };
  const labels = { floorplans: 'floor plan', drills: 'drill assignment', shift_routing: 'shift', dual_approval: 'approval policy' };
  const enabled = root.querySelector('#sls-operations-enabled'); enabled.checked = config.enabled;
  root.querySelectorAll('[data-enable-operation]').forEach(n => n.checked = config[n.dataset.enableOperation].enabled);
  function rows() {
    for (const [section, list] of Object.entries(lists)) {
      const target = root.querySelector(`[data-operation-list="${section}"]`); target.replaceChildren();
      if (!config[section][list].length) { target.append(node('div', 'None configured.', 'sls-ent-empty')); continue; }
      config[section][list].forEach(row => {
        const wrapper = node('div', undefined, 'sls-ent-row'), description = node('div'); description.append(node('strong', row.name), node('div', row.enabled ? 'Enabled' : 'Disabled'));
        const actions = node('div', undefined, 'sls-ent-actions'), edit = node('button', 'Edit', 'btn btn-default btn-sm'); edit.type = 'button'; edit.addEventListener('click', () => editRow(section, row));
        const remove = node('button', 'Remove', 'btn btn-link'); remove.type = 'button'; remove.addEventListener('click', () => { config[section][list] = config[section][list].filter(item => item.id !== row.id); rows(); feedback('Removed in this editor. Save coordination settings to apply. Existing delivery and drill history remain.'); });
        actions.append(edit, remove);
        if (section === 'floorplans' && row.enabled && config.enabled && config.floorplans.enabled) {
          const overlay = node('button', 'View incident overlay', 'btn btn-default btn-sm'); overlay.type = 'button';
          overlay.disabled = !(state.incident_choices || []).length;
          overlay.addEventListener('click', () => viewOverlay(row)); actions.append(overlay);
        }
        wrapper.append(description, actions); target.append(wrapper);
      });
    }
  }
  function viewOverlay(plan) {
    open(`Human responses · ${plan.name}`, 'Load or refresh overlay');
    const incident = field('Incident', select(state.incident_choices)), output = node('div'); body.append(output);
    handler = async () => {
      const result = await post('floorplan_overlay', { plan_id: plan.id, incident_id: incident.value });
      output.replaceChildren(node('p', result.meaning), node('p', `Observed ${result.observed_at}`));
      const figure = node('div', undefined, 'sls-ent-map'), picture = node('img'), legend = node('ul'); picture.alt = plan.name;
      picture.src = `${endpoint}&image_id=${encodeURIComponent(result.plan.image_id)}`; figure.append(picture);
      result.plan.markers.forEach(marker => {
        const text = `${marker.label} · ${marker.status.replaceAll('_', ' ')}`;
        const dot = node('span', undefined, 'sls-ent-marker'); dot.style.left = `${Number(marker.x)}%`; dot.style.top = `${Number(marker.y)}%`;
        dot.dataset.status = marker.status; dot.title = text; dot.setAttribute('role', 'img'); dot.setAttribute('aria-label', text);
        figure.append(dot); legend.append(node('li', text));
      });
      output.append(figure, legend); return false;
    };
  }
  function saveRow(section, row) { const list = config[section][lists[section]], index = list.findIndex(item => item.id === row.id); if (index < 0) list.push(row); else list[index] = row; rows(); feedback('Saved in this editor. Use Save coordination settings to apply.');  window.SlsSaveState?.set(root,'editor'); }
  async function editRow(section, original) {
    if (section === 'dual_approval' || section === 'drills') {
      try { state.people = (await post('operations_people')).people; }
      catch (error) { feedback(error.message); return; }
    }
    const row = original ? structuredClone(original) : { id: id({ floorplans: 'plan', drills: 'drill', shift_routing: 'shift', dual_approval: 'approval' }[section]), enabled: false, name: '' };
    open(`${original ? 'Edit' : 'Add'} ${labels[section]}`);
    const name = field('Name', input('text', row.name)); name.required = true; name.maxLength = 80;
    const active = input('checkbox'); active.checked = row.enabled; field('Enable this saved entry', active);
    if (section === 'dual_approval') {
      const channels = field('Channels requiring review', select(['phones', 'desktops', 'webhooks', 'voice_recipient_ids', 'email_recipient_ids', 'sms_recipient_ids', 'browser_recipient_ids', 'connector_ids'].map(key => ({ id: key, name: { phones: 'Phone audio/display', desktops: 'Desktop', webhooks: 'Webhook', voice_recipient_ids: 'External calls', email_recipient_ids: 'Email', sms_recipient_ids: 'SMS / MMS', browser_recipient_ids: 'Browser Web Push', connector_ids: 'ntfy / Gotify' }[key] })), row.channels || [], true));
      const people = field('Authorized people', select(state.people, row.approver_ids || [], true), 'Choose at least two distinct people. Both must retain send permission for every selected recipient.');
      const expiry = field('Review lifetime (seconds)', input('number', row.expires_seconds || 300)); expiry.min = 60; expiry.max = 900; expiry.step = 1;
      handler = () => saveRow(section, { ...row, name: name.value, enabled: active.checked, channels: values(channels), approver_ids: values(people), expires_seconds: Number(expiry.value) });
    } else if (section === 'drills') {
      const template = field('Applied incident template', select(state.templates, row.template_id)); template.required = true;
      const owner = field('Drill owner', select(state.people, row.owner_id)); owner.required = true;
      const reviewers = field('Reviewers', select(state.people, row.reviewer_ids || [], true));
      const sites = field('Sites', select(state.sites, row.site_ids || [], true));
      const due = field('Completion deadline', input('datetime-local')); due.required = true;
      if (row.due_at) { const date = new Date(row.due_at); due.value = new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16); }
      const objectives = field('Review objectives', node('textarea'), 'One objective per line, up to 25.'); objectives.value = (row.objectives || []).join('\n');
      handler = () => saveRow(section, { ...row, name: name.value, enabled: active.checked, template_id: template.value, owner_id: owner.value, reviewer_ids: values(reviewers), site_ids: values(sites), due_at: new Date(due.value).toISOString().replace(/\.\d{3}Z$/, 'Z'), objectives: objectives.value.split(/\r?\n/).map(v => v.trim()).filter(Boolean) });
    } else if (section === 'shift_routing') {
      const templates = field('Incident templates', select(state.templates, row.template_ids || [], true));
      const timezone = field('Timezone', select(state.timezones.map(key => ({ id: key, name: key })), row.timezone || state.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone));
      const weekdays = field('Start weekdays', select(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'].map((name, i) => ({ id: String(i + 1), name })), (row.days || []).map(String), true), 'Overnight shifts belong to the weekday on which they start.');
      const start = field('Start time', input('time', row.start || '08:00')), end = field('End time', input('time', row.end || '17:00')); start.required = end.required = true;
      const groups = field('On-duty saved audiences', select(state.audiences, row.delivery?.group_ids || [], true));
      const mode = field('Audio', select(['none', 'tones', 'tts', 'tones_tts'].map(key => ({ id: key, name: key.replaceAll('_', ' + ') })), row.delivery?.audio_mode || 'tones_tts'));
      handler = () => { const delivery = { ...(row.delivery || {}), group_ids: values(groups), audio_mode: mode.value }; if (!original) Object.assign(delivery, { extensions: [], desktop_clients: [], voice_recipient_ids: [], webhook_ids: [], email_recipient_ids: [], sms_recipient_ids: [], opening_tone: '', closing_tone: '', style: 'standard', background_color: '#1f2937' }); saveRow(section, { ...row, name: name.value, enabled: active.checked, template_ids: values(templates), timezone: timezone.value, days: values(weekdays).map(Number), start: start.value, end: end.value, delivery }); };
    } else {
      const site = field('Site', select(state.sites, row.site_id)); site.required = true;
      let imageId = row.image_id || '', markers = structuredClone(row.markers || []);
      const upload = field('PNG/JPEG floor plan', input('file'), 'At most 5 MiB, 4096 pixels per side and eight megapixels. Images are re-encoded and stored privately.'); upload.accept = 'image/png,image/jpeg';
      const map = node('div', undefined, 'sls-ent-map'), image = node('img'); image.alt = 'Floor plan. Select a target and click the image to place its marker.'; map.append(image); body.append(map);
      if (imageId) image.src = `${endpoint}&image_id=${encodeURIComponent(imageId)}`; else map.hidden = true;
      const target = field('Marker location or device', select(state.marker_choices.map(item => ({ id: `${item.kind}:${item.id}`, name: `${item.kind} · ${item.name}` })), ''));
      const markerList = node('div'); body.append(markerList);
      const renderMarkers = () => { map.querySelectorAll('button').forEach(n => n.remove()); markerList.replaceChildren(); markers.forEach((m, i) => { const point = node('button', String(i + 1), 'sls-ent-marker'); point.type = 'button'; point.style.left = `${m.x}%`; point.style.top = `${m.y}%`; point.title = m.label; map.append(point); const line = node('div', undefined, 'sls-ent-row'), remove = node('button', 'Remove', 'btn btn-link btn-sm'); remove.type = 'button'; remove.addEventListener('click', () => { markers.splice(i, 1); renderMarkers(); }); line.append(node('span', `${i + 1}. ${m.label}`), remove); markerList.append(line); }); };
      upload.addEventListener('change', async () => {
        const file = upload.files[0];
        if (!file) return;
        if (requestBusy) { feedback('Another Enterprise Labs request is already in progress. Wait for its result before uploading a floor plan.'); return; }
        requestBusy = true;
        const form = new FormData(); form.set('action', 'floorplan_upload'); form.set('slsmassnotifyserver_csrf', data.csrf); form.set('floorplan', file);
        upload.disabled = true;
        const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 60000);
        try {
          const response = await fetch(endpoint, { method: 'POST', body: form, credentials: 'same-origin', signal: controller.signal });
          let result; try { result = await response.json(); } catch { throw Error('The PBX returned an unreadable floor-plan upload response. Reload saved images before retrying.'); }
          const serverMessage = result && typeof result.message === 'string' && result.message.trim() !== '' ? result.message : '';
          if (!result || typeof result !== 'object' || Array.isArray(result) || !response.ok || result.success !== true || typeof result.image_id !== 'string' || result.image_id === '') throw Error(serverMessage || 'The floor-plan upload was not confirmed. Reload saved images before retrying.');
          imageId = result.image_id; image.src = `${endpoint}&image_id=${encodeURIComponent(imageId)}`; map.hidden = false;
        } catch (error) {
          feedback(controller.signal.aborted ? 'The floor plan upload exceeded 60 seconds. Check saved images before retrying.' : error.message);
        } finally {
          clearTimeout(timer); upload.disabled = false; requestBusy = false;
        }
      });
      map.addEventListener('click', event => { if (event.target !== image || !target.value || markers.length >= 250) return; const chosen = state.marker_choices.find(item => `${item.kind}:${item.id}` === target.value), rect = image.getBoundingClientRect(); markers.push({ id: id('mark'), label: chosen.name.slice(0, 80), kind: chosen.kind, target_id: chosen.id, x: Math.max(0, Math.min(100, (event.clientX - rect.left) / rect.width * 100)), y: Math.max(0, Math.min(100, (event.clientY - rect.top) / rect.height * 100)) }); renderMarkers(); });
      renderMarkers(); handler = () => { if (!imageId) throw Error('Upload a floor plan before saving this entry.'); saveRow(section, { ...row, name: name.value, enabled: active.checked, site_id: site.value, image_id: imageId, markers }); };
    }
  }
  root.querySelectorAll('[data-add-operation]').forEach(n => n.addEventListener('click', () => editRow(n.dataset.addOperation)));
  rows();
  const storage = root.querySelector('#sls-floorplan-storage');
  const storedImages = state.floorplan_storage?.images || [];
  if (!storedImages.length) storage.append(node('div', 'No floor-plan images are stored.', 'sls-ent-empty'));
  storedImages.forEach(image => {
    const report = image.reference_report || { references: [], blocking_count: 0 };
    const row = node('div', undefined, 'sls-ent-row'), description = node('div');
    description.append(node('strong', image.image_id.slice(4, 20)), node('div', `${(Number(image.bytes) / 1048576).toFixed(2)} MiB`));
    if (report.blocking_count) description.append(node('small', report.references.map(reference => `${reference.source.replaceAll('_', ' ')} · ${reference.name}`).join('; ')));
    else description.append(node('small', 'No active or staged reference. Retained configuration backups are checked again before retirement.'));
    const actions = node('div', undefined, 'sls-ent-actions');
    const download = node('a', 'Download', 'btn btn-default btn-sm'); download.href = `${endpoint}&image_id=${encodeURIComponent(image.image_id)}&download=1`;
    const retire = node('button', 'Review retirement', 'btn btn-danger btn-sm'); retire.type = 'button'; retire.disabled = report.blocking_count > 0;
    retire.addEventListener('click', () => {
      open('Retire stored floor plan', 'Retire image');
      body.append(node('p', `Image ${image.image_id.slice(4, 20)} uses ${(Number(image.bytes) / 1048576).toFixed(2)} MiB.`),
        node('p', 'Download the image first if it must be retained outside SLS. The server will recheck active, staged and retained configuration references while settings are locked.'));
      const exportLink = node('a', 'Download image before retirement', 'btn btn-default'); exportLink.href = download.href; body.append(exportLink);
      const confirmation = field('Enter RETIRE to confirm permanent removal', input('text')); confirmation.required = true; confirmation.pattern = 'RETIRE'; confirmation.autocomplete = 'off';
      handler = async () => { const result = await post('floorplan_retire', { image_id: image.image_id, confirmation: confirmation.value }); feedback(result.message); location.reload(); };
    });
    actions.append(download, retire); row.append(description, actions); storage.append(row);
  });
  const retirementEvents = state.floorplan_retirements || [];
  if (retirementEvents.length) {
    const latest = retirementEvents[0];
    storage.append(node('p', `Latest retirement audit: ${latest.kind.replaceAll('_', ' ')} · ${latest.at}`, 'help-block'));
  }
  root.querySelector('#sls-operations-save').addEventListener('click', async event => {
    const button = event.currentTarget; button.disabled = true;
    try { config.enabled = enabled.checked; root.querySelectorAll('[data-enable-operation]').forEach(n => config[n.dataset.enableOperation].enabled = n.checked); await post('operations_save', { config, revision: state.revision }); location.reload(); } catch (error) { feedback(error.message); } finally { button.disabled = false; }
  });
  const reviews = root.querySelector('#sls-enterprise-reviews');
  if (!state.reviews.length) reviews.append(node('div', 'No reviews are assigned to your current account.', 'sls-ent-empty'));
  state.reviews.forEach(review => {
    const card = node('article', undefined, 'sls-ent-card'); card.append(node('h3', review.title), node('p', review.message), node('p', `${review.state.replaceAll('_', ' ')} · expires ${review.expires_at}`));
    const destinations = Object.entries(review.recipients).filter(([, ids]) => ids.length).map(([channel, ids]) => `${channel.replaceAll('_', ' ')}: ${ids.join(', ')}`); card.append(node('p', destinations.join(' · ')));
    if (review.state === 'awaiting_approval') { const actions = node('div', undefined, 'sls-ent-actions'); for (const [action, label] of [['approve', 'Approve exact message'], ['submit', 'Send approved message'], ['reject', 'Reject review']]) { const button = node('button', label, `btn ${action === 'submit' ? 'btn-primary' : 'btn-default'}`); button.type = 'button'; button.addEventListener('click', async () => { button.disabled = true; try { const result = await post(`approval_${action}`, { review_id: review.id }); feedback(result.message || 'Approval action recorded.'); location.reload(); } catch (error) { feedback(error.message); } finally { button.disabled = false; } }); actions.append(button); } card.append(actions); }
    reviews.append(card);
  });
  const drills = root.querySelector('#sls-enterprise-drills');
  if (!state.drill_report.length) drills.append(node('div', 'No enabled drill assignments.', 'sls-ent-empty'));
  state.drill_report.forEach(assignment => {
    const card = node('article', undefined, 'sls-ent-card'); card.append(node('h3', assignment.name), node('p', `${assignment.status.replaceAll('_', ' ')} · due ${assignment.due_at} · owner ${assignment.owner_id}`));
    const launch = node('button', 'Launch drill', 'btn btn-primary'); launch.type = 'button'; launch.addEventListener('click', () => { open(`Launch drill · ${assignment.name}`, 'Launch marked drill'); body.append(node('p', 'Every announcement in this run is marked DRILL / TEST. The applied template supplies the audience and delivery settings.')); const template = state.templates.find(row => row.id === assignment.template_id), fields = {}; (template?.fields || []).forEach(row => { const control = field(row.label, input('text')); control.required = true; control.maxLength = 120; fields[row.key] = control; }); const request = requestId(); handler = async () => { const values = Object.fromEntries(Object.entries(fields).map(([key, control]) => [key, control.value])); const result = await post('drill_start', { assignment_id: assignment.id, request_id: request, fields: values }); feedback(result.message || 'Drill run created. Review its incident delivery details.'); location.reload(); }; }); card.append(launch);
    assignment.runs.forEach(run => { const line = node('div', undefined, 'sls-ent-row'); line.append(node('span', `${run.created_at} · ${run.state}`)); const review = node('button', 'Record review', 'btn btn-default btn-sm'); review.type = 'button'; review.addEventListener('click', () => { open('Review drill objectives', 'Record review'); const flags = run.assignment.objectives.map(text => { const flag = input('checkbox'); field(text, flag); return flag; }); const note = field('Review notes', node('textarea')); note.maxLength = 1000; handler = async () => { await post('drill_review', { run_id: run.id, completed: flags.map(flag => flag.checked), note: note.value }); location.reload(); }; }); line.append(review); card.append(line); }); drills.append(card);
  });
  root.querySelector('#sls-operations-archive').addEventListener('click', async event => { event.currentTarget.disabled = true; try { const result = await post('operations_archive', { retention_days: Number(root.querySelector('#sls-operations-retention').value) }); feedback('Completed coordination history archived.'); } catch (error) { feedback(error.message); } finally { event.currentTarget.disabled = false; } });
  root.querySelector('#sls-drill-export').addEventListener('click', async () => { try { const report = await post('drill_report'), url = URL.createObjectURL(new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' })), link = node('a'); link.href = url; link.download = 'sls-drill-report.json'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); } catch (error) { feedback(error.message); } });
})();
