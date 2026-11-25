<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    if (!empty($_SESSION['device_token'])) {
        // Removing the device also removes its push subscriptions, so alerts stop on this phone.
        db()->prepare('DELETE FROM devices WHERE token_hash = ?')->execute([hash('sha256', $_SESSION['device_token'])]);
    }
    $_SESSION = [];
    session_destroy();
}
redirect('login.php');
