<?php
// Called by the service worker when a push arrives, to fetch what to display.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$device = require_device();
$now = time();

// Anyone still waiting (last 30 min), plus knocks answered in the last 90s so other
// organisers' notifications update to "X is getting the door".
$stmt = db()->prepare(
    "SELECT * FROM knocks
      WHERE (status = 'waiting'  AND created_at  > ?)
         OR (status = 'answered' AND answered_at > ?)
      ORDER BY created_at DESC LIMIT 10"
);
$stmt->execute([$now - 1800, $now - 90]);

$test = (int) $device['test_pending'] === 1;
if ($test) {
    db()->prepare('UPDATE devices SET test_pending = 0 WHERE id = ?')->execute([$device['id']]);
}

json_response([
    'event'  => config('event_name'),
    'app'    => app_name(),
    'test'   => $test,
    'knocks' => array_map('knock_public', $stmt->fetchAll()),
]);
