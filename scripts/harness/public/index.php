<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/** @var Application $app */
$app = require dirname(__DIR__).'/bootstrap/app.php';

$app->handleRequest(Request::capture());
