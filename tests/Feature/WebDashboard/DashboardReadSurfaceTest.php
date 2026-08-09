<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\AuthorizedMonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroup;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Models\IncidentGroupMember;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Queries\IncidentTimelineReadModel;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Models\MaintenanceMode;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Contracts\MonitorType;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;

function dashboardState(
    string $project,
    string $type,
    string $state,
    string $severity,
    CarbonImmutable $observedAt,
    ?string $monitor = null,
): MonitorState {
    return MonitorState::query()->create([
        'project_id' => $project,
        'monitor_id' => $monitor ?? (string) Str::uuid(),
        'monitor_type' => $type,
        'state' => $state,
        'severity' => $severity,
        'observed_at' => $observedAt,
        'entered_at' => $observedAt,
    ]);
}

function dashboardOperator(string $currentProject, array $authorizedProjects): GenericUser
{
    return new GenericUser([
        'id' => 'dashboard-operator',
        'current_project_id' => $currentProject,
        'project_ids' => $authorizedProjects,
    ]);
}

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/web-dashboard-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->dashboardDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->dashboardDatabase);
    config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->dashboardDatabase,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 10000,
    ]);
    DB::purge('sqlite');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_010000_create_alerting_result_runtime_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_010100_create_alerting_incident_group_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_010200_create_maintenance_mode_tables.php')->up();
    CarbonImmutable::setTestNow('2026-08-07T12:00:00Z');
    $this->inertiaHeaders = ['X-Inertia' => 'true', 'Accept' => 'application/json'];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    DB::disconnect('sqlite');
    @unlink($this->dashboardDatabase);
});

it('authorizes exactly the current project and returns bounded filtered dashboard props', function (): void {
    $project = (string) Str::uuid();
    $otherProject = (string) Str::uuid();
    $now = CarbonImmutable::now();

    $serverHealthy = dashboardState($project, 'server', 'healthy', 'warn', $now->subSeconds(10));
    dashboardState($project, 'server', 'warn', 'warn', $now->subSeconds(9));
    dashboardState($project, 'server', 'recovering', 'warn', $now->subSeconds(8));
    dashboardState($project, 'server', 'down', 'critical', $now->subSeconds(7));
    dashboardState($project, 'website', 'healthy', 'warn', $now->subSeconds(6));
    dashboardState($project, 'website', 'warn', 'warn', $now->subSeconds(5));
    dashboardState($project, 'website', 'down', 'critical', $now->subSeconds(4));
    dashboardState($project, 'api', 'healthy', 'warn', $now->subSeconds(3));
    $apiWarn = dashboardState($project, 'api', 'warn', 'warn', $now->subSeconds(2));
    dashboardState($project, 'api', 'down', 'critical', $now->subSecond());
    $foreign = dashboardState($otherProject, 'api', 'down', 'critical', $now);

    MaintenanceMode::query()->create([
        'operation_id' => (string) Str::uuid(),
        'payload_hash' => hash('sha256', 'global-dashboard-maintenance'),
        'scope' => 'global',
        'project_id' => null,
        'reason' => 'Contact operator@example.test before ending maintenance',
        'starts_at' => $now->subMinute(),
        'ends_at' => $now->addMinutes(20),
    ]);

    $this->withHeaders($this->inertiaHeaders)->get('/checkybot')->assertRedirect('/login');

    $operator = dashboardOperator($project, [$project, $otherProject]);
    $response = $this->actingAs($operator)->withHeaders($this->inertiaHeaders)
        ->get('/checkybot?project_uuid='.$otherProject)
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'CheckybotDashboard/Overview')
        ->assertJsonPath('props.filters.states', ['warn', 'down', 'recovering'])
        ->assertJsonPath('props.summary.stale', false)
        ->assertJsonPath('props.summary.counts', [
            'servers' => ['healthy' => 1, 'warn' => 2, 'down' => 1],
            'websites' => ['healthy' => 1, 'warn' => 1, 'down' => 1],
            'apis' => ['healthy' => 1, 'warn' => 1, 'down' => 1],
        ])
        ->assertJsonPath('props.maintenance.silenced', true)
        ->assertJsonPath('props.maintenance.effective_scope', 'global')
        ->assertJsonPath('props.maintenance.ends_at', $now->addMinutes(20)->toRfc3339String());

    expect($response->json('props.summary.updated_at'))->toBe($now->subSecond()->toRfc3339String())
        ->and($response->json('props.maintenance.reason'))->toBe('Contact [REDACTED] before ending maintenance')
        ->and(collect($response->json('props.problems'))->pluck('identity.project_id')->unique()->all())->toBe([$project])
        ->and(collect($response->json('props.problems'))->pluck('identity.monitor_id'))->not->toContain($serverHealthy->monitor_id, $foreign->monitor_id);

    $filtered = $this->withHeaders($this->inertiaHeaders)->get('/checkybot?types[]=api&states[]=warn&severities[]=warn&monitor_uuids[]='.$apiWarn->monitor_id)
        ->assertOk()
        ->assertJsonPath('props.filters', [
            'types' => ['api'],
            'states' => ['warn'],
            'severities' => ['warn'],
            'monitor_uuids' => [$apiWarn->monitor_id],
        ]);
    expect($filtered->json('props.problems'))->toHaveCount(1)
        ->and($filtered->json('props.problems.0.identity.monitor_id'))->toBe($apiWarn->monitor_id);

    $firstPage = $this->withHeaders($this->inertiaHeaders)->get('/checkybot?per_page=2')->assertOk();
    $cursor = $firstPage->json('props.pagination.next_cursor');
    expect($firstPage->json('props.problems'))->toHaveCount(2)->and($cursor)->toBeString();
    $secondPage = $this->withHeaders($this->inertiaHeaders)->get('/checkybot?per_page=2&cursor='.urlencode($cursor))->assertOk();
    expect(collect([...$firstPage->json('props.problems'), ...$secondPage->json('props.problems')])->pluck('identity.monitor_id'))
        ->not->toContain($foreign->monitor_id);

    foreach ([
        '/checkybot?types[]=database',
        '/checkybot?states[]=healthy',
        '/checkybot?severities[]=urgent',
        '/checkybot?monitor_uuids[]=not-a-uuid',
        '/checkybot?per_page=101',
        '/checkybot?cursor=not-opaque',
        '/checkybot?types[]=api&types[]=api',
    ] as $invalidUrl) {
        $this->withHeaders($this->inertiaHeaders)->get($invalidUrl)
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors']);
    }

    $this->actingAs(dashboardOperator($project, [$otherProject]))
        ->withHeaders($this->inertiaHeaders)->get('/checkybot')->assertForbidden();
})->group('AC-web-dashboard-api-builder-1');

