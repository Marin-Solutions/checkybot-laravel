<?php

use MarinSolutions\CheckybotLaravel\Checks\ResponseTimeBudgetCheck;

it('serializes the v1 default and explicit response budget thresholds', function (): void {
    $default = (new ResponseTimeBudgetCheck('budget'))->url('https://example.com')->everyFiveMinutes();
    $explicit = (new ResponseTimeBudgetCheck('budget'))->url('https://example.com')->everyFiveMinutes()->percentile(99)->budgetMs(1250);

    expect($default->toArray())->toBe([
        'name' => 'budget',
        'url' => 'https://example.com',
        'interval' => '5m',
        'percentile' => 95,
        'budget_ms' => 2000,
    ])->and($explicit->toArray()['percentile'])->toBe(99)
        ->and($explicit->toArray()['budget_ms'])->toBe(1250)
        ->and((new ResponseTimeBudgetCheck('p95'))->p95(800)->toArray()['budget_ms'])->toBe(800);
})->group('AC-laravel-sdk-monitor-definitions-1');
