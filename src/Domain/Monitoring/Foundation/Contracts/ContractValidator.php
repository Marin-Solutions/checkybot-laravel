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
        $this->rejectUnknown($input, ['contract_version', 'uptime', 'ssl', 'api', 'dead_links', 'open_graph']);
        $rules = ['contract_version' => ['required', Rule::in([FoundationContract::CHECK_SYNC_VERSION])]];
        foreach (['uptime', 'ssl', 'api', 'dead_links', 'open_graph'] as $type) {
            $rules[$type] = ['required', 'array'];
            $rules["{$type}.*.name"] = ['required', 'string', 'max:255'];
            $rules["{$type}.*.url"] = ['required', 'url:http,https'];
            $rules["{$type}.*.interval"] = ['required', 'regex:/^\\d+[smhd]$/'];
        }

        return Validator::make($input, $rules)->validate();
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
