(() => {
  'use strict';
  const root = document.getElementById('sls-enterprise');
  if (!root) return;
  const data = JSON.parse(document.getElementById('sls-enterprise-data').textContent);
  const state = data.operations, config = structuredClone(state.config);
  const endpoint = 'config.php?display=slsmassnotifyserver_enterprise';
  const feedback = message => { const box = root.querySelector('#sls-enterprise-status'); box.textContent = message; box.hidden = false; box.focus(); };
  const node = (tag, text, className) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (className) n.className = className; return n; };
  const post = async (action, input = {}) => {
    const body = new FormData(); body.set('slsmassnotifyserver_csrf', data.csrf); body.set('payload', JSON.stringify({ action, input }));
    const response = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    let result; try { result = await response.json(); } catch { throw Error('The server did not return a valid result. Reload saved state before retrying.'); }
    if (!response.ok || result.success === false) throw Error(result.message || `The request failed (HTTP ${response.status}).`);
    return result;
  };
  function tab(key, update = false) {
    const keys = ['continuity', 'identity', 'integrations', 'operations']; if (!keys.includes(key)) key = keys[0];
    root.querySelectorAll('[data-labs-panel]').forEach(n => n.hidden = n.dataset.labsPanel !== key);
    root.querySelectorAll('[data-labs-tab]').forEach(n => { if (n.dataset.labsTab === key) n.setAttribute('aria-current', 'page'); else n.removeAttribute('aria-current'); });
    if (update) history.replaceState(null, '', `#labs-${key}`);
  }
  root.querySelectorAll('[data-labs-tab]').forEach(n => n.addEventListener('click', event => { event.preventDefault(); tab(n.dataset.labsTab, true); }));
  tab(location.hash.replace(/^#labs-/, '')); window.addEventListener('hashchange', () => tab(location.hash.replace(/^#labs-/, '')));

  const danger = root.querySelector('#sls-labs-danger'), agree = root.querySelector('#sls-labs-danger-agree');
  const accept = root.querySelector('#sls-labs-danger-accept'), cancel = root.querySelector('#sls-labs-danger-cancel');
  const acknowledged = new Set(Object.keys(data.safety.receipts).filter(key => data.safety.receipts[key].revision === data.safety_revision));
  const pageFeature = 'enterprise_labs';
  const dashboard = 'config.php?display=index';
  const pageAccess = allowed => root.querySelectorAll('[data-labs-panel], .sls-ent-tabs').forEach(panel => { panel.inert = !allowed; });
  pageAccess(acknowledged.has(pageFeature));
  let dangerRequest;
  function acknowledge() {
    if (acknowledged.has(pageFeature)) return Promise.resolve(true);
    if (!dangerRequest) dangerRequest = showWarning().finally(() => { dangerRequest = null; });
    return dangerRequest;
  }
  async function showWarning() {
      const feature = pageFeature;
      const challenge = await post('danger_begin', { feature });
      if (challenge.acknowledged) { acknowledged.add(feature); pageAccess(true); return true; }
      root.querySelector('#sls-labs-danger-title').textContent = challenge.title;
      root.querySelector('#sls-labs-danger-body').textContent = challenge.body;
      root.querySelector('#sls-labs-danger-agreement').textContent = challenge.agreement;
      const error = root.querySelector('#sls-labs-danger-error'), countdown = root.querySelector('#sls-labs-danger-countdown');
      error.hidden = true; agree.checked = false; agree.disabled = true; accept.disabled = true; countdown.textContent = '(5s)';
      danger.showModal(); const deadline = performance.now() + challenge.delay_seconds * 1000;
      const timer = setInterval(() => {
        const left = Math.max(0, Math.ceil((deadline - performance.now()) / 1000)); countdown.textContent = left ? `(${left}s)` : '';
        if (performance.now() >= deadline) { agree.disabled = false; clearInterval(timer); }
      }, 100);
      return await new Promise(resolve => {
        const cleanup = result => {
          clearInterval(timer); agree.onchange = null; accept.onclick = null; cancel.onclick = null; danger.oncancel = null; danger.onclose = null;
          pageAccess(result); danger.close(); resolve(result);
          if (!result) window.location.replace(dashboard);
        };
        agree.onchange = () => accept.disabled = !agree.checked || performance.now() < deadline;
        cancel.onclick = () => cleanup(false); danger.oncancel = event => { event.preventDefault(); cleanup(false); };
        danger.onclose = () => cleanup(false);
        accept.onclick = async () => {
          if (!agree.checked || agree.disabled || performance.now() < deadline) return;
          accept.disabled = true; cancel.disabled = true;
          try { await post('danger_accept', { feature, challenge: challenge.challenge, agree: true }); acknowledged.add(feature); cleanup(true); }
          catch (failure) { error.textContent = failure.message; error.hidden = false; accept.disabled = false; }
          finally { cancel.disabled = false; }
        };
      });
  }
  window.SlsLabsSafety = { acknowledge };
  acknowledge().catch(error => { feedback(error.message); window.location.replace(dashboard); });
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
  function open(title, label = 'Save in editor') { body.replaceChildren(); root.querySelector('#sls-enterprise-editor-title').textContent = title; submit.textContent = label; handler = null; editor.showModal(); }
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
  function saveRow(section, row) { const list = config[section][lists[section]], index = list.findIndex(item => item.id === row.id); if (index < 0) list.push(row); else list[index] = row; rows(); feedback('Saved in this editor. Use Save coordination settings to apply.'); }
  function editRow(section, original) {
    const row = original ? structuredClone(original) : { id: id({ floorplans: 'plan', drills: 'drill', shift_routing: 'shift', dual_approval: 'approval' }[section]), enabled: false, name: '' };
    open(`${original ? 'Edit' : 'Add'} ${labels[section]}`);
    const name = field('Name', input('text', row.name)); name.required = true; name.maxLength = 80;
    const active = input('checkbox'); active.checked = row.enabled; field('Enable this saved entry', active);
    if (section === 'dual_approval') {
      const channels = field('Channels requiring review', select(['phones', 'desktops', 'webhooks', 'voice_recipient_ids', 'email_recipient_ids', 'sms_recipient_ids'].map(key => ({ id: key, name: { phones: 'Phone audio/display', desktops: 'Desktop', webhooks: 'Webhook', voice_recipient_ids: 'External calls', email_recipient_ids: 'Email', sms_recipient_ids: 'SMS / MMS' }[key] })), row.channels || [], true));
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
      upload.addEventListener('change', async () => { const file = upload.files[0]; if (!file) return; const form = new FormData(); form.set('action', 'floorplan_upload'); form.set('slsmassnotifyserver_csrf', data.csrf); form.set('floorplan', file); upload.disabled = true; try { const response = await fetch(endpoint, { method: 'POST', body: form, credentials: 'same-origin' }), result = await response.json(); if (!response.ok || !result.success) throw Error(result.message || 'Floor plan upload failed.'); imageId = result.image_id; image.src = `${endpoint}&image_id=${encodeURIComponent(imageId)}`; map.hidden = false; } catch (error) { feedback(error.message); } finally { upload.disabled = false; } });
      map.addEventListener('click', event => { if (event.target !== image || !target.value || markers.length >= 250) return; const chosen = state.marker_choices.find(item => `${item.kind}:${item.id}` === target.value), rect = image.getBoundingClientRect(); markers.push({ id: id('mark'), label: chosen.name.slice(0, 80), kind: chosen.kind, target_id: chosen.id, x: Math.max(0, Math.min(100, (event.clientX - rect.left) / rect.width * 100)), y: Math.max(0, Math.min(100, (event.clientY - rect.top) / rect.height * 100)) }); renderMarkers(); });
      renderMarkers(); handler = () => { if (!imageId) throw Error('Upload a floor plan before saving this entry.'); saveRow(section, { ...row, name: name.value, enabled: active.checked, site_id: site.value, image_id: imageId, markers }); };
    }
  }
  root.querySelectorAll('[data-add-operation]').forEach(n => n.addEventListener('click', () => editRow(n.dataset.addOperation)));
  rows();
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
  root.querySelector('#sls-drill-export').addEventListener('click', async () => { try { const report = await post('drill_report'), url = URL.createObjectURL(new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' })), link = node('a'); link.href = url; link.download = 'sls-drill-report.json'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); } catch (error) { feedback(error.message); } });
})();
