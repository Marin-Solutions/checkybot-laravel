<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Support;

use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\ContractValidator;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;

final readonly class AcceptFoundationEvent
{
    public function __construct(private ContractValidator $validator) {}

    /** @param array<string, mixed> $input */
    public function execute(array $input): OutboxEvent
    {
        $event = $this->validator->validateEvent($input);

        return DB::transaction(function () use ($event): OutboxEvent {
            if ($event['event_type'] === 'monitor.transitioned') {
                $this->persistTransition($event['operation_id'], $event['payload']);
            }

            return OutboxEvent::query()->create([
                'operation_id' => $event['operation_id'],
                'event_type' => $event['event_type'],
                'contract_version' => $event['payload']['contract_version'],
                'payload' => $event['payload'],
                'status' => 'pending',
                'available_at' => now(),
            ]);
        });
    }

    /** @param array<string, mixed> $payload */
    private function persistTransition(string $operationId, array $payload): void
    {
        $identity = $payload['identity'];
        $identityQuery = MonitorState::query()
            ->where('project_id', $identity['project_id'])
            ->where('monitor_type', $identity['type'])
            ->where('monitor_id', $identity['monitor_id']);
        $identityQuery->lockForUpdate()->first();

        $sequence = ((int) MonitorTransition::query()
            ->where('project_id', $identity['project_id'])
            ->where('monitor_type', $identity['type'])
            ->where('monitor_id', $identity['monitor_id'])
            ->max('operation_sequence')) + 1;

        MonitorTransition::query()->create([
            'project_id' => $identity['project_id'],
            'monitor_id' => $identity['monitor_id'],
            'monitor_type' => $identity['type'],
            'operation_id' => $operationId,
            'operation_sequence' => $sequence,
            'from_state' => $payload['from_state'],
            'to_state' => $payload['to_state'],
            'severity' => $payload['severity'],
            'monitor_filter' => $payload['filter'],
            'occurred_at' => $payload['occurred_at'],
        ]);

        MonitorState::query()->updateOrCreate([
            'project_id' => $identity['project_id'],
            'monitor_id' => $identity['monitor_id'],
            'monitor_type' => $identity['type'],
        ], [
            'state' => $payload['to_state'],
            'severity' => $payload['severity'],
            'observed_at' => $payload['occurred_at'],
        ]);
    }
}
