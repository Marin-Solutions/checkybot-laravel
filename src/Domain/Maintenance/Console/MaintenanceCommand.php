<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Maintenance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Actions\CreateMaintenanceMode;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Data\CreateMaintenanceData;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Exceptions\ActiveMaintenanceModeExists;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Exceptions\ImmutableMaintenanceOperation;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\RecursiveRedactor;

final class MaintenanceCommand extends Command
{
    protected $signature = 'checkybot:maintenance
        {scope : project or global}
        {--project= : Project UUID for project scope}
        {--duration=60 : Duration in minutes (1-1440)}
        {--reason= : Optional reason}
        {--operation-id= : Idempotency UUID; generated when omitted}';

    protected $description = 'Start a scoped Checkybot maintenance mode';

    public function handle(CreateMaintenanceMode $create, RecursiveRedactor $redactor): int
    {
        $scope = (string) $this->argument('scope');
        $project = $this->option('project');
        $duration = filter_var($this->option('duration'), FILTER_VALIDATE_INT);
        $operationId = (string) ($this->option('operation-id') ?: Str::uuid());

        if (! in_array($scope, ['project', 'global'], true)
            || ($scope === 'project' && (! is_string($project) || ! Str::isUuid($project)))
            || ($scope === 'global' && $project !== null)
            || $duration === false || $duration < 1 || $duration > 1440
            || ! Str::isUuid($operationId)) {
            $this->error('Invalid maintenance scope, project, operation id, or duration.');

            return self::INVALID;
        }

        $reason = $this->option('reason');
        if (is_string($reason) && mb_strlen($reason) > 200) {
            $this->error('The reason may not be greater than 200 characters.');

            return self::INVALID;
        }

        try {
            $result = $create->execute(new CreateMaintenanceData(
                $operationId,
                $scope,
                $scope === 'project' ? $project : null,
                $duration,
                is_string($reason) ? (string) $redactor->redact($reason, 'reason') : null,
            ));
        } catch (ActiveMaintenanceModeExists|ImmutableMaintenanceOperation $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $verb = $result->created ? 'Started' : 'Replayed';
        $this->info("{$verb} {$scope} maintenance {$result->mode->public_id} until {$result->mode->ends_at->toRfc3339String()}.");

        return self::SUCCESS;
    }
}
