<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

$token = (string) ($_GET['k'] ?? '');
$knock = $token !== '' ? find_knock_by_visitor_token($token) : null;
if (!$knock) {
    redirect('index.php');
}

page_start(config('event_name') . ' — Someone is coming');
?>
<section class="card" id="status"
         data-token="<?= h($token) ?>"
         data-ring-after="<?= (int) config('ring_again_after') ?>">
    <div class="door knocking" id="icon" aria-hidden="true">🚪</div>
    <h1 id="headline">Thanks, <?= h($knock['name']) ?>!</h1>
    <p class="lead" id="detail">The organisers have been notified that you're at the door. Hang tight — someone will be with you shortly.</p>

    <button type="button" class="btn secondary" id="ring" hidden>Knock again</button>
    <p class="muted small" id="ringNote"></p>
    <p class="small"><a href="index.php">Not you? Start again</a></p>
</section>

<script>
(() => {
    const el = document.getElementById('status');
    const token = el.dataset.token;
    const ringAfter = Number(el.dataset.ringAfter) * 1000;
    const ring = document.getElementById('ring');
    const note = document.getElementById('ringNote');
    let lastRing = Date.now();
    let done = false;

    function render(s) {
        if (s.status === 'answered') {
            done = true;
            document.getElementById('icon').textContent = '🙌';
            document.getElementById('icon').classList.remove('knocking');
            document.getElementById('headline').textContent =
                (s.answered_by ? s.answered_by + ' is' : 'Someone is') + ' on the way!';
            document.getElementById('detail').textContent = "They'll be at the door in a moment.";
            ring.hidden = true;
            note.textContent = '';
            return;
        }
        ring.hidden = Date.now() - lastRing < ringAfter;
    }

    async function poll() {
        if (done) return;
        try {
            const r = await fetch('api/status.php?k=' + encodeURIComponent(token), { cache: 'no-store' });
            if (r.ok) render(await r.json());
        } catch (e) { /* offline — try again shortly */ }
        setTimeout(poll, 4000);
    }

    ring.addEventListener('click', async () => {
        ring.disabled = true;
        try {
            const r = await fetch('api/ring.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ k: token })
            });
            const data = await r.json();
            note.textContent = data.message || '';
            lastRing = Date.now();
            ring.hidden = true;
            if (data.status) render(data);
        } finally {
            ring.disabled = false;
        }
    });

    poll();
})();
</script>
<?php page_end();
