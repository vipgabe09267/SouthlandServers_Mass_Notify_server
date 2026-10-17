'use strict';
const pendingDisplays = new Map();
self.addEventListener('push', event => {
    event.waitUntil((async () => {
        let alert;
        try { alert = event.data.json(); } catch (_) { return; }
        const now = Math.floor(Date.now() / 1000);
        if (!alert || typeof alert.event_id !== 'string' || !/^[A-Za-z0-9_-]{1,128}$/.test(alert.event_id)
            || typeof alert.title !== 'string' || typeof alert.message !== 'string'
            || typeof alert.is_test !== 'boolean' || !Number.isSafeInteger(alert.expires_at)
            || alert.expires_at <= now || alert.expires_at > now + 600) return;
        const ledger = await caches.open('sls-browser-push-v1');
        const key = new Request(new URL('/mass-notify/subscriber.php?event=' + alert.event_id, self.location.origin));
        if (await ledger.match(key)) return;
        if (pendingDisplays.has(alert.event_id)) return pendingDisplays.get(alert.event_id);
        const display = (async () => {
            if (alert.expires_at <= Math.floor(Date.now() / 1000)) return;
            await self.registration.showNotification('SLS · ' + (alert.is_test ? 'TEST · ' : '') + alert.title,
                {body: alert.message, tag: alert.event_id, data: {expires_at: alert.expires_at}});
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
    event.waitUntil(clients.openWindow('/mass-notify/subscriber.php'));
});
