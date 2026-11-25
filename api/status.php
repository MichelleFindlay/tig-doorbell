<?php
// Visitor polls this to learn whether an organiser is on the way.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$knock = find_knock_by_visitor_token((string) ($_GET['k'] ?? ''));
if (!$knock) {
    json_response(['error' => 'Not found'], 404);
}
json_response([
    'status'      => $knock['status'],
    'answered_by' => $knock['answered_by'],
]);
