<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Tests\Feature\ReleaseHardening\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class RetirementChecklistContract
{
    /** @param array<string, mixed> $evidence @return list<string> */
    public static function violations(array $evidence, CarbonImmutable $now): array
    {
        $violations = [];
        $watchdog = $evidence['external_watchdog'] ?? null;
        if (! is_array($watchdog)) {
            $violations[] = 'external_watchdog.missing';
        } else {
            if (($watchdog['status'] ?? null) !== 'success') {
                $violations[] = 'external_watchdog.failed';
            }
            if (($watchdog['independent'] ?? null) !== true || ($watchdog['depends_on_checkybot'] ?? null) !== false) {
                $violations[] = 'external_watchdog.not_independent';
            }
            try {
                $checkedAt = CarbonImmutable::parse((string) ($watchdog['checked_at'] ?? ''))->utc();
                if ($checkedAt->isAfter($now) || $checkedAt->lt($now->subMinutes(5))) {
                    $violations[] = 'external_watchdog.stale';
                }
            } catch (\Throwable) {
                $violations[] = 'external_watchdog.stale';
            }
        }

        $reliability = $evidence['push_reliability'] ?? null;
        if (! is_array($reliability)
            || ($reliability['retirement_ready'] ?? null) !== true
            || ($reliability['consecutive_complete_days'] ?? null) !== 28) {
            $violations[] = 'push_reliability.incomplete_window';
        } elseif (($reliability['failed_or_missing_pairs'] ?? null) !== 0
            || ! is_int($reliability['critical_intents'] ?? null)
            || $reliability['critical_intents'] < 1
            || ($reliability['expo_accepted'] ?? null) !== $reliability['critical_intents']
            || ($reliability['legacy_webhook_accepted'] ?? null) !== $reliability['critical_intents']) {
            $violations[] = 'push_reliability.failed_or_missing_pair';
        }

        foreach (['responsible_owner', 'signed_at'] as $field) {
            if (! is_string($evidence[$field] ?? null) || trim($evidence[$field]) === '') {
                $violations[] = $field.'.missing';
            }
        }

        return array_values(array_unique($violations));
    }

    /** @param array<string, mixed> $evidence */
    public static function isSignable(array $evidence, CarbonImmutable $now): bool
    {
        return self::violations($evidence, $now) === [];
    }
}

final class FleetReadinessContract
{
    /** @param list<string> $enabledServers @param array<string, mixed> $manifest @return list<string> */
    public static function violations(array $enabledServers, array $manifest): array
    {
        $errors = [];
        $expectedVersion = $manifest['expected_agent_version'] ?? null;
        if (! is_string($expectedVersion) || preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/', $expectedVersion) !== 1) {
            $errors[] = 'expected_agent_version.invalid';
        }

        $servers = $manifest['servers'] ?? null;
        if (! is_array($servers)) {
            return [...$errors, 'servers.missing'];
        }

        $inventoried = [];
        foreach ($servers as $index => $server) {
            $prefix = "servers.{$index}";
            if (! is_array($server) || ! Str::isUuid((string) ($server['server_uuid'] ?? ''))) {
                $errors[] = $prefix.'.server_uuid.invalid';

                continue;
            }
            $serverUuid = (string) $server['server_uuid'];
            if (in_array($serverUuid, $inventoried, true)) {
                $errors[] = $prefix.'.server_uuid.duplicate';
            }
            $inventoried[] = $serverUuid;

            $report = $server['latest_report'] ?? [];
            if (($report['accepted'] ?? null) !== true
                || ($report['schema_version'] ?? null) !== 'agent-report.v2'
                || ($report['reporting_interval_seconds'] ?? null) !== 60
                || ($report['agent_version'] ?? null) !== $expectedVersion
                || ! is_string($report['accepted_at'] ?? null)) {
                $errors[] = $prefix.'.latest_report.invalid';
            }

            $prerequisites = $report['prerequisites'] ?? [];
            foreach (['nginx_access', 'nginx_error', 'php_fpm'] as $required) {
                if (($prerequisites[$required] ?? null) !== 'readable') {
                    $errors[] = $prefix.'.prerequisites.'.$required;
                }
            }
            if (($prerequisites['mysql'] ?? null) === 'not_configured') {
                $exception = $server['optional_mysql_exception'] ?? [];
                if (($exception['approved'] ?? null) !== true
                    || ! is_string($exception['approved_by'] ?? null) || trim($exception['approved_by']) === ''
                    || ! is_string($exception['approved_at'] ?? null) || trim($exception['approved_at']) === ''
                    || ! is_string($exception['reason'] ?? null) || trim($exception['reason']) === '') {
                    $errors[] = $prefix.'.optional_mysql_exception.unapproved';
                }
            } elseif (($prerequisites['mysql'] ?? null) !== 'readable') {
                $errors[] = $prefix.'.prerequisites.mysql';
            }

            $cap = $server['link_cap'] ?? [];
            if (($cap['confirmed'] ?? null) !== true || ! is_int($cap['bits_per_second'] ?? null) || $cap['bits_per_second'] < 1) {
                $errors[] = $prefix.'.link_cap.unconfirmed';
            }
            $canary = $server['canary_wave'] ?? [];
            if (! in_array($canary['name'] ?? null, ['variant-canary', '5%', '25%', '50%', '100%'], true)
                || ($canary['result'] ?? null) !== 'passed'
                || ! is_string($canary['evidence_path'] ?? null)) {
                $errors[] = $prefix.'.canary_wave.invalid';
            }
            $rotation = $server['scoped_token_rotation'] ?? [];
            if (($rotation['abilities'] ?? null) !== ['agent:report']
                || ($rotation['new_token_report_accepted'] ?? null) !== true
                || ($rotation['old_token_revoked'] ?? null) !== true
                || ! is_string($rotation['evidence_path'] ?? null)) {
                $errors[] = $prefix.'.scoped_token_rotation.invalid';
            }
            $rollback = $server['rollback'] ?? [];
            if (! is_string($rollback['owner'] ?? null) || trim($rollback['owner']) === ''
                || ! is_string($rollback['post_rollback_heartbeat_procedure'] ?? null) || trim($rollback['post_rollback_heartbeat_procedure']) === ''
                || ! is_string($rollback['evidence_path'] ?? null)) {
                $errors[] = $prefix.'.rollback.invalid';
            }
        }

        sort($enabledServers);
        $inventoried = array_values(array_unique($inventoried));
        sort($inventoried);
        if ($inventoried !== $enabledServers) {
            $errors[] = 'servers.inventory_incomplete';
        }

        return array_values(array_unique($errors));
    }
}

final class EvidenceSecretScanContract
{
    /** @param mixed $value @return list<string> */
    public static function violations(mixed $value, string $path = '$'): array
    {
        $violations = [];
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $childPath = $path.'.'.$key;
                if (is_string($key) && preg_match('/(?:plaintext[_-]?token|provider[_-]?credentials?|webhook[_-]?url|raw[_-]?payload|authorization)/i', $key) === 1) {
                    $violations[] = $childPath;
                }
                array_push($violations, ...self::violations($child, $childPath));
            }

            return array_values(array_unique($violations));
        }
        if (! is_string($value)) {
            return [];
        }

        if (preg_match('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', $value) === 1
            || str_contains($value, 'ExponentPushToken[')
            || preg_match('~https://[^\s/?#]+(?:/[^\s?#]*)?(?:\?[^\s#]+)~i', $value) === 1
            || preg_match('~https://[^/@\s]+:[^/@\s]+@~', $value) === 1) {
            $violations[] = $path;
        }

        return $violations;
    }
}
