<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\MonitorResultIngestionInterface;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Actions\EvaluateDomainExpiry;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Actions\EvaluateResponseBudget;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Actions\RefreshDomainExpiry;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Contracts\DomainExpiryLookup;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data\DomainLookupResult;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Data\DomainName;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\DomainExpiryObservation;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedCheckEvaluation;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\ExpandedWebsiteMonitor;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Models\StoredCheckSpeedSample;
use MarinSolutions\CheckybotLaravel\Domain\ExpandedChecks\Support\RdapDomainExpiryLookup;
use MarinSolutions\CheckybotLaravel\Models\MonitorState;
use MarinSolutions\CheckybotLaravel\Models\MonitorTransition;
use MarinSolutions\CheckybotLaravel\Tests\Feature\ExpandedChecks\Support\RecordingExpandedIngestion;

beforeEach(function (): void {
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_010000_create_alerting_result_runtime_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_010100_create_alerting_incident_group_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_010200_create_maintenance_mode_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_032000_create_expanded_website_check_tables.php')->up();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function expandedRecordingIngestion(): RecordingExpandedIngestion
{
    $recording = new RecordingExpandedIngestion;
    app()->instance(MonitorResultIngestionInterface::class, $recording);

    return $recording;
}

it('canonicalizes Unicode and mixed-case domains and persists an authoritative RDAP result', function (): void {
    $domain = new DomainName('BÜCHER.Example.');
    expect($domain->ascii)->toBe('xn--bcher-kva.example');

    CarbonImmutable::setTestNow('2026-08-07T12:00:00Z');
    $handler = new MockHandler([new Response(200, [], json_encode([
        'events' => [['eventAction' => 'expiration', 'eventDate' => '2027-01-02T03:04:05Z']],
    ], JSON_THROW_ON_ERROR))]);
    $adapter = new RdapDomainExpiryLookup(new Client(['handler' => HandlerStack::create($handler)]), 1);
    $recording = expandedRecordingIngestion();
    $monitor = ExpandedWebsiteMonitor::domainExpiry((string) Str::uuid(), (string) Str::uuid(), 'BÜCHER.Example');

    (new RefreshDomainExpiry($adapter, $recording))->execute($monitor);

    $observation = DomainExpiryObservation::query()->sole();
    expect($observation->canonical_domain)->toBe('xn--bcher-kva.example')
        ->and($observation->source)->toBe('rdap')
        ->and($observation->expires_at->toRfc3339String())->toBe('2027-01-02T03:04:05+00:00')
        ->and($recording->results)->toHaveCount(1)
        ->and($recording->results[0]->signal)->toBe('success');
})->group('AC-agent-v2-expanded-monitors-10');

it('retains the authoritative observation and submits a redacted failure for every invalid lookup shape', function (string $failureCode): void {
    $at = CarbonImmutable::parse('2026-08-07T12:00:00Z');
    $monitor = ExpandedWebsiteMonitor::domainExpiry((string) Str::uuid(), (string) Str::uuid(), 'retained.example');
    DomainExpiryObservation::query()->create([
        'expanded_website_monitor_id' => $monitor->getKey(), 'canonical_domain' => 'retained.example',
        'expires_at' => $at->addYear(), 'source' => 'whois', 'fetched_at' => $at->subDay(),
    ]);
    $lookup = new class($failureCode, $at) implements DomainExpiryLookup
    {
        public function __construct(private string $code, private CarbonImmutable $at) {}

        public function lookup(DomainName $domain): DomainLookupResult
        {
            return DomainLookupResult::failure($domain, $this->code, true, $this->at);
        }
    };
    $recording = expandedRecordingIngestion();

    (new RefreshDomainExpiry($lookup, $recording))->execute($monitor, $at);

    $observation = DomainExpiryObservation::query()->sole();
    expect($observation->source)->toBe('whois')
        ->and($observation->expires_at->toRfc3339String())->toBe($at->addYear()->toRfc3339String())
        ->and($recording->results)->toHaveCount(1)
        ->and($recording->results[0]->signal)->toBe('failure')
        ->and($recording->results[0]->reasonCode)->toBe($failureCode)
        ->and($recording->results[0]->reasonCode)->toMatch('/^[a-z0-9_]+$/');
})->with(['domain_lookup_timeout', 'domain_lookup_malformed', 'domain_lookup_no_expiry'])
    ->group('AC-agent-v2-expanded-monitors-10');

it('evaluates domain expiry whole-day boundaries and rejects absent or stale observations', function (): void {
    $recording = expandedRecordingIngestion();
    $now = CarbonImmutable::parse('2026-08-07T12:00:00Z');
    $project = (string) Str::uuid();
    $expectations = [[31, 'healthy'], [30, 'warn'], [0, 'warn'], [-1, 'critical']];
    foreach ($expectations as $index => [$days, $signal]) {
        $monitor = ExpandedWebsiteMonitor::domainExpiry($project, (string) Str::uuid(), "case{$index}.example");
        DomainExpiryObservation::query()->create([
            'expanded_website_monitor_id' => $monitor->getKey(), 'canonical_domain' => "case{$index}.example",
            'expires_at' => $days < 0 ? $now->subSecond() : $now->addDays($days), 'source' => 'rdap', 'fetched_at' => $now,
        ]);
        expect($this->app->make(EvaluateDomainExpiry::class)->execute($monitor, $now))->toBeTrue();
        expect($recording->results[array_key_last($recording->results)]->signal)->toBe($signal);
    }

    $absent = ExpandedWebsiteMonitor::domainExpiry($project, (string) Str::uuid(), 'absent.example');
    $stale = ExpandedWebsiteMonitor::domainExpiry($project, (string) Str::uuid(), 'stale.example');
    DomainExpiryObservation::query()->create([
        'expanded_website_monitor_id' => $stale->getKey(), 'canonical_domain' => 'stale.example',
        'expires_at' => $now->addYear(), 'source' => 'rdap', 'fetched_at' => $now->subDays(2),
    ]);
    expect($this->app->make(EvaluateDomainExpiry::class)->execute($absent, $now))->toBeFalse()
        ->and($this->app->make(EvaluateDomainExpiry::class)->execute($stale, $now))->toBeFalse()
        ->and($recording->results)->toHaveCount(4)
        ->and(ExpandedCheckEvaluation::query()->where('status', 'unavailable')->count())->toBe(2)
        ->and(collect($recording->results)->pluck('identity.projectId')->unique()->all())->toBe([$project])
        ->and(collect($recording->results)->pluck('operationId')->unique())->toHaveCount(4);
})->group('AC-agent-v2-expanded-monitors-11');

it('computes target-isolated nearest-rank p95 and never reports invalid data healthy', function (): void {
    $recording = expandedRecordingIngestion();
    $now = CarbonImmutable::parse('2026-08-07T12:00:00Z');
    $project = (string) Str::uuid();
    $healthy = ExpandedWebsiteMonitor::responseBudget($project, (string) Str::uuid());
    $warn = ExpandedWebsiteMonitor::responseBudget($project, (string) Str::uuid());
    $tail = ExpandedWebsiteMonitor::responseBudget($project, (string) Str::uuid());
    foreach ([[$healthy, 2000.0], [$warn, 2001.0]] as [$monitor, $speed]) {
        StoredCheckSpeedSample::query()->create(['project_id' => $project, 'check_id' => $monitor->monitor_id, 'successful' => true, 'speed_ms' => $speed, 'observed_at' => $now->subMinute()]);
        $this->app->make(EvaluateResponseBudget::class)->execute($monitor, $now);
    }
    foreach (range(1, 18) as $offset) {
        StoredCheckSpeedSample::query()->create(['project_id' => $project, 'check_id' => $tail->monitor_id, 'successful' => true, 'speed_ms' => 100, 'observed_at' => $now->subSeconds(100 - $offset)]);
    }
    StoredCheckSpeedSample::query()->create(['project_id' => $project, 'check_id' => $tail->monitor_id, 'successful' => true, 'speed_ms' => 9000, 'observed_at' => $now->subSecond()]);
    $this->app->make(EvaluateResponseBudget::class)->execute($tail, $now);

    $empty = ExpandedWebsiteMonitor::responseBudget($project, (string) Str::uuid());
    StoredCheckSpeedSample::query()->insert([
        ['project_id' => $project, 'check_id' => $empty->monitor_id, 'successful' => false, 'speed_ms' => 10, 'observed_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ['project_id' => $project, 'check_id' => $empty->monitor_id, 'successful' => true, 'speed_ms' => null, 'observed_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ['project_id' => $project, 'check_id' => $empty->monitor_id, 'successful' => true, 'speed_ms' => 10, 'observed_at' => $now->subHour(), 'created_at' => $now, 'updated_at' => $now],
        ['project_id' => (string) Str::uuid(), 'check_id' => $empty->monitor_id, 'successful' => true, 'speed_ms' => 10, 'observed_at' => $now, 'created_at' => $now, 'updated_at' => $now],
    ]);
    expect($this->app->make(EvaluateResponseBudget::class)->execute($empty, $now))->toBeFalse()
        ->and(collect($recording->results)->pluck('signal')->all())->toBe(['healthy', 'warn', 'warn'])
        ->and(ExpandedCheckEvaluation::query()->where('expanded_website_monitor_id', $tail->getKey())->firstOrFail()->details['p95_ms'])->toEqual(9000)
        ->and(ExpandedCheckEvaluation::query()->where('expanded_website_monitor_id', $empty->getKey())->value('status'))->toBe('unavailable');
})->group('AC-agent-v2-expanded-monitors-12');

it('registers overlap-safe schedules, suppresses disabled checks, and only submits through ingestion', function (): void {
    $recording = expandedRecordingIngestion();
    $project = (string) Str::uuid();
    ExpandedWebsiteMonitor::domainExpiry($project, (string) Str::uuid(), 'disabled.example', false);
    ExpandedWebsiteMonitor::responseBudget($project, (string) Str::uuid(), false);

    $this->artisan('checkybot:expanded-refresh-domains')->assertSuccessful()->expectsOutput('Queued 0 domain refresh job(s).');
    $this->artisan('checkybot:expanded-evaluate-domains')->assertSuccessful()->expectsOutput('Queued 0 domain budget job(s).');
    $this->artisan('checkybot:expanded-evaluate-response-budgets')->assertSuccessful()->expectsOutput('Queued 0 response budget job(s).');

    $events = collect($this->app->make(Schedule::class)->events());
    foreach (['checkybot:expanded-refresh-domains', 'checkybot:expanded-evaluate-domains', 'checkybot:expanded-evaluate-response-budgets'] as $name) {
        $event = $events->first(fn ($event) => $event->description === $name);
        expect($event)->not->toBeNull()
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->onOneServer)->toBeTrue();
    }
    expect($recording->results)->toBeEmpty()
        ->and(MonitorState::query()->count())->toBe(0)
        ->and(MonitorTransition::query()->count())->toBe(0);
})->group('AC-agent-v2-expanded-monitors-13');
