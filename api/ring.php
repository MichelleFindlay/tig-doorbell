<?php
// Visitor "knock again": re-notifies organisers if nobody has answered yet.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

require_method('POST');
$knock = find_knock_by_visitor_token((string) (json_input()['k'] ?? ''));
if (!$knock) {
    json_response(['error' => 'Not found'], 404);
}
if ($knock['status'] !== 'waiting') {
    json_response(['status' => $knock['status'], 'answered_by' => $knock['answered_by'], 'message' => '']);
}

$wait = (int) $knock['last_notified_at'] + config('ring_again_after') - time();
if ($wait > 0) {
    json_response(['status' => 'waiting', 'message' => "Please wait {$wait}s before knocking again."]);
}

db()->prepare('UPDATE knocks SET last_notified_at = ? WHERE id = ?')->execute([time(), $knock['id']]);
try {
    notify_devices();
} catch (Throwable $e) {
    error_log('[tig-door] notify failed: ' . $e->getMessage());
}
json_response(['status' => 'waiting', 'message' => "We've given the organisers another nudge."]);
