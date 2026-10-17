'use strict';
// Run the actual General Settings monitor with a private clock and transport.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const source = fs.readFileSync(process.env.SLS_PROGRESS_VIEW || path.join(__dirname, '../slsmassnotifyserver/views/other_settings.php'), 'utf8');
const start = source.indexOf('(function() {\n\tvar updateProgress');
const end = source.indexOf("\tdocument.addEventListener('click'", start);
assert(start >= 0 && end > start, 'Actual monitor source was not found');
const script = source.slice(start, end).replace(/<\?php[\s\S]*?\?>/g, JSON.stringify('Retry update')) + '\n})();';
const profileStart = source.indexOf("(function () {\n 'use strict';\n var list = document.getElementById('sls-test-profile-list');");
const profileEnd = source.indexOf('\n function addProfile(', profileStart);
assert(profileStart >= 0 && profileEnd > profileStart, 'Actual saved-check monitor was not found');
const profileScript = source.slice(profileStart, profileEnd).replace(/<\?php[\s\S]*?\?>/g, '[]')
    + '\npoll("job_' + 'a'.repeat(32) + '",performance.now());})();';

async function exercise(kind, outcome, leave = false) {
    let now = 0, nextId = 1, calls = 0, replaced = 0;
    const timers = new Map(), events = {};
    const parts = {icon: {}, title: {}, message: {}};
    const attributes = {'data-active': '1', 'data-action': kind === 'uninstall' ? 'uninstall' : 'repair', 'data-state': 'queued', 'data-status-url': '/private-status'};
    const progress = {
        querySelectorAll: () => [],
        getAttribute: key => attributes[key], setAttribute: (key, value) => {attributes[key] = value;},
        querySelector: selector => selector.includes('__icon') ? parts.icon : selector.includes('__title') ? parts.title : parts.message
    };
    const controls = {querySelectorAll: () => [], querySelector: () => null, appendChild: () => {}};
    const document = {
        hidden: false,
        body: {contains: () => true},
        getElementById: id => kind === 'profile' || id === (kind === 'update' ? 'sls-update-progress' : 'sls-maintenance-progress') ? progress : null,
        querySelectorAll: () => [], querySelector: () => controls, createElement: () => ({})
    };
    const window = {
        setTimeout: (callback, delay) => {const id = nextId++; timers.set(id, {callback, due: now + delay}); return id;},
        clearTimeout: id => timers.delete(id), addEventListener: (name, callback) => {events[name] = callback;},
        location: {href: 'https://pbx.example/admin/config.php', replace: () => {replaced++;}, reload: () => {replaced++;}},
        history: {replaceState: () => {}}
    };
    const fetch = (url, options) => {
        calls++;
        if (kind !== 'profile') assert.strictEqual(url, '/private-status');
        if (outcome === 'hung') return new Promise((resolve, reject) => {
            if (options.signal) options.signal.addEventListener('abort', () => reject(new Error('private timeout')));
        });
        if (outcome === 'error') return Promise.reject(new Error('private transport failure'));
        return Promise.resolve({ok: true, json: () => Promise.resolve({state: outcome, action: attributes['data-action'], message: 'private fixture'})});
    };
    vm.runInNewContext(kind === 'profile' ? profileScript : script, {document, window, fetch, URL, AbortController,
        Date: {now: () => 0}, performance: {now: () => now}}, {timeout: 1000});
    if (leave && events.pagehide) events.pagehide();
    await new Promise(resolve => setImmediate(resolve));
    for (let count = 0; timers.size && count < 3500; count++) {
        const [id, timer] = Array.from(timers).sort((a, b) => a[1].due - b[1].due)[0];
        timers.delete(id); now = timer.due; timer.callback();
        await new Promise(resolve => setImmediate(resolve));
    }
    return {state: attributes['data-state'], timers: timers.size, calls, replaced, text: kind === 'profile' ? progress.textContent : parts.message.textContent};
}

(async () => {
    const selected = process.env.SLS_PROGRESS_CASE || '';
    if (selected === 'uninstall') {
        assert.strictEqual((await exercise('uninstall', 'error')).state, 'unconfirmed', 'Unavailable transport was labeled a completed uninstall');
        return;
    }
    if (selected === 'hung') {
        assert.strictEqual((await exercise('update', 'hung')).state, 'unconfirmed', 'A stalled request never settled');
        return;
    }
    if (selected === 'profile') {
        const result = await exercise('profile', 'running');
        assert.strictEqual(result.timers, 0, 'Saved check remained busy after its observation budget');
        assert.match(result.text, /longer than expected/);
        return;
    }
    for (const kind of ['update', 'maintenance']) {
        const result = await exercise(kind, 'running');
        assert.strictEqual(result.state, 'unconfirmed', `${kind} remained busy after the observation budget`);
        assert.strictEqual(result.timers, 0);
    }
    const unavailable = await exercise('uninstall', 'error');
    assert.strictEqual(unavailable.state, 'unconfirmed', 'Unavailable transport was labeled a completed uninstall');
    const hung = await exercise('update', 'hung');
    assert.strictEqual(hung.state, 'unconfirmed', 'A stalled request never settled');
    const complete = await exercise('update', 'complete');
    assert.strictEqual(complete.state, 'complete'); assert.strictEqual(complete.calls, 1); assert.strictEqual(complete.replaced, 1);
    const failure = await exercise('maintenance', 'failed');
    assert.strictEqual(failure.state, 'failed'); assert.strictEqual(failure.calls, 1);
    const departed = await exercise('update', 'running', true);
    assert.strictEqual(departed.calls, 0, 'A departed page continued polling');
    const profile = await exercise('profile', 'running');
    assert.strictEqual(profile.timers, 0, 'Saved check remained busy after its observation budget');
    assert.match(profile.text, /longer than expected/);
    const profileHung = await exercise('profile', 'hung');
    assert.match(profileHung.text, /longer than expected/);
    const profileDeparted = await exercise('profile', 'running', true);
    assert(profileDeparted.calls <= 1, 'Saved check continued after page departure');
    assert.strictEqual(profileDeparted.timers, 0);
    console.log('Actual operation monitors: bounded pending/status waits, honest uninstall evidence, confirmed results and page departure passed.');
})().catch(error => {console.error(error); process.exitCode = 1;});
