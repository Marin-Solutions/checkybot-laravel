<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Actions;

use Carbon\CarbonImmutable;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Contracts\RedactedLogSnippetProvider;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\AuthorizedLogSnippetRequest;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiAnnotationOperation;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Models\AiProjectSetting;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use Throwable;

final readonly class ProcessIncidentAnnotation
{
    public function __construct(
        private RedactedLogSnippetProvider $snippets,
        private GenerateIncidentAnnotation $generate,
        private NotificationSideEffectSnapshot $sideEffects,
    ) {}

    public function execute(string $operationId): void
    {
        $claimed = AiAnnotationOperation::query()
            ->where('operation_id', $operationId)
            ->where('status', 'queued')
            ->update(['status' => 'processing', 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        $operation = AiAnnotationOperation::query()->where('operation_id', $operationId)->firstOrFail();
        $operation->forceFill([
            'notification_side_effects' => [
                'before' => $this->sideEffects->capture($operation->project_id),
                'after' => null,
            ],
        ])->save();
        try {
            $transition = $this->eligibleTransition($operation);
            if ($transition === null) {
                $this->finish($operation, 'skipped', 'missing_transition');

                return;
            }
            if (! AiProjectSetting::query()->forProject($operation->project_id)->where('enabled', true)->exists()) {
                $this->finish($operation, 'skipped', 'disabled');

                return;
            }

            $occurredAt = CarbonImmutable::instance($transition->occurred_at)->utc();
            $before = max(1, min(3600, (int) config('ai-annotations.incident_window.before_seconds', 300)));
            $after = max(0, min(600, (int) config('ai-annotations.incident_window.after_seconds', 60)));
            $limit = max(1, min(200, (int) config('ai-annotations.limits.max_lines', 100)));
            $snippet = $this->snippets->forIncident(new AuthorizedLogSnippetRequest(
                identity: new MonitorIdentity($transition->project_id, $transition->monitor_id, MonitorType::Server),
                authorizedProjectId: $transition->project_id,
                from: $occurredAt->subSeconds($before),
                to: $occurredAt->addSeconds($after),
                sources: ['nginx', 'fpm', 'mysql'],
                limit: $limit,
            ));

            $operation->forceFill([
                'snippet_line_count' => min(65535, count($snippet->lines)),
                'snippet_truncated' => $snippet->truncated,
                'redaction_version' => mb_substr($snippet->redactionVersion, 0, 40),
            ])->save();

            if ($snippet->alertEligible) {
                $this->finish($operation, 'skipped', 'alert_eligible');

                return;
            }
            if ($snippet->denied) {
                $this->finish($operation, 'skipped', 'snippet_denied');

                return;
            }
            if ($snippet->lines === []) {
                $this->finish($operation, 'skipped', 'empty_context');

                return;
            }

            $this->generate->execute(
                operationId: $operation->operation_id,
                transitionOperationId: $transition->operation_id,
                projectId: $transition->project_id,
                lines: $snippet->lines,
                truncated: $snippet->truncated,
                redactionVersion: $snippet->redactionVersion,
            );
            $this->recordAfter($operation);
        } catch (Throwable) {
            $this->finish($operation, 'failed', 'processing_failure');
        }
    }

    private function eligibleTransition(AiAnnotationOperation $operation): ?MonitorTransition
    {
        return MonitorTransition::query()
            ->where('operation_id', $operation->transition_operation_id)
            ->where('project_id', $operation->project_id)
            ->where('monitor_type', 'server')
            ->where('to_state', 'down')
            ->first();
    }

    private function finish(AiAnnotationOperation $operation, string $status, string $reason): void
    {
        AiAnnotationOperation::query()
            ->whereKey($operation->getKey())
            ->where('status', 'processing')
            ->update(['status' => $status, 'skip_reason' => $reason, 'updated_at' => now()]);
        $this->recordAfter($operation);
    }

    private function recordAfter(AiAnnotationOperation $operation): void
    {
        $sideEffects = $operation->notification_side_effects ?? ['before' => null];
        $sideEffects['after'] = $this->sideEffects->capture($operation->project_id);
        AiAnnotationOperation::query()->whereKey($operation->getKey())->update([
            'notification_side_effects' => $sideEffects,
            'updated_at' => now(),
        ]);
    }
}
