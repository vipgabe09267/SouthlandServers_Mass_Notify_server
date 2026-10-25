'use strict';
const pendingDisplays = new Map();
function receiptProof(value) {
    return value && typeof value === 'object' && !Array.isArray(value)
        && Object.keys(value).length === 5 && typeof value.subscriber_id === 'string' && /^sub_[a-f0-9]{24}$/.test(value.subscriber_id)
        && Number.isSafeInteger(value.expires_at) && ['event_key','endpoint_id','token'].every(key => typeof value[key] === 'string' && /^[a-f0-9]{64}$/.test(value[key]));
}
async function softwareReceipt(proof, stage) {
    if (!receiptProof(proof)) return;
    const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 3000);
    try {
        await fetch('/mass-notify/subscriber.php', {method: 'POST', credentials: 'omit', cache: 'no-store', redirect: 'error',
            referrerPolicy: 'no-referrer', signal: controller.signal, headers: {'X-SLS-Browser-Receipt':'1'},
            body: new URLSearchParams({subscriber_action:'push_receipt', ...proof, stage})});
    } catch (_) { /* Missing software evidence never causes an alert replay. */ }
    finally { clearTimeout(timer); }
}
self.addEventListener('push', event => {
    event.waitUntil((async () => {
        let alert;
        try { alert = event.data.json(); } catch (_) { return; }
        const now = Math.floor(Date.now() / 1000);
        if (!alert || typeof alert.event_id !== 'string' || !/^[A-Za-z0-9_-]{1,128}$/.test(alert.event_id)
            || typeof alert.title !== 'string' || typeof alert.message !== 'string'
            || typeof alert.is_test !== 'boolean' || !Number.isSafeInteger(alert.expires_at)
            || alert.expires_at <= now || alert.expires_at > now + 600) return;
        if (alert.browser_receipt !== undefined && (!receiptProof(alert.browser_receipt) || alert.browser_receipt.expires_at !== alert.expires_at)) return;
        if (alert.incident !== undefined && (!alert.incident || alert.incident.schema !== 1
            || typeof alert.incident.incident_id !== 'string' || !/^inc_[a-f0-9]{32}$/.test(alert.incident.incident_id)
            || !Number.isSafeInteger(alert.incident.sequence) || alert.incident.sequence < 1 || alert.incident.sequence > 250
            || !['initial','update','all_clear','escalation'].includes(alert.incident.kind)
            || alert.incident.is_test !== alert.is_test)) return;
        const ledger = await caches.open('sls-browser-push-v1');
        const key = new Request(new URL('/mass-notify/subscriber.php?event=' + alert.event_id, self.location.origin));
        if (await ledger.match(key)) return;
        if (pendingDisplays.has(alert.event_id)) return pendingDisplays.get(alert.event_id);
        const display = (async () => {
            if (alert.expires_at <= Math.floor(Date.now() / 1000)) return;
            const received = softwareReceipt(alert.browser_receipt, 'received');
            await self.registration.showNotification('SLS · ' + (alert.is_test ? 'TEST · ' : '') + alert.title,
                {body: alert.message, tag: alert.event_id, data: {expires_at: alert.expires_at, event_id: alert.event_id,
                    browser_receipt: receiptProof(alert.browser_receipt) ? alert.browser_receipt : null}});
            await Promise.allSettled([received, softwareReceipt(alert.browser_receipt, 'displayed')]);
            await ledger.put(key, new Response(String(alert.expires_at)));
        })();
        pendingDisplays.set(alert.event_id, display);
        try { await display; } finally { pendingDisplays.delete(alert.event_id); }
        const keys = await ledger.keys();
        for (let i = 0; i < keys.length; i++) {
            const stored = await ledger.match(keys[i]);
            if (!stored || i < keys.length - 100 || Number(await stored.text()) <= now) await ledger.delete(keys[i]);
        }
    })());
});
self.addEventListener('notificationclick', event => {
    event.notification.close();
    if (!event.notification.data || !Number.isSafeInteger(event.notification.data.expires_at) || event.notification.data.expires_at <= Math.floor(Date.now() / 1000)) return;
    const id = event.notification.data.event_id;
    if (typeof id !== 'string' || !/^[A-Za-z0-9_-]{1,128}$/.test(id)) return;
    event.waitUntil(Promise.allSettled([softwareReceipt(event.notification.data.browser_receipt, 'clicked'),
        clients.openWindow('/mass-notify/subscriber.php?event=' + encodeURIComponent(id))]));
});
