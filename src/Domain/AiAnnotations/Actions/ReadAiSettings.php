<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Actions;

use Carbon\CarbonImmutable;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\AiSettingsData;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiBudgetBucket;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiProjectSetting;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Support\AiConfiguration;

final readonly class ReadAiSettings
{
    public function __construct(private AiConfiguration $configuration) {}

    public function forProject(string $projectId, ?CarbonImmutable $at = null): AiSettingsData
    {
        $projectId = strtolower($projectId);
        $period = ($at ?? CarbonImmutable::now('UTC'))->utc()->format('Y-m');
        $setting = AiProjectSetting::query()->forProject($projectId)->first();
        $bucket = AiBudgetBucket::query()->forProject($projectId)->where('period', $period)->first();
        $limit = max(0, (int) config('ai-annotations.budget.project_monthly_limit_microusd', 0));
        $reserved = $bucket === null ? 0 : $bucket->reserved_microusd;
        $spent = $bucket === null ? 0 : $bucket->spent_microusd;

        return new AiSettingsData(
            enabled: $setting === null ? false : $setting->enabled,
            version: $setting === null ? 0 : $setting->version,
            providerConfigured: $this->configuration->providerConfigured(),
            budget: [
                'period' => $period,
                'monthly_limit_microusd' => $limit,
                'reserved_microusd' => $reserved,
                'spent_microusd' => $spent,
                'remaining_microusd' => max(0, $limit - $reserved - $spent),
            ],
            updatedAt: $setting === null || $setting->updated_at === null
                ? null
                : $setting->updated_at->utc()->toRfc3339String(),
        );
    }
}
