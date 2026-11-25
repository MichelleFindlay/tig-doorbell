/* Door knocker service worker — turns (payload-less) pushes into door alerts. */
const TOKEN = new URL(self.location.href).searchParams.get('t') || '';
const BASE = new URL('./', self.location.href).href;
const DASHBOARD = BASE + 'organiser/';
const ICON = BASE + 'assets/icon-192.png';
const BADGE = BASE + 'assets/badge-96.png';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

function api(path, opts = {}) {
    return fetch(BASE + 'api/' + path, {
        ...opts,
        cache: 'no-store',
        headers: { 'X-Token': TOKEN, 'Content-Type': 'application/json' },
    });
}

self.addEventListener('push', (event) => event.waitUntil(handlePush()));

async function handlePush() {
    const reg = self.registration;
    let data;
    try {
        const r = await api('latest.php');
        if (!r.ok) throw new Error('HTTP ' + r.status);
        data = await r.json();
    } catch (e) {
        // Couldn't fetch details — still raise the alarm.
        return reg.showNotification('🚪 Someone is at the door', {
            body: 'Open the organiser page for details.',
            tag: 'knock-generic',
            icon: ICON, badge: BADGE,
            requireInteraction: true,
            vibrate: [300, 100, 300, 100, 300],
            data: { url: DASHBOARD },
        });
    }

    const jobs = [];

    if (data.test) {
        jobs.push(reg.showNotification('🔔 Test alert', {
            body: "Door alerts are working on this device.",
            tag: 'tig-test', icon: ICON, badge: BADGE,
            data: { url: DASHBOARD },
        }));
    }

    for (const k of data.knocks) {
        const tag = 'knock-' + k.id;
        if (k.status === 'waiting') {
            const time = new Date(k.created_at * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            jobs.push(reg.showNotification(`🚪 ${k.name} is at the door`, {
                body: `Here for ${k.event || data.event} · ${time}` + (k.message ? `\n“${k.message}”` : ''),
                tag, renotify: true, requireInteraction: true,
                icon: ICON, badge: BADGE,
                vibrate: [300, 100, 300, 100, 300],
                actions: [{ action: 'ack', title: "I'm on my way" }],
                data: { id: k.id, url: DASHBOARD },
            }));
        } else {
            // Someone else answered — quietly replace any alert still showing for this visitor.
            jobs.push(reg.showNotification(`✔ ${k.answered_by || 'An organiser'} is getting the door`, {
                body: `${k.name} is being looked after.`,
                tag, silent: true, icon: ICON, badge: BADGE,
                data: { url: DASHBOARD },
            }));
        }
    }

    if (!jobs.length) {
        // Browsers require every push to show something.
        jobs.push(reg.showNotification(data.app || 'Door', {
            body: 'All visitors have been let in.', tag: 'tig-status', silent: true,
            icon: ICON, badge: BADGE, data: { url: DASHBOARD },
        }));
    }
    await Promise.all(jobs);
}

self.addEventListener('notificationclick', (event) => {
    const n = event.notification;
    n.close();

    if (event.action === 'ack' && n.data && n.data.id) {
        event.waitUntil((async () => {
            const r = await api('ack.php', { method: 'POST', body: JSON.stringify({ id: n.data.id }) });
            const d = await r.json().catch(() => ({}));
            const who = d.knock && d.knock.answered_by;
            await self.registration.showNotification(
                r.ok ? '👍 Thanks — the visitor knows you\'re coming' : `${who || 'Someone'} is already on it`,
                { tag: 'knock-' + n.data.id, silent: true, icon: ICON, badge: BADGE, data: { url: DASHBOARD } }
            );
        })());
        return;
    }

    event.waitUntil((async () => {
        const url = (n.data && n.data.url) || DASHBOARD;
        const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const existing = wins.find((w) => w.url.startsWith(DASHBOARD));
        if (existing) return existing.focus();
        return self.clients.openWindow(url);
    })());
});
