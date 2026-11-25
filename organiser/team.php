<?php
// Manage organiser accounts and change your own password.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$me = current_organiser();
if (!$me) {
    redirect('login.php');
}

$errors = [];
$notice = '';
$username = $displayName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!csrf_check()) {
        $errors[] = 'Your session expired — please try again.';
    } elseif ($action === 'unlock') {
        if (admin_word_matches((string) ($_POST['admin_word'] ?? ''))) {
            admin_unlock();
            $notice = 'Team management unlocked.';
        } else {
            $errors[] = admin_word_configured()
                ? 'Wrong admin access word.'
                : "No admin access word is set. Set 'admin_access_word' in config.php first.";
        }
    } elseif ($action === 'lock') {
        admin_lock();
        $notice = 'Team management locked.';
    } elseif (in_array($action, ['add', 'remove'], true) && !admin_unlocked()) {
        $errors[] = 'Enter the admin access word to add or remove organisers.';
    } elseif ($action === 'add') {
        $username    = trim((string) ($_POST['username'] ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $password    = (string) ($_POST['password'] ?? '');
        $errors = validate_organiser($username, $displayName, $password, (string) ($_POST['password2'] ?? ''));
        $exists = db()->prepare('SELECT 1 FROM organisers WHERE username = ?');
        $exists->execute([$username]);
        if (!$errors && $exists->fetchColumn()) {
            $errors[] = 'That username is already taken.';
        }
        if (!$errors) {
            save_organiser($username, $displayName, $password);
            $notice = "Added {$displayName}. Send them the organiser link and their password.";
            $username = $displayName = '';
        }
    } elseif ($action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $me['id']) {
            $errors[] = "You can't remove yourself.";
        } else {
            // Cascades to their devices and push subscriptions.
            db()->prepare('DELETE FROM organisers WHERE id = ?')->execute([$id]);
            $notice = 'Organiser removed. Their devices will no longer get alerts.';
        }
    } elseif ($action === 'password') {
        $stmt = db()->prepare('SELECT password_hash FROM organisers WHERE id = ?');
        $stmt->execute([$me['id']]);
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $stmt->fetchColumn())) {
            $errors[] = 'Your current password is wrong.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== ($_POST['new_password2'] ?? '')) {
            $errors[] = 'New passwords do not match.';
        } else {
            db()->prepare('UPDATE organisers SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            $notice = 'Password changed.';
        }
    }
}

$team = db()->query(
    'SELECT o.id, o.username, o.display_name,
            (SELECT COUNT(*) FROM subscriptions s JOIN devices d ON d.id = s.device_id WHERE d.organiser_id = o.id) AS alert_devices
       FROM organisers o ORDER BY o.display_name COLLATE NOCASE'
)->fetchAll();

$csrf = csrf_token();
$admin = admin_unlocked();
page_start('Team — ' . app_name(), '../');
?>
<?php organiser_nav($me, 'team'); ?>

<?php if ($errors): ?>
    <div class="alert" role="alert"><?php foreach ($errors as $e): ?><p><?= h($e) ?></p><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($notice): ?>
    <div class="notice" role="status"><p><?= h($notice) ?></p></div>
<?php endif; ?>

<section class="card">
    <h2>Organisers</h2>
    <ul class="knocks">
        <?php foreach ($team as $t): ?>
            <li class="knock">
                <div>
                    <strong><?= h($t['display_name']) ?></strong>
                    <span class="muted">· <?= h($t['username']) ?></span>
                    <p><?= (int) $t['alert_devices'] > 0
                        ? '🔔 Alerts on ' . (int) $t['alert_devices'] . ' device' . ((int) $t['alert_devices'] === 1 ? '' : 's')
                        : '🔕 No devices with alerts' ?></p>
                </div>
                <?php if ((int) $t['id'] === (int) $me['id']): ?>
                    <span class="badge">You</span>
                <?php elseif ($admin): ?>
                    <form method="post" class="confirm-remove" data-name="<?= h($t['display_name']) ?>">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <button class="btn secondary" type="submit">Remove</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<?php if ($admin): ?>
<section class="card">
    <h2>Add an organiser</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="add">
        <?php organiser_fields($username, $displayName); ?>
        <button class="btn big" type="submit">Add organiser</button>
    </form>
    <form method="post" class="small">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="lock">
        <p><button class="link" type="submit">Lock team management</button></p>
    </form>
</section>
<?php else: ?>
<section class="card">
    <h2>🔒 Manage the team</h2>
    <p class="muted">Enter the admin access word to add or remove organisers.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="unlock">
        <label for="admin_word">Admin access word</label>
        <input id="admin_word" name="admin_word" type="password" required autocomplete="off">
        <button class="btn big" type="submit">Unlock</button>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <h2>Change my password</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="password">
        <label for="cp">Current password</label>
        <input id="cp" name="current_password" type="password" required autocomplete="current-password">
        <label for="np">New password</label>
        <input id="np" name="new_password" type="password" required minlength="8" autocomplete="new-password">
        <label for="np2">Confirm new password</label>
        <input id="np2" name="new_password2" type="password" required minlength="8" autocomplete="new-password">
        <button class="btn big" type="submit">Change password</button>
    </form>
</section>

<script>
document.querySelectorAll('.confirm-remove').forEach((f) => f.addEventListener('submit', (e) => {
    if (!confirm('Remove ' + f.dataset.name + '? Their phones will stop getting door alerts.')) e.preventDefault();
}));
</script>
<?php page_end();
