<?php

it('has required config keys', function () {
    $config = include __DIR__.'/../../config/checkybot-laravel.php';

    expect($config)->toHaveKeys([
        'api_key',
        'project_identifier',
        'project_id',
        'base_url',
        'environment',
        'checks_path',
        'default_headers',
        'timeout',
        'retry_times',
        'retry_delay',
        'checks',
    ]);
});

it('has default values for optional settings', function () {
    $config = include __DIR__.'/../../config/checkybot-laravel.php';

    expect($config['base_url'])->toBeNull()
        ->and($config['environment'])->toBe('production')
        ->and($config['default_headers'])->toBeArray()
        ->and($config['timeout'])->toBe(30)
        ->and($config['retry_times'])->toBe(3)
        ->and($config['retry_delay'])->toBe(1000);
});

it('has checks structure with all check sections', function () {
    $config = include __DIR__.'/../../config/checkybot-laravel.php';

    expect($config['checks'])->toHaveKeys(['uptime', 'ssl', 'api', 'dead_links', 'open_graph'])
        ->and($config['checks']['uptime'])->toBeArray()
        ->and($config['checks']['ssl'])->toBeArray()
        ->and($config['checks']['api'])->toBeArray()
        ->and($config['checks']['dead_links'])->toBeArray()
        ->and($config['checks']['open_graph'])->toBeArray();
});
