<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

if (organiser_count() === 0) {
    redirect('setup.php'); // first run
}
if (current_organiser()) {
    redirect('./');
}

$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT * FROM organisers WHERE username = ?');
    $stmt->execute([$username]);
    $org = $stmt->fetch();

    if (!csrf_check()) {
        $error = 'Your session expired — please try again.';
    } elseif ($org && password_verify($password, $org['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['organiser_id'] = (int) $org['id'];
        $_SESSION['device_token'] = create_device((int) $org['id']);
        redirect('./');
    } else {
        sleep(1); // slow down guessing
        $error = 'Wrong username or password.';
    }
}

page_start('Organiser login — ' . app_name(), '../');
?>
<section class="card narrow">
    <div class="door small-door" aria-hidden="true">🔑</div>
    <h1>Organiser login</h1>
    <p class="lead">Log in on your phone to get an alert whenever someone knocks.</p>

    <?php if ($error): ?><div class="alert" role="alert"><p><?= h($error) ?></p></div><?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <label for="username">Username</label>
        <input id="username" name="username" type="text" required autocomplete="username" autocapitalize="none" value="<?= h($username) ?>">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">
        <button class="btn big" type="submit">Log in</button>
    </form>
</section>
<?php page_end();
