<?php
// Saves (POST) or removes (DELETE) this device's push subscription.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$device = require_device();
$in = json_input();
$endpoint = (string) ($in['endpoint'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    db()->prepare('DELETE FROM subscriptions WHERE endpoint = ? AND device_id = ?')->execute([$endpoint, $device['id']]);
    json_response(['ok' => true]);
}
require_method('POST');

if (!push_host_allowed($endpoint) || strlen($endpoint) > 1000) {
    json_response(['error' => 'Unsupported push service. Add its host to push_hosts in config.php.'], 422);
}

db()->prepare(
    'INSERT INTO subscriptions(device_id, endpoint, p256dh, auth, created_at) VALUES(?, ?, ?, ?, ?)
     ON CONFLICT(endpoint) DO UPDATE SET device_id = excluded.device_id, p256dh = excluded.p256dh, auth = excluded.auth'
)->execute([
    $device['id'],
    $endpoint,
    substr((string) ($in['keys']['p256dh'] ?? ''), 0, 200),
    substr((string) ($in['keys']['auth'] ?? ''), 0, 100),
    time(),
]);
json_response(['ok' => true]);
