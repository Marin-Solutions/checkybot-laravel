<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Http;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\AgentReportData;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\RecursiveRedactor;

final class StoreAgentReportRequest extends FormRequest
{
    private const ROOT_FIELDS = [
        'schema_version', 'operation_id', 'agent_version', 'server_uuid', 'observed_at',
        'reporting_interval_seconds', 'cpu', 'memory', 'disks', 'network_interfaces',
        'php_fpm_pools', 'nginx_window', 'prerequisites', 'relevant_log_lines',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $percent = ['required', 'numeric', 'between:0,100'];

        return [
            'schema_version' => ['required', 'string', Rule::in(['agent-report.v2'])],
            'operation_id' => ['required', 'uuid'],
            'agent_version' => ['required', 'string', 'max:40', 'regex:/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/'],
            'server_uuid' => ['required', 'uuid'],
            'observed_at' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/', 'date'],
            'reporting_interval_seconds' => ['required', 'integer', Rule::in([60])],
            'cpu' => ['required', 'array:five_min_percent'],
            'cpu.five_min_percent' => $percent,
            'memory' => ['required', 'array:used_percent'],
            'memory.used_percent' => $percent,
            'disks' => ['required', 'array', 'max:256'],
            'disks.*' => ['required', 'array:mount,used_percent,predicted_days_to_full'],
            'disks.*.mount' => ['required', 'string', 'max:255', 'distinct:strict'],
            'disks.*.used_percent' => $percent,
            'disks.*.predicted_days_to_full' => ['nullable', 'numeric', 'min:0'],
            'network_interfaces' => ['required', 'array', 'max:256'],
            'network_interfaces.*' => ['required', 'array:name,rx_bytes_total,tx_bytes_total,rx_delta_bytes,tx_delta_bytes,elapsed_seconds,sample_status'],
            'network_interfaces.*.name' => ['required', 'string', 'max:100', 'distinct:strict'],
            'network_interfaces.*.rx_bytes_total' => ['required', 'integer', 'min:0'],
            'network_interfaces.*.tx_bytes_total' => ['required', 'integer', 'min:0'],
            'network_interfaces.*.rx_delta_bytes' => ['nullable', 'integer', 'min:0'],
            'network_interfaces.*.tx_delta_bytes' => ['nullable', 'integer', 'min:0'],
            'network_interfaces.*.elapsed_seconds' => ['nullable', 'numeric', 'gt:0'],
            'network_interfaces.*.sample_status' => ['required', Rule::in(['baseline', 'ready', 'reset'])],
            'php_fpm_pools' => ['required', 'array', 'max:256'],
            'php_fpm_pools.*' => ['required', 'array:pool,active_workers,max_children,max_children_reached_5m'],
            'php_fpm_pools.*.pool' => ['required', 'string', 'max:100', 'distinct:strict'],
            'php_fpm_pools.*.active_workers' => ['required', 'integer', 'min:0'],
            'php_fpm_pools.*.max_children' => ['required', 'integer', 'min:1'],
            'php_fpm_pools.*.max_children_reached_5m' => ['required', 'integer', 'min:0'],
            'nginx_window' => ['required', 'array:window_seconds,total_requests,five_xx_count,upstream_timeout_count'],
            'nginx_window.window_seconds' => ['required', 'integer', Rule::in([300])],
            'nginx_window.total_requests' => ['required', 'integer', 'min:0'],
            'nginx_window.five_xx_count' => ['required', 'integer', 'min:0', 'lte:nginx_window.total_requests'],
            'nginx_window.upstream_timeout_count' => ['required', 'integer', 'min:0'],
            'prerequisites' => ['required', 'array', 'max:5'],
            'prerequisites.*' => ['required', 'array:kind,path_hint,status'],
            'prerequisites.*.kind' => ['required', Rule::in(['nginx_access_log', 'nginx_error_log', 'php_fpm_status', 'php_fpm_log', 'mysql_log']), 'distinct:strict'],
            'prerequisites.*.path_hint' => ['required', 'string', 'max:500'],
            'prerequisites.*.status' => ['required', Rule::in(['readable', 'missing', 'permission_denied', 'disabled', 'not_configured'])],
            'relevant_log_lines' => ['sometimes', 'array', 'max:200'],
            'relevant_log_lines.*' => ['required', 'array:source,observed_at,line'],
            'relevant_log_lines.*.source' => ['required', Rule::in(['nginx', 'fpm', 'mysql'])],
            'relevant_log_lines.*.observed_at' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/', 'date'],
            'relevant_log_lines.*.line' => ['required', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $payload = $this->all();
            foreach (array_diff(array_keys($payload), self::ROOT_FIELDS) as $unknown) {
                $validator->errors()->add((string) $unknown, 'Unknown fields are not allowed.');
            }

            $this->validateJsonScalarTypes($validator, $payload);
            $this->validateObservationTime($validator, $payload['observed_at'] ?? null);
            $this->validateNetworkConsistency($validator, $payload['network_interfaces'] ?? null);
            $this->validateRedaction($validator, $payload['relevant_log_lines'] ?? null, $payload['prerequisites'] ?? null);
        });
    }

