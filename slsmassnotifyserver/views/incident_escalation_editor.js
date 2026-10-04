// Each follow-up has its own frozen audience and attributable delivery attempt.
function escalationEditor(parent, policy) {
    policy = policy || {};
    const section = node('section', {class: 'sls-inc-section'});
    const heading = node('h3');
    heading.append(node('i', {class: 'fa fa-level-up', 'aria-hidden': 'true'}), document.createTextNode(' Escalation ladder'));
    section.append(heading, node('p', {class: 'sls-inc-help'},
        'Add up to five follow-ups for people with no response, missing or needing assistance. Delays start when the initial announcement obtains a job ID. Each step has its own destinations. App receipts do not count as human responses. An all-clear stops future steps; sent alerts remain unchanged.'));
    const enabled = check(section, 'Enable automatic follow-ups for this template', !!policy.enabled);
    enabled.id = 'sls-inc-escalation-enabled';
    const container = node('div'), rows = node('div'), empty = node('p', {class: 'sls-inc-help'}, 'No follow-ups configured. Add a step and select its destinations.');
    const add = node('button', {type: 'button', class: 'btn btn-default btn-sm', id: 'sls-inc-escalation-add'});
    add.append(node('i', {class: 'fa fa-plus', 'aria-hidden': 'true'}), document.createTextNode(' Add follow-up'));
    container.append(rows, empty, add, node('p', {class: 'sls-inc-help'},
        'Use increasing delays from 60 to 86400 seconds. A delayed worker preserves the interval between levels. Uncertain submissions pause the ladder and require review in delivery details; SLS does not replay them automatically.'));
    section.append(container); parent.append(section);
    const entries = [];
    function refresh() {
        container.hidden = !enabled.checked;
        container.querySelectorAll('input,select,textarea,button').forEach(control => control.disabled = !enabled.checked);
        add.disabled = !enabled.checked || entries.length >= 5;
        empty.hidden = entries.length > 0;
        entries.forEach((entry, index) => {
            if (rows.children[index] !== entry.card) rows.insertBefore(entry.card, rows.children[index] || null);
            entry.summary.textContent = 'Step ' + (index + 1) + ' · ' + (entry.name.value.trim() || 'Unnamed follow-up') + ' · after ' + entry.delay.value + ' seconds';
            entry.up.disabled = !enabled.checked || index === 0;
            entry.down.disabled = !enabled.checked || index === entries.length - 1;
        });
    }
    function create(value) {
        if (entries.length >= 5) { status('An escalation ladder supports at most five follow-ups.', true); return; }
        const card = node('details', {class: 'sls-inc-resource-card sls-inc-escalation-step'}), summary = node('summary');
        card.open = !entries.length; card.append(summary);
        const actions = node('div', {class: 'sls-inc-actions', style: 'margin:14px 0'});
        const up = node('button', {type: 'button', class: 'btn btn-default btn-sm', 'aria-label': 'Move follow-up earlier'}, 'Move earlier');
        const down = node('button', {type: 'button', class: 'btn btn-default btn-sm', 'aria-label': 'Move follow-up later'}, 'Move later');
        const remove = node('button', {type: 'button', class: 'btn btn-default btn-sm', 'aria-label': 'Remove follow-up'});
        remove.append(node('i', {class: 'fa fa-trash-o', 'aria-hidden': 'true'}), document.createTextNode(' Remove'));
        actions.append(up, down, remove); card.append(actions);
        const grid = node('div', {class: 'sls-inc-grid'}); card.append(grid);
        const name = field(grid, 'Follow-up name', input(value.name || ('Supervisor follow-up ' + (entries.length + 1)), 80, true));
        name.dataset.escalationName = 'true';
        const delay = node('input', {type: 'number', min: '60', max: '86400', step: '1', required: 'required', 'data-escalation-delay': 'true'});
        delay.value = String(value.after_seconds || (entries.length ? Number(entries[entries.length - 1].delay.value) + 300 : 300));
        field(grid, 'Delay after initial job (seconds)', delay, 'Every later step needs a larger delay.');
        const getDelivery = deliveryEditor(card, value.delivery, 'Destinations for this step');
        const entry = {card, summary, name, delay, getDelivery, up, down}; entries.push(entry);
        name.addEventListener('input', refresh); delay.addEventListener('input', refresh);
        function move(direction) {
            const index = entries.indexOf(entry), next = index + direction;
            if (next < 0 || next >= entries.length) return;
            [entries[index], entries[next]] = [entries[next], entries[index]]; refresh();
            entry.card.open = true; entry.summary.focus();
        }
        up.addEventListener('click', () => move(-1)); down.addEventListener('click', () => move(1));
        remove.addEventListener('click', () => { entries.splice(entries.indexOf(entry), 1); card.remove(); refresh(); add.focus(); });
        refresh(); return entry;
    }
    add.addEventListener('click', () => { const entry = create({}); if (entry) { entry.card.open = true; entry.name.focus(); } });
    enabled.addEventListener('change', refresh);
    const initial = Array.isArray(policy.steps) ? policy.steps : (policy.enabled || policy.delivery ? [{name: 'Supervisor follow-up', after_seconds: policy.after_seconds, delivery: policy.delivery}] : []);
    initial.forEach(create); refresh();
    return () => {
        const steps = entries.map(entry => ({name: entry.name.value.trim(), after_seconds: Number(entry.delay.value), delivery: entry.getDelivery()}));
        if (enabled.checked && !steps.length) throw Error('Add at least one follow-up before enabling escalation.');
        let previous = 0;
        steps.forEach(step => {
            if (!Number.isInteger(step.after_seconds) || step.after_seconds < 60 || step.after_seconds > 86400 || step.after_seconds <= previous)
                throw Error('Follow-up delays must be increasing whole seconds between 60 and 86400. Check the step order and delays.');
            previous = step.after_seconds;
        });
        return {enabled: enabled.checked, steps};
    };
}