it('returns the authorized read model timeline and distinguishes wrong unknown and foreign monitors', function (): void {
    $project = (string) Str::uuid();
    $foreignProject = (string) Str::uuid();
    $monitor = (string) Str::uuid();
    $foreignMonitor = (string) Str::uuid();
    $start = CarbonImmutable::parse('2026-08-07T10:00:00Z');
    dashboardState($project, 'website', 'healthy', 'warn', $start->addSeconds(70), $monitor);
    dashboardState($foreignProject, 'website', 'down', 'critical', $start, $foreignMonitor);

    $group = IncidentGroup::query()->create([
        'project_id' => $project,
        'severity' => 'critical',
        'notification_thread_key' => 'incident:'.$project.':'.Str::uuid(),
        'opened_at' => $start,
        'latest_member_at' => $start,
        'collection_due_at' => $start->addSeconds(30),
        'closed_at' => $start->addSeconds(70),
    ]);
    IncidentGroupMember::query()->create([
        'group_id' => $group->public_id,
        'project_id' => $project,
        'monitor_id' => $monitor,
        'monitor_type' => 'website',
        'severity' => 'critical',
        'down_transition_id' => (string) Str::uuid(),
        'confirmed_down_at' => $start,
        'healthy_transition_id' => (string) Str::uuid(),
        'confirmed_healthy_at' => $start->addSeconds(70),
    ]);

    foreach ([
        ['healthy', 'down', 'critical', $start, 'connection-timeout', false],
        ['down', 'recovering', 'warn', $start->addSeconds(40), null, true],
        ['recovering', 'healthy', 'warn', $start->addSeconds(70), 'probe-ok', false],
    ] as $index => [$from, $to, $severity, $occurredAt, $reason, $suppressed]) {
        $transition = MonitorTransition::query()->create([
            'project_id' => $project,
            'monitor_id' => $monitor,
            'monitor_type' => 'website',
            'operation_id' => (string) Str::uuid(),
            'operation_sequence' => $index + 1,
            'from_state' => $from,
            'to_state' => $to,
            'severity' => $severity,
            'reason_code' => $reason,
            'monitor_filter' => ['types' => ['website'], 'states' => [$to], 'severities' => [$severity]],
            'occurred_at' => $occurredAt,
            'entered_at' => $occurredAt,
        ]);
        DB::table('monitor_transitions')->where('id', $transition->getKey())->update([
            'incident_group_id' => $group->public_id,
            'maintenance_suppressed' => $suppressed,
        ]);
    }

    $this->actingAs(dashboardOperator($project, [$project]));
    $this->withHeaders($this->inertiaHeaders)->get('/checkybot/monitors/website/'.Str::uuid())->assertNotFound();
    $this->withHeaders($this->inertiaHeaders)->get('/checkybot/monitors/api/'.$monitor)->assertNotFound();
    $this->withHeaders($this->inertiaHeaders)->get('/checkybot/monitors/website/'.$foreignMonitor)->assertForbidden();

    $expected = app(IncidentTimelineReadModel::class)->forMonitor(new AuthorizedMonitorIdentity(
        new MonitorIdentity($project, $monitor, MonitorType::Website),
        $project,
    ));
    $response = $this->withHeaders($this->inertiaHeaders)->get('/checkybot/monitors/website/'.$monitor)
        ->assertOk()
        ->assertJsonPath('component', 'CheckybotDashboard/MonitorDetail')
        ->assertJsonPath('props.monitor.current_state', 'healthy')
        ->assertJsonPath('props.monitor.entered_at', $start->addSeconds(70)->toRfc3339String());

    expect($response->json('props.timeline'))->toBe([
        'transitions' => $expected['transitions'],
        'incident_groups' => $expected['incident_groups'],
        'annotation_slots' => $expected['annotation_slots'],
    ])->and(array_column($response->json('props.timeline.transitions'), 'duration_seconds'))->toBe([40, 30, null])
        ->and(array_column($response->json('props.timeline.transitions'), 'maintenance_suppressed'))->toBe([false, true, false])
        ->and($response->json('props.timeline.incident_groups.0.affected_monitors.0.monitor_uuid'))->toBe($monitor)
        ->and(array_column($response->json('props.timeline.annotation_slots'), 'value'))->toBe([null, null, null, null]);
})->group('AC-web-dashboard-api-builder-2');
