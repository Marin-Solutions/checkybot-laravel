<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Maintenance\Actions;

use Closure;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Data\CreateMaintenanceData;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Data\CreateMaintenanceResult;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Exceptions\ActiveMaintenanceModeExists;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Exceptions\ImmutableMaintenanceOperation;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Models\MaintenanceMode;
use Throwable;

final class CreateMaintenanceMode
{
    public function execute(CreateMaintenanceData $data): CreateMaintenanceResult
    {
        /** @var CreateMaintenanceResult $result */
        $result = $this->serializedTransaction(function () use ($data): CreateMaintenanceResult {
            $scopeKey = $data->scope === 'global' ? 'global' : 'project:'.$data->projectId;
            DB::table('maintenance_scope_locks')->insertOrIgnore([
                'scope_key' => $scopeKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('maintenance_scope_locks')->where('scope_key', $scopeKey)->lockForUpdate()->first();

            $replay = MaintenanceMode::query()->where('operation_id', $data->operationId)->first();
            if ($replay !== null) {
                if (! hash_equals($replay->payload_hash, $data->payloadHash())) {
                    throw new ImmutableMaintenanceOperation('An operation_id may represent only one maintenance request.');
                }

                return new CreateMaintenanceResult($replay, false);
            }

            $overlap = MaintenanceMode::query()
                ->where('scope', $data->scope)
                ->when($data->scope === 'global', fn ($query) => $query->whereNull('project_id'))
                ->when($data->scope === 'project', fn ($query) => $query->where('project_id', $data->projectId))
                ->whereNull('cleared_at')
                ->where('ends_at', '>', now())
                ->exists();
            if ($overlap) {
                throw new ActiveMaintenanceModeExists('An active maintenance mode already exists for this scope.');
            }

            $startsAt = now();
            $mode = MaintenanceMode::query()->create([
                'operation_id' => $data->operationId,
                'payload_hash' => $data->payloadHash(),
                'scope' => $data->scope,
                'project_id' => $data->scope === 'project' ? $data->projectId : null,
                'reason' => $data->reason,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes($data->durationMinutes),
            ]);

            return new CreateMaintenanceResult($mode, true);
        });

        return $result;
    }

    private function serializedTransaction(Closure $callback): mixed
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return DB::transaction($callback, 5);
        }

        DB::statement('BEGIN IMMEDIATE');
        try {
            $result = $callback();
            DB::statement('COMMIT');

            return $result;
        } catch (Throwable $exception) {
            if (DB::connection()->getPdo()->inTransaction()) {
                DB::statement('ROLLBACK');
            }
            throw $exception;
        }
    }
}
