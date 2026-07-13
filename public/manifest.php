<?php
require dirname(__DIR__) . '/app_config.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$version = rawurlencode(APP_VERSION);

echo json_encode([
    'name' => 'App Iperal',
    'short_name' => 'Iperal',
    'start_url' => './index.php',
    'scope' => './',
    'display' => 'standalone',
    'background_color' => '#e8f2ff',
    'theme_color' => '#07146a',
    'icons' => [
        [
            'src' => 'img/icon-192.png?v=' . $version,
            'sizes' => '192x192',
            'type' => 'image/png',
        ],
        [
            'src' => 'img/icon-512.png?v=' . $version,
            'sizes' => '512x512',
            'type' => 'image/png',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
