<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Jobs\ProcessIncidentTransition;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\AlertingMonitorRuntime;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\AlertingResult;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\PullRetryRequest;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Support\MaintenanceSilencer;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\FoundationContract;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;
use Ramsey\Uuid\Uuid;
use Throwable;

final readonly class ProcessAcceptedMonitorResult
{
    public function __construct(private MaintenanceSilencer $silencer) {}

    public function execute(string $operationId): void
    {
        $this->serializedTransaction(function () use ($operationId): void {
            $result = AlertingResult::query()->where('operation_id', $operationId)->lockForUpdate()->firstOrFail();
            if ($result->status !== 'queued') {
                return;
            }
            $result->forceFill(['status' => 'processing'])->save();

            $runtime = AlertingMonitorRuntime::query()->firstOrCreate($this->identity($result), [
                'pull_failure_streak' => 0,
                'push_streak' => 0,
                'recovery_streak' => 0,
            ]);
            $runtime = AlertingMonitorRuntime::query()->whereKey($runtime->getKey())->lockForUpdate()->firstOrFail();

            $state = MonitorState::query()->firstOrCreate($this->identity($result), [
                'state' => 'healthy',
                'severity' => 'warn',
                'observed_at' => $result->observed_at,
                'entered_at' => $result->observed_at,
            ]);
            $state = MonitorState::query()->whereKey($state->getKey())->lockForUpdate()->firstOrFail();

            if ($runtime->last_processed_observed_at !== null
                && $result->observed_at->lessThanOrEqualTo($runtime->last_processed_observed_at)) {
                $this->complete($result);

                return;
            }

            if ($result->source === 'pull') {
                $this->processPull($result, $runtime, $state);
            } else {
                $this->processPush($result, $runtime, $state);
            }

            $runtime->last_processed_observed_at = $result->observed_at;
            $runtime->save();
            $state->observed_at = $result->observed_at;
            $state->save();
            $this->complete($result);
        });

        $this->dispatchIncidentTransitions($operationId);
    }

    private function processPull(AlertingResult $result, AlertingMonitorRuntime $runtime, MonitorState $state): void
    {
        $runtime->push_band = null;
        $runtime->push_streak = 0;
        $runtime->recovery_streak = 0;

        if ($result->signal === 'success') {
            $this->cancelPendingRetries($result);
            $runtime->pull_failure_streak = 0;
            $runtime->pull_failure_started_at = null;
            if ($state->state->value !== 'healthy') {
                $this->transition($result, $state, 'healthy', 'warn');
            }

            return;
        }

        $runtime->pull_failure_streak++;
        if ($runtime->pull_failure_streak === 1) {
            $runtime->pull_failure_started_at = $result->observed_at;
            if ($state->state->value === 'healthy') {
                $this->transition($result, $state, 'warn', 'warn');
            }
            $this->scheduleRetry($result, 2, $result->observed_at->addSeconds(10));

            return;
        }

        if ($runtime->pull_failure_streak === 2) {
            $startedAt = $runtime->pull_failure_started_at ?? $result->observed_at;
            $this->scheduleRetry($result, 3, $startedAt->addSeconds(30));

            return;
        }

        if ($state->state->value !== 'down') {
            $this->transition($result, $state, 'down', 'critical');
        }
    }

    private function processPush(AlertingResult $result, AlertingMonitorRuntime $runtime, MonitorState $state): void
    {
        $this->cancelPendingRetries($result);
        $runtime->pull_failure_streak = 0;
        $runtime->pull_failure_started_at = null;
        $thresholds = $result->thresholds;
        $signal = $result->signal;
        $recoveryEligible = $signal === 'healthy'
            && $result->value <= ((float) $thresholds['warn'] - (float) $thresholds['recovery_delta']);
        $current = $state->state->value;

        if ($current !== 'healthy' && $recoveryEligible) {
            $runtime->push_band = null;
            $runtime->push_streak = 0;
            $runtime->recovery_streak++;
            if ($runtime->recovery_streak >= 3) {
                $runtime->recovery_streak = 0;
                $this->transition($result, $state, 'healthy', 'warn');
            }

            return;
        }

        $runtime->recovery_streak = 0;
        if (! in_array($signal, ['warn', 'critical'], true)) {
            $runtime->push_band = null;
            $runtime->push_streak = 0;

            return;
        }

        if ($runtime->push_band !== $signal) {
            $runtime->push_band = $signal;
            $runtime->push_streak = 1;
        } else {
            $runtime->push_streak++;
        }

        if ($runtime->push_streak < 3) {
            return;
        }

        $runtime->push_band = null;
        $runtime->push_streak = 0;
        if ($signal === 'warn' && $current === 'healthy') {
            $this->transition($result, $state, 'warn', 'warn');
        } elseif ($signal === 'critical' && $current === 'healthy') {
            $this->transition($result, $state, 'warn', 'warn', 0);
            $this->transition($result, $state, 'down', 'critical', 1);
        } elseif ($signal === 'critical' && $current === 'warn') {
            $this->transition($result, $state, 'down', 'critical');
        }
    }

    private function transition(
        AlertingResult $result,
        MonitorState $state,
        string $to,
        string $severity,
        int $derivedIndex = 0,
    ): void {
        $from = $state->state->value;
        if ($from === $to) {
            return;
        }
        $operationId = $derivedIndex === 0
            ? $result->operation_id
            : Uuid::uuid5(Uuid::NAMESPACE_URL, $result->operation_id."#transition-{$derivedIndex}")->toString();
        $sequence = ((int) MonitorTransition::query()
            ->where($this->identity($result))
            ->max('operation_sequence')) + 1;
        $filter = ['types' => [$result->monitor_type], 'states' => [$to], 'severities' => [$severity]];

        $transition = new MonitorTransition;
        $transition->forceFill([
            ...$this->identity($result),
            'operation_id' => $operationId,
            'operation_sequence' => $sequence,
            'from_state' => $from,
            'to_state' => $to,
            'severity' => $severity,
            'reason_code' => $result->reason_code,
            'monitor_filter' => $filter,
            'occurred_at' => $result->observed_at,
            'entered_at' => $result->observed_at,
            'maintenance_suppressed' => $this->silencer->isSilencedNow($result->project_id),
        ])->save();

        $state->setAttribute('state', $to);
        $state->setAttribute('severity', $severity);
        $state->entered_at = $result->observed_at;
        $state->save();

        $payload = [
            'contract_version' => FoundationContract::VERSION,
            'identity' => [
                'project_id' => $result->project_id,
                'monitor_id' => $result->monitor_id,
                'type' => $result->monitor_type,
            ],
            'from_state' => $from,
            'to_state' => $to,
            'severity' => $severity,
            'filter' => $filter,
            'occurred_at' => $result->observed_at->toRfc3339String(),
        ];
        OutboxEvent::query()->create([
            'operation_id' => $operationId,
            'event_type' => 'monitor.transitioned',
            'contract_version' => FoundationContract::VERSION,
            'payload' => $payload,
            'status' => 'pending',
            'available_at' => now(),
        ]);
    }

    private function dispatchIncidentTransitions(string $operationId): void
    {
        $result = AlertingResult::query()->where('operation_id', $operationId)->first();
        if ($result === null || $result->status !== 'processed') {
            return;
        }

        $derivedOperationId = Uuid::uuid5(Uuid::NAMESPACE_URL, $operationId.'#transition-1')->toString();
        $transitionIds = MonitorTransition::query()
            ->where('project_id', $result->project_id)
            ->whereIn('operation_id', [$operationId, $derivedOperationId])
            ->whereIn('to_state', ['down', 'healthy'])
            ->pluck('public_id');

        foreach ($transitionIds as $transitionId) {
            ProcessIncidentTransition::dispatch((string) $transitionId);
        }
    }

    private function cancelPendingRetries(AlertingResult $result): void
    {
        PullRetryRequest::query()
            ->where($this->identity($result))
            ->whereNull('requested_at')
            ->whereNull('canceled_at')
            ->update(['canceled_at' => now(), 'updated_at' => now()]);
    }

    private function scheduleRetry(AlertingResult $result, int $attemptNumber, CarbonImmutable $dueAt): void
    {
        PullRetryRequest::query()->create([
            'public_id' => (string) Str::uuid(),
            ...$this->identity($result),
            'result_operation_id' => $result->operation_id,
            'attempt_number' => $attemptNumber,
            'due_at' => $dueAt,
        ]);
    }

    /** @return array{project_id: string, monitor_id: string, monitor_type: string} */
    private function identity(AlertingResult $result): array
    {
        return [
            'project_id' => $result->project_id,
            'monitor_id' => $result->monitor_id,
            'monitor_type' => $result->monitor_type,
        ];
    }

    private function complete(AlertingResult $result): void
    {
        $result->forceFill(['status' => 'processed', 'processed_at' => now(), 'failure_code' => null])->save();
    }

    private function serializedTransaction(\Closure $callback): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::transaction($callback, 5);

            return;
        }

        DB::statement('BEGIN IMMEDIATE');
        try {
            $callback();
            DB::statement('COMMIT');
        } catch (Throwable $exception) {
            if (DB::connection()->getPdo()->inTransaction()) {
                DB::statement('ROLLBACK');
            }
            throw $exception;
        }
    }
}
