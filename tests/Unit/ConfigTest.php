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
