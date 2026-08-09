#!/usr/bin/env php
<?php

declare(strict_types=1);

$database = (string) getenv('DB_DATABASE');
if ($database === '' || dirname($database) !== (string) getenv('HARNESS_RUN_DIR') || basename($database) !== 'database.sqlite') {
    fwrite(STDERR, "Refusing non-run-scoped database.\n");
    exit(65);
}
$pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$row = $pdo->query("SELECT operation_id, payload, status FROM outbox_events WHERE event_type = 'notification.intent.created' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (! is_array($row)) {
    fwrite(STDOUT, "null\n");
    exit(0);
}
$payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
fwrite(STDOUT, json_encode([
    'operation_id' => $payload['operation_id'],
    'project_uuid' => $payload['project_uuid'],
    'group_id' => $payload['group_id'],
    'phase' => $payload['phase'],
    'severity' => $payload['severity'],
    'monitor_uuids' => $payload['problem_filter']['monitor_uuids'],
    'outbox_status' => $row['status'],
], JSON_THROW_ON_ERROR)."\n");
