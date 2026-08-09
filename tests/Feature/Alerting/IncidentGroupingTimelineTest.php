<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions\EmitDueIncidentIntent;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions\GroupIncidentTransition;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\AuthorizedMonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Jobs\EmitIncidentIntent;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroup;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroupMember;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\NotificationIntent;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Queries\IncidentTimelineReadModel;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use MarinSolutions\CheckybotLaravel\Models\OutboxEvent;

function groupingTransition(
    string $project,
    string $monitor,
    string $to,
    string $severity,
    CarbonImmutable $at,
    string $type = 'website',
    bool $maintenanceSuppressed = false,
    ?string $reason = 'probe-failure',
): MonitorTransition {
    $identity = ['project_id' => $project, 'monitor_id' => $monitor, 'monitor_type' => $type];
    $state = MonitorState::query()->firstOrCreate($identity, [
        'state' => 'healthy',
        'severity' => 'warn',
        'observed_at' => $at->subSecond(),
        'entered_at' => $at->subSecond(),
    ]);
    $sequence = ((int) MonitorTransition::query()->where($identity)->max('operation_sequence')) + 1;
    $transition = MonitorTransition::query()->create([
        ...$identity,
        'operation_id' => (string) Str::uuid(),
        'operation_sequence' => $sequence,
        'from_state' => $state->state->value,
        'to_state' => $to,
        'severity' => $severity,
        'reason_code' => $reason,
        'monitor_filter' => ['types' => [$type], 'states' => [$to], 'severities' => [$severity]],
        'occurred_at' => $at,
        'entered_at' => $at,
        'maintenance_suppressed' => $maintenanceSuppressed,
    ]);
    $state->forceFill([
        'state' => $to,
        'severity' => $severity,
        'observed_at' => $at,
        'entered_at' => $at,
    ])->save();
    DB::table('monitor_transitions')->where('id', $transition->getKey())->update([
        'reason_code' => $reason,
        'maintenance_suppressed' => $maintenanceSuppressed,
    ]);
    $transition->refresh();

    app(GroupIncidentTransition::class)->execute($transition->public_id);

    return $transition->fresh();
}

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/incident-grouping-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->groupingDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->groupingDatabase);

    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->groupingDatabase,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 10000,
    ]);
    DB::purge('sqlite');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_010000_create_alerting_result_runtime_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_010100_create_alerting_incident_group_tables.php')->up();
    Queue::fake();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    DB::disconnect('sqlite');
    @unlink($this->groupingDatabase);
});

it('holds thirty seconds then emits one complete same-project incident intent', function (): void {
    $start = CarbonImmutable::parse('2026-08-07T10:00:00+00:00');
    CarbonImmutable::setTestNow($start);
    $project = (string) Str::uuid();
    $firstMonitor = (string) Str::uuid();
    $secondMonitor = (string) Str::uuid();

    groupingTransition($project, $firstMonitor, 'down', 'warn', $start);
    groupingTransition($project, $secondMonitor, 'down', 'critical', $start->addSeconds(20), 'api');
    $group = IncidentGroup::query()->firstOrFail();

    CarbonImmutable::setTestNow($start->addSeconds(29));
    app(EmitDueIncidentIntent::class)->execute($group->public_id);
    Artisan::call('checkybot:alerting-groups');
    expect(NotificationIntent::query()->count())->toBe(0)
        ->and(Queue::pushed(EmitIncidentIntent::class))->toHaveCount(1);

    CarbonImmutable::setTestNow($start->addSeconds(30));
    Artisan::call('checkybot:alerting-groups');
    expect(Queue::pushed(EmitIncidentIntent::class))->toHaveCount(2);
    app(EmitDueIncidentIntent::class)->execute($group->public_id);

    $intent = NotificationIntent::query()->sole();
    expect($intent->phase)->toBe('incident')
        ->and($intent->payload['severity'])->toBe('critical')
        ->and($intent->payload['affected_monitors'])->toHaveCount(2)
        ->and($intent->payload['notification_thread_key'])->toBe($group->notification_thread_key)
        ->and($intent->payload['problem_filter'])->toBe([
            'route' => 'problems',
            'monitor_uuids' => [$firstMonitor, $secondMonitor],
        ])
        ->and(OutboxEvent::query()->where('event_type', 'notification.intent.created')->count())->toBe(1);
})->group('AC-alerting-reliability-core-6');

