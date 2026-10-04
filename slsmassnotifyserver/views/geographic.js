(function () {
    'use strict';
    const ns = 'http://www.w3.org/2000/svg';
    const el = name => document.getElementById('sls-geo-' + name);
    const node = (tag, text, className) => {
        const item = document.createElement(tag);
        if (text !== undefined) item.textContent = text;
        if (className) item.className = className;
        return item;
    };
    const svgNode = (tag, attributes) => {
        const item = document.createElementNS(ns, tag);
        Object.entries(attributes).forEach(([key, value]) => item.setAttribute(key, value));
        return item;
    };
    const reasonNames = {unlocated: 'No coordinates', stale: 'Review too old', future_review: 'Review date is in the future', outside_area: 'Outside this area'};
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    const inArea = (area, point) => point.latitude >= area.south && point.latitude <= area.north &&
        (Math.abs(point.longitude) === 180 ? [-180, 180] : [point.longitude]).some(lon => area.west < area.east ?
            lon >= area.west && lon <= area.east : lon >= area.west || lon <= area.east);

    window.SlsGeographicAudience = function (options) {
        const map = el('map'), root = document.getElementById('sls-locations');
        let view = [0, 0, 360, 180], mode = 'pan', drag = null, preview = null, initialFit = false, refreshedAt = performance.now();
        const busy = () => root.hasAttribute('aria-busy');
        const state = () => options.state();
        const now = () => (state().observed_at || Date.now() / 1000) + (performance.now() - refreshedAt) / 1000;
        const positions = () => Object.entries(state().positions || {}).filter(([, position]) => position);
        const path = id => {
            const names = []; let current = id;
            for (let depth = 0; current && depth < 4; depth++) {
                const row = state().directory.nodes.find(item => item.id === current); if (!row) break;
                names.unshift(row.name); current = row.parent_id;
            }
            return names.join(' / ');
        };
        function selection() {
            const area = {};
            ['west', 'south', 'east', 'north'].forEach(key => {
                const input = el(key), limit = ['north', 'south'].includes(key) ? 90 : 180;
                if (!input.value.trim() || !Number.isFinite(input.valueAsNumber) || Math.abs(input.valueAsNumber) > limit) throw new Error('Enter valid latitude and longitude boundaries, or draw an area on the map.');
                area[key] = input.valueAsNumber;
            });
            if (area.north <= area.south || area.west === area.east || (area.west === 180 && area.east === -180)) throw new Error('The area needs nonzero width and height. North must be above south.');
            area.max_age_days = el('age').valueAsNumber;
            if (!Number.isInteger(area.max_age_days) || area.max_age_days < 1 || area.max_age_days > 3650) throw new Error('Coordinate review age must be a whole number from 1 to 3650 days.');
            return area;
        }
        function input() {
            if (!options.canReview()) throw new Error('Save or discard location edits before reviewing a geographic audience.');
            return {selection: selection(), channels: Array.from(el('channels').querySelectorAll('input:checked')).map(item => item.value)};
        }
        function invalidate() { preview = null; el('result').hidden = true; el('confirm').checked = false; el('create').disabled = true; }
        function current(point, age) { return point.reviewed_at <= now() + 60 && point.reviewed_at >= now() - age * 86400; }
        function setView(x, y, width) {
            const aspect = map.clientWidth > 0 && map.clientHeight > 0 ? map.clientWidth / map.clientHeight : 2;
            width = clamp(width, 0.0001, 360);
            const height = width / aspect;
            view = [clamp(x, 0, 360 - width), height > 180 ? (180 - height) / 2 : clamp(y, 0, 180 - height), width, height];
            render();
        }
        function zoom(factor) {
            const width = view[2] * factor, height = view[3] * factor;
            setView(view[0] + (view[2] - width) / 2, view[1] + (view[3] - height) / 2, width);
        }
        function fit() {
            const points = positions().map(([, p]) => [p.longitude + 180, 90 - p.latitude]);
            if (!points.length) { setView(0, 0, 360); return; }
            const xs = points.map(p => p[0]), ys = points.map(p => p[1]);
            const aspect = map.clientWidth / (map.clientHeight || 1) || 2;
            const width = Math.max(0.04, (Math.max(...xs) - Math.min(...xs)) * 1.3, (Math.max(...ys) - Math.min(...ys)) * aspect * 1.3);
            setView((Math.max(...xs) + Math.min(...xs) - width) / 2, (Math.max(...ys) + Math.min(...ys) - width / aspect) / 2, width);
        }
        function rectangle(west, south, east, north) {
            el('area').append(svgNode('rect', {x: west + 180, y: 90 - north, width: east - west, height: north - south,
                fill: '#337ab7', 'fill-opacity': '0.16', stroke: '#245a87', 'stroke-width': view[2] / (map.clientWidth || 720) * 2}));
        }
        function render() {
            map.setAttribute('viewBox', view.join(' '));
            let area = null; try { area = selection(); } catch (_) { /* Incomplete numeric form. */ }
            el('fit-area').disabled = busy() || !area;
            el('area').replaceChildren(); el('markers').replaceChildren();
            if (drag && mode === 'draw') {
                rectangle(Math.min(drag.start[0], drag.end[0]) - 180, 90 - Math.max(drag.start[1], drag.end[1]),
                    Math.max(drag.start[0], drag.end[0]) - 180, 90 - Math.min(drag.start[1], drag.end[1]));
            } else if (area) {
                if (area.west < area.east) rectangle(area.west, area.south, area.east, area.north);
                else { rectangle(area.west, area.south, 180, area.north); rectangle(-180, area.south, area.east, area.north); }
            }
            const age = area ? area.max_age_days : (el('age').valueAsNumber || 365);
            let selected = 0, fresh = 0;
            positions().forEach(([id, position]) => {
                const valid = current(position, age), inside = valid && area && inArea(area, position);
                if (valid) fresh++; if (inside) selected++;
                const circle = svgNode('circle', {cx: position.longitude + 180, cy: 90 - position.latitude,
                    r: view[2] / (map.clientWidth || 720) * 5, fill: inside ? '#15803d' : valid ? '#245a87' : '#b7791f',
                    stroke: '#fff', 'stroke-width': view[2] / (map.clientWidth || 720) * 1.4});
                const title = svgNode('title', {}); title.textContent = path(id) + ' · ' + position.latitude + ', ' + position.longitude +
                    (valid ? inside ? ' · inside area' : ' · reviewed' : ' · review needed'); circle.append(title); el('markers').append(circle);
            });
            const total = state().directory.nodes.length, located = positions().length;
            el('map-status').textContent = (area ? selected + ' location(s) inside this area. ' : 'Choose an area to start. ') +
                fresh + ' with current coordinates; ' + (located - fresh) + ' need review; ' + (total - located) + ' have no position.';
        }
        const point = event => {
            const p = map.createSVGPoint(); p.x = event.clientX; p.y = event.clientY;
            const world = p.matrixTransform(map.getScreenCTM().inverse()); return [clamp(world.x, 0, 360), clamp(world.y, 0, 180)];
        };
        function cancelDrag() {
            if (drag && map.hasPointerCapture(drag.id)) map.releasePointerCapture(drag.id);
            drag = null; render();
        }
        map.addEventListener('pointerdown', event => {
            if (busy() || !event.isPrimary || event.button !== 0) return;
            map.focus({preventScroll: true}); const p = point(event);
            drag = {id: event.pointerId, start: p, end: p, view: view.slice(), screen: [event.clientX, event.clientY]};
            map.setPointerCapture(event.pointerId); event.preventDefault();
        });
        map.addEventListener('pointermove', event => {
            if (!drag || drag.id !== event.pointerId) return;
            if (busy()) { cancelDrag(); return; }
            if (mode === 'pan') {
                setView(drag.view[0] - (event.clientX - drag.screen[0]) * drag.view[2] / map.clientWidth,
                    drag.view[1] - (event.clientY - drag.screen[1]) * drag.view[3] / map.clientHeight, drag.view[2]);
            } else { drag.end = point(event); render(); }
        });
        map.addEventListener('pointerup', event => {
            if (!drag || drag.id !== event.pointerId) return;
            if (mode === 'draw' && !busy() && Math.abs(event.clientX - drag.screen[0]) >= 4 && Math.abs(event.clientY - drag.screen[1]) >= 4) {
                drag.end = point(event);
                const area = {west: Math.min(drag.start[0], drag.end[0]) - 180, east: Math.max(drag.start[0], drag.end[0]) - 180,
                    south: 90 - Math.max(drag.start[1], drag.end[1]), north: 90 - Math.min(drag.start[1], drag.end[1])};
                Object.entries(area).forEach(([key, value]) => { el(key).value = value.toFixed(6); }); invalidate();
            }
            cancelDrag();
        });
        map.addEventListener('pointercancel', cancelDrag);
        map.addEventListener('lostpointercapture', () => { if (drag) { drag = null; render(); } });
        map.addEventListener('keydown', event => {
            if (busy()) return;
            if (event.key === 'Escape') { cancelDrag(); return; }
            const moves = {ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1]};
            if (moves[event.key]) { event.preventDefault(); setView(view[0] + moves[event.key][0] * view[2] / 5, view[1] + moves[event.key][1] * view[3] / 5, view[2]); }
            else if (['+', '=', '-', '−'].includes(event.key)) { event.preventDefault(); zoom(['+', '='].includes(event.key) ? 0.5 : 2); }
        });
        ['pan', 'draw'].forEach(name => el(name).addEventListener('click', () => {
            cancelDrag(); mode = name; map.dataset.mode = mode;
            ['pan', 'draw'].forEach(key => el(key).setAttribute('aria-pressed', String(key === mode)));
        }));
        el('zoom-in').addEventListener('click', () => zoom(0.5)); el('zoom-out').addEventListener('click', () => zoom(2)); el('fit').addEventListener('click', fit);
        el('fit-area').addEventListener('click', () => {
            const area = selection();
            if (area.west > area.east) { setView(0, 0, 360); return; }
            const aspect = map.clientWidth / map.clientHeight;
            const width = Math.max((area.east - area.west) * 1.15, (area.north - area.south) * aspect * 1.15);
            setView(180 + (area.west + area.east - width) / 2, 90 - (area.north + area.south + width / aspect) / 2, width);
        });
        for (let x = 0; x <= 360; x += 30) el('grid').append(svgNode('line', {x1: x, y1: 0, x2: x, y2: 180}));
        for (let y = 0; y <= 180; y += 30) el('grid').append(svgNode('line', {x1: 0, y1: y, x2: 360, y2: y}));
        ['west', 'east', 'north', 'south', 'age'].forEach(key => el(key).addEventListener('input', () => { invalidate(); render(); }));
        Object.entries(options.channels).forEach(([key, definition]) => {
            const label = node('label', undefined, 'sls-location-check'), checkbox = node('input'); checkbox.type = 'checkbox'; checkbox.checked = true; checkbox.value = key;
            checkbox.addEventListener('change', invalidate); label.append(checkbox, document.createTextNode(definition[0])); el('channels').append(label);
        });
        function details(container, heading, rows, open) {
            const section = node('details'), summary = node('summary', heading), list = node('ul');
            rows.forEach(text => list.append(node('li', text))); section.append(summary, list); section.open = open; container.append(section);
        }
        function allowCreate() {
            el('create').disabled = !preview || !el('confirm').checked || preview.unavailable.length > 0 ||
                Object.values(preview.members).every(ids => !ids.length) || state().group_count >= 20;
        }
        el('confirm').addEventListener('change', allowCreate);
        el('preview').addEventListener('click', async () => {
            try {
                invalidate(); const response = await options.request('preview_geographic_audience', input()); preview = response.preview;
                const total = Object.values(preview.members).reduce((sum, ids) => sum + ids.length, 0);
                el('result-heading').textContent = total + ' recipient(s) across ' + preview.location_count + ' included location(s)';
                el('review-locations').replaceChildren(); el('review-members').replaceChildren();
                details(el('review-locations'), 'Included locations · ' + preview.included_locations.length, preview.included_locations.map(row => row.path + ' · ' +
                    row.position.latitude + ', ' + row.position.longitude + ' · reviewed ' + new Date(row.position.reviewed_at * 1000).toISOString().slice(0, 10) +
                    (row.position.source_id === row.id ? '' : ' · inherited from ' + path(row.position.source_id))), preview.included_locations.length <= 10);
                Object.entries(reasonNames).forEach(([reason, label]) => {
                    const rows = preview.excluded_locations.filter(row => row.reason === reason);
                    if (rows.length) details(el('review-locations'), 'Excluded · ' + label + ' · ' + rows.length, rows.map(row => row.path), reason !== 'outside_area' && rows.length <= 10);
                });
                Object.entries(preview.members).forEach(([key, ids]) => {
                    if (ids.length) details(el('review-members'), options.channels[key][0] + ' · ' + ids.length,
                        ids.map(id => Object.prototype.hasOwnProperty.call(state().catalog[key], id) ? state().catalog[key][id].label : 'Unavailable · ' + id), ids.length <= 10);
                });
                el('unavailable').hidden = !preview.unavailable.length && state().group_count < 20; el('unavailable').replaceChildren();
                if (preview.unavailable.length) {
                    el('unavailable').append(node('strong', 'Resolve unavailable recipients before creating this audience.'));
                    const list = node('ul'); preview.unavailable.forEach(row => list.append(node('li', row.location + ': ' + row.id + ' · ' + row.reason))); el('unavailable').append(list);
                }
                if (state().group_count >= 20) el('unavailable').append(node('p', 'All 20 announcement groups are in use. Remove an unused group on the Dashboard first.'));
                el('result').hidden = false; allowCreate();
            } catch (error) { invalidate(); options.announce(error.message, false); }
        });
        el('create').addEventListener('click', async () => {
            try {
                if (!preview || !el('confirm').checked) throw new Error('Review and confirm this geographic audience first.');
                const request = Object.assign(input(), {preview_token: preview.token, exclusions_reviewed: true, name: el('name').value});
                const result = await options.request('create_geographic_audience', request); invalidate(); options.onCreated(result);
            } catch (error) { options.announce(error.message, false); }
        });
        el('panel').addEventListener('toggle', () => { if (el('panel').open && !initialFit) { fit(); initialFit = true; } });
        new ResizeObserver(() => { if (map.clientWidth && map.clientHeight) setView(view[0], view[1], view[2]); }).observe(map);
        this.invalidate = invalidate;
        this.refresh = () => { refreshedAt = performance.now(); invalidate(); render(); };
        this.refresh();
    };
}());
