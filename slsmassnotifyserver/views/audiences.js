(function () {
    'use strict';
    window.SlsAudienceEditor = class {
        constructor(options) {
            this.options = options;
            this.draft = null;
            this.dirty = false;
            this.el = name => document.getElementById('sls-audience-' + name);
            this.el('add').addEventListener('click', () => this.open(null));
            this.el('close').addEventListener('click', () => { if (this.mayLeave()) this.close(); });
            this.el('form').addEventListener('input', () => { this.dirty = true; });
            this.el('form').addEventListener('submit', event => this.save(event));
            window.addEventListener('beforeunload', event => { if (this.dirty) { event.preventDefault(); event.returnValue = ''; } });
            this.refresh();
        }
        node(tag, text, className) {
            const node = document.createElement(tag);
            if (text !== undefined) node.textContent = text;
            if (className) node.className = className;
            return node;
        }
        mayLeave() { return !this.dirty || window.confirm('Discard your unsaved audience edits?'); }
        close() { this.draft = null; this.dirty = false; this.el('form').hidden = true; }
        refresh() {
            const state = this.options.state(), list = this.el('list');
            list.replaceChildren();
            const audiences = state.audiences || [];
            this.el('add').disabled = audiences.length >= 20;
            if (!audiences.length) list.append(this.node('p', 'No audiences configured. Add a person’s devices or a group of recipients.', 'sls-location-empty'));
            audiences.forEach(row => {
                const card = this.node('article', undefined, 'sls-location-card'), body = this.node('div', undefined, 'sls-location-card-body');
                body.append(this.node('h3', row.name));
                const counts = Object.entries(this.options.channels).filter(([key]) => row.members[key].length)
                    .map(([key, definition]) => row.members[key].length + ' ' + definition[0].toLowerCase());
                body.append(this.node('p', counts.join(' · '), 'help-block'));
                if (row.reviewed_location) body.append(this.node('p', 'Reviewed location: ' + row.reviewed_location, 'help-block'));
                if (!row.identities_current) body.append(this.node('p', 'A desktop identity changed. Review the audience before sending.', 'text-warning'));
                const detail = this.node('details'), summary = this.node('summary', 'View recipients'), members = this.node('ul', undefined, 'sls-audience-device-list');
                Object.entries(row.members).forEach(([channel, ids]) => ids.forEach(id => {
                    const choice = state.catalog[channel][id];
                    members.append(this.node('li', this.options.channels[channel][0] + ' · ' + (choice ? choice.label : 'Unavailable recipient')));
                }));
                detail.append(summary, members); body.append(detail); card.append(body);
                const footer = this.node('footer', undefined, 'sls-location-card-footer');
                if (!row.reviewed_location) {
                    const edit = this.node('button', 'Edit', 'btn btn-default btn-sm'); edit.type = 'button';
                    edit.addEventListener('click', () => this.open(row)); footer.append(edit);
                }
                const remove = this.node('button', 'Remove', 'btn btn-link text-danger btn-sm'); remove.type = 'button';
                remove.addEventListener('click', async () => {
                    if (!this.mayLeave() || !window.confirm('Remove “' + row.name + '”? Existing delivery history will remain.')) return;
                    try { const result = await this.options.request('delete_audience', {id: row.id}); this.close(); this.options.onSaved(result); }
                    catch (error) { this.options.announce(error.message, false); }
                });
                footer.append(remove); card.append(footer); list.append(card);
            });
        }
        open(row) {
            if (!this.mayLeave() || !this.options.canEdit()) return;
            this.draft = row ? JSON.parse(JSON.stringify(row)) : {id: '', name: '', members: Object.fromEntries(Object.keys(this.options.channels).map(key => [key, []]))};
            this.dirty = false;
            this.el('name').value = this.draft.name;
            this.el('title').textContent = row ? 'Edit audience' : 'New audience';
            this.el('members').replaceChildren();
            Object.entries(this.options.channels).forEach(([channel, definition]) => this.renderChannel(channel, definition));
            this.el('form').hidden = false; this.count();
            this.el('form').scrollIntoView({block: 'start', behavior: 'smooth'}); this.el('name').focus({preventScroll: true});
        }
        count() { this.el('total').textContent = Object.values(this.draft.members).reduce((sum, ids) => sum + ids.length, 0) + ' selected'; }
        renderChannel(channel, definition) {
            const details = this.node('details'), summary = this.node('summary'), label = this.node('span');
            const icon = this.node('i', undefined, 'fa fa-' + definition[1]); icon.setAttribute('aria-hidden', 'true');
            summary.append(icon, label); details.append(summary);
            const toolbar = this.node('div', undefined, 'sls-location-member-toolbar'), search = this.node('input', undefined, 'form-control');
            search.type = 'search'; search.placeholder = 'Find ' + definition[0].toLowerCase(); search.setAttribute('aria-label', search.placeholder);
            toolbar.append(search); details.append(toolbar);
            const list = this.node('div', undefined, 'sls-location-options'); details.append(list);
            const choices = Object.assign(Object.create(null), this.options.state().catalog[channel]);
            this.draft.members[channel].forEach(id => { if (!choices[id]) choices[id] = {id, label: 'Removed recipient', available: false}; });
            const checks = [];
            const update = () => {
                this.draft.members[channel] = checks.filter(row => row.check.checked).map(row => row.check.value);
                label.textContent = definition[0] + ' · ' + this.draft.members[channel].length + ' selected'; this.count();
            };
            Object.values(choices).sort((a, b) => a.label.localeCompare(b.label, undefined, {numeric: true})).forEach(choice => {
                const row = this.node('label', undefined, 'sls-location-option'), check = this.node('input'), text = this.node('span', choice.label);
                check.type = 'checkbox'; check.value = choice.id; check.dataset.channel = channel;
                check.checked = this.draft.members[channel].includes(choice.id);
                check.disabled = !choice.available && !check.checked;
                if (!choice.available) text.append(this.node('small', 'Unavailable · remove this selection or enable the recipient.', 'sls-location-unavailable'));
                row.append(check, text); list.append(row); checks.push({row, check});
                check.addEventListener('change', () => { this.dirty = true; update(); });
            });
            if (!checks.length) list.append(this.node('p', 'No saved recipients for this channel.', 'sls-location-list-empty'));
            search.addEventListener('input', () => {
                const query = search.value.trim().toLowerCase(); checks.forEach(({row}) => { row.hidden = !row.textContent.toLowerCase().includes(query); });
            });
            update(); details.open = this.draft.members[channel].length > 0; this.el('members').append(details);
        }
        async save(event) {
            event.preventDefault();
            try {
                const result = await this.options.request('save_audience', {id: this.draft.id, name: this.el('name').value, members: this.draft.members});
                this.close(); this.options.onSaved(result);
            } catch (error) { this.options.announce(error.message, false); }
        }
    };
}());