    public function reportData(): AgentReportData
    {
        return AgentReportData::fromValidated($this->validated());
    }

    /** @param array<string,mixed> $payload */
    private function validateJsonScalarTypes(Validator $validator, array $payload): void
    {
        $numericPaths = ['cpu.five_min_percent', 'memory.used_percent'];
        foreach ($numericPaths as $path) {
            $value = data_get($payload, $path);
            if ($value !== null && ! is_int($value) && ! is_float($value)) {
                $validator->errors()->add($path, 'The value must be a JSON number.');
            } elseif (is_float($value) && ! is_finite($value)) {
                $validator->errors()->add($path, 'The value must be finite.');
            }
        }
        if (isset($payload['reporting_interval_seconds']) && ! is_int($payload['reporting_interval_seconds'])) {
            $validator->errors()->add('reporting_interval_seconds', 'The value must be a JSON integer.');
        }
    }

    private function validateObservationTime(Validator $validator, mixed $value): void
    {
        if (! is_string($value)) {
            return;
        }
        try {
            $observedAt = CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return;
        }
        $past = (int) config('checkybot.agent.report_max_past_skew_seconds', 300);
        $future = (int) config('checkybot.agent.report_max_future_skew_seconds', 60);
        $now = CarbonImmutable::now('UTC');
        if ($observedAt->lt($now->subSeconds($past)) || $observedAt->gt($now->addSeconds($future))) {
            $validator->errors()->add('observed_at', 'The observation timestamp is outside the accepted clock skew.');
        }
    }

    private function validateNetworkConsistency(Validator $validator, mixed $interfaces): void
    {
        if (! is_array($interfaces)) {
            return;
        }
        foreach ($interfaces as $index => $interface) {
            if (! is_array($interface)) {
                continue;
            }
            $status = $interface['sample_status'] ?? null;
            $rx = $interface['rx_delta_bytes'] ?? null;
            $tx = $interface['tx_delta_bytes'] ?? null;
            $elapsed = $interface['elapsed_seconds'] ?? null;
            if ($status === 'ready' && ($rx === null || $tx === null || $elapsed === null)) {
                $validator->errors()->add("network_interfaces.$index.sample_status", 'Ready samples require both deltas and elapsed time.');
            }
            if (in_array($status, ['baseline', 'reset'], true) && ($rx !== null || $tx !== null || $elapsed !== null)) {
                $validator->errors()->add("network_interfaces.$index.sample_status", 'Baseline and reset samples must be unavailable.');
            }
            if (is_int($rx) && isset($interface['rx_bytes_total']) && $rx > $interface['rx_bytes_total']) {
                $validator->errors()->add("network_interfaces.$index.rx_delta_bytes", 'The delta cannot exceed the total counter.');
            }
            if (is_int($tx) && isset($interface['tx_bytes_total']) && $tx > $interface['tx_bytes_total']) {
                $validator->errors()->add("network_interfaces.$index.tx_delta_bytes", 'The delta cannot exceed the total counter.');
            }
        }
    }

    private function validateRedaction(Validator $validator, mixed $lines, mixed $prerequisites): void
    {
        $redactor = app(RecursiveRedactor::class);
        if (is_array($lines)) {
            foreach ($lines as $index => $line) {
                $text = is_array($line) ? ($line['line'] ?? null) : null;
                if (is_string($text) && $redactor->redact($text) !== $text) {
                    $validator->errors()->add("relevant_log_lines.$index.line", 'Log lines must be redacted before ingestion.');
                }
            }
        }
        if (is_array($prerequisites)) {
            foreach ($prerequisites as $index => $prerequisite) {
                $hint = is_array($prerequisite) ? ($prerequisite['path_hint'] ?? null) : null;
                if (is_string($hint) && $redactor->redact($hint) !== $hint) {
                    $validator->errors()->add("prerequisites.$index.path_hint", 'Diagnostic hints must be redacted before ingestion.');
                }
            }
        }
    }
}
