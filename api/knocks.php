<?php
// Organiser dashboard feed.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

require_device();
$rows = db()->query('SELECT * FROM knocks ORDER BY created_at DESC LIMIT 50')->fetchAll();
json_response(['knocks' => array_map('knock_public', $rows), 'now' => time()]);
