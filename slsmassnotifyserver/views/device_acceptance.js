(function () {
    'use strict';
    const root = document.getElementById('sls-device-tests');
    if (!root) return;
    const element = id => document.getElementById('sls-device-test-' + id);
    const dialog = element('dialog'), refresh = document.getElementById('sls-device-tests-refresh');
    const add = document.getElementById('sls-device-tests-add'), status = document.getElementById('sls-device-tests-status');
    const summary = document.getElementById('sls-device-tests-summary'), history = document.getElementById('sls-device-tests-history');
    const formStatus = element('form-status'), save = element('save');
    const typeLabels = {phone: 'Internal phone', desktop: 'Desktop app', external_voice: 'External voice', email: 'Email', sms: 'SMS', webhook: 'Webhook', paging: 'Dial-in paging', action: 'Device or script action'};
    const resultLabels = {passed: 'Passed', failed: 'Failed', incomplete: 'Incomplete'};
    let state = null, busy = false;
    // General Settings has its own form. Keep this dialog outside it and give
    // these controls no names, so they cannot enter a settings submission.
    document.body.appendChild(dialog);

    function node(tag, text, className) {
        const value = document.createElement(tag);
        if (text !== undefined) value.textContent = text;
        if (className) value.className = className;
        return value;
    }

    function render(report) {
        if (!report || report.schema !== 1 || !Array.isArray(report.records) || report.records.length > 200) throw new Error('The PBX returned invalid device-test history. Check module diagnostics.');
        summary.textContent = ''; history.textContent = '';
        const counts = report.current_counts || {};
        [['passed', 'Passed'], ['failed', 'Failed'], ['incomplete', 'Incomplete'], ['review_required', 'Recheck']].forEach(([key, label]) => {
            summary.append(node('span', label + ': ' + Number(counts[key] || 0), 'sls-device-tests-chip'));
        });
        report.records.forEach(record => {
            const card = node('details', undefined, 'sls-device-test');
            const time = new Date(record.tested_at), result = resultLabels[record.result] || 'Unknown';
            const qualifier = record.superseded ? ' · Earlier observation' : record.configuration_changed || record.time_ahead ? ' · Review required' : '';
            card.append(node('summary', record.target_label + ' · ' + result + qualifier));
            const checks = Array.isArray(record.check_labels) ? record.check_labels.join(', ') : '';
            card.append(node('p', (typeLabels[record.target_type] || 'Device') + (record.model ? ' · ' + record.model : '') + (record.firmware ? ' · ' + record.firmware : '')));
            card.append(node('p', 'Checked: ' + checks));
            card.append(node('p', record.notes));
            card.append(node('p', 'Tested ' + (Number.isNaN(time.getTime()) ? record.tested_at : time.toLocaleString()) + ' · Recorded by ' + record.recorded_by + ' · SLS ' + record.module_version));
            if (record.configuration_changed) card.append(node('small', 'SLS settings or software changed after this observation. Review and test the current setup.'));
            if (record.time_ahead) card.append(node('small', 'This observation is ahead of the PBX clock. Verify its test time before relying on it.'));
            history.append(card);
        });
        history.hidden = !report.records.length;
        status.textContent = report.records.length ? report.records.length + ' saved observation(s). Only the latest test for each destination contributes to the counts.' : 'No device tests recorded. Local checks and transport receipts do not substitute for device acceptance.';
        if (report.retired_records) status.textContent += ' ' + report.retired_records + ' older observation(s) retired by the 200-record limit.';
    }

    async function call(action, payload) {
        const form = new FormData(), controller = new AbortController();
        form.set('slsmassnotifyserver_action', action); form.set('slsmassnotifyserver_csrf', root.dataset.csrf);
        if (payload !== undefined) form.set('payload', JSON.stringify(payload));
        const timer = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch('config.php?display=slsmassnotifyserver_help', {method: 'POST', credentials: 'same-origin', cache: 'no-store', body: form, signal: controller.signal});
            const body = await response.json().catch(() => { throw new Error('The PBX returned an unreadable response. Refresh observations before saving again.'); });
            if (!response.ok || !body.success) throw new Error(body.message || 'The PBX could not confirm this request. Refresh observations before saving again.');
            return body;
        } catch (error) {
            if (error.name === 'AbortError') throw new Error('The request exceeded 15 seconds. Refresh observations to check whether it saved before trying again.');
            throw error;
        } finally { clearTimeout(timer); }
    }

    function setBusy(value) {
        busy = value; refresh.disabled = value; add.disabled = value; save.disabled = value; element('reload').disabled = value;
        root.setAttribute('aria-busy', String(value));
    }

    async function load() {
        const value = await call('device_acceptance_state');
        if (!Array.isArray(value.catalog) || value.catalog.length > 20000 || !value.checks || !/^[a-f0-9]{64}$/.test(value.revision || '')) throw new Error('The configured device list is invalid. Reload and check module diagnostics.');
        state = value; render(value.report); return value;
    }

    function selectOptions(select, choices, emptyLabel) {
        select.textContent = ''; select.append(new Option(emptyLabel, ''));
        choices.forEach(([value, label]) => select.append(new Option(label, value)));
    }

    function targets() {
        const type = element('type').value;
        selectOptions(element('target'), state.catalog.filter(row => row.type === type).map(row => [row.id, row.label]), 'Choose a saved destination');
        element('model').required = type === 'phone';
        element('checks').textContent = '';
        Object.entries(state.checks[type] || {}).forEach(([key, label]) => {
            const row = node('label'), checkbox = document.createElement('input');
            checkbox.type = 'checkbox'; checkbox.value = key;
            row.append(checkbox, node('span', label)); element('checks').append(row);
        });
    }

    refresh.addEventListener('click', async () => {
        if (busy) return; setBusy(true); status.textContent = 'Loading saved observations…';
        try { await load(); } catch (error) { status.textContent = error.message; }
        finally { setBusy(false); }
    });

    add.addEventListener('click', async () => {
        if (busy) return; setBusy(true); status.textContent = 'Loading configured destinations…';
        try {
            await load();
            if (!state.catalog.length) throw new Error('No destinations are configured. Save the intended devices or paging group before recording a test.');
            const types = Object.keys(typeLabels).filter(type => state.catalog.some(row => row.type === type));
            selectOptions(element('type'), types.map(type => [type, typeLabels[type]]), 'Choose a device or channel');
            element('result').value = ''; element('model').value = ''; element('firmware').value = ''; element('notes').value = ''; element('confirm').checked = false;
            const date = new Date(), pad = value => String(value).padStart(2, '0');
            element('time').value = date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
            formStatus.textContent = ''; targets(); dialog.showModal(); element('type').focus();
        } catch (error) { status.textContent = error.message; }
        finally { setBusy(false); }
    });

    element('type').addEventListener('change', targets);
    element('reload').addEventListener('click', async () => {
        if (busy) return;
        const type = element('type').value, target = element('target').value;
        const selected = Array.from(element('checks').querySelectorAll('input:checked'), input => input.value);
        setBusy(true); formStatus.textContent = 'Refreshing saved destinations…';
        try {
            await load();
            const types = Object.keys(typeLabels).filter(value => state.catalog.some(row => row.type === value));
            selectOptions(element('type'), types.map(value => [value, typeLabels[value]]), 'Choose a device or channel');
            element('type').value = types.includes(type) ? type : ''; targets();
            if (state.catalog.some(row => row.type === type && row.id === target)) element('target').value = target;
            element('checks').querySelectorAll('input').forEach(input => { input.checked = selected.includes(input.value); });
            formStatus.textContent = element('target').value ? 'Destinations refreshed. Your entered observation is preserved.' : 'Your observation is preserved. Select a current saved destination.';
        } catch (error) { formStatus.textContent = error.message; }
        finally { setBusy(false); }
    });
    element('cancel').addEventListener('click', () => { if (!busy) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    save.addEventListener('click', async () => {
        if (busy || !state) return;
        const inputs = ['type', 'target', 'model', 'firmware', 'time', 'result', 'notes', 'confirm'].map(element);
        if (inputs.some(input => !input.reportValidity())) return;
        const checks = Array.from(element('checks').querySelectorAll('input:checked'), input => input.value);
        if (!checks.length) { formStatus.textContent = 'Select the behavior you actually checked.'; element('checks').querySelector('input')?.focus(); return; }
        const tested = new Date(element('time').value);
        if (Number.isNaN(tested.getTime())) { formStatus.textContent = 'Enter a valid test date and time.'; return; }
        const payload = {revision: state.revision, target_type: element('type').value, target_id: element('target').value,
            model: element('model').value, firmware: element('firmware').value, tested_at: tested.toISOString().replace('.000Z', 'Z'),
            result: element('result').value, checks, notes: element('notes').value, confirmed: element('confirm').checked};
        setBusy(true); formStatus.textContent = 'Recording your observation…';
        try { const response = await call('record_device_acceptance', payload); render(response.report); dialog.close(); status.textContent = response.message; state = null; }
        catch (error) { formStatus.textContent = error.message; }
        finally { setBusy(false); }
    });

    document.getElementById('sls-readiness')?.addEventListener('sls:readiness-checked', event => {
        if (event.detail.device_acceptance) {
            try { render(event.detail.device_acceptance); } catch (error) { status.textContent = error.message; }
        } else {
            state = null; history.textContent = ''; summary.textContent = '';
            status.textContent = 'Sign in as an SLS administrator to review device-test observations. Local health checks remain available.';
        }
    });
})();
