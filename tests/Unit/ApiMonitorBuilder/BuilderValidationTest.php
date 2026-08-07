<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\BuilderInputValidator;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\JsonPathCatalog;

function validBuilderPayload(array $override = []): array
{
    return array_replace([
        'endpoint' => 'https://api.example.test/v1',
        'method' => 'GET',
        'headers' => [],
        'assertions' => [],
        'configuration_version' => 1,
    ], $override);
}

it('normalizes every valid assertion kind and preserves chained JSON path order', function (): void {
    $assertions = [
        ['kind' => 'status', 'operator' => 'equals', 'expected' => 200],
        ['kind' => 'status', 'operator' => 'in', 'expected' => [200, 204]],
        ['kind' => 'latency', 'operator' => 'less_than_or_equal', 'expected' => 750],
        ['kind' => 'json_path', 'path' => '$.data[0].id', 'operator' => 'exists'],
        ['kind' => 'json_path', 'path' => '$.data[0].id', 'operator' => 'not_null'],
        ['kind' => 'json_path', 'path' => "\$['odd key']", 'operator' => 'type', 'expected' => 'string'],
        ['kind' => 'json_path', 'path' => '$.enabled', 'operator' => 'equals', 'expected' => true],
        ['kind' => 'json_path', 'path' => '$.name', 'operator' => 'not_equals', 'expected' => null],
        ['kind' => 'json_path', 'path' => '$.items', 'operator' => 'non_empty'],
    ];

    $input = app(BuilderInputValidator::class)->validate(validBuilderPayload(['assertions' => $assertions]), true);

    expect($input->assertions)->toEqual($assertions);
})->group('AC-web-dashboard-api-builder-7');

it('rejects invalid assertion grammar operator and operand combinations', function (array $assertion, string $errorKey): void {
    try {
        app(BuilderInputValidator::class)->validate(validBuilderPayload(['assertions' => [$assertion]]), true);
        test()->fail('Validation should have failed.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($errorKey);
    }
})->with([
    'recursive descent grammar' => [['kind' => 'json_path', 'path' => '$..secret', 'operator' => 'exists'], 'assertions.0.path'],
    'unclosed bracket grammar' => [['kind' => 'json_path', 'path' => '$[0', 'operator' => 'exists'], 'assertions.0.path'],
    'oversized grammar' => [['kind' => 'json_path', 'path' => '$.'.str_repeat('a', 512), 'operator' => 'exists'], 'assertions.0.path'],
    'status operator' => [['kind' => 'status', 'operator' => 'less_than', 'expected' => 200], 'assertions.0.operator'],
    'status code range' => [['kind' => 'status', 'operator' => 'equals', 'expected' => 99], 'assertions.0.expected'],
    'status set types' => [['kind' => 'status', 'operator' => 'in', 'expected' => [200, '204']], 'assertions.0.expected'],
    'latency operator' => [['kind' => 'latency', 'operator' => 'equals', 'expected' => 20], 'assertions.0.operator'],
    'latency range' => [['kind' => 'latency', 'operator' => 'less_than_or_equal', 'expected' => -1], 'assertions.0.expected'],
    'operand prohibited' => [['kind' => 'json_path', 'path' => '$.id', 'operator' => 'exists', 'expected' => true], 'assertions.0.expected'],
    'operand required' => [['kind' => 'json_path', 'path' => '$.id', 'operator' => 'equals'], 'assertions.0.expected'],
    'type operand' => [['kind' => 'json_path', 'path' => '$.id', 'operator' => 'type', 'expected' => 'date'], 'assertions.0.expected'],
])->group('AC-web-dashboard-api-builder-7');

it('emits deterministic canonical paths types previews and redacts secret-shaped values', function (): void {
    $json = new stdClass;
    $json->z = [(object) ['name' => 'a very visible value']];
    $json->{'odd key'} = 3.5;
    $json->api_token = 'must-not-escape';
    $json->active = true;

    $catalog = app(JsonPathCatalog::class)->build($json);

    expect(array_column($catalog['paths'], 'path'))->toBe([
        '$', '$.active', '$.api_token', "\$['odd key']", '$.z', '$.z[0]', '$.z[0].name',
    ])->and(array_column($catalog['paths'], 'inferred_type'))->toBe([
        'object', 'boolean', 'string', 'number', 'array', 'object', 'string',
    ])->and(json_encode($catalog, JSON_THROW_ON_ERROR))->not->toContain('must-not-escape');
})->group('AC-web-dashboard-api-builder-5');
