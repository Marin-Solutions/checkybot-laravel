<?php

use MarinSolutions\CheckybotLaravel\ConfigValidator;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;

it('registers CheckybotClient as singleton', function () {
    config([
        'checkybot-laravel.base_url' => 'https://checkybot.com',
        'checkybot-laravel.api_key' => 'test-key',
        'checkybot-laravel.project_id' => '1',
        'checkybot-laravel.timeout' => 30,
        'checkybot-laravel.retry_times' => 3,
        'checkybot-laravel.retry_delay' => 1000,
    ]);

    $client1 = app(CheckybotClient::class);
    $client2 = app(CheckybotClient::class);

    expect($client1)->toBeInstanceOf(CheckybotClient::class)
        ->and($client1)->toBe($client2);
});

it('registers ConfigValidator', function () {
    $validator = app(ConfigValidator::class);

    expect($validator)->toBeInstanceOf(ConfigValidator::class);
});
