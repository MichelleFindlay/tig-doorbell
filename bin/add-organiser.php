<?php
// Usage: php bin/add-organiser.php <username> "<Display Name>"
// Creates an organiser, or resets the password if the username exists.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../src/bootstrap.php';

[$script, $username, $displayName] = $argv + [null, null, null];
if (!$username) {
    fwrite(STDERR, "Usage: php bin/add-organiser.php <username> \"<Display Name>\"\n");
    exit(1);
}
$displayName = $displayName ?: $username;

function ask_hidden(string $prompt): string
{
    echo $prompt;
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $value;
}

$password = ask_hidden('Password: ');
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}
if (ask_hidden('Confirm password: ') !== $password) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

save_organiser($username, $displayName, $password);

WebPush::keys(); // generate VAPID keys up front
echo "Organiser '{$username}' ({$displayName}) saved.\n";
