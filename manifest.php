<?php
// Web app manifest (for "Add to Home Screen"), using the name and colours from config.php.
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$which = theme_mode() === 'dark' ? 'dark' : 'light';
$palette = theme_palette($which);

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode([
    'name'             => app_name() . ' — Organisers',
    'short_name'       => mb_substr(app_name(), 0, 12),
    'start_url'        => './organiser/',
    'scope'            => './',
    'display'          => 'standalone',
    'background_color' => $palette['background'],
    'theme_color'      => $palette['text'],
    'icons'            => [
        ['src' => 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
