<?php

use MarinSolutions\CheckybotLaravel\Checks\DomainExpiryCheck;

it('serializes the v1 default and explicit domain expiry thresholds', function (): void {
    $default = (new DomainExpiryCheck('domain'))->url('https://example.com')->daily();
    $explicit = (new DomainExpiryCheck('domain'))->url('https://example.com')->daily()->warnDays(45);

    expect($default->toArray())->toBe([
        'name' => 'domain',
        'url' => 'https://example.com',
        'interval' => '1d',
        'warn_days' => 30,
    ])->and($explicit->toArray()['warn_days'])->toBe(45);
})->group('AC-laravel-sdk-monitor-definitions-1');
