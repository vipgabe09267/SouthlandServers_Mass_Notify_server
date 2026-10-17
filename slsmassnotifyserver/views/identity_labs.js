(() => {
  'use strict';
  const root = document.getElementById('enterprise-identity-labs');
  if (!root) return;
  const boot = JSON.parse(root.querySelector('[data-identity-bootstrap]').textContent);
  const find = selector => root.querySelector(selector);
  const status = find('[data-identity-status]');
  let plan = '';
  let confirmationRequired = false;
  const read = key => JSON.parse(find(`[data-config="${key}"]`).value);
  const write = (key, value) => { find(`[data-config="${key}"]`).value = JSON.stringify(value, null, 2); };
  const randomId = prefix => { const bytes = new Uint8Array(12); crypto.getRandomValues(bytes); return prefix + [...bytes].map(value => value.toString(16).padStart(2, '0')).join(''); };
  const node = (tag, text, className) => { const result = document.createElement(tag); if (text !== undefined) result.textContent = text; if (className) result.className = className; return result; };
  const feedback = (message, error = false) => { status.textContent = message; status.className = error ? 'alert alert-danger' : 'alert alert-success'; };
  const update = (key, mutate) => { const value = read(key); mutate(value); write(key, value); };
  const input = (parent, label, value, change, options = {}) => {
    const wrapper = node('label', label), control = node(options.choices ? 'select' : options.multiline ? 'textarea' : 'input');
    control.className = 'form-control';
    if (options.choices) options.choices.forEach(choice => { const option = node('option', choice.name); option.value = choice.id; control.append(option); });
    else if (!options.multiline) { control.type = options.type ?? 'text'; if (options.type === 'password') { control.autocomplete = 'new-password'; control.placeholder = 'Blank preserves saved secret'; } }
    control.value = value ?? ''; control.readOnly = options.readonly === true; control.addEventListener('input', () => change(options.type === 'number' ? Number(control.value) : control.value)); wrapper.append(control); parent.append(wrapper); return control;
  };
  const toggle = (parent, label, enabled, change) => { const wrapper = node('label', undefined, 'sls-identity-toggle'), control = node('input'); control.type = 'checkbox'; control.checked = enabled === true; control.addEventListener('change', () => change(control.checked)); wrapper.append(control, document.createTextNode(label)); parent.append(wrapper); };
  const card = (parent, title, remove) => { const result = node('section', undefined, 'sls-identity-card'), heading = node('div', undefined, 'sls-identity-card-heading'), button = node('button', 'Remove', 'btn btn-link text-danger'); button.type = 'button'; button.addEventListener('click', remove); heading.append(node('h4', title), button); result.append(heading); parent.append(result); return result; };
  const call = async (action, inputValue) => {
    status.textContent = 'Working…';
    status.className = 'alert alert-info';
    const body = new URLSearchParams({ slsmassnotifyserver_action: 'enterprise_labs', slsmassnotifyserver_csrf: boot.csrf, payload: JSON.stringify({ action, input: inputValue }) });
    const signal = AbortSignal.timeout(25000);
    try {
      const response = await fetch(boot.endpoint, { method: 'POST', credentials: 'same-origin', body, cache: 'no-store', headers: { Accept: 'application/json' }, signal });
      let result;
      try {
        result = await response.json();
      } catch (error) {
        throw new Error('The PBX returned an unreadable directory or operator SSO response. Reload saved settings before retrying.');
      }
      if (!result || typeof result !== 'object' || Array.isArray(result)) throw new Error('The PBX did not return a valid directory or operator SSO response. Reload saved settings before retrying.');
      feedback(result.message || 'Request completed.', result.success !== true);
      if (!response.ok || result.success !== true) throw new Error(result.message || 'The operation was not confirmed.');
      return result;
    } catch (error) {
      if (signal.aborted) throw new Error('The directory or operator SSO change was not confirmed within 25 seconds. Reload saved settings before retrying.');
      throw error;
    }
  };
  const run = fn => async event => {
    const button = event?.currentTarget; if (button) button.disabled = true;
    try { await fn(); } catch (error) { feedback(error.message, true); }
    finally { if (button) { button.disabled = false; if (button.hasAttribute('data-browser-key')) button.disabled = read('subscriber').push?.private_key_configured === true; if (button.hasAttribute('data-apply-directory')) button.disabled = !plan || (confirmationRequired && !find('[data-confirm-deactivation]').checked); } }
  };
  const choices = (rows, prompt) => [{ id: '', name: prompt }, ...rows];
  function render() {
    const identity = boot.identity ? read('identity') : null;
    if (identity) {
    identity.providers.forEach(provider => { provider.callback_url = boot.callback_url; }); write('identity', identity);
    const providers = find('[data-identity-providers]'); providers.replaceChildren();
    toggle(providers, 'Enable operator SSO', identity.enabled, enabled => update('identity', value => { value.enabled = enabled; }));
    identity.providers.forEach((provider, index) => {
      const section = card(providers, `${provider.name} · ${provider.protocol.toUpperCase()}`, () => { update('identity', value => { value.providers.splice(index, 1); }); render(); });
      const fields = node('div', undefined, 'sls-identity-fields'); section.append(fields);
      const change = (key, value) => update('identity', config => { config.providers[index][key] = value; });
      toggle(section, 'Enabled', provider.enabled, value => change('enabled', value));
      input(fields, 'Display name', provider.name, value => change('name', value));
      input(fields, 'Exact issuer', provider.issuer, value => change('issuer', value));
      input(fields, 'Registered HTTPS callback', boot.callback_url, () => {}, { readonly: true });
      if (provider.protocol === 'oidc') {
        for (const [key, label] of [['discovery_url', 'Discovery URL'], ['client_id', 'Client ID'], ['client_secret', 'Client secret'], ['hosted_domain', 'Workspace domain (Google only)']]) input(fields, label, provider[key], value => change(key, value), { type: key === 'client_secret' ? 'password' : 'text' });
        input(fields, 'Allowed endpoint hostnames (one per line)', provider.endpoint_hosts.join('\n'), value => change('endpoint_hosts', value.split(/\s+/).filter(Boolean)), { multiline: true });
        input(fields, 'Private endpoint pins (host=IP, one per line)', (provider.private_endpoint_pins ?? []).map(pin => `${pin.host}=${pin.ip}`).join('\n'), value => change('private_endpoint_pins', value.split(/\r?\n/).map(line => line.trim()).filter(Boolean).map(line => { const split = line.lastIndexOf('='); return { host: split < 1 ? '' : line.slice(0, split).trim(), ip: split < 1 ? '' : line.slice(split + 1).trim() }; })), { multiline: true });
      } else {
        for (const [key, label] of [['idp_sso_url', 'IdP sign-in URL'], ['sp_entity_id', 'SP entity ID'], ['sp_certificate', 'SP certificate PEM'], ['sp_private_key', 'SP private key PEM']]) input(fields, label, provider[key], value => change(key, value), { multiline: key.includes('certificate') || key.includes('private_key') });
        input(section, 'Pinned IdP signing certificates (one complete PEM per block)', provider.idp_signing_certificates.join('\n'), value => change('idp_signing_certificates', value.match(/-----BEGIN CERTIFICATE-----[\s\S]*?-----END CERTIFICATE-----/g) ?? []), { multiline: true });
        if (provider.certificate_warning) section.append(node('p', provider.certificate_warning, 'alert alert-warning'));
      }
    });
    const grants = find('[data-identity-grants]'); grants.replaceChildren();
    identity.grants.forEach((grant, index) => {
      const section = card(grants, 'Account binding', () => { update('identity', value => { value.grants.splice(index, 1); }); render(); });
      const change = (key, value) => update('identity', config => { config.grants[index][key] = value; });
      input(section, 'Provider', grant.provider_id, value => change('provider_id', value), { choices: choices(identity.providers, 'Select a provider') });
      input(section, 'Exact immutable subject / NameID', grant.subject, value => change('subject', value));
      input(section, 'Existing local account', grant.operator_account_id, value => change('operator_account_id', value), { choices: choices(boot.accounts.map(account => ({ id: account.id, name: `${account.username} · ${account.role}` })), 'Select a local account') });
      toggle(section, 'Enabled', grant.enabled, value => change('enabled', value));
    });
    }
    if (!boot.directory || !boot.subscriber) return;
    const directory = read('directory'), subscriber = read('subscriber');
    const sources = find('[data-directory-sources]'); sources.replaceChildren();
    toggle(sources, 'Enable directory synchronization', directory.enabled, enabled => update('directory', value => { value.enabled = enabled; }));
    directory.sources.forEach((source, index) => {
      const section = card(sources, `${source.name} · ${source.type.toUpperCase()}`, () => { update('directory', value => { value.sources.splice(index, 1); }); render(); });
      const fields = node('div', undefined, 'sls-identity-fields'); section.append(fields);
      const change = (key, value) => update('directory', config => { config.sources[index][key] = value; });
      input(fields, 'Name', source.name, value => change('name', value));
      toggle(section, 'Enabled', source.enabled, value => change('enabled', value));
      toggle(section, 'Synchronize saved browser subscribers', source.sync_subscribers, value => change('sync_subscribers', value));
      input(fields, 'Deactivation confirmation threshold (%)', source.maximum_deactivation_percent ?? 25, value => change('maximum_deactivation_percent', value), { type: 'number' });
      section.append(node('p', `${source.records?.length ?? 0} imported records. Select the incident templates this source may update.`, 'help-block'));
      boot.templates.forEach(template => toggle(section, template.name, source.template_ids.includes(template.id), enabled => update('directory', config => { const saved = config.sources[index]; saved.template_ids = enabled ? [...saved.template_ids, template.id] : saved.template_ids.filter(id => id !== template.id); })));
      if (source.type === 'ldap') {
        for (const [key, label] of [['ldap_url', 'LDAP URL'], ['ldap_bind_dn', 'Bind DN'], ['ldap_password', 'Bind password'], ['ldap_base_dn', 'Search base DN'], ['ldap_filter', 'Search filter']]) input(fields, label, source[key], value => change(key, value), { type: key === 'ldap_password' ? 'password' : 'text' });
        toggle(section, 'Require StartTLS for ldap://', source.ldap_starttls, value => change('ldap_starttls', value));
        for (const [key, label] of [['external_id', 'Immutable ID attribute'], ['name', 'Display name attribute'], ['email', 'Email attribute'], ['location', 'Location attribute'], ['desktop_username', 'Desktop username attribute'], ['active', 'Active / flags attribute']]) input(fields, label, source.ldap_attributes[key], value => update('directory', config => { config.sources[index].ldap_attributes[key] = value; }));
        input(fields, 'Inactive flags mask (AD disabled bit: 2; 0 uses inactive values)', source.ldap_inactive_mask ?? 0, value => change('ldap_inactive_mask', value), { type: 'number' });
      }
      if (source.type === 'scim') {
        input(fields, 'SCIM base HTTPS URL', source.scim_base_url, value => change('scim_base_url', value));
        input(fields, 'Read-only bearer token', source.scim_token, value => change('scim_token', value), { type: 'password' });
        for (const key of ['external_id', 'name', 'location', 'desktop_username']) input(fields, `${key.replaceAll('_', ' ')} JSON pointer`, source.scim_attributes?.[key] ?? ({ external_id: '/id', name: '/displayName' }[key] ?? ''), value => update('directory', config => { config.sources[index].scim_attributes ??= { external_id: '/id', name: '/displayName', location: '', desktop_username: '' }; config.sources[index].scim_attributes[key] = value; }));
        section.append(node('p', 'Mappings accept documented SCIM core fields and enterprise department, organization or employeeNumber. Remote partial results cannot remove people.', 'help-block'));
      }
    });
    const fill = (selector, rows, prompt) => { const select = find(selector), previous = select.value; select.replaceChildren(); choices(rows, prompt).forEach(choice => { const option = node('option', choice.name); option.value = choice.id; select.append(option); }); select.value = previous; };
    fill('[data-preview-source]', directory.sources, 'Select a saved source');
    const settings = find('[data-subscriber-settings]'); settings.replaceChildren();
    toggle(settings, 'Enable verified browser subscribers', subscriber.enabled, enabled => update('subscriber', value => { value.enabled = enabled; if (!enabled) value.push.enabled = false; }));
    input(settings, 'Public subscriber HTTPS URL', subscriber.public_base_url, value => update('subscriber', config => { config.public_base_url = value; }));
    toggle(settings, 'Enable encrypted browser Web Push', subscriber.push?.enabled, enabled => update('subscriber', value => { value.push.enabled = enabled; }));
    input(settings, 'VAPID contact (mailto: or HTTPS)', subscriber.push?.subject, value => update('subscriber', config => { config.push.subject = value; }));
    const key = find('[data-browser-key]'); key.disabled = subscriber.push?.private_key_configured === true;
    if (subscriber.push?.public_key) settings.append(node('p', 'VAPID key configured. Existing enrollments depend on this key.', 'help-block'));
    const people = find('[data-subscriber-people]'); people.replaceChildren();
    subscriber.people.forEach((person, index) => {
      const section = card(people, person.name || 'New subscriber', () => { update('subscriber', value => { value.people.splice(index, 1); }); render(); });
      const fields = node('div', undefined, 'sls-identity-fields'); section.append(fields);
      const change = (key, value) => update('subscriber', config => { config.people[index][key] = value; });
      input(fields, 'Incident roster person', person.person_id, value => { change('person_id', value); const selected = boot.people.find(row => row.id === value); if (selected) { change('name', selected.name); render(); } }, { choices: choices(boot.people, 'Select an existing person') });
      input(fields, 'Exact roster name', person.name, value => change('name', value)); input(fields, 'Verification email', person.email, value => change('email', value));
      toggle(section, 'Enabled', person.enabled, value => change('enabled', value));
    });
    fill('[data-invite-subscriber]', subscriber.people.filter(person => person.enabled), 'Select a subscriber');
    fill('[data-invite-incident]', boot.incidents, 'Select an open incident');
  }
  const clearPlan = () => { plan = ''; confirmationRequired = false; if (!find('[data-apply-directory]')) return; find('[data-apply-directory]').disabled = true; find('[data-deactivation-warning]').hidden = true; find('[data-confirm-deactivation]').checked = false; };
  root.querySelectorAll('[data-config]').forEach(editor => editor.addEventListener('change', () => { try { clearPlan(); render(); } catch (error) { feedback(error.message, true); } }));
  root.querySelectorAll('[data-save-config]').forEach(button => button.addEventListener('click', run(async () => {
    const key = button.dataset.saveConfig, config = read(key);
    if (key === 'identity' && config.enabled) { if (!window.SlsLabsSafety) throw new Error('Reload Operator Access to review the SSO warning.'); if (!await window.SlsLabsSafety.acknowledge()) return; }
    const result = await call({ identity: 'identity_save', directory: 'directory_save', subscriber: 'subscriber_save' }[key], { config, revision: boot.revisions[key] });
    write(key, result.config); boot.revisions[key] = result.revision; clearPlan(); render();
  })));
  find('[data-add-provider]')?.addEventListener('click', run(() => {
    const config = read('identity'), vendor = find('[data-provider-vendor]').value, protocol = find('[data-provider-protocol]').value;
    const provider = { id: randomId('idp_'), vendor, protocol, name: { entra: 'Entra ID', okta: 'Okta', google: 'Google Workspace', keycloak: 'Keycloak', onelogin: 'OneLogin' }[vendor], enabled: false, issuer: '', callback_url: boot.callback_url };
    if (protocol === 'oidc') { Object.assign(provider, { discovery_url: '', client_id: '', client_secret: '', token_auth: vendor === 'onelogin' ? 'none' : 'client_secret_basic', endpoint_hosts: [], private_endpoint_pins: [], hosted_domain: '' }); if (vendor === 'google') { provider.issuer = 'https://accounts.google.com'; provider.discovery_url = provider.issuer + '/.well-known/openid-configuration'; provider.endpoint_hosts = ['accounts.google.com', 'oauth2.googleapis.com', 'www.googleapis.com']; } }
    else Object.assign(provider, { idp_sso_url: '', idp_signing_certificates: [], sp_entity_id: '', sp_certificate: '', sp_private_key: '', nameid_format: 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent' });
    config.providers.push(provider); write('identity', config); render(); feedback('Provider added disabled. Complete its registered settings before saving.');
  }));
  find('[data-add-grant]')?.addEventListener('click', run(() => { update('identity', config => config.grants.push({ provider_id: '', subject: '', operator_account_id: '', enabled: false })); render(); }));
  find('[data-add-source]')?.addEventListener('click', run(() => {
    const config = read('directory'), type = find('[data-source-type]').value, source = { id: randomId('dir_'), name: `New ${type} source`, type, enabled: false, template_ids: [], sync_subscribers: false, records: [], maximum_deactivation_percent: 25 };
    if (type === 'ldap') Object.assign(source, { ldap_url: '', ldap_starttls: true, ldap_bind_dn: '', ldap_password: '', ldap_base_dn: '', ldap_filter: '(objectClass=person)', ldap_attributes: { external_id: 'entryUUID', name: 'displayName', email: 'mail', location: 'l', desktop_username: 'uid', active: '' }, ldap_inactive_values: ['false', '0'], ldap_inactive_mask: 0 });
    if (type === 'scim') Object.assign(source, { scim_base_url: '', scim_token: '', scim_attributes: { external_id: '/id', name: '/displayName', location: '', desktop_username: '' } });
    config.sources.push(source); write('directory', config); render(); feedback('Source added disabled. Select its templates and complete connector settings before saving.');
  }));
  const preview = async deprovision => {
    clearPlan(); const value = { source_id: find('[data-preview-source]').value };
    if (deprovision) value.deprovision_all = true;
    else { const file = find('[data-csv-file]').files[0]; if (file) { if (file.size > 524288) throw new Error('CSV exceeds 512 KiB.'); value.csv = await file.text(); } }
    const result = await call('directory_preview', value); plan = result.plan_id; confirmationRequired = result.plan.requires_deactivation_confirmation === true;
    find('[data-directory-diff]').textContent = JSON.stringify(result.plan, null, 2); find('[data-deactivation-warning]').hidden = !confirmationRequired; find('[data-apply-directory]').disabled = confirmationRequired;
  };
  find('[data-preview-directory]')?.addEventListener('click', run(() => preview(false)));
  find('[data-preview-deprovision]')?.addEventListener('click', run(() => preview(true)));
  find('[data-confirm-deactivation]')?.addEventListener('change', event => { find('[data-apply-directory]').disabled = !plan || (confirmationRequired && !event.target.checked); });
  find('[data-apply-directory]')?.addEventListener('click', run(async () => { if (!plan) throw new Error('Run a dry run first.'); await call('directory_apply', { plan_id: plan, confirm_deactivation: find('[data-confirm-deactivation]').checked }); clearPlan(); location.reload(); }));
  find('[data-add-subscriber]')?.addEventListener('click', run(() => { update('subscriber', config => config.people.push({ id: randomId('sub_'), person_id: '', name: '', email: '', enabled: false })); render(); }));
  find('[data-invite-subscriber-button]')?.addEventListener('click', run(() => call('subscriber_invite', { subscriber_id: find('[data-invite-subscriber]').value, incident_id: find('[data-invite-incident]').value })));
  find('[data-revoke-subscriber]')?.addEventListener('click', run(async () => { const result = await call('subscriber_revoke', { subscriber_id: find('[data-invite-subscriber]').value }); write('subscriber', result.config); boot.revisions.subscriber = result.revision; render(); }));
  find('[data-browser-key]')?.addEventListener('click', run(async () => { const result = await call('subscriber_push_key', {}); write('subscriber', result.config); boot.revisions.subscriber = result.revision; render(); }));
  render();
})();
