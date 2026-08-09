<?php

it('publishes credentials transport and all seven config definition sections', function (): void {
    $config = include __DIR__.'/../../config/checkybot-laravel.php';

    expect($config)->toHaveKeys(['api_key', 'project_id', 'base_url', 'timeout', 'retry_times', 'retry_delay', 'checks'])
        ->and($config['base_url'])->toBe('https://checkybot.com')
        ->and($config['timeout'])->toBe(30)
        ->and($config['retry_times'])->toBe(3)
        ->and($config['retry_delay'])->toBe(1000)
        ->and(array_keys($config['checks']))->toBe([
            'uptime', 'ssl', 'api', 'dead_links', 'open_graph', 'domain_expiry', 'response_time_budget',
        ]);

    foreach ($config['checks'] as $checks) {
        expect($checks)->toBeArray();
    }
})->group('AC-laravel-sdk-monitor-definitions-1');

it('keeps fluent publish stub examples for existing and new factories', function (): void {
    $stub = file_get_contents(__DIR__.'/../../stubs/checkybot.php.stub');

    foreach (['uptime', 'ssl', 'api', 'links', 'openGraph', 'domainExpiry', 'responseTimeBudget'] as $factory) {
        expect($stub)->toContain("Checkybot::{$factory}(");
    }
})->group('AC-laravel-sdk-monitor-definitions-1', 'AC-laravel-sdk-monitor-definitions-5');

it('documents the complete v1 declaration upgrade and security contract', function (): void {
    $root = dirname(__DIR__, 2);
    $documents = [
        'README' => file_get_contents($root.'/README.md'),
        'CHANGELOG' => file_get_contents($root.'/CHANGELOG.md'),
        'facade' => file_get_contents($root.'/src/Facades/Checkybot.php'),
        'config' => file_get_contents($root.'/config/checkybot-laravel.php'),
        'stub' => file_get_contents($root.'/stubs/checkybot.php.stub'),
    ];
    $all = implode("\n", $documents);

    foreach ($documents as $name => $document) {
        expect($document, "{$name} must identify the transport contract")->toContain('check-sync.v1');
    }

    expect($documents['README'])
        ->toContain("Checkybot::domainExpiry('primary-domain')", '->warnDays(30)')
        ->toContain("Checkybot::responseTimeBudget('homepage-p95')", '->percentile(95)', '->budgetMs(2000)')
        ->toContain('->expectStatus(200)', '->maxLatency(750)', "->expect('status')->toEqual('healthy')")
        ->toContain("'domain_expiry'", "'response_time_budget'", "'expected_status'", "'max_latency_ms'")
        ->toContain('Legacy `uptime_checks`', 'Deploy a server that accepts `check-sync.v1` before upgrading')
        ->toContain('authenticated HTTPS Checkybot API', 'downstream service can encrypt them at rest', 'masked from package');

    expect($documents['config'])
        ->toContain("'domain_expiry'", "'warn_days' => 30")
        ->toContain("'response_time_budget'", "'percentile' => 95", "'budget_ms' => 2000")
        ->toContain("'expected_status' => 200", "'max_latency_ms' => 750", "'kind' => 'json_path'");

    expect($documents['stub'])
        ->toContain('->expectStatus(200)', '->maxLatency(750)', "->expect('status')->toEqual('healthy')")
        ->toContain('->warnDays(30)', '->percentile(95)', '->budgetMs(2000)');

    expect($documents['facade'])
        ->toContain('@method static DomainExpiryCheck domainExpiry')
        ->toContain('@method static ResponseTimeBudgetCheck responseTimeBudget')
        ->toContain('API status, latency, and body assertions');

    expect($all)->toContain('existing', 'compatible', 'authenticated HTTPS', 'downstream encryption', 'masked');
})->group('AC-laravel-sdk-monitor-definitions-8');
