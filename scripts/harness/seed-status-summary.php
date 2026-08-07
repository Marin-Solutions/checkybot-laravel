#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: seed-status-summary.php <run-scoped-sqlite-file>\n");
    exit(64);
}

$runDirectory = (string) getenv('HARNESS_RUN_DIR');
$database = $argv[1];
if ($runDirectory === '' || dirname($database) !== $runDirectory || basename($database) !== 'database.sqlite') {
    fwrite(STDERR, "Refusing to seed a database outside the harness run directory.\n");
    exit(65);
}

$pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$statement = $pdo->prepare(<<<'SQL'
INSERT INTO project_api_tokens
    (public_id, project_id, name, token_hash, abilities, expires_at, revoked_at, created_at, updated_at)
VALUES
    (:public_id, :project_id, :name, :token_hash, :abilities, NULL, NULL, :created_at, :updated_at)
SQL);
$now = gmdate('Y-m-d H:i:s');
$statement->execute([
    'public_id' => '11111111-1111-4111-8111-111111111112',
    'project_id' => '11111111-1111-4111-8111-111111111111',
    'name' => 'canonical-browser-fixture',
    'token_hash' => hash('sha256', 'cbp_harness_status_read_token'),
    'abilities' => json_encode(['status:read'], JSON_THROW_ON_ERROR),
    'created_at' => $now,
    'updated_at' => $now,
]);
$statement->execute([
    'public_id' => '11111111-1111-4111-8111-111111111113',
    'project_id' => '11111111-1111-4111-8111-111111111111',
    'name' => 'canonical-agent-evaluator-fixture',
    'token_hash' => hash('sha256', 'cbp_harness_agent_report_token'),
    'abilities' => json_encode(['agent:report'], JSON_THROW_ON_ERROR),
    'created_at' => $now,
    'updated_at' => $now,
]);

$server = $pdo->prepare(<<<'SQL'
INSERT INTO agent_servers
    (server_uuid, project_id, enabled, link_cap_bps, share_redacted_logs, created_at, updated_at)
VALUES
    (:server_uuid, :project_id, 1, 1000000000, 0, :created_at, :updated_at)
SQL);
$server->execute([
    'server_uuid' => '22222222-2222-4222-8222-222222222222',
    'project_id' => '11111111-1111-4111-8111-111111111111',
    'created_at' => $now,
    'updated_at' => $now,
]);

fwrite(STDOUT, "Seeded canonical status-summary and agent evaluator harness identities.\n");
