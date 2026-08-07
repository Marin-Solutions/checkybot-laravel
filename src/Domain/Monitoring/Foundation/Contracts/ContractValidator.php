<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ContractValidator
{
    /** @return array<string, mixed> */
    public function validateEvent(array $input): array
    {
        $this->rejectUnknown($input, ['operation_id', 'event_type', 'payload']);
        $base = Validator::make($input, [
            'operation_id' => ['required', 'uuid'],
            'event_type' => ['required', Rule::in(FoundationContract::eventTypes())],
            'payload' => ['required', 'array'],
        ])->validate();

        $payload = match ($base['event_type']) {
            'monitor.transitioned' => $this->validateTransition($base['payload']),
            'contract.check_sync.probed' => $this->validateCheckSync($base['payload']),
            'incident.redaction.probed' => $this->validateIncident($base['payload']),
            default => self::fail('event_type', 'The event type is not part of this contract.'),
        };

        return [...$base, 'payload' => $payload];
    }

    /** @return array<string, mixed> */
    public function validateIdentity(array $input): array
    {
        $this->rejectUnknown($input, ['project_id', 'monitor_id', 'type']);

        return Validator::make($input, [
            'project_id' => ['required', 'uuid'],
            'monitor_id' => ['required', 'uuid'],
            'type' => ['required', Rule::enum(MonitorType::class)],
        ])->validate();
    }

    /** @return array<string, mixed> */
    public function validateFilter(array $input): array
    {
        $this->rejectUnknown($input, ['types', 'states', 'severities']);

        return Validator::make($input, [
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['required', 'distinct', Rule::enum(MonitorType::class)],
            'states' => ['required', 'array', 'min:1'],
            'states.*' => ['required', 'distinct', Rule::enum(LifecycleState::class)],
            'severities' => ['required', 'array', 'min:1'],
            'severities.*' => ['required', 'distinct', Rule::enum(Severity::class)],
        ])->validate();
    }

    /** @return array<string, mixed> */
    public function validateTransition(array $input): array
    {
        $this->rejectUnknown($input, ['contract_version', 'identity', 'from_state', 'to_state', 'severity', 'filter', 'occurred_at']);
        $validated = Validator::make($input, [
            'contract_version' => ['required', Rule::in([FoundationContract::VERSION])],
            'identity' => ['required', 'array'],
            'from_state' => ['required', Rule::enum(LifecycleState::class)],
            'to_state' => ['required', Rule::enum(LifecycleState::class)],
            'severity' => ['required', Rule::enum(Severity::class)],
            'filter' => ['required', 'array'],
            'occurred_at' => ['required', $this->rfc3339Rule()],
        ])->validate();

        $validated['identity'] = $this->validateIdentity($validated['identity']);
        $validated['filter'] = $this->validateFilter($validated['filter']);

        return $validated;
    }

    /** @return array<string, mixed> */
    public function validateStatusSummary(array $input): array
    {
        $this->rejectUnknown($input, ['counts', 'updated_at', 'stale']);
        if (isset($input['counts']) && is_array($input['counts'])) {
            $this->rejectUnknown($input['counts'], ['servers', 'websites', 'apis'], 'counts.');
            foreach (['servers', 'websites', 'apis'] as $type) {
                if (isset($input['counts'][$type]) && is_array($input['counts'][$type])) {
                    $this->rejectUnknown($input['counts'][$type], ['healthy', 'warn', 'down'], "counts.{$type}.");
                }
            }
        }

        $rules = ['stale' => ['required', 'boolean'], 'updated_at' => ['required', 'nullable', $this->rfc3339Rule()]];
        foreach (['servers', 'websites', 'apis'] as $type) {
            foreach (['healthy', 'warn', 'down'] as $state) {
                $rules["counts.{$type}.{$state}"] = ['required', 'integer', 'min:0'];
            }
        }

        return Validator::make($input, $rules)->validate();
    }

    /** @return array<string, mixed> */
    public function validateIncident(array $input): array
    {
        $this->rejectUnknown($input, ['contract_version', 'incident_id', 'log_lines']);

        return Validator::make($input, [
            'contract_version' => ['required', Rule::in([FoundationContract::VERSION])],
            'incident_id' => ['required', 'uuid'],
            'log_lines' => ['required', 'array', 'min:1', 'max:50'],
            'log_lines.*' => ['required', 'string', 'max:2000'],
        ])->validate();
    }

    /** @return array<string, mixed> */
    public function validateCheckSync(array $input): array
    {
        $types = ['uptime', 'ssl', 'api', 'dead_links', 'open_graph', 'domain_expiry', 'response_time_budget'];
        $this->rejectUnknown($input, ['contract_version', ...$types]);

        $allowedFields = [
            'uptime' => ['name', 'url', 'interval', 'max_redirects', 'headers'],
            'ssl' => ['name', 'url', 'interval'],
            'api' => ['name', 'url', 'interval', 'headers', 'expected_status', 'max_latency_ms', 'retry_count', 'assertions'],
            'dead_links' => ['name', 'url', 'interval', 'max_depth', 'exclude_paths', 'headers'],
            'open_graph' => ['name', 'url', 'interval', 'required_tags', 'headers'],
            'domain_expiry' => ['name', 'url', 'interval', 'warn_days'],
            'response_time_budget' => ['name', 'url', 'interval', 'percentile', 'budget_ms'],
        ];

        foreach ($types as $type) {
            if (! is_array($input[$type] ?? null)) {
                continue;
            }
            if (! array_is_list($input[$type])) {
                self::fail($type, "The {$type} field must be a list.");
            }
            foreach ($input[$type] as $index => $check) {
                if (! is_array($check)) {
                    continue;
                }
                $this->rejectUnknown($check, $allowedFields[$type], "{$type}.{$index}.");
                $this->validateHeaderShape($check['headers'] ?? null, "{$type}.{$index}.headers");

                if ($type === 'api' && is_array($check['assertions'] ?? null)) {
                    if (! array_is_list($check['assertions'])) {
                        self::fail("api.{$index}.assertions", 'The assertions field must be a list.');
                    }
                    foreach ($check['assertions'] as $assertionIndex => $assertion) {
                        if (is_array($assertion)) {
                            $this->rejectUnknown(
                                $assertion,
                                ['kind', 'operator', 'path', 'operand', 'sort_order', 'is_active'],
                                "api.{$index}.assertions.{$assertionIndex}.",
                            );
                        }
                    }
                }
            }
        }

        $rules = ['contract_version' => ['required', Rule::in([FoundationContract::CHECK_SYNC_VERSION])]];
        foreach ($types as $type) {
            $rules[$type] = ['present', 'array'];
            $rules["{$type}.*"] = ['required', 'array'];
            $rules["{$type}.*.name"] = ['required', 'string', 'max:255', 'regex:/\\S/'];
            $rules["{$type}.*.url"] = ['required', 'url:http,https'];
            $rules["{$type}.*.interval"] = ['required', 'regex:/^\\d+[smhd]$/'];
            $rules["{$type}.*.headers"] = ['sometimes', 'array', 'max:50'];
            $rules["{$type}.*.headers.*"] = ['string', 'max:8192'];
        }

        $rules += [
            'uptime.*.max_redirects' => ['sometimes', 'integer', 'between:0,20'],
            'api.*.expected_status' => ['sometimes', 'integer', 'between:100,599'],
            'api.*.max_latency_ms' => ['sometimes', 'integer', 'between:1,3600000'],
            'api.*.retry_count' => ['sometimes', 'integer', 'between:0,10'],
            'api.*.assertions' => ['sometimes', 'array', 'max:50'],
            'api.*.assertions.*' => ['required', 'array'],
            'api.*.assertions.*.kind' => ['required', Rule::in(['status', 'latency', 'json_path'])],
            'api.*.assertions.*.operator' => ['required', 'string'],
            'api.*.assertions.*.path' => ['sometimes', 'string', 'max:512'],
            'api.*.assertions.*.sort_order' => ['required', 'integer', 'min:1'],
            'api.*.assertions.*.is_active' => ['required', 'boolean'],
            'dead_links.*.max_depth' => ['sometimes', 'integer', 'between:0,10'],
            'dead_links.*.exclude_paths' => ['sometimes', 'array', 'max:100'],
            'dead_links.*.exclude_paths.*' => ['string', 'max:2048'],
            'open_graph.*.required_tags' => ['sometimes', 'array', 'max:50'],
            'open_graph.*.required_tags.*' => ['string', 'max:255'],
            'domain_expiry.*.warn_days' => ['required', 'integer', 'between:1,365'],
            'response_time_budget.*.percentile' => ['required', 'integer', 'between:1,100'],
            'response_time_budget.*.budget_ms' => ['required', 'integer', 'between:1,3600000'],
        ];

        Validator::make($input, $rules)->validate();

        foreach ($input['api'] as $checkIndex => $check) {
            foreach (($check['assertions'] ?? []) as $assertionIndex => $assertion) {
                $this->validateCheckSyncAssertion($assertion, "api.{$checkIndex}.assertions.{$assertionIndex}");
            }
        }

        foreach ($types as $type) {
            $names = array_column($input[$type], 'name');
            if (count($names) !== count(array_unique($names, SORT_STRING))) {
                self::fail("{$type}.name", "The {$type} names must be unique.");
            }
        }

        // Return the untouched generated-contract body. Consumers need the exact
        // SDK-emitted optional fields, not a second package-specific projection.
        return $input;
    }

    private function validateHeaderShape(mixed $headers, string $field): void
    {
        if ($headers === null || ! is_array($headers)) {
            return;
        }

        foreach ($headers as $name => $value) {
            if (! is_string($name) || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
                self::fail($field, 'Header names must use the HTTP token grammar.');
            }
            if (! is_string($value) || preg_match('/[\\r\\n]/', $value) === 1) {
                self::fail("{$field}.{$name}", 'Header values must be strings without line breaks.');
            }
        }
    }

    /** @param array<string, mixed> $assertion */
    private function validateCheckSyncAssertion(array $assertion, string $field): void
    {
        $kind = $assertion['kind'];
        $operator = $assertion['operator'];
        $hasOperand = array_key_exists('operand', $assertion);
        $operand = $assertion['operand'] ?? null;

        if ($kind === 'status') {
            if (array_key_exists('path', $assertion)
                || ! in_array($operator, ['equals', 'in'], true)
                || ($operator === 'equals' && (! is_int($operand) || $operand < 100 || $operand > 599))
                || ($operator === 'in' && (! is_array($operand) || ! array_is_list($operand) || $operand === []
                    || collect($operand)->contains(fn (mixed $status): bool => ! is_int($status) || $status < 100 || $status > 599)))) {
                self::fail($field, 'The status assertion combination is invalid.');
            }

            return;
        }

        if ($kind === 'latency') {
            if (array_key_exists('path', $assertion) || $operator !== 'less_than_or_equal'
                || ! is_int($operand) || $operand < 1 || $operand > 3_600_000) {
                self::fail($field, 'The latency assertion combination is invalid.');
            }

            return;
        }

        $path = $assertion['path'] ?? null;
        if (! is_string($path) || $path === '' || str_contains($path, '..')
            || preg_match('/^(?:[A-Za-z_][A-Za-z0-9_-]*(?:\\.[A-Za-z_][A-Za-z0-9_-]*)*|\\$(?:(?:\\.[A-Za-z_][A-Za-z0-9_-]*)|(?:\\[\\d+\\])|(?:\\[\'[^\'\\\\]+\'\\]))*)$/', $path) !== 1) {
            self::fail("{$field}.path", 'The JSON path is invalid.');
        }

        if (in_array($operator, ['exists', 'not_null', 'non_empty'], true) && $hasOperand) {
            self::fail($field, "The {$operator} operator does not accept an operand.");
        }
        if (in_array($operator, ['equals', 'not_equals', 'greater_than', 'greater_than_or_equal', 'less_than', 'less_than_or_equal'], true)
            && (! $hasOperand || (! is_scalar($operand) && $operand !== null))) {
            self::fail($field, "The {$operator} operator requires a scalar operand.");
        }
        if ($operator === 'type' && (! is_string($operand)
            || ! in_array($operand, ['string', 'integer', 'number', 'boolean', 'null', 'array', 'object'], true))) {
            self::fail($field, 'The type assertion operand is invalid.');
        }
        if ($operator === 'matches' && (! is_string($operand) || $operand === '')) {
            self::fail($field, 'The matches assertion operand is invalid.');
        }
        if (! in_array($operator, [
            'exists', 'not_null', 'non_empty', 'equals', 'not_equals', 'greater_than',
            'greater_than_or_equal', 'less_than', 'less_than_or_equal', 'type', 'matches',
        ], true)) {
            self::fail($field, 'The JSON assertion operator is invalid.');
        }
    }

    /** @param list<string> $allowed */
    private function rejectUnknown(array $input, array $allowed, string $prefix = ''): void
    {
        $unknown = array_values(array_diff(array_keys($input), $allowed));

        if ($unknown !== []) {
            self::fail($prefix.$unknown[0], 'The field is not part of this contract.');
        }
    }

    private function rfc3339Rule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value)
                || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?(?:Z|[+-]\\d{2}:\\d{2})$/', $value) !== 1
                || strtotime($value) === false) {
                $fail("The {$attribute} must be an RFC3339 datetime.");
            }
        };
    }

    /**
     * Turn a validation exception into the API contract's stable error payload.
     *
     * @return never
     */
    public static function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
