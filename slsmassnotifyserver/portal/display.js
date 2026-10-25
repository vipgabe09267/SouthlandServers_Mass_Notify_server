'use strict';
class SlsDisplayFeed {
    constructor() { this.reset(); }
    reset() { this.cursor = null; }
    static event(row, now) {
        if (!Number.isFinite(now)) throw Error('PBX time is unavailable.');
        if (!row || typeof row !== 'object' || Array.isArray(row)) throw Error('Invalid announcement.');
        const id = row.id ?? row.event_id;
        if (typeof id !== 'string' || !/^[\x21-\x7e]{1,2048}$/.test(id)) throw Error('Invalid announcement ID.');
        const timestamp = value => typeof value === 'string' && /(?:Z|[+-]\d{2}:\d{2})$/.test(value) ? Date.parse(value) : NaN;
        const created = timestamp(row.created_at);
        if (!Number.isFinite(created) || created > now + 30000) throw Error('Invalid announcement publication time.');
        let expires = created + 600000;
        for (const key of ['display_expires_at', 'expires_at', 'expires', 'delivery_expires_at']) {
            if (row[key] !== undefined && row[key] !== null && row[key] !== '') {
                const value = timestamp(row[key]);
                if (!Number.isFinite(value)) throw Error('Invalid announcement expiry.');
                expires = Math.min(expires, value);
            }
        }
        const timeout = row.display_timeout_seconds ?? row.display_timeout;
        if (timeout !== undefined && timeout !== 0) {
            if (!Number.isInteger(timeout) || timeout < 0 || timeout > 86400) throw Error('Invalid display timeout.');
            expires = Math.min(expires, created + timeout * 1000);
        }
        if (expires <= now || row.message_type === 'cancel') return null;
        const title = row.title || row.event || 'SLS announcement';
        const message = row.message || row.body || row.description || '';
        if (typeof title !== 'string' || title.length > 500 || typeof message !== 'string' || message.length > 16384
            || typeof row.is_test !== 'boolean') throw Error('Unsupported announcement text or test flag.');
        return {id, title, message, expires, isTest: row.is_test, image: typeof row.image_url === 'string' ? row.image_url : '', imageAuthentication: row.image_authentication === 'desktop_basic'};
    }
    async poll(request, clock = () => Date.now()) {
        const deadline = clock() + 10000, baseline = this.cursor === null;
        let cursor = this.cursor, records = 0, bytes = 0;
        const result = [], ids = new Set();
        try {
            for (let page = 0; page < 20; page++) {
                if (clock() >= deadline) throw Error('Polling deadline exceeded.');
                const response = await request(cursor, deadline);
                const data = response.data;
                if (!data || data.ok !== true || !Array.isArray(data.events) || data.events.length > 100
                    || typeof data.last_event_id !== 'string' || typeof data.has_more !== 'boolean' || typeof data.cursor_gap !== 'boolean') throw Error('Incompatible desktop polling response.');
                bytes += response.bytes || 0; records += data.events.length;
                if (bytes > 8388608 || records > 1000 || clock() >= deadline || data.cursor_gap) throw Error('Live polling history was interrupted.');
                const next = data.last_event_id || '@sls:empty';
                if (next !== '@sls:empty' && !/^[\x21-\x7e]{1,2048}$/.test(next)) throw Error('Invalid polling cursor.');
                if (baseline) { this.cursor = next; return []; }
                for (const row of data.events) {
                    const event = SlsDisplayFeed.event(row, response.serverTime);
                    if (event && !ids.has(event.id)) { result.push(event); ids.add(event.id); }
                }
                if (!data.has_more) { this.cursor = next; return result; }
                if (next === cursor) throw Error('Polling cursor did not progress.');
                cursor = next;
            }
            throw Error('Too many polling pages.');
        } catch (error) { this.reset(); throw error; }
    }
}
if (typeof module !== 'undefined' && module.exports) module.exports = SlsDisplayFeed;
if (typeof document !== 'undefined') (() => {
    const root = document.getElementById('sls-display');
    if (!root) return;
    const form = document.getElementById('display-login'), status = document.getElementById('display-status');
    const alerts = document.getElementById('display-alerts'), disconnect = document.getElementById('display-disconnect');
    const fullscreen = document.getElementById('display-fullscreen');
    const active = new Map(), receipts = new Map(), requests = new Set();
    let feed = new SlsDisplayFeed();
    let authorization = '', generation = 0, timer = null, lastWall = 0, lastMono = 0;
    let serverBase = Date.now(), monotonicBase = performance.now();
    const now = () => serverBase + performance.now() - monotonicBase;
    const dispose = value => { value.controller?.abort(); if(value.imageUrl) URL.revokeObjectURL(value.imageUrl); value.card.remove(); };
    const clearAlerts = () => { for(const value of active.values())dispose(value); active.clear(); alerts.replaceChildren(); };
    const cancelRequests = () => { for (const controller of requests) controller.abort(); requests.clear(); };
    function stop(message = 'Disconnected') {
        authorization = ''; generation++; cancelRequests(); clearTimeout(timer); feed = new SlsDisplayFeed(); receipts.clear(); clearAlerts();
        form.hidden = false; disconnect.hidden = fullscreen.hidden = true; status.textContent = message;
    }
    async function request(path, options = {}, deadline = Date.now() + 5000) {
        const controller = new AbortController(), wait = setTimeout(() => controller.abort(), Math.max(1, Math.min(5000, deadline - Date.now())));
        const current = generation, credential = authorization;
        requests.add(controller);
        const requireCurrent = () => {
            if (current !== generation || credential !== authorization) throw new DOMException('Display connection changed.', 'AbortError');
        };
        try {
            const response = await fetch('/api/sipnotify/' + path, { ...options, cache: 'no-store', credentials: 'omit', redirect: 'error',
                referrerPolicy: 'no-referrer', signal: controller.signal, headers: { ...options.headers, Authorization: credential, Accept: 'application/json', 'X-SLS-Browser-Display': '1' } });
            requireCurrent();
            if (response.status === 401 || response.status === 403) { stop('Display credentials were rejected or browser display was disabled.'); throw Error('Authentication required.'); }
            if (!response.ok) throw Error('Desktop API returned HTTP ' + response.status + '.');
            const reader = response.body.getReader(), parts = []; let bytes = 0;
            try {
                for (;;) {
                    const chunk = await reader.read(); if (chunk.done) break;
                    bytes += chunk.value.byteLength;
                    if (bytes > 1048576) { controller.abort(); throw Error('Desktop response exceeded 1 MiB.'); }
                    parts.push(chunk.value);
                }
            } finally { reader.releaseLock(); }
            const joined = new Uint8Array(bytes); let offset = 0;
            for (const part of parts) { joined.set(part, offset); offset += part.byteLength; }
            const text = new TextDecoder('utf-8', {fatal: true}).decode(joined);
            const serverTime = Date.parse(response.headers.get('Date') || '');
            if (!Number.isFinite(serverTime) || Math.abs(serverTime - Date.now()) > 300000) throw Error('Display and PBX clocks differ by more than five minutes.');
            requireCurrent();
            return { data: JSON.parse(text), bytes, serverTime };
        } finally { requests.delete(controller); clearTimeout(wait); controller.abort(); }
    }
    async function loadImage(event, value, current) {
        let timer;
        try {
            const url = new URL(event.image, location.origin);
            if(url.protocol!=='https:'||url.origin!==location.origin||url.username||url.password||url.hash)throw Error('Image origin is not permitted.');
            const headers = {Accept:'image/png'};
            if(event.imageAuthentication){
                if(url.pathname!=='/api/sipnotify/desktop/media'||url.searchParams.size!==1||url.searchParams.get('event_id')!==event.id)throw Error('Unsupported authenticated image route.');
                headers.Authorization=authorization;
            }else if(!/^\/sls_mass_notify\/[A-Za-z0-9_.-]{1,180}\.png$/.test(url.pathname)||url.search)throw Error('Unsupported image route.');
            value.controller=new AbortController();timer=setTimeout(()=>value.controller.abort(),5000);
            const response=await fetch(url.href,{headers,credentials:'omit',redirect:'error',cache:'no-store',referrerPolicy:'no-referrer',signal:value.controller.signal});
            if(!response.ok||!response.headers.get('Content-Type')?.toLowerCase().startsWith('image/png'))throw Error('Image was unavailable.');
            const reader=response.body.getReader(),parts=[];let bytes=0;
            try{for(;;){const chunk=await reader.read();if(chunk.done)break;bytes+=chunk.value.byteLength;if(bytes>5242880){value.controller.abort();throw Error('Image exceeded 5 MiB.');}parts.push(chunk.value);}}
            finally{reader.releaseLock();}
            if(current!==generation||active.get(event.id)!==value||event.expires<=now())return;
            const image=document.createElement('img');image.alt=event.title;image.referrerPolicy='no-referrer';
            value.imageUrl=URL.createObjectURL(new Blob(parts,{type:'image/png'}));image.src=value.imageUrl;
            image.addEventListener('error',()=>{image.remove();if(value.imageUrl){URL.revokeObjectURL(value.imageUrl);value.imageUrl='';}},{once:true});
            value.card.append(image);
        }catch(_){value.controller?.abort(); /* Plain text remains visible when an image cannot be loaded. */}
        finally{clearTimeout(timer);}
    }
    function show(event) {
        if (active.has(event.id) || event.expires <= now()) return;
        const card = document.createElement('article'), heading = document.createElement('h2'), body = document.createElement('p'), meta = document.createElement('p');
        heading.textContent = (event.isTest ? 'TEST · ' : '') + event.title;
        body.textContent = event.message; meta.className = 'display-meta'; meta.textContent = 'Expires ' + new Date(event.expires).toLocaleTimeString();
        card.append(heading, body, meta); alerts.prepend(card); const value={card, expires:event.expires};active.set(event.id,value);
        if(event.image)loadImage(event,value,generation);
        while (active.size > 10) { const [id, value] = active.entries().next().value; dispose(value); active.delete(id); }
        receipts.set(event.id, {expires: event.expires, attempts: 0, next: 0});
    }
    async function receiptBatch(current) {
        let count = 0; const deadline = Date.now() + 2000;
        for (const [id, receipt] of receipts) {
            if (receipt.expires <= now()) { receipts.delete(id); continue; }
            if (count >= 20 || Date.now() >= deadline || current !== generation) break;
            if (receipt.next > now()) continue;
            count++;
            try {
                const response = await request('desktop/ack', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({event_id: id})}, deadline);
                if (current !== generation) return;
                if (response.data.ok !== true || response.data.event_id !== id) throw Error('Receipt not confirmed.');
                receipts.delete(id);
            } catch (_) { receipt.attempts++; receipt.next = now() + Math.min(30000, 5000 * receipt.attempts); }
        }
    }
    async function tick(current) {
        if (current !== generation || !authorization || document.hidden) return;
        const wall = Date.now(), mono = performance.now();
        if (lastWall && (wall - lastWall > 15000 || Math.abs((wall - lastWall) - (mono - lastMono)) > 3000)) { feed.reset(); clearAlerts(); }
        lastWall = wall; lastMono = mono;
        const currentFeed = feed;
        try {
            const events = await currentFeed.poll(async (cursor, deadline) => {
                const query = cursor === null ? '' : '?last_event_id=' + encodeURIComponent(cursor);
                const response = await request('desktop' + query, {}, deadline);
                serverBase = response.serverTime; monotonicBase = performance.now(); return response;
            });
            if (current !== generation) return;
            events.forEach(show); status.textContent = 'Connected · listening for new announcements';
            await receiptBatch(current);
        } catch (error) {
            if (current === generation && authorization) { clearAlerts(); status.textContent = 'Reconnecting · missed announcements will be skipped. ' + error.message; }
        }
        if (current === generation && authorization) timer = setTimeout(() => tick(current), Math.max(100, 5000 - (Date.now() - wall)));
    }
    form.addEventListener('submit', event => {
        event.preventDefault(); stop();
        const bytes = new TextEncoder().encode(form.elements.username.value + ':' + form.elements.password.value);
        authorization = 'Basic ' + btoa(String.fromCharCode(...bytes)); form.elements.password.value = '';
        form.hidden = true; disconnect.hidden = fullscreen.hidden = false; lastWall = lastMono = 0;
        status.textContent = 'Connecting…'; tick(generation);
    });
    disconnect.addEventListener('click', () => stop());
    fullscreen.addEventListener('click', () => root.requestFullscreen?.().catch(() => { status.textContent = 'Full screen was not permitted by this browser.'; }));
    document.addEventListener('visibilitychange', () => { generation++; cancelRequests(); clearTimeout(timer); feed = new SlsDisplayFeed(); clearAlerts(); if (!document.hidden && authorization) tick(generation); });
    setInterval(() => { for (const [id, event] of active) if (event.expires <= now()) { dispose(event); active.delete(id); } }, 500);
    window.addEventListener('pagehide', () => stop());
})();
