<?php

declare(strict_types=1);

use MarinSolutions\CheckybotLaravel\Tests\Feature\ReleaseHardening\Support\EvidenceSecretScanContract;

require_once __DIR__.'/Support/ReleaseEvidenceContracts.php';

function releaseHardeningDocument(string $relative): string
{
    $contents = file_get_contents(dirname(__DIR__, 3).'/'.$relative);
    expect($contents)->not->toBeFalse();

    return (string) $contents;
}

it('defines executable release, watchdog, retirement, fleet, rollback, and redaction contracts', function (): void {
    $release = releaseHardeningDocument('docs/release-hardening.md');
    $retirement = releaseHardeningDocument('docs/watchdog-and-push-retirement.md');
    $readme = releaseHardeningDocument('.full-send/canvas-runs/current/handover-checklists/README.md');

    expect($release)->toContain(
        './vendor/bin/pest tests/Feature/ReleaseHardening --compact',
        'less than 30 seconds',
        '0 incident/recovery intents',
        'Exactly 1 grouped incident and 1 grouped recovery',
        'responsible_owner',
        'recorded_at',
        'Evidence path',
        'Recency / expiry',
        'Rollback trigger',
        'variant-canary',
        '5% → 25% → 50% → 100%',
        'agent-report.v2',
        'exactly 60 seconds',
        'secret-scan.txt',
        'plaintext tokens',
        'provider credentials',
        'webhook URLs',
        'unredacted payloads',
        'exit 1',
    )->and($retirement)->toContain(
        'Telegram fallback maps to the existing generic legacy webhook',
        'php artisan checkybot:watchdog',
        'outside the Checkybot host and failure domain',
        'no more than 5 minutes old',
        '28 complete elapsed UTC calendar days',
        'failed_or_missing_pairs=0',
        'expo_accepted',
        'legacy_webhook_accepted',
        'responsible_owner',
        'recorded_at',
        'Rollback immediately',
        'expires after 5 minutes',
        'php artisan tinker --execute=',
    )->and($readme)->toContain('fail-closed', 'UTC timestamps', 'no-secret scan');

    preg_match_all('/```bash\n(.+?)```/s', $release."\n".$retirement, $blocks);
    expect($blocks[1])->not->toBeEmpty();
    foreach ($blocks[1] as $block) {
        expect(trim($block))->not->toBeEmpty()
            ->and($block)->not->toContain('<actual-token>', 'Bearer ey');
    }
})->group('AC-release-hardening-5');

it('ships parseable versioned templates with thresholds owners timestamps evidence and rollback fields', function (): void {
    $directory = dirname(__DIR__, 3).'/.full-send/canvas-runs/current/handover-checklists/v1';
    $templates = [
        'release-evidence.template.json' => 'release-evidence.v1',
        'push-retirement.template.json' => 'push-retirement.v1',
        'fleet-readiness.template.json' => 'fleet-readiness.v1',
    ];

    foreach ($templates as $file => $version) {
        $json = releaseHardeningDocument('.full-send/canvas-runs/current/handover-checklists/v1/'.$file);
        $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['contract_version'])->toBe($version)
            ->and(json_encode($manifest, JSON_THROW_ON_ERROR))->toContain('responsible_owner')
            ->and($json)->toContain('evidence');
    }

    $release = json_decode((string) file_get_contents($directory.'/release-evidence.template.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($release)->toHaveKeys(['commands', 'thresholds', 'evidence_paths', 'rollback_triggers', 'rollback_owner', 'signed_at', 'expires_at'])
        ->and($release['thresholds'])->toMatchArray([
            'sub_30_second_notification_intents' => 0,
            'sub_30_second_deliveries' => 0,
            'grouped_incident_intents' => 1,
            'grouped_recovery_intents' => 1,
            'maximum_watchdog_age_seconds' => 300,
            'required_complete_proving_days' => 28,
            'fleet_reporting_interval_seconds' => 60,
            'secret_scan_matches' => 0,
        ]);

    $retirement = json_decode((string) file_get_contents($directory.'/push-retirement.template.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($retirement['telegram_mapping'])->toBe('existing generic legacy webhook')
        ->and($retirement)->toHaveKeys(['external_watchdog', 'push_reliability', 'responsible_owner', 'signed_at', 'rollback_trigger']);

    $fleet = json_decode((string) file_get_contents($directory.'/fleet-readiness.template.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($fleet['rollout_order'])->toBe(['variant-canary', '5%', '25%', '50%', '100%'])
        ->and($fleet['servers'][0])->toHaveKeys([
            'server_uuid', 'latest_report', 'optional_mysql_exception', 'link_cap', 'canary_wave', 'scoped_token_rotation', 'rollback',
        ]);
})->group('AC-release-hardening-5');

it('fails the evidence bundle scan for plaintext credentials webhook URLs and raw payloads', function (): void {
    $safe = [
        'operation_id' => '9aa23958-44d4-4a3b-bc31-2a6ad5ee7989',
        'provider_event_id' => 'evt_redacted_42',
        'evidence_path' => 'evidence/run/push-receipt.json',
        'status' => 'accepted',
    ];
    expect(EvidenceSecretScanContract::violations($safe))->toBe([]);

    $unsafe = [
        ['plaintext_token' => 'secret-value'],
        ['provider_credentials' => ['client' => 'operator', 'password' => 'secret-value']],
        ['webhook_url' => 'https://hooks.example.test/path'],
        ['raw_payload' => ['monitor' => 'private-hostname']],
        ['request' => 'Authorization: Bearer secret-token-value'],
        ['device' => 'ExponentPushToken[secret-device-token]'],
        ['endpoint' => 'https://watchdog.example.test/heartbeat?token=secret'],
    ];
    foreach ($unsafe as $evidence) {
        expect(EvidenceSecretScanContract::violations($evidence))->not->toBe([]);
    }
})->group('AC-release-hardening-5');
