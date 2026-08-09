<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use Ramsey\Uuid\Uuid;

final readonly class NormalizedMonitorResult
{
    public function __construct(
        public string $operationId,
        public MonitorIdentity $identity,
        public string $source,
        public CarbonImmutable $observedAt,
        public string $signal,
        public ?string $reasonCode = null,
        public ?float $value = null,
        public ?NormalizedThresholds $thresholds = null,
    ) {
        if (! Uuid::isValid($operationId)) {
            throw new InvalidArgumentException('The operation id must be a UUID.');
        }
        if (! in_array($source, ['pull', 'push'], true)) {
            throw new InvalidArgumentException('The result source must be pull or push.');
        }
        if ($observedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('The observed time must be UTC.');
        }
        if ($reasonCode !== null && (mb_strlen($reasonCode) > 120 || preg_match('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F]/', $reasonCode))) {
            throw new InvalidArgumentException('The reason code must be a bounded single-line string.');
        }

        if ($source === 'pull') {
            if (! in_array($signal, ['success', 'failure'], true) || $thresholds !== null || $value !== null) {
                throw new InvalidArgumentException('Pull results accept success or failure and cannot contain push metric data.');
            }

            return;
        }

        if (! in_array($signal, ['healthy', 'warn', 'critical'], true)
            || $thresholds === null
            || $value === null
            || ! is_finite($value)) {
            throw new InvalidArgumentException('Push results require a finite value, thresholds, and a lifecycle-compatible signal.');
        }

        $expected = $value >= $thresholds->critical ? 'critical' : ($value >= $thresholds->warn ? 'warn' : 'healthy');
        if ($signal !== $expected) {
            throw new InvalidArgumentException('The push signal is incompatible with its value and thresholds.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        self::rejectUnknown($input, ['operation_id', 'identity', 'source', 'observed_at', 'signal', 'reason_code', 'value', 'thresholds']);
        if (isset($input['identity']) && is_array($input['identity'])) {
            self::rejectUnknown($input['identity'], ['project_uuid', 'monitor_uuid', 'type'], 'identity.');
        }
        if (isset($input['thresholds']) && is_array($input['thresholds'])) {
            self::rejectUnknown($input['thresholds'], ['warn', 'critical', 'recovery_delta'], 'thresholds.');
        }

        $validated = Validator::make($input, [
            'operation_id' => ['required', 'uuid'],
            'identity' => ['required', 'array'],
            'identity.project_uuid' => ['required', 'uuid'],
            'identity.monitor_uuid' => ['required', 'uuid'],
            'identity.type' => ['required', Rule::enum(MonitorType::class)],
            'source' => ['required', Rule::in(['pull', 'push'])],
            'observed_at' => ['required', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value)
                    || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?(?:Z|\\+00:00)$/', $value) !== 1
                    || strtotime($value) === false) {
                    $fail('The observed_at must be an RFC3339 UTC datetime.');
                }
            }],
            'signal' => ['required', 'string'],
            'reason_code' => ['nullable', 'string', 'max:120', 'not_regex:/[\\r\\n]/'],
            'value' => ['nullable', 'numeric'],
            'thresholds' => ['nullable', 'array'],
            'thresholds.warn' => ['required_if:source,push', 'numeric'],
            'thresholds.critical' => ['required_if:source,push', 'numeric'],
            'thresholds.recovery_delta' => ['sometimes', 'numeric'],
        ])->validate();

        try {
            $observedAt = CarbonImmutable::parse($validated['observed_at'])->utc();
            $thresholds = $validated['source'] === 'push'
                ? new NormalizedThresholds(
                    (float) $validated['thresholds']['warn'],
                    (float) $validated['thresholds']['critical'],
                    (float) ($validated['thresholds']['recovery_delta'] ?? 5),
                )
                : null;

            return new self(
                $validated['operation_id'],
                new MonitorIdentity(
                    $validated['identity']['project_uuid'],
                    $validated['identity']['monitor_uuid'],
                    MonitorType::from($validated['identity']['type']),
                ),
                $validated['source'],
                $observedAt,
                $validated['signal'],
                $validated['reason_code'] ?? null,
                isset($validated['value']) ? (float) $validated['value'] : null,
                $thresholds,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['result' => [$exception->getMessage()]]);
        }
    }

    /** @param list<string> $allowed */
    private static function rejectUnknown(array $input, array $allowed, string $prefix = ''): void
    {
        $unknown = array_values(array_diff(array_keys($input), $allowed));
        if ($unknown !== []) {
            throw ValidationException::withMessages([$prefix.$unknown[0] => ['The field is not part of this contract.']]);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'operation_id' => $this->operationId,
            'identity' => [
                'project_uuid' => $this->identity->projectId,
                'monitor_uuid' => $this->identity->monitorId,
                'type' => $this->identity->type->value,
            ],
            'source' => $this->source,
            'observed_at' => $this->observedAt->toRfc3339String(),
            'signal' => $this->signal,
            'reason_code' => $this->reasonCode,
            'value' => $this->value,
            'thresholds' => $this->thresholds?->toArray(),
        ];
    }
}
