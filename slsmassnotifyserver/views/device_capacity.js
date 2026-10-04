(function () {
    'use strict';
    var root = document.getElementById('sls-capacity-preview');
    if (!root) return;
    var form = document.getElementById('sls-other-settings-form');
    var phone = document.getElementById('sls-phone-limit');
    var desktop = document.getElementById('sls-desktop-limit');
    if (!form || !phone || !desktop) return;
    var status = root.querySelector('[data-capacity-status]');
    var metrics = root.querySelector('dl');
    var errors = root.querySelector('[data-capacity-errors]');
    var timer, request, generation = 0;
    function text(selector, value) { root.querySelector(selector).textContent = value; }
    function gib(bytes) { return (Number(bytes) / 1073741824).toFixed(2) + ' GiB'; }
    function metric(selector, value, check) {
        var field = root.querySelector(selector);
        field.replaceChildren(document.createTextNode(value));
        var state = check && typeof check.ok === 'boolean' ? check.ok : null;
        var badge = document.createElement('small');
        badge.className = 'sls-capacity-result ' + (state === null ? 'sls-capacity-unknown' : state ? 'sls-capacity-pass' : 'sls-capacity-fail');
        var icon = document.createElement('i');
        icon.className = 'fa ' + (state === null ? 'fa-question-circle' : state ? 'fa-check-circle' : 'fa-times-circle');
        icon.setAttribute('aria-hidden', 'true');
        badge.appendChild(icon);
        badge.appendChild(document.createTextNode(state === null ? ' Not verified' : state ? ' Sufficient' : ' Insufficient'));
        if (check && Array.isArray(check.filesystems)) {
            badge.title = check.filesystems.map(function (disk) {
                return disk.paths.join(', ') + ': ' + gib(disk.actual) + ' free / ' + gib(disk.required) + ' required';
            }).join('\n');
        }
        field.appendChild(badge);
    }
    function refresh() {
        generation++;
        var current = generation;
        if (request) { request.abort(); request = null; }
        clearTimeout(timer);
        metrics.hidden = true; errors.hidden = true; errors.replaceChildren();
        text('[data-capacity-hardware]', '');
        if (![phone, desktop].every(function (input) { return /^[0-9]{1,4}$/.test(input.value) && Number(input.value) >= 1 && Number(input.value) <= 1000; })) {
            status.textContent = 'Enter phone and desktop capacities from 1 through 1000.';
            root.setAttribute('aria-busy', 'false'); return;
        }
        root.setAttribute('aria-busy', 'true');
        status.textContent = 'Checking ' + phone.value + ' phone contacts and ' + desktop.value + ' desktops…';
        timer = setTimeout(function () {
            var body = new FormData();
            body.set('slsmassnotifyserver_action', 'preview_device_capacity');
            body.set('slsmassnotifyserver_csrf', form.querySelector('[name=slsmassnotifyserver_csrf]').value);
            body.set('phone_device_limit', phone.value); body.set('desktop_client_limit', desktop.value);
            var xhr = request = new XMLHttpRequest();
            xhr.open('POST', 'config.php?display=slsmassnotifyserver_other'); xhr.timeout = 10000;
            function failed(message) {
                if (generation !== current) return;
                request = null; root.setAttribute('aria-busy', 'false'); status.textContent = message;
            }
            xhr.onload = function () {
                if (generation !== current) return;
                var result;
                try { result = JSON.parse(xhr.responseText); } catch (ignored) {}
                if (!result || !result.success || !result.requirements || !result.hardware) {
                    failed(result && typeof result.message === 'string' ? result.message : 'The resource check did not return a report. Reload the page and check your administrator session.'); return;
                }
                var required = result.requirements;
                var checks = result.resource_checks || {};
                metric('[data-capacity-cpu]', required.cpu_count + ' cores', checks.cpu);
                metric('[data-capacity-memory]', gib(required.memory_bytes), checks.memory);
                metric('[data-capacity-storage]', gib(required.sls_persistent_free_bytes), checks.storage);
                metric('[data-capacity-temporary]', gib(required.temporary_free_bytes), checks.temporary);
                text('[data-capacity-hardware]', 'Allocated: ' + result.hardware.effective_cpu_count + ' effective CPU cores · ' + gib(result.hardware.effective_memory_bytes) + ' usable RAM. Phone capacity with these desktop settings: ' + result.eligible_phone_limit + '.');
                (result.errors || []).forEach(function (message) { var item = document.createElement('li'); item.textContent = message; errors.appendChild(item); });
                metrics.hidden = false; errors.hidden = !errors.children.length;
                var unverified = ['cpu', 'memory', 'storage', 'temporary'].some(function (name) {
                    return !checks[name] || typeof checks[name].ok !== 'boolean';
                });
                failed(!errors.hidden ? 'The PBX does not meet these requirements. Saving an increase will be rejected.'
                    : unverified ? 'One or more resource checks could not be verified. Retry the check before increasing capacity.'
                    : 'The PBX meets the allocation requirements for these capacities.');
            };
            xhr.onerror = xhr.ontimeout = function () { failed('The resource check could not finish. Check the PBX connection and try again.'); };
            xhr.send(body);
        }, 600);
    }
    phone.addEventListener('input', refresh); desktop.addEventListener('input', refresh); refresh();
}());
