(function () {
    'use strict';
    const data = document.getElementById('sls-source-picker-data');
    if (!data) return;
    if (window.slsAudiencePickerObserver) window.slsAudiencePickerObserver.disconnect();
    const sources = JSON.parse(data.textContent);
    let sequence = 0;
    const node = (tag, text, className) => {
        const element = document.createElement(tag);
        if (text !== undefined) element.textContent = text;
        if (className) element.className = className;
        return element;
    };
    function attach(editor) {
        if (editor.dataset.sourcePickerReady) return;
        editor.dataset.sourcePickerReady = '1';
        const lightning = editor.hasAttribute('data-lightning-editor');
        const mode = editor.dataset.slsPicker || 'weather', weather = mode === 'weather', paging = mode === 'paging';
        const prefix = lightning ? 'lightning' : 'zone';
        const wrapper = node('details', undefined, 'sls-source-picker'), summary = node('summary');
        const icon = node('i', undefined, 'fa fa-users'); icon.setAttribute('aria-hidden', 'true');
        summary.append(icon, document.createTextNode('Copy recipients from a location or audience')); wrapper.append(summary);
        const body = node('div', undefined, 'sls-source-picker-body'), label = node('label', 'Saved source');
        const select = node('select', undefined, 'form-control'); select.id = 'sls-source-picker-' + (++sequence); label.htmlFor = select.id;
        const placeholder = node('option', 'Choose a location or audience'); placeholder.value = ''; select.append(placeholder);
        ['Locations', 'Saved audiences'].forEach(kind => {
            const group = node('optgroup'); group.label = kind;
            sources.filter(source => source.kind === kind).forEach(source => { const option = node('option', source.label); option.value = source.id; group.append(option); });
            select.append(group);
        });
        body.append(label, select);
        const options = node('div', undefined, 'sls-source-picker-types');
        const types = paging ? [['extensions', 'Live audio phones'], ['notify_extensions', 'SIP text phones']]
            : [['extensions', 'Phones'], ['desktop_clients', 'Desktops'], [weather ? 'email_addresses' : 'email_recipient_ids', 'Email']];
        if (weather && !lightning) types.push(['weather_webhooks', 'Weather webhooks']);
        if (!paging) types.push(['voice_recipient_ids', 'External voice'], ['sms_recipient_ids', 'SMS']);
        if (!weather && !paging) types.push(['webhook_ids', 'Webhooks']);
        const checks = {};
        types.forEach(([key, name]) => { const label = node('label'), input = node('input'); input.type = 'checkbox'; input.defaultChecked = input.checked = true; checks[key] = input; label.append(input, document.createTextNode(name)); options.append(label); });
        body.append(options);
        const coverage = node('input'); coverage.type='checkbox';
        if (weather) { const label=node('label',undefined,'sls-source-picker-types'); label.append(coverage,document.createTextNode('Also copy saved weather coverage')); body.append(label); }
        const review = node('div', undefined, 'sls-source-picker-review'); review.setAttribute('role', 'status'); review.hidden = true;
        const copy = node('button', 'Use these recipients', 'btn btn-default btn-sm'); copy.type = 'button'; copy.disabled = true;
        body.append(review, copy, node('p', paging ? 'Copy phone recipients, then review them and save the paging group. Configure authorized callers and PINs separately.'
            : weather ? 'This replaces the selected recipient types in this editor. Review the individual selections, then save and Apply Config. Later directory changes do not change this alert route.'
            : 'Copy these recipients into the selected device types. Saved-group and all-device selections are cleared. Review the resulting audience before saving or sending.', 'help-block'));
        wrapper.append(body);
        const anchor = Array.from(editor.children).find(child => child.classList.contains('row') || child.classList.contains('sls-destination-grid')) || editor.firstChild;
        editor.insertBefore(wrapper, anchor);
        const targetInputs = key => {
            if (paging) return Array.from(editor.querySelectorAll('#sls-paging-' + (key === 'extensions' ? 'audio' : 'notify') + '-selector input[type=checkbox]'));
            if (!weather) {
                const field = mode === 'schedule' ? 'schedule_' + key : ({extensions:'announcement_extensions', desktop_clients:'announcement_desktop_clients',
                    email_recipient_ids:'announcement_email_recipient_ids', sms_recipient_ids:'announcement_sms_recipient_ids', webhook_ids:'announcement_webhooks', voice_recipient_ids:'voice_recipient_ids'}[key]);
                return Array.from(editor.querySelectorAll('input[name="' + field + '[]"]'));
            }
            return Array.from(editor.querySelectorAll('[data-' + prefix + '-' + ({extensions:'extension', desktop_clients:'desktop', discord_webhook_ids:'discord', generic_webhook_ids:'generic', voice_recipient_ids:'voice', sms_recipient_ids:'sms'}[key]) + ']'));
        };
        function selection() {
            const source = sources.find(row => row.id === select.value);
            if (!source) return null;
            const members = {};
            types.forEach(([key]) => {
                if (!checks[key].checked) return;
                if (key === 'weather_webhooks') { members.discord_webhook_ids = source.members.discord_webhook_ids; members.generic_webhook_ids = source.members.generic_webhook_ids; }
                else members[key] = source.members[key === 'notify_extensions' ? 'extensions' : key];
            });
            return {source, members};
        }
        function refresh() {
            const chosen = selection(); review.replaceChildren(); review.hidden = !chosen; copy.disabled = true;
            if (!chosen) return;
            const {source, members} = chosen, errors = source.unavailable.slice();
            if (source.identity_error) errors.push('A saved desktop identity changed. Review this audience in Locations before copying it.');
            for (const [key, ids] of Object.entries(members)) {
                if (key === 'email_addresses') continue;
                const known = new Set(targetInputs(key).filter(input => !input.disabled).map(input => input.value));
                if (ids.some(id => !known.has(String(id)))) errors.push('Some selected recipients are unavailable in this editor. Review the source and current device configuration.');
            }
            const count = Object.values(members).reduce((sum, values) => sum + values.length, 0);
            review.append(node('p', count + ' recipient(s) in the selected types.'));
            const unsupported = [];
            if (paging && source.members.voice_recipient_ids.length) unsupported.push('external voice');
            if (paging && source.members.sms_recipient_ids.length) unsupported.push('SMS');
            if (paging && (source.members.desktop_client_ids.length || source.members.email_recipient_ids.length || source.members.webhook_ids.length)) unsupported.push('desktop, email and webhook destinations');
            if (weather && lightning && source.members.webhook_ids.length) unsupported.push('audience webhooks (Lightning uses its configured provider webhooks)');
            if (weather && !lightning && source.weather_unmapped_webhooks) unsupported.push('announcement webhooks without a matching Weather webhook');
            if (unsupported.length) review.append(node('p', 'These source devices are outside the recipient types supported here and will not be copied: ' + unsupported.join(', ') + '.', 'text-muted'));
            errors.forEach(message => review.append(node('p', message, 'text-danger')));
            copy.disabled = !count || errors.length > 0;
        }
        select.addEventListener('change', refresh); options.addEventListener('change', refresh);
        copy.addEventListener('click', () => {
            refresh(); if (copy.disabled) return;
            const {members,source} = selection();
            if (weather && source.site_id) { const site=editor.querySelector('[data-'+prefix+'-field="site_id"]'); if(site) {site.value=source.site_id; site.dispatchEvent(new Event('change',{bubbles:true}));} }
            if (weather && coverage.checked) { const field=editor.querySelector('[data-'+prefix+'-field="'+(lightning?'location':'zone')+'"]'); const value=(source.coverage||{})[lightning?'lightning_location':'weather_zone']; if (field && value) {field.value=value;field.dispatchEvent(new Event('input',{bubbles:true}));} }
            if (!weather && !paging) {
                const form = editor.closest('form');
                const fields = mode === 'schedule' ? ['schedule_groups[]', 'schedule_all_phones', 'schedule_all_desktops']
                    : ['announcement_groups[]', 'announcement_all_phones', 'announcement_all_desktops'];
                fields.forEach(name => form.querySelectorAll('input[name="' + name + '"]').forEach(input => { input.checked = false; input.dispatchEvent(new Event('change', {bubbles:true})); }));
            }
            Object.entries(members).forEach(([key, ids]) => {
                if (key === 'email_addresses') {
                    const email = editor.querySelector('[data-' + prefix + '-email]'); email.value = ids.join('\n'); email.dispatchEvent(new Event('input', {bubbles:true}));
                } else targetInputs(key).forEach(input => { input.checked = ids.includes(input.value); input.dispatchEvent(new Event('change', {bubbles:true})); });
            });
            review.replaceChildren(node('p', mode === 'announcement' ? 'Recipients copied. Review the selections and message before sending.' : 'Recipients copied into this editor. Review them below and save your changes.', 'text-success'));
        });
    }
    const selector = '[data-zone-editor], [data-lightning-editor], [data-sls-picker]';
    document.querySelectorAll(selector).forEach(attach);
    window.slsAudiencePickerObserver = new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(added => {
        if (added.nodeType !== 1) return;
        if (added.matches(selector)) attach(added);
        added.querySelectorAll(selector).forEach(attach);
    })));
    window.slsAudiencePickerObserver.observe(document.body, {childList:true, subtree:true});
}());
