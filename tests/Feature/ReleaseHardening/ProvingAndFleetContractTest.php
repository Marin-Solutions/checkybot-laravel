<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Push\Contracts\ProjectIdentity;
use MarinSolutions\CheckybotLaravel\Domain\Push\Queries\PushReliabilityReadModel;
use MarinSolutions\CheckybotLaravel\Tests\Feature\ReleaseHardening\Support\FleetReadinessContract;
use MarinSolutions\CheckybotLaravel\Tests\Feature\ReleaseHardening\Support\RetirementChecklistContract;

require_once __DIR__.'/Support/ReleaseEvidenceContracts.php';

beforeEach(function (): void {
    $directory = dirname(__DIR__, 3).'/build/release-hardening-tests';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $this->releaseContractDatabase = $directory.'/'.Str::uuid().'.sqlite';
    touch($this->releaseContractDatabase);
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite', 'database' => $this->releaseContractDatabase, 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
    DB::purge('sqlite');
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php')->up();
    (include dirname(__DIR__, 3).'/database/migrations/2026_08_06_020000_create_push_delivery_tables.php')->up();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    DB::disconnect('sqlite');
    @unlink($this->releaseContractDatabase);
});

it('keeps generic-webhook Telegram retirement fail-closed until watchdog and proving evidence are complete', function (): void {
    $now = CarbonImmutable::parse('2026-08-08T12:00:00Z');
    CarbonImmutable::setTestNow($now);
    $project = (string) Str::uuid();
    for ($day = 28; $day >= 1; $day--) {
        DB::table('push_proving_days')->insert([
            'project_id' => $project,
            'proving_date' => $now->startOfDay()->subDays($day)->toDateString(),
            'critical_intents' => 1,
            'expo_accepted' => 1,
            'legacy_webhook_accepted' => 1,
            'failed_or_missing_pairs' => 0,
            'last_failure_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    $ready = app(PushReliabilityReadModel::class)->forProject(new ProjectIdentity($project));
    $evidence = [
        'external_watchdog' => [
            'status' => 'success', 'independent' => true, 'depends_on_checkybot' => false,
            'checked_at' => $now->subMinute()->toRfc3339String(), 'evidence_path' => 'evidence/watchdog.json',
        ],
        'push_reliability' => $ready,
        'responsible_owner' => 'Release manager',
        'signed_at' => $now->toRfc3339String(),
    ];

    expect($ready['retirement_ready'])->toBeTrue()
        ->and(RetirementChecklistContract::isSignable($evidence, $now))->toBeTrue();

    $missing = $evidence;
    unset($missing['external_watchdog']);
    expect(RetirementChecklistContract::violations($missing, $now))->toContain('external_watchdog.missing');

    $stale = $evidence;
    $stale['external_watchdog']['checked_at'] = $now->subMinutes(6)->toRfc3339String();
    expect(RetirementChecklistContract::violations($stale, $now))->toContain('external_watchdog.stale');

    $failed = $evidence;
    $failed['external_watchdog']['status'] = 'failure';
    expect(RetirementChecklistContract::violations($failed, $now))->toContain('external_watchdog.failed');

    $dependent = $evidence;
    $dependent['external_watchdog']['independent'] = false;
    $dependent['external_watchdog']['depends_on_checkybot'] = true;
    expect(RetirementChecklistContract::violations($dependent, $now))->toContain('external_watchdog.not_independent');

    DB::table('push_proving_days')->where('project_id', $project)->orderBy('proving_date')->limit(1)->delete();
    $shortWindow = $evidence;
    $shortWindow['push_reliability'] = app(PushReliabilityReadModel::class)->forProject(new ProjectIdentity($project));
    expect($shortWindow['push_reliability']['consecutive_complete_days'])->toBe(27)
        ->and(RetirementChecklistContract::violations($shortWindow, $now))->toContain('push_reliability.incomplete_window');

    DB::table('push_proving_days')->where('project_id', $project)->delete();
    for ($day = 28; $day >= 1; $day--) {
        DB::table('push_proving_days')->insert([
            'project_id' => $project, 'proving_date' => $now->startOfDay()->subDays($day)->toDateString(),
            'critical_intents' => 1, 'expo_accepted' => $day === 1 ? 0 : 1, 'legacy_webhook_accepted' => 1,
            'failed_or_missing_pairs' => $day === 1 ? 1 : 0, 'last_failure_at' => $day === 1 ? $now->subDay() : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $failedPair = $evidence;
    $failedPair['push_reliability'] = app(PushReliabilityReadModel::class)->forProject(new ProjectIdentity($project));
    expect($failedPair['push_reliability']['retirement_ready'])->toBeFalse()
        ->and(RetirementChecklistContract::isSignable($failedPair, $now))->toBeFalse();
})->group('AC-release-hardening-3');

it('rejects an incomplete fleet handover and accepts only every enabled ready server', function (): void {
    $enabled = [(string) Str::uuid(), (string) Str::uuid()];
    $server = static function (string $uuid, string $wave, string $mysql = 'readable'): array {
        return [
            'server_uuid' => $uuid,
            'latest_report' => [
                'accepted' => true, 'schema_version' => 'agent-report.v2', 'reporting_interval_seconds' => 60,
                'agent_version' => '2.4.1', 'accepted_at' => '2026-08-08T11:59:00Z',
                'prerequisites' => ['nginx_access' => 'readable', 'nginx_error' => 'readable', 'php_fpm' => 'readable', 'mysql' => $mysql],
            ],
            'optional_mysql_exception' => $mysql === 'not_configured'
                ? ['approved' => true, 'approved_by' => 'Database owner', 'approved_at' => '2026-08-08T11:00:00Z', 'reason' => 'No MySQL service on host']
                : null,
            'link_cap' => ['confirmed' => true, 'bits_per_second' => 1_000_000_000],
            'canary_wave' => ['name' => $wave, 'result' => 'passed', 'evidence_path' => 'evidence/canary-'.$wave.'.json'],
            'scoped_token_rotation' => [
                'abilities' => ['agent:report'], 'new_token_report_accepted' => true, 'old_token_revoked' => true,
                'evidence_path' => 'evidence/token-rotation.json',
            ],
            'rollback' => [
                'owner' => 'Fleet operator',
                'post_rollback_heartbeat_procedure' => 'Confirm a fresh accepted agent-report.v2 within 120 seconds.',
                'evidence_path' => 'evidence/post-rollback-heartbeat.json',
            ],
        ];
    };
    $manifest = [
        'contract_version' => 'fleet-readiness.v1',
        'expected_agent_version' => '2.4.1',
        'servers' => [$server($enabled[0], 'variant-canary'), $server($enabled[1], '5%', 'not_configured')],
    ];

    expect(FleetReadinessContract::violations($enabled, $manifest))->toBe([]);

    $mutations = [
        'missing enabled server' => static function (array $value): array {
            array_pop($value['servers']);

            return $value;
        },
        'report not accepted' => static function (array $value): array {
            $value['servers'][0]['latest_report']['accepted'] = false;

            return $value;
        },
        'wrong schema' => static function (array $value): array {
            $value['servers'][0]['latest_report']['schema_version'] = 'agent-report.v1';

            return $value;
        },
        'wrong interval' => static function (array $value): array {
            $value['servers'][0]['latest_report']['reporting_interval_seconds'] = 300;

            return $value;
        },
        'wrong version' => static function (array $value): array {
            $value['servers'][0]['latest_report']['agent_version'] = '2.3.0';

            return $value;
        },
        'unreadable nginx' => static function (array $value): array {
            $value['servers'][0]['latest_report']['prerequisites']['nginx_access'] = 'permission_denied';

            return $value;
        },
        'unreadable FPM' => static function (array $value): array {
            $value['servers'][0]['latest_report']['prerequisites']['php_fpm'] = 'missing';

            return $value;
        },
        'unapproved MySQL exception' => static function (array $value): array {
            $value['servers'][1]['optional_mysql_exception']['approved'] = false;

            return $value;
        },
        'cap unconfirmed' => static function (array $value): array {
            $value['servers'][0]['link_cap']['confirmed'] = false;

            return $value;
        },
        'canary failed' => static function (array $value): array {
            $value['servers'][0]['canary_wave']['result'] = 'failed';

            return $value;
        },
        'token too broad' => static function (array $value): array {
            $value['servers'][0]['scoped_token_rotation']['abilities'][] = 'status:read';

            return $value;
        },
        'rollback owner missing' => static function (array $value): array {
            $value['servers'][0]['rollback']['owner'] = '';

            return $value;
        },
        'heartbeat procedure missing' => static function (array $value): array {
            $value['servers'][0]['rollback']['post_rollback_heartbeat_procedure'] = '';

            return $value;
        },
    ];

    foreach ($mutations as $label => $mutate) {
        expect(FleetReadinessContract::violations($enabled, $mutate($manifest)))
            ->not->toBe([], "Fleet contract unexpectedly accepted: {$label}");
    }
})->group('AC-release-hardening-4');
