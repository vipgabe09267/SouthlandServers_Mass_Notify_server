// Included inside the incident page's private script scope; no document fetches.
function resourceEditor(parent, initial) {
    const section = node('section', {class: 'sls-inc-section'});
    const heading = node('h3');
    heading.append(node('i', {class: 'fa fa-map-o', 'aria-hidden': 'true'}), document.createTextNode(' Maps and responder resources'));
    section.append(heading, node('p', {class: 'sls-inc-help'},
        'Add up to 10 approved HTTPS links for operators. Use versioned documents, keep passwords and access tokens out of URLs, and confirm responder access. Links are preserved in incident reports; they are not appended to phone, desktop or SMS announcements.'));
    const rows = node('div', {class: 'sls-inc-resource-grid'});
    const add = node('button', {type: 'button', class: 'btn btn-default btn-sm'});
    add.append(node('i', {class: 'fa fa-plus', 'aria-hidden': 'true'}), document.createTextNode(' Add resource'));
    section.append(rows, add); parent.append(section);
    const entries = [];
    function create(value) {
        if (entries.length >= 10) { status('A template supports up to 10 resources.', true); return; }
        const card = node('div', {class: 'sls-inc-resource-card'});
        const kind = field(card, 'Resource type', select([
            ['map', 'Floor plan / map'], ['instructions', 'Evacuation or response instructions'], ['reference', 'Responder reference']
        ], value.kind || 'map'));
        const label = field(card, 'Link label', input(value.label, 80, true));
        const url = input(value.url, 2048, true); url.type = 'url'; url.placeholder = 'https://…';
        field(card, 'HTTPS document URL', url);
        const revision = field(card, 'Revision or review note', input(value.revision, 100, true), 'For example: Approved evacuation plan, revision 2026-09.');
        const reviewed = check(card, 'I reviewed this resource and confirmed responder access', value.reviewed === true);
        reviewed.required = true;
        [kind, label, url, revision].forEach(control => control.addEventListener('input', () => { reviewed.checked = false; }));
        const remove = node('button', {type: 'button', class: 'btn btn-default btn-sm', 'aria-label': 'Remove resource'});
        remove.append(node('i', {class: 'fa fa-trash-o', 'aria-hidden': 'true'}), document.createTextNode(' Remove resource'));
        const entry = {card, kind, label, url, revision, reviewed};
        remove.addEventListener('click', () => { entries.splice(entries.indexOf(entry), 1); card.remove(); add.disabled = false; });
        card.append(remove); rows.append(card); entries.push(entry); add.disabled = entries.length >= 10;
    }
    add.addEventListener('click', () => create({}));
    (initial || []).forEach(create);
    return () => entries.map(entry => ({kind: entry.kind.value, label: entry.label.value.trim(),
        url: entry.url.value.trim(), revision: entry.revision.value.trim(), reviewed: entry.reviewed.checked}));
}
