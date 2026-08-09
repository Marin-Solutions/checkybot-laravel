<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\BudgetReservationResult;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Exceptions\BudgetExceeded;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiBudgetReservation;
use RuntimeException;

final class BudgetLedger
{
    public function reserve(string $operationId, string $projectId, ?CarbonImmutable $at = null): BudgetReservationResult
    {
        $projectId = strtolower($projectId);
        $period = ($at ?? CarbonImmutable::now('UTC'))->utc()->format('Y-m');
        $maximum = (int) config('ai-annotations.budget.max_request_microusd', 0);
        $projectLimit = (int) config('ai-annotations.budget.project_monthly_limit_microusd', 0);
        $globalLimit = (int) config('ai-annotations.budget.global_monthly_limit_microusd', 0);
        if ($maximum < 1 || $projectLimit < $maximum || $globalLimit < $maximum) {
            return new BudgetReservationResult(false, false);
        }

        for ($attempt = 0; $attempt < 25; $attempt++) {
            try {
                return DB::transaction(function () use ($operationId, $projectId, $period, $maximum, $projectLimit, $globalLimit): BudgetReservationResult {
                    $existing = AiBudgetReservation::query()->where('operation_id', $operationId)->lockForUpdate()->first();
                    if ($existing !== null) {
                        return new BudgetReservationResult(true, false, $existing);
                    }

                    $now = now();
                    DB::table('ai_annotation_budget_buckets')->insertOrIgnore([
                        [
                            'scope_key' => 'global', 'project_id' => null, 'period' => $period,
                            'reserved_microusd' => 0, 'spent_microusd' => 0,
                            'created_at' => $now, 'updated_at' => $now,
                        ],
                        [
                            'scope_key' => 'project:'.$projectId, 'project_id' => $projectId, 'period' => $period,
                            'reserved_microusd' => 0, 'spent_microusd' => 0,
                            'created_at' => $now, 'updated_at' => $now,
                        ],
                    ]);

                    $projectChanged = $this->claimBucket('project:'.$projectId, $period, $maximum, $projectLimit, $now);
                    if ($projectChanged !== 1) {
                        throw new BudgetExceeded;
                    }
                    $globalChanged = $this->claimBucket('global', $period, $maximum, $globalLimit, $now);
                    if ($globalChanged !== 1) {
                        throw new BudgetExceeded;
                    }

                    $reservation = AiBudgetReservation::query()->create([
                        'operation_id' => $operationId,
                        'project_id' => $projectId,
                        'period' => $period,
                        'reserved_microusd' => $maximum,
                        'state' => 'reserved',
                    ]);

                    return new BudgetReservationResult(true, true, $reservation);
                });
            } catch (BudgetExceeded) {
                return new BudgetReservationResult(false, false);
            } catch (QueryException $exception) {
                if ($attempt === 24 || ! str_contains(strtolower($exception->getMessage()), 'database is locked')) {
                    throw $exception;
                }
                // SQLite cannot promote two deferred read transactions at once.
                // A short bounded jitter retries the whole atomic reservation;
                // MySQL/PostgreSQL continue to use their row-lock path once.
                usleep(10_000 + random_int(0, 20_000));
            }
        }

        return new BudgetReservationResult(false, false);
    }

    public function settle(string $operationId, int $billedMicrousd): bool
    {
        return DB::transaction(function () use ($operationId, $billedMicrousd): bool {
            $reservation = AiBudgetReservation::query()->where('operation_id', $operationId)->lockForUpdate()->first();
            if ($reservation === null || $reservation->state !== 'reserved') {
                return false;
            }
            if ($billedMicrousd < 0 || $billedMicrousd > $reservation->reserved_microusd) {
                throw new BudgetExceeded('Provider billing exceeded the reservation.');
            }

            foreach (['global', 'project:'.$reservation->project_id] as $scope) {
                $changed = DB::table('ai_annotation_budget_buckets')
                    ->where('scope_key', $scope)
                    ->where('period', $reservation->period)
                    ->where('reserved_microusd', '>=', $reservation->reserved_microusd)
                    ->update([
                        'reserved_microusd' => DB::raw('reserved_microusd - '.(int) $reservation->reserved_microusd),
                        'spent_microusd' => DB::raw('spent_microusd + '.(int) $billedMicrousd),
                        'updated_at' => now(),
                    ]);
                if ($changed !== 1) {
                    throw new RuntimeException('The AI budget ledger is inconsistent.');
                }
            }

            $reservation->forceFill([
                'state' => 'settled',
                'billed_microusd' => $billedMicrousd,
                'settled_at' => now(),
            ])->save();

            return true;
        }, 10);
    }

    public function release(string $operationId): bool
    {
        return DB::transaction(function () use ($operationId): bool {
            $reservation = AiBudgetReservation::query()->where('operation_id', $operationId)->lockForUpdate()->first();
            if ($reservation === null || $reservation->state !== 'reserved') {
                return false;
            }
            foreach (['global', 'project:'.$reservation->project_id] as $scope) {
                DB::table('ai_annotation_budget_buckets')
                    ->where('scope_key', $scope)
                    ->where('period', $reservation->period)
                    ->where('reserved_microusd', '>=', $reservation->reserved_microusd)
                    ->decrement('reserved_microusd', $reservation->reserved_microusd, ['updated_at' => now()]);
            }
            $reservation->forceFill(['state' => 'released', 'settled_at' => now()])->save();

            return true;
        }, 10);
    }

    private function claimBucket(string $scope, string $period, int $amount, int $limit, mixed $now): int
    {
        return DB::table('ai_annotation_budget_buckets')
            ->where('scope_key', $scope)
            ->where('period', $period)
            ->whereRaw('reserved_microusd + spent_microusd <= ?', [$limit - $amount])
            ->update([
                'reserved_microusd' => DB::raw('reserved_microusd + '.$amount),
                'updated_at' => $now,
            ]);
    }
}
