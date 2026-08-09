#!/usr/bin/env php
<?php

declare(strict_types=1);

use Illuminate\Encryption\Encrypter;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = (string) getenv('DB_DATABASE');
$project = (string) getenv('MOBILE_E2E_PROJECT_UUID');
$token = (string) getenv('MOBILE_E2E_EXPO_TOKEN');
$key = (string) getenv('APP_KEY');
if ($database === '' || dirname($database) !== (string) getenv('HARNESS_RUN_DIR') || basename($database) !== 'database.sqlite') {
    fwrite(STDERR, "Refusing non-run-scoped database.\n");
    exit(65);
}
if ($project === '' || $token === '' || ! str_starts_with($key, 'base64:')) {
    fwrite(STDERR, "Missing runtime seed configuration.\n");
    exit(64);
}
$pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$encrypter = new Encrypter(base64_decode(substr($key, 7), true), 'AES-256-CBC');
$device = '77777777-7777-4777-8777-777777777777';
$statement = $pdo->prepare('INSERT INTO push_devices (public_id, user_id, installation_id, project_id, platform, expo_push_token, expo_token_hash, permission, app_version, active, registered_at, created_at, updated_at) VALUES (:public_id, :user_id, :installation_id, :project_id, :platform, :token, :hash, :permission, :version, 1, :now, :now, :now)');
$statement->execute([
    'public_id' => $device,
    'user_id' => 'canonical-mobile-runtime-user',
    'installation_id' => '88888888-8888-4888-8888-888888888888',
    'project_id' => $project,
    'platform' => 'ios',
    'token' => $encrypter->encryptString($token),
    'hash' => hash('sha256', $token),
    'permission' => 'granted',
    'version' => '1.0.0-e2e',
    'now' => gmdate('Y-m-d H:i:s'),
]);
fwrite(STDOUT, "Seeded canonical mobile push device {$device}.\n");