it('coalesces over a sliding five-minute window while preserving logical intent identity', function (): void {
    $start = CarbonImmutable::parse('2026-08-07T11:00:00+00:00');
    CarbonImmutable::setTestNow($start);
    $project = (string) Str::uuid();
    $first = (string) Str::uuid();
    $second = (string) Str::uuid();
    $third = (string) Str::uuid();

    groupingTransition($project, $first, 'down', 'critical', $start);
    $group = IncidentGroup::query()->sole();
    CarbonImmutable::setTestNow($start->addSeconds(30));
    app(EmitDueIncidentIntent::class)->execute($group->public_id);
    $originalIntent = NotificationIntent::query()->sole();
    $originalOperation = $originalIntent->operation_id;
    $originalThread = $group->notification_thread_key;

    $within = $start->addMinutes(4)->addSeconds(59);
    groupingTransition($project, $second, 'down', 'warn', $within, 'server');
    $sameGroup = IncidentGroup::query()->orderBy('id')->firstOrFail();
    $updatedIntent = NotificationIntent::query()->sole();
    expect(IncidentGroup::query()->count())->toBe(1)
        ->and($sameGroup->public_id)->toBe($group->public_id)
        ->and($sameGroup->notification_thread_key)->toBe($originalThread)
        ->and($updatedIntent->public_id)->toBe($originalIntent->public_id)
        ->and($updatedIntent->operation_id)->toBe($originalOperation)
        ->and($updatedIntent->payload['problem_filter']['monitor_uuids'])->toBe([$first, $second]);

    app(GroupIncidentTransition::class)->execute(
        MonitorTransition::query()->where('monitor_id', $second)->sole()->public_id,
    );
    expect(IncidentGroup::query()->count())->toBe(1)
        ->and(IncidentGroupMember::query()->count())->toBe(2)
        ->and(NotificationIntent::query()->count())->toBe(1)
        ->and(OutboxEvent::query()->where('event_type', 'notification.intent.created')->count())->toBe(1);

    $outside = $within->addMinutes(5)->addSecond();
    groupingTransition($project, $third, 'down', 'critical', $outside);
    expect(IncidentGroup::query()->count())->toBe(2);
    CarbonImmutable::setTestNow($outside->addSeconds(30));
    $newGroup = IncidentGroup::query()->orderByDesc('id')->firstOrFail();
    app(EmitDueIncidentIntent::class)->execute($newGroup->public_id);

    expect(NotificationIntent::query()->where('phase', 'incident')->count())->toBe(2)
        ->and(OutboxEvent::query()->where('event_type', 'notification.intent.created')->count())->toBe(2)
        ->and($newGroup->public_id)->not->toBe($group->public_id)
        ->and($newGroup->notification_thread_key)->not->toBe($originalThread);
})->group('AC-alerting-reliability-core-7');

