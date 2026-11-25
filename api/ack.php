<?php
// Organiser says "I'm on my way" — from the dashboard or straight from the notification.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

require_method('POST');
$device = require_device();
$id = (int) (json_input()['id'] ?? 0);

$stmt = db()->prepare("UPDATE knocks SET status = 'answered', answered_by = ?, answered_at = ? WHERE id = ? AND status = 'waiting'");
$stmt->execute([$device['display_name'], time(), $id]);
$claimed = $stmt->rowCount() === 1;

$stmt = db()->prepare('SELECT * FROM knocks WHERE id = ?');
$stmt->execute([$id]);
$knock = $stmt->fetch();
if (!$knock) {
    json_response(['error' => 'Not found'], 404);
}

if ($claimed) {
    // Let the other organisers know it's handled (their notification is replaced).
    try {
        notify_devices(excludeDeviceId: (int) $device['id']);
    } catch (Throwable $e) {
        error_log('[tig-door] notify failed: ' . $e->getMessage());
    }
}

json_response(['claimed' => $claimed, 'knock' => knock_public($knock)], $claimed ? 200 : 409);
