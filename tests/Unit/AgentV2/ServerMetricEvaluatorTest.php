<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Actions\EvaluateServerMetrics;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentDiskSample;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentNetworkSample;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentPhpFpmSample;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentPrerequisite;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentReport;

/** @param array<string, mixed> $overrides */
function evaluatorReport(array $overrides = []): AgentReport
{
    $report = new AgentReport([
        'cpu_five_min_percent' => $overrides['cpu'] ?? 10,
        'memory_used_percent' => $overrides['memory'] ?? 10,
        'nginx_window_seconds' => 300,
        'nginx_total_requests' => $overrides['requests'] ?? 100,
        'nginx_five_xx_count' => $overrides['five_xx'] ?? 0,
        'nginx_upstream_timeout_count' => $overrides['timeouts'] ?? 0,
    ]);
    $report->setRelation('disks', new Collection([
        new AgentDiskSample([
            'mount' => '/',
            'used_percent' => $overrides['disk'] ?? 10,
            'predicted_days_to_full' => $overrides['predicted_days'] ?? null,
        ]),
    ]));
    $network = $overrides['network'] ?? [
        ['sample_status' => 'ready', 'rx_delta_bytes' => 0, 'tx_delta_bytes' => 0, 'elapsed_seconds' => 10],
        ['sample_status' => 'baseline', 'rx_delta_bytes' => null, 'tx_delta_bytes' => null, 'elapsed_seconds' => null],
        ['sample_status' => 'reset', 'rx_delta_bytes' => null, 'tx_delta_bytes' => null, 'elapsed_seconds' => null],
    ];
    $report->setRelation('networkInterfaces', new Collection(array_map(
        static fn (array $sample): AgentNetworkSample => new AgentNetworkSample($sample),
        $network,
    )));
    $report->setRelation('phpFpmPools', new Collection($overrides['fpm'] ?? [
        new AgentPhpFpmSample(['pool' => 'www', 'active_workers' => 1, 'max_children' => 10, 'max_children_reached_5m' => 0]),
    ]));
    $report->setRelation('prerequisites', new Collection([
        new AgentPrerequisite(['kind' => 'php_fpm_status', 'status' => $overrides['fpm_status'] ?? 'readable']),
        new AgentPrerequisite(['kind' => 'nginx_access_log', 'status' => $overrides['nginx_status'] ?? 'readable']),
    ]));

    return $report;
}

it('evaluates all server metric boundaries and excludes unavailable network samples', function (): void {
    $evaluator = new EvaluateServerMetrics;
    $cases = [
        'cpu below' => [['cpu' => 84.99], 'healthy'],
        'cpu warn' => [['cpu' => 85], 'warn'],
        'cpu critical' => [['cpu' => 95], 'critical'],
        'ram below' => [['memory' => 84.99], 'healthy'],
        'ram warn' => [['memory' => 85], 'warn'],
        'ram critical' => [['memory' => 95], 'critical'],
        'disk below' => [['disk' => 79.99], 'healthy'],
        'disk warn' => [['disk' => 80], 'warn'],
        'disk critical' => [['disk' => 90], 'critical'],
        'disk predictive critical' => [['predicted_days' => 7], 'critical'],
        'network below' => [['network' => [
            ['sample_status' => 'ready', 'rx_delta_bytes' => 699, 'tx_delta_bytes' => 100, 'elapsed_seconds' => 10],
            ['sample_status' => 'baseline', 'rx_delta_bytes' => null, 'tx_delta_bytes' => null, 'elapsed_seconds' => null],
            ['sample_status' => 'reset', 'rx_delta_bytes' => null, 'tx_delta_bytes' => null, 'elapsed_seconds' => null],
        ]], 'healthy'],
        'network warn uses max tx' => [['network' => [
            ['sample_status' => 'ready', 'rx_delta_bytes' => 100, 'tx_delta_bytes' => 700, 'elapsed_seconds' => 10],
            ['sample_status' => 'baseline', 'rx_delta_bytes' => null, 'tx_delta_bytes' => null, 'elapsed_seconds' => null],
            ['sample_status' => 'reset', 'rx_delta_bytes' => null, 'tx_delta_bytes' => null, 'elapsed_seconds' => null],
        ]], 'warn'],
        'network critical' => [['network' => [
            ['sample_status' => 'ready', 'rx_delta_bytes' => 900, 'tx_delta_bytes' => 100, 'elapsed_seconds' => 10],
            ['sample_status' => 'baseline', 'rx_delta_bytes' => null, 'tx_delta_bytes' => null, 'elapsed_seconds' => null],
            ['sample_status' => 'reset', 'rx_delta_bytes' => null, 'tx_delta_bytes' => null, 'elapsed_seconds' => null],
        ]], 'critical'],
    ];

    foreach ($cases as $name => [$overrides, $signal]) {
        $result = $evaluator->execute(evaluatorReport($overrides), 800);
        expect($result->signal)->toBe($signal, $name);
        if (str_starts_with($name, 'network')) {
            expect($result->details['components']['network']['excluded_samples'])->toBe(2, $name);
        }
    }
})->group('AC-agent-v2-expanded-monitors-5');

