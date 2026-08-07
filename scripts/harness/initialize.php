#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: initialize.php <run-scoped-sqlite-file>\n");
    exit(64);
}

$runDirectory = (string) getenv('HARNESS_RUN_DIR');
$database = $argv[1];

if ($runDirectory === '' || dirname($database) !== $runDirectory || basename($database) !== 'database.sqlite') {
    fwrite(STDERR, "Refusing to initialize a database outside the harness run directory.\n");
    exit(65);
}

if (! is_dir($runDirectory) || ! touch($database)) {
    fwrite(STDERR, "Unable to create the run-scoped SQLite database.\n");
    exit(73);
}

$pdo = new PDO('sqlite:'.$database, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec(<<<'SQL'
CREATE TABLE harness_queue_probes (
    probe_id VARCHAR(36) PRIMARY KEY NOT NULL,
    status VARCHAR(16) NOT NULL,
    accepted_at VARCHAR(40) NOT NULL,
    processed_at VARCHAR(40) NULL
)
SQL);

$pdo->exec(<<<'SQL'
CREATE TABLE jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts INTEGER NOT NULL,
    reserved_at INTEGER NULL,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
)
SQL);

$pdo->exec('CREATE INDEX jobs_queue_index ON jobs (queue)');

fwrite(STDOUT, "Initialized {$database}\n");
