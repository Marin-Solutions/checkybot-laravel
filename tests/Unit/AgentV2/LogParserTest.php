<?php

declare(strict_types=1);

use Checkybot\AgentV2\AgentLogParser;
use Checkybot\AgentV2\LocalRedactor;

require_once dirname(__DIR__, 3).'/agent/src/AgentV2Collector.php';
require_once dirname(__DIR__, 3).'/agent/src/AgentLogParser.php';

it('parses exact PHP-FPM and five-minute nginx aggregates', function (): void {
    $now = new DateTimeImmutable('2026-08-07T12:00:00Z');
    $parser = new AgentLogParser;
    $fpm = $parser->phpFpmPools(
        ['www|8|20', 'api|4|8'],
        [
            '2026-08-07T11:56:00Z [pool www] warning: server reached pm.max_children setting (20)',
            '2026-08-07T11:58:00Z [pool www] warning: server reached pm.max_children setting (20)',
            '2026-08-07T11:54:59Z [pool api] warning: server reached pm.max_children setting (8)',
        ],
        $now,
    );
    $nginx = $parser->nginxWindow(
        [
            '2026-08-07T11:56:00Z|200|GET /',
            '2026-08-07T11:57:00Z|502|GET /api',
            '2026-08-07T11:59:00Z|504|GET /slow',
            '2026-08-07T11:54:59Z|503|GET /old',
        ],
        [
            '2026-08-07T11:58:00Z upstream timed out while reading response header',
            '2026-08-07T11:54:00Z upstream timed out while reading response header',
        ],
        $now,
    );

    expect($fpm)->toBe([
        ['pool' => 'www', 'active_workers' => 8, 'max_children' => 20, 'max_children_reached_5m' => 2],
        ['pool' => 'api', 'active_workers' => 4, 'max_children' => 8, 'max_children_reached_5m' => 0],
    ])->and($nginx)->toBe([
        'total_requests' => 3,
        'five_xx_count' => 2,
        'upstream_timeout_count' => 1,
    ]);
})->group('AC-agent-v2-expanded-monitors-3');

it('redacts query values, credentials, configured secrets, emails, and addresses before collection', function (): void {
    $line = 'GET /callback?token=query-secret&mode=verbose Authorization: Bearer auth-secret Cookie: sid=cookie-secret configured-secret operator@example.test 192.0.2.44';
    $redacted = (new LocalRedactor(['configured-secret']))->redact($line);

    expect($redacted)->toContain('token=[REDACTED]', 'mode=[REDACTED]', 'Authorization: [REDACTED]')
        ->not->toContain('query-secret', 'verbose', 'auth-secret', 'cookie-secret', 'configured-secret', 'operator@example.test', '192.0.2.44');
})->group('AC-agent-v2-expanded-monitors-3');
