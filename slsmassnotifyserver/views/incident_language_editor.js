// Reviewed wording is entered by an operator; no translation service is called.
function languageEditor(parent, initial) {
    const section = node('section', {class: 'sls-inc-section'});
    const heading = node('h3');
    heading.append(node('i', {class: 'fa fa-language', 'aria-hidden': 'true'}), document.createTextNode(' Reviewed language variants'));
    section.append(heading, node('p', {class: 'sls-inc-help'},
        'Store up to six reviewed translations using the same required {{fields}} as the default message. Select one version for the audience. English, Spanish, French, German and Portuguese can use matching installed speech voices; other languages require text or tones only. SLS sends the supplied wording without translation.'));
    const rows = node('div', {class: 'sls-inc-resource-grid'});
    const add = node('button', {type: 'button', class: 'btn btn-default btn-sm'});
    add.append(node('i', {class: 'fa fa-plus', 'aria-hidden': 'true'}), document.createTextNode(' Add language'));
    section.append(rows, add); parent.append(section);
    const entries = [];
    function create(value) {
        if (entries.length >= 6) { status('A template supports up to six reviewed language variants.', true); return; }
        const card = node('div', {class: 'sls-inc-resource-card'});
        const label = field(card, 'Language name', input(value.label, 80, true));
        const locale = field(card, 'Language tag', input(value.locale, 35, true), 'For example: en-US, es-MX or fr-CA.');
        const title = field(card, 'Reviewed title', input(value.title, 80, true));
        const message = node('textarea', {maxlength: '500', required: 'required', rows: '4'});
        message.value = value.message || ''; field(card, 'Reviewed message', message);
        const note = field(card, 'Reviewer and revision', input(value.review_note, 100, true));
        const reviewed = check(card, 'A competent reviewer approved this wording', value.reviewed === true);
        reviewed.required = true;
        [label, locale, title, message, note].forEach(control => control.addEventListener('input', () => { reviewed.checked = false; }));
        const remove = node('button', {type: 'button', class: 'btn btn-default btn-sm', 'aria-label': 'Remove language variant'});
        remove.append(node('i', {class: 'fa fa-trash-o', 'aria-hidden': 'true'}), document.createTextNode(' Remove language'));
        const entry = {card, label, locale, title, message, note, reviewed};
        remove.addEventListener('click', () => { entries.splice(entries.indexOf(entry), 1); card.remove(); add.disabled = false; });
        card.append(remove); rows.append(card); entries.push(entry); add.disabled = entries.length >= 6;
    }
    add.addEventListener('click', () => create({}));
    (initial || []).forEach(create);
    return () => entries.map(entry => ({locale: entry.locale.value.trim(), label: entry.label.value.trim(),
        title: entry.title.value.trim(), message: entry.message.value.trim(),
        review_note: entry.note.value.trim(), reviewed: entry.reviewed.checked}));
}
