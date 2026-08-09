<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Actions;

use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\MetricCandidate;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\ServerEvaluation;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\AgentReport;

final readonly class EvaluateServerMetrics
{
    public function execute(AgentReport $report, int $linkCapBps): ServerEvaluation
    {
        $candidates = [];
        $details = ['metrics' => [], 'components' => []];

        $this->percentCandidate($candidates, $details, 'cpu_five_min', (float) $report->cpu_five_min_percent, 85, 95);
        $this->percentCandidate($candidates, $details, 'memory_used', (float) $report->memory_used_percent, 85, 95);

        foreach ($report->disks as $disk) {
            $name = 'disk:'.substr(hash('sha256', (string) $disk->mount), 0, 12);
            $this->percentCandidate($candidates, $details, $name, (float) $disk->used_percent, 80, 90, 'disk_used');
            if ($disk->predicted_days_to_full !== null) {
                $days = (float) $disk->predicted_days_to_full;
                $details['metrics'][$name]['predicted_days_to_full'] = $days;
                if ($days <= 7.0) {
                    $candidates[] = $this->candidate('critical', 'disk_predictive_full_critical', [
                        'metric' => $name,
                        'predicted_days_to_full' => $days,
                    ]);
                }
            }
        }

        $readyNetwork = $report->networkInterfaces->where('sample_status', 'ready');
        $networkRates = [];
        foreach ($readyNetwork as $sample) {
            $elapsed = (float) $sample->elapsed_seconds;
            if ($elapsed <= 0.0 || $sample->rx_delta_bytes === null || $sample->tx_delta_bytes === null) {
                continue;
            }
            $rxBps = ((float) $sample->rx_delta_bytes * 8.0) / $elapsed;
            $txBps = ((float) $sample->tx_delta_bytes * 8.0) / $elapsed;
            $networkRates[] = max($rxBps, $txBps);
        }
        $details['components']['network'] = [
            'status' => $networkRates === [] ? 'unavailable' : 'ready',
            'excluded_samples' => $report->networkInterfaces->count() - count($networkRates),
            'link_cap_bps' => $linkCapBps,
        ];
        if ($networkRates !== []) {
            $maxBps = max($networkRates);
            $percent = ($maxBps / max(1, $linkCapBps)) * 100.0;
            $details['metrics']['network_max_direction'] = [
                'value_bps' => $maxBps,
                'percent_of_cap' => $percent,
                'warn' => 70.0,
                'critical' => 90.0,
            ];
            $candidates[] = $this->band($percent, 70, 90, 'network_cap');
        }

        $this->evaluateFpm($report, $candidates, $details);
        $this->evaluateNginx($report, $candidates, $details);

        if ($candidates === []) {
            $candidates[] = $this->candidate('healthy', 'server_metrics_healthy', ['metric' => 'aggregate']);
        }

        $dominant = $candidates[0];
        foreach ($candidates as $candidate) {
            if ($candidate->bandValue > $dominant->bandValue) {
                $dominant = $candidate;
            }
        }
        $details['dominant'] = $dominant->detail;
        $details['candidate_count'] = count($candidates);

        return new ServerEvaluation($dominant->signal, $dominant->bandValue, $dominant->reasonCode, $details);
    }

    /** @param list<MetricCandidate> $candidates @param array<string, mixed> $details */
    private function evaluateFpm(AgentReport $report, array &$candidates, array &$details): void
    {
        $prerequisite = $report->prerequisites->firstWhere('kind', 'php_fpm_status');
        if ($prerequisite === null || $prerequisite->status !== 'readable') {
            $status = $prerequisite === null ? 'not_configured' : $prerequisite->status;
            $reason = 'prerequisite_php_fpm_status_'.$status;
            $details['components']['php_fpm'] = ['status' => 'misconfigured', 'prerequisite_status' => $status];
            $candidates[] = $this->candidate('warn', $reason, ['component' => 'php_fpm', 'status' => $status]);

            return;
        }
        if ($report->phpFpmPools->isEmpty()) {
            $details['components']['php_fpm'] = ['status' => 'misconfigured', 'prerequisite_status' => 'readable'];
            $candidates[] = $this->candidate('warn', 'php_fpm_pool_data_unavailable', ['component' => 'php_fpm']);

            return;
        }

        $details['components']['php_fpm'] = ['status' => 'ready', 'pool_count' => $report->phpFpmPools->count()];
        foreach ($report->phpFpmPools as $pool) {
            $poolKey = 'fpm:'.substr(hash('sha256', (string) $pool->pool), 0, 12);
            $utilization = ((float) $pool->active_workers / max(1, (int) $pool->max_children)) * 100.0;
            $details['metrics'][$poolKey] = [
                'utilization_percent' => $utilization,
                'active_workers' => (int) $pool->active_workers,
                'max_children' => (int) $pool->max_children,
                'max_children_reached_5m' => (int) $pool->max_children_reached_5m,
            ];
            if ((int) $pool->max_children_reached_5m > 0) {
                $candidates[] = $this->candidate('critical', 'php_fpm_max_children_reached', ['metric' => $poolKey]);
            } else {
                $candidates[] = $this->band($utilization, 80, 100, 'php_fpm_utilization');
            }
        }
    }

    /** @param list<MetricCandidate> $candidates @param array<string, mixed> $details */
    private function evaluateNginx(AgentReport $report, array &$candidates, array &$details): void
    {
        $timeouts = (int) $report->nginx_upstream_timeout_count;
        $fiveXx = (int) $report->nginx_five_xx_count;
        $correlatedFiveXx = $fiveXx + $timeouts;
        if ($timeouts > 0) {
            $candidates[] = $this->candidate('critical', 'nginx_upstream_timeout', [
                'component' => 'nginx', 'upstream_timeout_count' => $timeouts,
            ]);
        }

        $prerequisite = $report->prerequisites->firstWhere('kind', 'nginx_access_log');
        if ($prerequisite === null || $prerequisite->status !== 'readable') {
            $status = $prerequisite === null ? 'not_configured' : $prerequisite->status;
            $details['components']['nginx'] = [
                'status' => 'misconfigured',
                'prerequisite_status' => $status,
                'correlated_five_xx_count' => $correlatedFiveXx,
            ];
            $candidates[] = $this->candidate('warn', 'prerequisite_nginx_access_log_'.$status, [
                'component' => 'nginx', 'status' => $status,
            ]);

            return;
        }

        $requests = (int) $report->nginx_total_requests;
        $details['components']['nginx'] = [
            'status' => 'ready',
            'window_seconds' => (int) $report->nginx_window_seconds,
            'total_requests' => $requests,
            'five_xx_count' => $fiveXx,
            'correlated_five_xx_count' => $correlatedFiveXx,
            'upstream_timeout_count' => $timeouts,
        ];
        if ($requests < 50) {
            $candidates[] = $correlatedFiveXx >= 10
                ? $this->candidate('critical', 'nginx_low_traffic_failures_critical', ['component' => 'nginx'])
                : $this->candidate('healthy', 'nginx_low_traffic_healthy', ['component' => 'nginx']);

            return;
        }

        $rate = ($correlatedFiveXx / $requests) * 100.0;
        $details['metrics']['nginx_five_xx_rate'] = ['percent' => $rate, 'warn_above' => 1.0, 'critical_above' => 5.0];
        $candidates[] = $rate > 5.0
            ? $this->candidate('critical', 'nginx_five_xx_rate_critical', ['metric' => 'nginx_five_xx_rate'])
            : ($rate > 1.0
                ? $this->candidate('warn', 'nginx_five_xx_rate_warn', ['metric' => 'nginx_five_xx_rate'])
                : $this->candidate('healthy', 'nginx_five_xx_rate_healthy', ['metric' => 'nginx_five_xx_rate']));
    }

    /** @param list<MetricCandidate> $candidates @param array<string, mixed> $details */
    private function percentCandidate(array &$candidates, array &$details, string $metric, float $value, float $warn, float $critical, ?string $reasonPrefix = null): void
    {
        $details['metrics'][$metric] = ['percent' => $value, 'warn' => $warn, 'critical' => $critical];
        $candidates[] = $this->band($value, $warn, $critical, $reasonPrefix ?? $metric);
    }

    private function band(float $value, float $warn, float $critical, string $prefix): MetricCandidate
    {
        if ($value >= $critical) {
            return $this->candidate('critical', $prefix.'_critical', ['metric' => $prefix, 'value' => $value]);
        }
        if ($value >= $warn) {
            return $this->candidate('warn', $prefix.'_warn', ['metric' => $prefix, 'value' => $value]);
        }

        return $this->candidate('healthy', $prefix.'_healthy', ['metric' => $prefix, 'value' => $value]);
    }

    /** @param array<string, mixed> $detail */
    private function candidate(string $signal, string $reasonCode, array $detail): MetricCandidate
    {
        return new MetricCandidate($signal, match ($signal) {
            'critical' => 2,
            'warn' => 1,
            default => 0,
        }, substr($reasonCode, 0, 120), $detail);
    }
}
