<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$publicPath = __DIR__.'/public'.($path === '/' ? '/index.html' : $path);

if ($path !== '/' && is_file($publicPath)) {
    return false;
}

require __DIR__.'/public/index.php';
