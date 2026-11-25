<?php
// Sends a test notification to the calling device only.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

require_method('POST');
$device = require_device();
db()->prepare('UPDATE devices SET test_pending = 1 WHERE id = ?')->execute([$device['id']]);
$result = notify_devices(onlyDeviceId: (int) $device['id']);
if ($result['sent'] === 0) {
    json_response(['error' => 'No working subscription for this device — tap "Enable notifications" first.'], 409);
}
json_response(['ok' => true]);
