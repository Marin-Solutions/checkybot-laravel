<?php

it('has required config keys', function () {
    $config = include __DIR__.'/../../config/checkybot-laravel.php';

    expect($config)->toHaveKeys([
        'api_key',
        'project_id',
        'base_url',
        'timeout',
        'retry_times',
        'retry_delay',
        'checks',
    ]);
});

it('has default values for optional settings', function () {
    $config = include __DIR__.'/../../config/checkybot-laravel.php';

    expect($config['base_url'])->toBe('https://checkybot.com')
        ->and($config['timeout'])->toBe(30)
        ->and($config['retry_times'])->toBe(3)
        ->and($config['retry_delay'])->toBe(1000);
});

it('has checks structure with uptime ssl and api sections', function () {
    $config = include __DIR__.'/../../config/checkybot-laravel.php';

    expect($config['checks'])->toHaveKeys(['uptime', 'ssl', 'api'])
        ->and($config['checks']['uptime'])->toBeArray()
        ->and($config['checks']['ssl'])->toBeArray()
        ->and($config['checks']['api'])->toBeArray();
});
