/* global TOKEN, VAPID_KEY, ROOT, APP_NAME */
(() => {
    const $ = (id) => document.getElementById(id);
    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

    async function api(path, opts = {}) {
        const r = await fetch(new URL('api/' + path, ROOT), {
            ...opts,
            cache: 'no-store',
            headers: { 'X-Token': TOKEN, 'Content-Type': 'application/json' },
        });
        const data = await r.json().catch(() => ({}));
        return { ok: r.ok, status: r.status, data };
    }

    // ------------------------------------------------------------ push setup

    const b64urlToBytes = (s) => {
        const b64 = (s + '='.repeat((4 - (s.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
    };
    const bytesToB64url = (buf) =>
        btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

    async function registerWorker() {
        // The device token rides in the worker URL so it can authenticate when a push wakes it.
        await navigator.serviceWorker.register(new URL('sw.js?t=' + encodeURIComponent(TOKEN), ROOT), {
            scope: ROOT.pathname,
        });
        return navigator.serviceWorker.ready;
    }

    async function subscribe() {
        const reg = await registerWorker();
        let sub = await reg.pushManager.getSubscription();
        if (sub && sub.options && sub.options.applicationServerKey &&
            bytesToB64url(sub.options.applicationServerKey) !== VAPID_KEY) {
            await sub.unsubscribe(); // server keys changed
            sub = null;
        }
        if (!sub) {
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: b64urlToBytes(VAPID_KEY),
            });
        }
        const res = await api('subscribe.php', { method: 'POST', body: JSON.stringify(sub) });
        if (!res.ok) throw new Error(res.data.error || 'Could not save subscription');
    }

    async function unsubscribe() {
        if (!supported) return;
        const reg = await navigator.serviceWorker.getRegistration(ROOT.pathname);
        const sub = reg && (await reg.pushManager.getSubscription());
        if (sub) {
            await api('subscribe.php', { method: 'DELETE', body: JSON.stringify({ endpoint: sub.endpoint }) });
            await sub.unsubscribe();
        }
    }

    function setState(text, buttons = []) {
        $('pushState').textContent = text;
        for (const id of ['enable', 'test', 'disable']) $(id).hidden = !buttons.includes(id);
    }

    async function refreshState() {
        if (!supported) {
            if (isIOS && !isStandalone) {
                setState('📱 On iPhone/iPad, alerts only work from the home-screen app — see the steps below.');
            } else if (isIOS) {
                setState('This iPhone/iPad needs iOS 16.4 or newer for alerts. Update iOS in Settings → General → Software Update.');
            } else {
                setState("This browser can't receive push alerts. Keep this page open to see knocks below.");
            }
            $('iosHint').hidden = !isIOS || isStandalone;
            return;
        }
        const reg = await navigator.serviceWorker.getRegistration(ROOT.pathname);
        const sub = reg && (await reg.pushManager.getSubscription());
        if (Notification.permission === 'denied') {
            setState('Notifications are blocked for this site. Allow them in your browser/site settings, then reload.');
        } else if (Notification.permission === 'granted' && sub) {
            setState("✅ Alerts are on. You'll be notified when someone knocks — even with this page closed.", ['test', 'disable']);
        } else {
            setState('Alerts are off on this device.', ['enable']);
        }
    }

    $('enable').addEventListener('click', async () => {
        if (!supported) { // safety net: never touch Notification where it doesn't exist
            refreshState();
            return;
        }
        $('enable').disabled = true;
        try {
            const perm = await Notification.requestPermission();
            if (perm === 'granted') await subscribe();
        } catch (e) {
            alert('Could not enable notifications: ' + e.message);
        } finally {
            $('enable').disabled = false;
            refreshState();
        }
    });

    $('test').addEventListener('click', async () => {
        $('test').disabled = true;
        const res = await api('test.php', { method: 'POST' });
        if (!res.ok) alert(res.data.error || 'Test failed');
        setTimeout(() => ($('test').disabled = false), 3000);
    });

    $('disable').addEventListener('click', async () => {
        await unsubscribe();
        refreshState();
    });


    // ------------------------------------------------------------ knock list

    let seen = null;
    let clockSkew = 0;

    const ago = (ts) => {
        const s = Math.max(0, Math.round(Date.now() / 1000 + clockSkew - ts));
        if (s < 45) return 'just now';
        if (s < 3600) return Math.round(s / 60) + ' min ago';
        if (s < 86400) return Math.round(s / 3600) + ' h ago';
        return new Date(ts * 1000).toLocaleString();
    };

    function beep() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            [0, 0.25].forEach((t) => {
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.frequency.value = 880;
                g.gain.setValueAtTime(0.2, ctx.currentTime + t);
                g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + t + 0.2);
                o.connect(g).connect(ctx.destination);
                o.start(ctx.currentTime + t);
                o.stop(ctx.currentTime + t + 0.2);
            });
        } catch (_) { /* audio needs a prior tap on some browsers */ }
    }

    function render(knocks) {
        const list = $('knocks');
        list.replaceChildren();
        if (!knocks.length) {
            const li = document.createElement('li');
            li.className = 'muted';
            li.textContent = 'Nobody yet. Alerts will appear here and on your phone.';
            list.append(li);
        }
        for (const k of knocks) {
            const li = document.createElement('li');
            li.className = 'knock ' + k.status;

            const info = document.createElement('div');
            const name = document.createElement('strong');
            name.textContent = k.name;
            const when = document.createElement('span');
            when.className = 'muted';
            when.textContent = ' · ' + ago(k.created_at);
            info.append(name, when);
            if (k.event) {
                const ev = document.createElement('p');
                ev.textContent = '🎟 ' + k.event;
                info.append(ev);
            }
            if (k.message) {
                const p = document.createElement('p');
                p.textContent = k.message;
                info.append(p);
            }
            li.append(info);

            if (k.status === 'waiting') {
                const btn = document.createElement('button');
                btn.className = 'btn';
                btn.textContent = "I'm on my way";
                btn.addEventListener('click', async () => {
                    btn.disabled = true;
                    const res = await api('ack.php', { method: 'POST', body: JSON.stringify({ id: k.id }) });
                    if (!res.ok && res.data.knock) alert((res.data.knock.answered_by || 'Someone') + ' is already on it.');
                    load();
                });
                li.append(btn);
            } else {
                const badge = document.createElement('span');
                badge.className = 'badge';
                badge.textContent = '✔ ' + (k.answered_by || 'Answered');
                li.append(badge);
            }
            list.append(li);
        }

        const waiting = knocks.filter((k) => k.status === 'waiting');
        document.title = (waiting.length ? `(${waiting.length}) ` : '') + APP_NAME + ' — Organisers';
        const ids = new Set(waiting.map((k) => k.id));
        if (seen && [...ids].some((id) => !seen.has(id))) beep();
        seen = ids;
    }

    async function load() {
        try {
            const res = await api('knocks.php');
            if (res.status === 401) {
                location.reload(); // device token revoked — server issues a new one
                return;
            }
            if (res.ok) {
                clockSkew = res.data.now - Date.now() / 1000;
                render(res.data.knocks);
            }
        } catch (_) { /* offline — retry */ }
    }

    // ------------------------------------------------------------ start

    (async () => {
        if (supported && Notification.permission === 'granted') {
            // Quietly re-sync: keeps the worker's token and the server's subscription current.
            try { await subscribe(); } catch (e) { console.warn(e); }
        }
        refreshState();
    })();

    load();
    setInterval(load, 5000);
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && load());
})();
