<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard;

use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class DashboardFilters
{
    /**
     * @param  list<string>  $types
     * @param  list<string>  $states
     * @param  list<string>  $severities
     * @param  list<string>  $monitorUuids
     */
    private function __construct(
        public array $types,
        public array $states,
        public array $severities,
        public array $monitorUuids,
        public int $perPage,
        public ?Cursor $cursor,
    ) {}

    /** @throws ValidationException */
    public static function fromRequest(Request $request): self
    {
        $validator = Validator::make($request->query(), [
            'types' => ['sometimes', 'array', 'max:3'],
            'types.*' => ['string', 'distinct:strict', Rule::in(['server', 'website', 'api'])],
            'states' => ['sometimes', 'array', 'max:3'],
            'states.*' => ['string', 'distinct:strict', Rule::in(['warn', 'down', 'recovering'])],
            'severities' => ['sometimes', 'array', 'max:2'],
            'severities.*' => ['string', 'distinct:strict', Rule::in(['warn', 'critical'])],
            'monitor_uuids' => ['sometimes', 'array', 'max:100'],
            'monitor_uuids.*' => ['string', 'distinct:strict', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => [
                'sometimes',
                'string',
                'max:2048',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || Cursor::fromEncoded($value) === null) {
                        $fail('The cursor is invalid.');
                    }
                },
            ],
        ]);

        $validated = $validator->validate();
        $encodedCursor = $validated['cursor'] ?? null;

        return new self(
            types: self::strings($validated['types'] ?? []),
            states: self::strings($validated['states'] ?? ['warn', 'down', 'recovering']),
            severities: self::strings($validated['severities'] ?? []),
            monitorUuids: array_map('strtolower', self::strings($validated['monitor_uuids'] ?? [])),
            perPage: (int) ($validated['per_page'] ?? 25),
            cursor: is_string($encodedCursor) ? Cursor::fromEncoded($encodedCursor) : null,
        );
    }

    /** @return array{types: list<string>, states: list<string>, severities: list<string>, monitor_uuids: list<string>} */
    public function toArray(): array
    {
        return [
            'types' => $this->types,
            'states' => $this->states,
            'severities' => $this->severities,
            'monitor_uuids' => $this->monitorUuids,
        ];
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_map('strval', $values)) : [];
    }
}
