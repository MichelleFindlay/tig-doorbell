<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$org = current_organiser();
if (!$org) {
    redirect('login.php');
}

// Make sure this browser has a live device token (it may have been revoked).
$token = $_SESSION['device_token'] ?? '';
$stmt = db()->prepare('SELECT 1 FROM devices WHERE token_hash = ?');
$stmt->execute([hash('sha256', $token)]);
if ($token === '' || !$stmt->fetchColumn()) {
    $token = $_SESSION['device_token'] = create_device((int) $org['id']);
}

$event = config('event_name');
page_start(app_name() . ' — Organisers', '../');
?>
<?php organiser_nav($org, 'door'); ?>

<section class="card" id="pushCard">
    <h2>Door alerts on this device</h2>
    <p id="pushState" class="muted">Checking…</p>
    <div class="row">
        <button class="btn" id="enable" hidden>Enable notifications</button>
        <button class="btn secondary" id="test" hidden>Send test alert</button>
        <button class="btn secondary" id="disable" hidden>Turn off</button>
    </div>
    <div id="iosHint" class="ios-steps" hidden>
        <ol>
            <li>Make sure this page is open in <strong>Safari</strong>.</li>
            <li>Tap the <strong>Share</strong> button (square with an arrow ↑).</li>
            <li>Choose <strong>Add to Home Screen</strong>, then <strong>Add</strong>.</li>
            <li>Close Safari and open <strong><?= h(app_name()) ?></strong> from your home screen.</li>
            <li>Log in again, then tap <strong>Enable notifications</strong> and <strong>Allow</strong>.</li>
        </ol>
        <p class="muted">Needs iOS 16.4 or newer.</p>
    </div>
</section>

<?php $todayEvents = events_on(new DateTimeImmutable('today')); $openIds = array_column(events_open_now(), 'id'); ?>
<section class="card">
    <div class="topbar" style="margin:0 0 8px">
        <h2 style="margin:0">Today's events</h2>
        <a href="events.php" class="link">Manage events</a>
    </div>
    <?php if (!$todayEvents): ?>
        <p class="muted">No events scheduled today. <a href="events.php?new">Add one</a></p>
    <?php else: ?>
        <ul class="knocks">
        <?php foreach ($todayEvents as $e): ?>
            <li class="knock">
                <div><strong><?= h($e['title']) ?></strong>
                    <span class="muted">· <?= h($e['start_time']) ?>–<?= h($e['end_time']) ?><?= $e['location'] ? ' · ' . h($e['location']) : '' ?></span></div>
                <?php if (in_array($e['id'], $openIds)): ?><span class="badge">● Door open</span><?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card">
    <h2>At the door</h2>
    <ul class="knocks" id="knocks"><li class="muted">Loading…</li></ul>
</section>

<script>
const TOKEN = <?= json_encode($token) ?>;
const VAPID_KEY = <?= json_encode(WebPush::publicKey()) ?>;
const ROOT = new URL('../', location.href);
const APP_NAME = <?= json_encode(app_name()) ?>;
</script>
<script src="../assets/dashboard.js?v=<?= asset_version('assets/dashboard.js') ?>"></script>
<?php page_end();
