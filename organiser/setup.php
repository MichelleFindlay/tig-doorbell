<?php
// First-run setup: checks the server and creates the first organiser.
// Only works while there are no organisers; after that it redirects to the login page.
declare(strict_types=1);

// Environment checks run before bootstrap so missing extensions give a readable message.
$checks = [
    'PHP 8.0 or newer'        => version_compare(PHP_VERSION, '8.0.0', '>='),
    'PDO SQLite extension'    => extension_loaded('pdo_sqlite'),
    'OpenSSL extension'       => extension_loaded('openssl'),
    'cURL extension'          => extension_loaded('curl'),
    'mbstring extension'      => extension_loaded('mbstring'),
    'data folder is writable' => is_writable(dirname(__DIR__) . '/data'),
];
if (in_array(false, $checks, true)) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>TIG Door setup</title>'
        . '<body style="font:16px system-ui;max-width:560px;margin:3em auto;padding:0 16px">'
        . '<h1>TIG Door — server check</h1><ul>';
    foreach ($checks as $label => $ok) {
        echo '<li>' . ($ok ? '✅' : '❌') . ' ' . htmlspecialchars($label) . '</li>';
    }
    echo '</ul><p>Fix the ❌ items (usually in your hosting control panel: PHP version / extensions, '
        . 'and folder permissions 755 or 775 for <code>data</code>), then reload this page.</p>'
        . '<p>Currently running PHP ' . htmlspecialchars(PHP_VERSION) . '.</p></body>';
    exit;
}

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

if (organiser_count() > 0) {
    redirect('login.php');
}

$errors = [];
$username = $displayName = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && admin_word_configured()) {
    $username    = trim((string) ($_POST['username'] ?? ''));
    $displayName = trim((string) ($_POST['display_name'] ?? ''));
    $password    = (string) ($_POST['password'] ?? '');

    if (!csrf_check()) {
        $errors[] = 'Your session expired — please try again.';
    } elseif (!admin_word_matches((string) ($_POST['admin_word'] ?? ''))) {
        $errors[] = 'Wrong admin access word (it is set in config.php).';
    }
    $errors = array_merge($errors, validate_organiser($username, $displayName, $password, (string) ($_POST['password2'] ?? '')));

    if (!$errors && organiser_count() === 0) {
        $id = save_organiser($username, $displayName, $password);
        WebPush::keys(); // generate the push keys now
        session_regenerate_id(true);
        $_SESSION['organiser_id'] = $id;
        $_SESSION['device_token'] = create_device($id);
        admin_unlock(); // so they can add the rest of the team straight away
        redirect('team.php');
    }
}

$csrf = csrf_token();
page_start('Set up ' . app_name(), '../');
?>
<section class="card narrow">
    <div class="door small-door" aria-hidden="true">🛠️</div>
    <h1>Set up <?= h(app_name()) ?></h1>
    <p class="lead">Create the first organiser account. You can add the rest of the team after logging in.</p>

    <?php if (!is_https() && !in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true) && !str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'localhost:')): ?>
        <div class="alert"><p>⚠️ This site isn't on HTTPS. Push notifications need HTTPS, so switch on SSL for this subdomain in your hosting panel (e.g. Let's Encrypt).</p></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert" role="alert"><?php foreach ($errors as $e): ?><p><?= h($e) ?></p><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if (!admin_word_configured()): ?>
        <div class="alert" role="alert">
            <p>🔒 Set an admin access word first. Open <code>config.php</code>, change
            <code>'admin_access_word' =&gt; 'change-me'</code> to your own secret word (8+ characters),
            upload it, then reload this page.</p>
        </div>
    <?php else: ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label for="admin_word">Admin access word <span class="muted">(from config.php)</span></label>
        <input id="admin_word" name="admin_word" type="password" required autocomplete="off">
        <?php organiser_fields($username, $displayName); ?>
        <button class="btn big" type="submit">Create account</button>
    </form>
    <?php endif; ?>
</section>
<?php page_end();
