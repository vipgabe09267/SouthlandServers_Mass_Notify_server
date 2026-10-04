(() => {
    'use strict';
    const enabled = value => value === true || value === '1' || value === 1;
    function combine(voices, messages) {
        const contacts = new Map();
        for (const [channel, records] of [['voice', voices], ['sms', messages]]) {
            for (const original of records) {
                let row = contacts.get(original.number);
                if (!row) {
                    row = {name:original.name, initial_name:original.name, number:original.number, voice:null, sms:null,
                        voice_enabled:false, sms_enabled:false, consent:false, consent_note:'', renew_consent:false};
                    contacts.set(original.number, row);
                }
                row[channel] = {...original};
                row[channel + '_enabled'] = enabled(original.enabled);
                if (channel === 'sms') { row.consent = original.consent; row.consent_note = original.consent_note || ''; }
            }
        }
        return [...contacts.values()];
    }
    function split(rows) {
        const voice = [], sms = [], numbers = new Set();
        for (const row of rows) {
            if (!row.name.trim() || new TextEncoder().encode(row.name).length > 240 || /[\p{C}]/u.test(row.name)) {
                throw new Error('Enter a contact name without control characters, up to 240 bytes.');
            }
            if (!/^\+[1-9][0-9]{1,14}$/.test(row.number) || numbers.has(row.number)) {
                throw new Error('Use one unique international phone number per contact, including + and country code.');
            }
            numbers.add(row.number);
            if (!row.voice && !row.sms && !row.voice_enabled && !row.sms_enabled) { throw new Error('Enable Calls, SMS or both for a new contact.'); }
            const name = channel => row.name === row.initial_name && row[channel] ? row[channel].name : row.name;
            if (row.voice || row.voice_enabled) {
                voice.push({id:row.voice?.id || '', name:name('voice'), number:row.number, enabled:row.voice_enabled ? '1' : '0'});
            }
            if (row.sms || row.sms_enabled) {
                if (!/^\+[1-9][0-9]{7,14}$/.test(row.number) || [...name('sms')].length > 80) { throw new Error('SMS contacts need a complete international number and a name of at most 80 characters.'); }
                if (row.consent && !row.consent_note.trim()) { throw new Error('Record when and how the contact agreed to SMS alerts.'); }
                if ([...row.consent_note].length > 160 || /[\p{C}]/u.test(row.consent_note)) { throw new Error('The SMS consent note must be at most 160 characters without control characters.'); }
                if (row.renew_consent && row.consent_note === (row.sms?.consent_note || '')) { throw new Error('Update the consent note when recording renewed consent.'); }
                if (row.sms && row.consent && (!row.sms.consent || row.number !== row.sms.number) && row.consent_note === row.sms.consent_note) {
                    throw new Error('Update the SMS consent note before restoring consent or changing the phone number.');
                }
                sms.push({id:row.sms?.id || '', name:name('sms'), number:row.number, enabled:row.sms_enabled,
                    consent:row.consent, consent_note:row.consent_note, renew_consent:row.renew_consent});
            }
        }
        if (voice.length > 1000 || sms.length > 50) { throw new Error('Save at most 1,000 call recipients and 50 SMS recipients.'); }
        const voiceJson = JSON.stringify(voice), smsJson = JSON.stringify(sms);
        if (new TextEncoder().encode(voiceJson).length > 524288 || new TextEncoder().encode(smsJson).length > 65536) { throw new Error('The contact editor exceeds its size limit.'); }
        return {voice, sms, voiceJson, smsJson};
    }
    globalThis.SlsPhoneContacts = {combine, split};
    const root = document.getElementById('sls-phone-contacts');
    if (!root) return;
    const seed = JSON.parse(document.getElementById('sls-phone-contact-data').textContent);
    const form = root.closest('form'), list = root.querySelector('[data-contact-rows]'), originals = new WeakMap(), blocked = new Set(seed.blocked);
    function values(node) {
        const row = {...originals.get(node)};
        for (const key of ['name', 'number', 'consent_note']) row[key] = node.querySelector('[data-contact-field="' + key + '"]').value;
        for (const key of ['voice_enabled', 'sms_enabled', 'consent', 'renew_consent']) row[key] = node.querySelector('[data-contact-field="' + key + '"]').checked;
        return row;
    }
    function sync() {
        form.elements.outbound_voice_complete.value = '0'; form.elements.announcement_sms_complete.value = '0';
        const rows = [...list.children].map(values);
        for (const node of list.children) {
            const row = values(node);
            node.querySelector('[data-contact-consent]').hidden = !row.sms && !row.sms_enabled;
            node.querySelector('[data-contact-blocked]').hidden = !row.sms || !blocked.has(row.sms.id);
        }
        try {
            const packed = split(rows);
            form.elements.outbound_voice_recipients_json.value = packed.voiceJson;
            form.elements.announcement_sms_recipients_json.value = packed.smsJson;
            form.elements.outbound_voice_complete.value = '1'; form.elements.announcement_sms_complete.value = '1';
            root.querySelector('[data-contact-error]').textContent = '';
        } catch (error) { root.querySelector('[data-contact-error]').textContent = error.message; }
        root.querySelector('[data-contact-empty]').hidden = rows.length !== 0;
        root.querySelector('[data-contact-count]').textContent = rows.length + ' contact(s)';
        root.querySelector('[data-contact-add]').disabled = rows.length >= 1050;
    }
    function append(row) {
        const node = root.querySelector('[data-contact-template]').content.firstElementChild.cloneNode(true);
        originals.set(node, row);
        for (const key of ['name', 'number', 'consent_note']) node.querySelector('[data-contact-field="' + key + '"]').value = row[key] || '';
        for (const key of ['voice_enabled', 'sms_enabled', 'consent', 'renew_consent']) node.querySelector('[data-contact-field="' + key + '"]').checked = !!row[key];
        list.append(node);
    }
    combine(seed.voice, seed.sms).forEach(append); sync();
    root.addEventListener('input', sync); root.addEventListener('change', sync);
    root.addEventListener('click', event => {
        if (event.target.closest('[data-contact-add]')) {
            if (list.children.length >= 1050) return;
            append({name:'', initial_name:'', number:'', voice:null, sms:null, voice_enabled:true, sms_enabled:false, consent:false, consent_note:'', renew_consent:false});
            sync(); list.lastElementChild.querySelector('[data-contact-field="name"]').focus();
        }
        if (event.target.closest('[data-contact-remove]')) { event.target.closest('[data-contact-row]').remove(); sync(); }
    });
    form.addEventListener('submit', event => {
        sync();
        if (form.elements.outbound_voice_complete.value !== '1' || form.elements.announcement_sms_complete.value !== '1') event.preventDefault();
    });
})();