it('waits for the final confirmed healthy member and emits one grouped recovery', function (): void {
    $start = CarbonImmutable::parse('2026-08-07T12:00:00+00:00');
    CarbonImmutable::setTestNow($start);
    $project = (string) Str::uuid();
    $first = (string) Str::uuid();
    $second = (string) Str::uuid();
    groupingTransition($project, $first, 'down', 'critical', $start);
    groupingTransition($project, $second, 'down', 'warn', $start->addSeconds(5), 'api');
    $group = IncidentGroup::query()->sole();
    CarbonImmutable::setTestNow($start->addSeconds(30));
    app(EmitDueIncidentIntent::class)->execute($group->public_id);
    $incident = NotificationIntent::query()->where('phase', 'incident')->sole();

    groupingTransition($project, $first, 'recovering', 'warn', $start->addSeconds(35));
    expect(NotificationIntent::query()->where('phase', 'recovery')->count())->toBe(0);
    groupingTransition($project, $first, 'healthy', 'warn', $start->addSeconds(45));
    expect(NotificationIntent::query()->where('phase', 'recovery')->count())->toBe(0)
        ->and(IncidentGroup::query()->sole()->closed_at)->toBeNull();

    $final = groupingTransition($project, $second, 'healthy', 'warn', $start->addSeconds(90), 'api');
    $recovery = NotificationIntent::query()->where('phase', 'recovery')->sole();
    expect($recovery->payload['notification_thread_key'])->toBe($incident->payload['notification_thread_key'])
        ->and($recovery->payload['affected_monitors'])->toHaveCount(2)
        ->and($recovery->payload['downtime_seconds'])->toBe(90)
        ->and($recovery->payload['phase'])->toBe('recovery')
        ->and(IncidentGroup::query()->sole()->closed_at->equalTo($start->addSeconds(90)))->toBeTrue();

    app(GroupIncidentTransition::class)->execute($final->public_id);
    expect(NotificationIntent::query()->where('phase', 'recovery')->count())->toBe(1)
        ->and(OutboxEvent::query()->where('event_type', 'notification.intent.created')->count())->toBe(2);
})->group('AC-alerting-reliability-core-8');

it('returns an authorized ordered incident timeline and rejects cross-project context', function (): void {
    $start = CarbonImmutable::parse('2026-08-07T13:00:00+00:00');
    CarbonImmutable::setTestNow($start);
    $project = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    groupingTransition($project, $monitor, 'down', 'critical', $start, reason: 'connection-timeout');
    CarbonImmutable::setTestNow($start->addSeconds(30));
    app(EmitDueIncidentIntent::class)->execute(IncidentGroup::query()->sole()->public_id);
    groupingTransition($project, $monitor, 'recovering', 'warn', $start->addSeconds(40), maintenanceSuppressed: true, reason: null);
    groupingTransition($project, $monitor, 'healthy', 'warn', $start->addSeconds(70), reason: 'probe-ok');

    $identity = new MonitorIdentity($project, $monitor, MonitorType::Website);
    $timeline = app(IncidentTimelineReadModel::class)->forMonitor(new AuthorizedMonitorIdentity($identity, $project));

    expect($timeline)->not->toBeNull()
        ->and($timeline['current_state'])->toBe('healthy')
        ->and($timeline['entered_at'])->toBe($start->addSeconds(70)->toRfc3339String())
        ->and(array_column($timeline['transitions'], 'to'))->toBe(['down', 'recovering', 'healthy'])
        ->and(array_column($timeline['transitions'], 'duration_seconds'))->toBe([40, 30, null])
        ->and(array_column($timeline['transitions'], 'maintenance_suppressed'))->toBe([false, true, false])
        ->and($timeline['transitions'][0]['reason_code'])->toBe('connection-timeout')
        ->and($timeline['transitions'][0]['group_id'])->toBe(IncidentGroup::query()->sole()->public_id)
        ->and($timeline['incident_groups'])->toHaveCount(1)
        ->and($timeline['incident_groups'][0]['closed_at'])->toBe($start->addSeconds(70)->toRfc3339String())
        ->and($timeline['incident_groups'][0]['affected_monitors'][0]['monitor_uuid'])->toBe($monitor)
        ->and(array_column($timeline['annotation_slots'], 'value'))->toBe([null, null, null, null]);

    $otherProject = (string) Str::uuid();
    expect(fn () => app(IncidentTimelineReadModel::class)->forMonitor(new AuthorizedMonitorIdentity($identity, $otherProject)))
        ->toThrow(AuthorizationException::class)
        ->and(MonitorTransition::query()->where('project_id', $otherProject)->count())->toBe(0);
})->group('AC-alerting-reliability-core-9');