it('evaluates FPM utilization saturation and unavailable status without invented health', function (): void {
    $evaluator = new EvaluateServerMetrics;
    $cases = [
        'below' => [[new AgentPhpFpmSample(['pool' => 'www', 'active_workers' => 79, 'max_children' => 100, 'max_children_reached_5m' => 0])], 'healthy'],
        'warn' => [[new AgentPhpFpmSample(['pool' => 'www', 'active_workers' => 80, 'max_children' => 100, 'max_children_reached_5m' => 0])], 'warn'],
        'critical' => [[new AgentPhpFpmSample(['pool' => 'www', 'active_workers' => 100, 'max_children' => 100, 'max_children_reached_5m' => 0])], 'critical'],
        'reached' => [[new AgentPhpFpmSample(['pool' => 'www', 'active_workers' => 1, 'max_children' => 100, 'max_children_reached_5m' => 1])], 'critical'],
    ];
    foreach ($cases as $name => [$pools, $signal]) {
        $result = $evaluator->execute(evaluatorReport(['fpm' => $pools]), 800);
        expect($result->signal)->toBe($signal, $name);
        if ($name === 'reached') {
            expect($result->reasonCode)->toBe('php_fpm_max_children_reached')
                ->and(strlen($result->reasonCode))->toBeLessThanOrEqual(120);
        }
    }

    foreach (['missing', 'permission_denied', 'disabled'] as $status) {
        $result = $evaluator->execute(evaluatorReport(['fpm_status' => $status, 'fpm' => []]), 800);
        expect($result->signal)->toBe('warn')
            ->and($result->reasonCode)->toBe("prerequisite_php_fpm_status_{$status}")
            ->and($result->details['components']['php_fpm']['status'])->toBe('misconfigured');
    }
})->group('AC-agent-v2-expanded-monitors-6');

it('evaluates nginx five-minute static rules and unavailable access logs', function (): void {
    $evaluator = new EvaluateServerMetrics;
    $cases = [
        'exact one percent' => [['requests' => 100, 'five_xx' => 1], 'healthy'],
        'above one percent' => [['requests' => 100, 'five_xx' => 2], 'warn'],
        'above five percent' => [['requests' => 100, 'five_xx' => 6], 'critical'],
        'low traffic nine' => [['requests' => 49, 'five_xx' => 9], 'healthy'],
        'low traffic ten' => [['requests' => 49, 'five_xx' => 10], 'critical'],
        'upstream timeout' => [['requests' => 100, 'five_xx' => 0, 'timeouts' => 1], 'critical'],
    ];
    foreach ($cases as $name => [$overrides, $signal]) {
        $result = $evaluator->execute(evaluatorReport($overrides), 800);
        expect($result->signal)->toBe($signal, $name);
    }

    foreach (['missing', 'permission_denied', 'disabled'] as $status) {
        $result = $evaluator->execute(evaluatorReport(['nginx_status' => $status, 'requests' => 0, 'five_xx' => 0]), 800);
        expect($result->signal)->toBe('warn')
            ->and($result->reasonCode)->toBe("prerequisite_nginx_access_log_{$status}")
            ->and($result->details['components']['nginx']['status'])->toBe('misconfigured')
            ->and($result->details['metrics'])->not->toHaveKey('nginx_five_xx_rate');
    }
})->group('AC-agent-v2-expanded-monitors-7');
