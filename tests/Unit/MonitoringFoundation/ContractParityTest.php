<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\ContractValidator;

it('accepts and rejects the canonical shared contract fixtures in PHP', function (): void {
    $fixtures = json_decode(
        file_get_contents(dirname(__DIR__, 3).'/packages/contracts/fixtures/contract-cases.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $validator = app(ContractValidator::class);

    foreach ($fixtures as $fixture) {
        $accepted = true;

        try {
            match ($fixture['kind']) {
                'identity' => $validator->validateIdentity($fixture['input']),
                'filter' => $validator->validateFilter($fixture['input']),
                'transition' => $validator->validateTransition($fixture['input']),
                'statusSummary' => $validator->validateStatusSummary($fixture['input']),
                'incident' => $validator->validateIncident($fixture['input']),
                'checkSync' => $validator->validateCheckSync($fixture['input']),
            };
        } catch (ValidationException) {
            $accepted = false;
        }

        expect($accepted)->toBe($fixture['valid'], $fixture['name']);
    }
})->group('AC-domain-runtime-foundation-1');

it('keeps generated schema enum values identical to PHP backed enums', function (): void {
    $schema = json_decode(
        file_get_contents(dirname(__DIR__, 3).'/packages/contracts/monitor-foundation.schema.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($schema['$defs']['monitorType']['enum'])->toBe(['server', 'website', 'api'])
        ->and($schema['$defs']['lifecycleState']['enum'])->toBe(['healthy', 'warn', 'down', 'recovering'])
        ->and($schema['$defs']['severity']['enum'])->toBe(['warn', 'critical']);
})->group('AC-domain-runtime-foundation-1');
