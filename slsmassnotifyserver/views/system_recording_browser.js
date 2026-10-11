(function () {
    'use strict';
    if (window.slsRecordingBrowserRegistered) return;
    window.slsRecordingBrowserRegistered = true;
    function start() {
        document.querySelectorAll('optgroup[data-sls-recordings]').forEach(function (group, index) {
            var select = group.parentElement, data;
            try { data = JSON.parse(group.dataset.slsRecordings); } catch (_) { return; }
            var box = document.createElement('details'), summary = document.createElement('summary');
            box.className = 'sls-recording-browser';
            summary.textContent = 'Search system recordings'; box.appendChild(summary);
            var row = document.createElement('div'), label = document.createElement('label'), input = document.createElement('input');
            row.className = 'sls-recording-search'; input.type = 'search'; input.maxLength = 200;
            input.id = 'sls-recording-search-' + index; input.className = 'form-control';
            label.htmlFor = input.id; label.textContent = 'Name or filename'; label.appendChild(input); row.appendChild(label);
            var search = document.createElement('button'), more = document.createElement('button'), status = document.createElement('p');
            [search, more].forEach(function (button) { button.type = 'button'; button.className = 'btn btn-default btn-sm'; });
            search.textContent = 'Search'; more.textContent = 'Load next 100'; row.appendChild(search); row.appendChild(more);
            status.className = 'help-block'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
            box.appendChild(row); box.appendChild(status); select.insertAdjacentElement('afterend', box);
            var offset = data.catalogue.next_offset, currentQuery = '', busy = false;
            function notice(page) {
                more.hidden = !page.has_more;
                status.textContent = page.warning || (page.has_more ? 'More recordings are available. Search by name or load the next page.' : 'All matching recordings are listed.');
            }
            notice(data.catalogue);
            async function load(append) {
                if (busy) return; busy = true; search.disabled = more.disabled = true;
                var query = append ? currentQuery : input.value.trim(), requestedOffset = append ? offset : 0;
                var params = new URLSearchParams(), endpoint = 'config.php?display=slsmassnotifyserver_other';
                params.set('slsmassnotifyserver_csrf', data.csrf);
                params.set('slsmassnotifyserver_action', 'recording_catalogue');
                if (data.portal) {
                    var portal = JSON.parse(document.getElementById('sls-operations-data').textContent);
                    endpoint = portal.endpoint; params.set('slsmassnotifyserver_csrf', portal.csrf);
                    params.set('payload', JSON.stringify({query: query, offset: requestedOffset || 0}));
                } else { params.set('query', query); params.set('offset', String(requestedOffset || 0)); }
                var controller = new AbortController(), timer = setTimeout(function () { controller.abort(); }, 10000);
                try {
                    var response = await fetch(endpoint, {method: 'POST', credentials: 'same-origin', cache: 'no-store', body: params, signal: controller.signal});
                    var page = await response.json();
                    if (!response.ok || !page.success || !Array.isArray(page.items)) throw new Error(page.message || 'The recording catalogue is unavailable.');
                    var chosen = select.value, selected = select.selectedOptions[0];
                    var chosenLabel = selected ? selected.textContent : chosen;
                    if (!append) group.replaceChildren();
                    page.items.forEach(function (item) {
                        if (typeof item.value !== 'string' || typeof item.label !== 'string') return;
                        if ([].some.call(group.children, function (option) { return option.value === item.value; })) return;
                        var option = document.createElement('option'); option.value = item.value; option.textContent = item.label; group.appendChild(option);
                    });
                    if (chosen.indexOf('system:') === 0 && ![].some.call(group.children, function (option) { return option.value === chosen; })) {
                        var pinned = document.createElement('option'); pinned.value = chosen; pinned.textContent = chosenLabel; group.prepend(pinned);
                    }
                    select.value = chosen; currentQuery = query; offset = page.next_offset; notice(page);
                } catch (error) { status.textContent = error.name === 'AbortError' ? 'Recording search timed out. Try again.' : error.message; }
                finally { clearTimeout(timer); busy = false; search.disabled = more.disabled = false; }
            }
            search.addEventListener('click', function () { load(false); }); more.addEventListener('click', function () { load(true); });
            input.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); load(false); } });
        });
        var style = document.createElement('style');
        style.textContent = '.sls-recording-browser{margin:6px 0 12px;font-size:12px}.sls-recording-browser>summary{cursor:pointer;color:inherit}.sls-recording-search{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin:10px 0}.sls-recording-search label{flex:1 1 180px;margin:0}.sls-recording-search input{height:30px}.sls-recording-search button{min-height:30px}';
        document.head.appendChild(style);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else queueMicrotask(start);
}());
