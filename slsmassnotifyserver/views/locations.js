(function () {
    'use strict';
    const root = document.getElementById('sls-locations');
    if (!root) return;
    const data = JSON.parse(document.getElementById('sls-location-data').textContent);
    const el = name => document.getElementById('sls-location-' + name);
    const types = {site: ['Site', 'globe'], building: ['Building', 'building'], floor: ['Floor', 'bars'], room: ['Room', 'map-marker']};
    const parents = {site: '', building: 'site', floor: 'building', room: 'floor'};
    const children = {site: 'building', building: 'floor', floor: 'room'};
    const channels = {
        extensions: ['Phones', 'phone'], desktop_client_ids: ['Desktops', 'desktop'],
        voice_recipient_ids: ['External voice', 'volume-up'], email_recipient_ids: ['Email', 'envelope'], sms_recipient_ids: ['SMS', 'comment'], webhook_ids: ['Webhooks', 'link']
    };
    let state = data.state, selectedId = '', draft = null, dirty = false, inflight = false, preview = null, geography = null, audiences = null, recipientsDirty = false;
    const clone = value => JSON.parse(JSON.stringify(value));
    const node = (tag, text, className) => {
        const item = document.createElement(tag);
        if (text !== undefined) item.textContent = text;
        if (className) item.className = className;
        return item;
    };
    const icon = name => { const item = node('i', undefined, 'fa fa-' + name); item.setAttribute('aria-hidden', 'true'); return item; };
    const saved = id => state.directory.nodes.find(row => row.id === id);
    const path = id => {
        const names = [];
        for (let depth = 0; id && depth < 4; depth++) {
            const row = saved(id); if (!row) break;
            names.unshift(row.name); id = row.parent_id;
        }
        return names.join(' / ');
    };
    function announce(message, success) {
        el('status').textContent = message;
        el('status').className = 'alert ' + (success ? 'alert-success' : 'alert-danger');
        el('status').hidden = false;
        el('status').focus({preventScroll: true});
    }
    function invalidatePreview() { preview = null; el('preview-result').hidden = true; }
    function changed() { dirty = true; el('edit-state').textContent = 'Unsaved edits'; invalidatePreview(); if (geography) geography.invalidate(); }
    function mayLeave() { return !dirty || window.confirm('Discard your unsaved location edits?'); }
    async function request(action, payload) {
        if (inflight) throw new Error('A location request is already in progress. Wait for its result.');
        inflight = true; root.setAttribute('aria-busy', 'true');
        const controls = Array.from(root.querySelectorAll('button,input,select'));
        const disabled = controls.map(control => control.disabled);
        controls.forEach(control => { control.disabled = true; });
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 20000);
        try {
            const body = new URLSearchParams({slsmassnotifyserver_action: action, slsmassnotifyserver_csrf: data.csrf,
                payload: JSON.stringify(Object.assign({revision: state.revision}, payload))});
            const response = await fetch('config.php?display=slsmassnotifyserver_locations', {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', body, signal: controller.signal
            });
            const text = await response.text();
            if (text.length > 1500000) throw new Error('Location response exceeded its size limit. Reload Locations.');
            let result;
            try { result = JSON.parse(text); }
            catch (_) { throw new Error('The PBX returned an unreadable location response. Reload Locations and check whether your session expired.'); }
            if (!response.ok || !result.success) throw new Error(result.message || 'Location request failed. Reload Locations before retrying.');
            return result;
        } catch (error) {
            if (error.name === 'AbortError' || error instanceof TypeError) {
                throw new Error('The PBX response was interrupted. The save may have completed. Reload Locations before retrying.');
            }
            throw error;
        } finally {
            window.clearTimeout(timeout);
            controls.forEach((control, index) => { if (control.isConnected) control.disabled = disabled[index]; });
            inflight = false; root.removeAttribute('aria-busy');
        }
    }
    function renderTree() {
        const query = el('search').value.trim().toLowerCase();
        el('tree').replaceChildren();
        const visible = new Set();
        state.directory.nodes.forEach(row => {
            if (!query || path(row.id).toLowerCase().includes(query)) {
                let current = row;
                for (let depth = 0; current && depth < 4; depth++) { visible.add(current.id); current = saved(current.parent_id); }
            }
        });
        function branch(parentId) {
            const list = node('ul');
            state.directory.nodes.filter(row => row.parent_id === parentId && visible.has(row.id))
                .sort((a, b) => a.name.localeCompare(b.name, undefined, {numeric: true})).forEach(row => {
                    const item = node('li'), button = node('button'); button.type = 'button'; button.dataset.locationId = row.id;
                    button.append(icon(types[row.type][1]), node('span', row.name));
                    if (row.id === state.directory.default_site_id) button.append(node('small', 'Default', 'sls-location-badge'));
                    button.title = path(row.id); button.setAttribute('aria-current', String(row.id === selectedId));
                    button.addEventListener('click', () => { if (mayLeave()) openEditor(row.id); });
                    item.append(button); const sub = branch(row.id); if (sub.childElementCount) item.append(sub); list.append(item);
                });
            return list;
        }
        const tree = branch('');
        el('tree').append(tree.childElementCount ? tree : node('p', query ? 'No matching locations.' : 'No locations saved yet.', 'sls-location-list-empty'));
        el('count').textContent = state.directory.nodes.length + ' / ' + state.max_locations + ' locations';
        el('empty-add').hidden = state.directory.nodes.length > 0;
    }
    function renderParents(selected) {
        const select = el('parent'); select.replaceChildren(); select.disabled = draft.type === 'site';
        const placeholder = node('option', draft.type === 'site' ? 'No parent · top-level site' : 'Choose a ' + parents[draft.type]);
        placeholder.value = ''; select.append(placeholder);
        state.directory.nodes.filter(row => row.type === parents[draft.type] && row.id !== draft.id)
            .sort((a, b) => path(a.id).localeCompare(path(b.id), undefined, {numeric: true})).forEach(row => {
                const option = node('option', path(row.id)); option.value = row.id; select.append(option);
            });
        select.value = selected || '';
    }
    function updateMemberCount() {
        const count = Object.values(draft.members).reduce((total, ids) => total + ids.length, 0);
        el('member-count').textContent = count + ' assigned';
    }
    function renderMembers() {
        el('members').replaceChildren();
        Object.entries(channels).forEach(([key, definition]) => {
            const details = node('details'), summary = node('summary');
            const count = node('span'); summary.append(icon(definition[1]), count); details.append(summary);
            const toolbar = node('div', undefined, 'sls-location-member-toolbar');
            const search = node('input', undefined, 'form-control'); search.type = 'search';
            search.placeholder = 'Find ' + definition[0].toLowerCase(); search.setAttribute('aria-label', search.placeholder);
            toolbar.append(search);
            const actions = node('div', undefined, 'sls-location-member-actions');
            const selectShown = node('button', 'Select shown'), clear = node('button', 'Clear selection');
            selectShown.type = clear.type = 'button'; actions.append(selectShown, clear); toolbar.append(actions); details.append(toolbar);
            const options = node('div', undefined, 'sls-location-options'); details.append(options);
            const assigned = new Map();
            state.directory.nodes.filter(row => key !== 'webhook_ids' && row.id !== draft.id).forEach(row => row.members[key].forEach(id => assigned.set(id, path(row.id))));
            const choices = Object.assign(Object.create(null), state.catalog[key]);
            draft.members[key].forEach(id => { if (!choices[id]) choices[id] = {id, label: id, available: false, reason: 'Removed recipient. Uncheck to remove this assignment.'}; });
            const checks = [];
            Object.values(choices).sort((a, b) => a.label.localeCompare(b.label, undefined, {numeric: true})).forEach(choice => {
                const label = node('label', undefined, 'sls-location-option');
                const checkbox = node('input'); checkbox.type = 'checkbox'; checkbox.value = choice.id;
                checkbox.checked = draft.members[key].includes(choice.id); checkbox.disabled = assigned.has(choice.id);
                checkbox.dataset.channel = key;
                const text = node('span', choice.label);
                if (assigned.has(choice.id)) text.append(node('small', 'Assigned to ' + assigned.get(choice.id)));
                else if (!choice.available) text.append(node('small', choice.reason || 'Recipient is not ready for delivery.', 'sls-location-unavailable'));
                label.append(checkbox, text); options.append(label); checks.push({checkbox, label});
                checkbox.addEventListener('change', () => { collect(); changed(); });
            });
            function collect() {
                draft.members[key] = checks.filter(item => item.checkbox.checked).map(item => item.checkbox.value);
                count.textContent = definition[0] + ' · ' + draft.members[key].length + ' assigned'; updateMemberCount();
            }
            const empty = node('p', checks.length ? 'No matching recipients.' : 'No recipients configured. Add them in General Settings.', 'sls-location-list-empty');
            options.append(empty); empty.hidden = checks.length > 0;
            search.addEventListener('input', () => {
                const query = search.value.trim().toLowerCase();
                checks.forEach(item => { item.label.hidden = !item.label.textContent.toLowerCase().includes(query); });
                empty.hidden = checks.some(item => !item.label.hidden);
            });
            selectShown.addEventListener('click', () => { checks.filter(item => !item.label.hidden && !item.checkbox.disabled).forEach(item => { item.checkbox.checked = true; }); collect(); changed(); });
            clear.addEventListener('click', () => { checks.forEach(item => { item.checkbox.checked = false; }); collect(); changed(); });
            collect(); details.open = draft.members[key].length > 0; el('members').append(details);
        });
    }
    function openEditor(id, type, parentId) {
        const existing = saved(id);
        selectedId = existing ? existing.id : '';
        draft = existing ? clone(existing) : {id: '', name: '', description: '', type: type || 'site', parent_id: parentId || '',
            members: Object.fromEntries(Object.keys(channels).map(key => [key, []]))};
        dirty = false; invalidatePreview();
        el('empty').hidden = true; el('form').hidden = false; el('audience').hidden = !existing;
        el('editor-title').textContent = existing ? existing.name : 'New ' + types[draft.type][0].toLowerCase();
        el('path').textContent = existing ? path(existing.id) : (parentId ? path(parentId) : 'Top-level location');
        el('edit-state').textContent = existing ? 'Saved location' : 'Not saved yet';
        el('weather-zone').value = draft.weather_zone || '';
        el('weather-routes').replaceChildren();
        (state.weather_routes||[]).filter(route=>route.site_id===draft.id).forEach(route=>{const item=document.createElement('p');item.textContent=route.name+' · '+route.coverage;el('weather-routes').append(item);});
        el('name').value = draft.name; el('type').value = draft.type; el('description').value = draft.description;
        renderParents(draft.parent_id); renderMembers(); renderTree();
        el('own-position').checked = !!draft.position;
        el('latitude').value = draft.position ? draft.position.latitude : '';
        el('longitude').value = draft.position ? draft.position.longitude : '';
        el('position-reviewed').checked = false; el('position-panel').open = !!draft.position; renderPosition();
        el('delete').hidden = !existing;
        el('delete').disabled = !!existing && existing.id === state.directory.default_site_id;
        el('set-default').hidden = !existing || existing.type !== 'site' || existing.id === state.directory.default_site_id;
        el('add-child').hidden = !existing || !children[draft.type];
        el('add-child').querySelector('span').textContent = 'Add ' + (children[draft.type] || 'child');
        el('group-name').value = existing ? existing.name.slice(0, 64) : '';
    }
    function updateState(result, id) {
        state = result.state; dirty = false; el('pending').hidden = false; renderTree();
        if (geography) geography.refresh();
        if (audiences) audiences.refresh();
        if (id && saved(id)) openEditor(id);
        else { draft = null; selectedId = ''; el('form').hidden = true; el('audience').hidden = true; el('empty').hidden = false; }
        announce(result.message, true);
    }
    async function save(event) {
        event.preventDefault();
        const value = clone(draft);
        if (el('weather-zone').value.trim()) value.weather_zone=el('weather-zone').value.trim().toUpperCase(); else delete value.weather_zone;
        value.name = el('name').value; value.description = el('description').value;
        value.type = el('type').value; value.parent_id = value.type === 'site' ? '' : el('parent').value;
        try {
            let reviewed = false;
            if (el('own-position').checked) {
                if (!el('latitude').value.trim() || !el('longitude').value.trim()) throw new Error('Enter both latitude and longitude, or turn off coordinates to inherit the parent position.');
                value.position = {latitude: el('latitude').valueAsNumber, longitude: el('longitude').valueAsNumber, reviewed_at: draft.position ? draft.position.reviewed_at : 1};
                reviewed = el('position-reviewed').checked;
            } else { delete value.position; }
            const result = await request('save_location', {node: value, position_reviewed: reviewed}); updateState(result, result.location_id);
        }
        catch (error) { announce(error.message, false); }
    }
    function renderPosition() {
        const own = el('own-position').checked;
        el('position-fields').hidden = !own;
        ['latitude', 'longitude', 'position-reviewed'].forEach(key => { el(key).disabled = !own; });
        let source = null;
        if (own && draft.position) source = {name: 'This location', position: draft.position};
        else if (!own) {
            let current = el('parent').value;
            for (let depth = 0; current && depth < 3; depth++) {
                const row = saved(current); if (!row) break;
                if (row.position) { source = {name: 'Inherited from ' + path(row.id), position: row.position}; break; }
                current = row.parent_id;
            }
        }
        el('position-status').textContent = source ? source.name + ' · ' + source.position.latitude + ', ' + source.position.longitude +
            ' · reviewed ' + new Date(source.position.reviewed_at * 1000).toISOString().slice(0, 10) : own ?
            'Enter decimal degrees and confirm your review before saving. No location is inferred from your browser.' :
            'No parent position is available. This location will be listed as unlocated during geographic review.';
    }
    function audienceInput() {
        if (dirty) throw new Error('Save or discard location edits before reviewing an audience.');
        if (!selectedId) throw new Error('Save and select a location first.');
        return {id: selectedId, include_descendants: el('descendants').checked,
            channels: Array.from(el('channels').querySelectorAll('input:checked')).map(input => input.value)};
    }
    el('form').addEventListener('submit', save);
    ['name', 'description', 'parent'].forEach(name => el(name).addEventListener('input', changed));
    el('type').addEventListener('change', () => { draft.type = el('type').value; renderParents(''); renderPosition(); changed(); });
    el('parent').addEventListener('change', renderPosition);
    el('own-position').addEventListener('change', () => { renderPosition(); changed(); });
    ['latitude', 'longitude'].forEach(key => el(key).addEventListener('input', () => { el('position-reviewed').checked = false; changed(); }));
    el('position-reviewed').addEventListener('change', changed);
    el('search').addEventListener('input', renderTree);
    ['add-site', 'empty-add'].forEach(name => el(name).addEventListener('click', () => { if (mayLeave()) { openEditor('', 'site'); el('name').focus(); } }));
    el('add-child').addEventListener('click', () => { if (mayLeave()) { const row = saved(selectedId); openEditor('', children[row.type], row.id); el('name').focus(); } });
    el('discard').addEventListener('click', () => { if (mayLeave()) { if (selectedId) openEditor(selectedId); else { dirty = false; draft = null; el('form').hidden = true; el('empty').hidden = false; } } });
    el('delete').addEventListener('click', async () => {
        if (!mayLeave() || !window.confirm('Remove this location and its assignments? Saved audience groups and delivery records will remain.')) return;
        try { updateState(await request('delete_location', {id: selectedId}), ''); }
        catch (error) { announce(error.message, false); }
    });
    el('set-default').addEventListener('click', async () => {
        if (dirty) { announce('Save or discard your edits before choosing a default site.', false); return; }
        try { updateState(await request('set_default_site', {id: selectedId}), selectedId); }
        catch (error) { announce(error.message, false); }
    });
    Object.entries(channels).forEach(([key, definition]) => {
        const label = node('label', undefined, 'sls-location-check'); const input = node('input'); input.type = 'checkbox'; input.value = key; input.checked = true;
        input.addEventListener('change', invalidatePreview); label.append(input, icon(definition[1]), document.createTextNode(definition[0])); el('channels').append(label);
    });
    el('descendants').addEventListener('change', invalidatePreview);
    el('preview').addEventListener('click', async () => {
        try {
            const result = await request('preview_location_audience', audienceInput()); preview = result.preview;
            const total = Object.values(preview.members).reduce((sum, ids) => sum + ids.length, 0);
            el('preview-heading').textContent = total + ' recipient(s) across ' + preview.location_count + ' location(s)';
            el('preview-members').replaceChildren();
            Object.entries(preview.members).filter(([, ids]) => ids.length).forEach(([key, ids]) => {
                const details = node('details'), summary = node('summary', channels[key][0] + ' · ' + ids.length), list = node('ul');
                ids.forEach(id => list.append(node('li', Object.prototype.hasOwnProperty.call(state.catalog[key], id) ? state.catalog[key][id].label : 'Unavailable · ' + id)));
                details.append(summary, list); details.open = ids.length <= 10; el('preview-members').append(details);
            });
            el('preview-issues').hidden = !preview.unavailable.length; el('preview-issues').replaceChildren();
            if (preview.unavailable.length) {
                el('preview-issues').append(node('strong', 'Resolve unavailable recipients before saving this audience.'));
                const list = node('ul'); preview.unavailable.forEach(row => list.append(node('li', row.id + ' · ' + row.location + ': ' + row.reason))); el('preview-issues').append(list);
            }
            el('create-group').disabled = preview.unavailable.length > 0 || total === 0 || state.group_count >= 20;
            if (state.group_count >= 20) announce('All 20 announcement groups are in use. Remove an unused group on the Dashboard before creating another.', false);
            el('preview-result').hidden = false;
        } catch (error) { invalidatePreview(); announce(error.message, false); }
    });
    el('create-group').addEventListener('click', async () => {
        try {
            const input = audienceInput(); if (!preview) throw new Error('Review the current audience first.');
            input.preview_token = preview.token; input.name = el('group-name').value;
            const result = await request('create_location_audience', input); updateState(result, selectedId);
        } catch (error) { announce(error.message, false); }
    });
    window.addEventListener('beforeunload', event => { if (dirty || inflight || recipientsDirty) { event.preventDefault(); event.returnValue = ''; } });
    geography = new window.SlsGeographicAudience({state: () => state, channels, request, announce, canReview: () => !dirty,
        onCreated: result => updateState(result, selectedId)});
    audiences = new window.SlsAudienceEditor({state: () => state, channels, request, announce,
        canEdit: () => { if (dirty) { announce('Save or discard your location edits first.', false); return false; } return true; },
        onSaved: result => updateState(result, selectedId)});
    const tabs = Array.from(root.querySelectorAll('[data-directory-tab]'));
    function selectTab(name, focus) {
        tabs.forEach(tab => { const active = tab.dataset.directoryTab === name; tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1; if (active && focus) tab.focus(); });
        document.getElementById('sls-directory-panel').hidden = name !== 'directory';
        document.getElementById('sls-audiences').hidden = name !== 'audiences';
        document.getElementById('sls-location-recipient-panel').hidden = name !== 'recipients';
    }
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => { selectTab(tab.dataset.directoryTab, false); history.replaceState(null, '', '#' + tab.dataset.directoryTab); });
        tab.addEventListener('keydown', event => { if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) { event.preventDefault(); const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowLeft' ? tabs.length - 1 : 1)) % tabs.length; selectTab(tabs[next].dataset.directoryTab, true); } });
    });
    selectTab(['#audiences','#recipients'].includes(location.hash) ? location.hash.slice(1) : 'directory', false);
    const recipientForm = document.getElementById('sls-location-recipients-form');
    recipientForm.querySelectorAll('[data-sls-provider-fields] input, [data-sls-provider-fields] select').forEach(field => { field.disabled = true; });
    recipientForm.addEventListener('input', () => { recipientsDirty = true; });
    recipientForm.addEventListener('click', event => { if (event.target.closest('[data-contact-remove],[data-contact-add],[data-email-remove],[data-email-add]')) recipientsDirty = true; });
    recipientForm.addEventListener('submit', async event => {
        const rejected = event.defaultPrevented; event.preventDefault();
        if (rejected) return;
        if (dirty || audiences.dirty) { announce('Save or discard your location and audience edits before saving recipients.', false); return; }
        const values = new FormData(recipientForm);
        if (['outbound_voice','announcement_email','announcement_sms'].some(key => values.get(key + '_complete') !== '1')) { announce('A recipient editor is incomplete. Review the highlighted fields.', false); return; }
        try {
            await request('save_recipients', {voice_json: values.get('outbound_voice_recipients_json'), email_json: values.get('announcement_email_recipients_json'), sms_json: values.get('announcement_sms_recipients_json')});
            recipientsDirty = false; history.replaceState(null, '', '#recipients'); location.reload();
        } catch (error) { announce(error.message, false); }
    });
    renderTree();
    if (state.directory.nodes.length) {
        const first = saved(state.directory.default_site_id) || state.directory.nodes.filter(row => row.type === 'site').sort((a, b) => a.name.localeCompare(b.name))[0];
        if (first) openEditor(first.id);
    }
}());
