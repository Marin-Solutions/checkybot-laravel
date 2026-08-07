<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\AiSettingsData;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Exceptions\StaleAiSettings;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiProjectSetting;

final readonly class UpdateAiSettings
{
    public function __construct(private ReadAiSettings $read) {}

    public function execute(string $projectId, bool $enabled, int $expectedVersion): AiSettingsData
    {
        $projectId = strtolower($projectId);

        try {
            DB::transaction(function () use ($projectId, $enabled, $expectedVersion): void {
                $now = now();
                if ($expectedVersion === 0 && ! AiProjectSetting::query()->forProject($projectId)->exists()) {
                    AiProjectSetting::query()->create([
                        'project_id' => $projectId,
                        'enabled' => $enabled,
                        'version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    return;
                }

                $changed = AiProjectSetting::query()
                    ->forProject($projectId)
                    ->where('version', $expectedVersion)
                    ->update([
                        'enabled' => $enabled,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => $now,
                    ]);
                if ($changed !== 1) {
                    throw new StaleAiSettings;
                }
            }, 5);
        } catch (QueryException $exception) {
            if (AiProjectSetting::query()->forProject($projectId)->exists()) {
                throw new StaleAiSettings(previous: $exception);
            }
            throw $exception;
        }

        return $this->read->forProject($projectId);
    }
}
