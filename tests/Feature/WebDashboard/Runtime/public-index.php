<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

$frontendOrigin = (string) getenv('CHECKYBOT_WEB_FRONTEND_ORIGIN');
if ($frontendOrigin !== '') {
    header('Access-Control-Allow-Origin: '.$frontendOrigin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Accept, Content-Type, X-Inertia, X-CSRF-TOKEN, X-XSRF-TOKEN');
    header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('LARAVEL_START', microtime(true));

/** @var Application $app */
$app = require dirname(__DIR__, 4).'/scripts/harness/bootstrap/app.php';
$app->booting(static function () use ($app): void {
    $app->make('config')->set('checkybot.api_builder.sample_exact_allowlist', array_values(array_filter([
        getenv('CHECKYBOT_WEB_SAMPLE_URL') ?: null,
        getenv('CHECKYBOT_WEB_AUTH_URL') ?: null,
    ])));
});
$app->handleRequest(Request::capture());
