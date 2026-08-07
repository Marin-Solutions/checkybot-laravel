<?php

namespace MarinSolutions\CheckybotLaravel;

use MarinSolutions\CheckybotLaravel\Checks\BaseCheck;

/**
 * The single package boundary that creates the canonical check-sync.v1 body.
 */
final class CheckSyncPayloadSerializer
{
    public const CONTRACT_VERSION = 'check-sync.v1';

    /**
     * Canonical type to accepted configuration section names.
     *
     * @var array<string, list<string>>
     */
    private const CONFIG_SECTIONS = [
        'uptime' => ['uptime', 'uptime_checks'],
        'ssl' => ['ssl', 'ssl_checks'],
        'api' => ['api', 'api_checks'],
        'dead_links' => ['dead_links', 'links', 'link_checks'],
        'open_graph' => ['open_graph', 'openGraph', 'open_graph_checks'],
        'domain_expiry' => ['domain_expiry', 'domain_expiry_checks'],
        'response_time_budget' => ['response_time_budget', 'response_time_budget_checks'],
    ];

    /** @return array<string, list<array<string, mixed>>> */
    public function registryDefinitions(CheckRegistry $registry): array
    {
        return [
            'uptime' => $this->arrays($registry->getUptimeChecks()),
            'ssl' => $this->arrays($registry->getSslChecks()),
            'api' => $this->arrays($registry->getApiChecks()),
            'dead_links' => $this->arrays($registry->getLinkChecks()),
            'open_graph' => $this->arrays($registry->getOpenGraphChecks()),
            'domain_expiry' => $this->arrays($registry->getDomainExpiryChecks()),
            'response_time_budget' => $this->arrays($registry->getResponseTimeBudgetChecks()),
        ];
    }

    /**
     * Read canonical and legacy config section names without changing values.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, list<mixed>>
     */
    public function configDefinitions(array $config): array
    {
        $checks = is_array($config['checks'] ?? null) ? $config['checks'] : [];
        $definitions = [];

        foreach (self::CONFIG_SECTIONS as $type => $aliases) {
            $definitions[$type] = [];
            foreach ($aliases as $alias) {
                if (! array_key_exists($alias, $checks)) {
                    continue;
                }

                $definitions[$type] = is_array($checks[$alias]) ? array_values($checks[$alias]) : [];
                break;
            }
        }

        return $definitions;
    }

    /** @return array<string, mixed> */
    public function fromRegistry(CheckRegistry $registry): array
    {
        return $this->serialize($this->registryDefinitions($registry));
    }

    /** @param array<string, mixed> $config */
    public function fromConfig(array $config): array
    {
        return $this->serialize($this->configDefinitions($config));
    }

    /**
     * @param  array<string, list<mixed>>  $definitions
     * @return array<string, mixed>
     */
    public function serialize(array $definitions): array
    {
        $payload = ['contract_version' => self::CONTRACT_VERSION];

        foreach (array_keys(self::CONFIG_SECTIONS) as $type) {
            $payload[$type] = array_map(
                fn (mixed $check): array => $this->normalizeCheck($type, is_array($check) ? $check : []),
                $definitions[$type] ?? [],
            );
        }

        return $payload;
    }

    /**
     * Convert old assertion field names to the generated contract vocabulary.
     *
     * @param  list<array<string, mixed>>  $assertions
     * @return list<array<string, mixed>>
     */
    public function normalizeAssertions(array $assertions): array
    {
        $normalized = [];

        foreach ($assertions as $index => $assertion) {
            if (isset($assertion['kind']) || isset($assertion['operator'])) {
                $item = [
                    'kind' => $assertion['kind'] ?? null,
                    'operator' => $assertion['operator'] ?? null,
                ];
                if (array_key_exists('path', $assertion)) {
                    $item['path'] = $assertion['path'];
                }
                if (array_key_exists('operand', $assertion)) {
                    $item['operand'] = $assertion['operand'];
                } elseif (array_key_exists('expected', $assertion)) {
                    $item['operand'] = $assertion['expected'];
                }
            } else {
                $operator = match ($assertion['assertion_type'] ?? null) {
                    'exists' => 'exists',
                    'type_check' => 'type',
                    'regex_match' => 'matches',
                    'value_compare' => match ($assertion['comparison_operator'] ?? null) {
                        '=' => 'equals',
                        '!=' => 'not_equals',
                        '>' => 'greater_than',
                        '>=' => 'greater_than_or_equal',
                        '<' => 'less_than',
                        '<=' => 'less_than_or_equal',
                        default => null,
                    },
                    default => null,
                };
                $item = [
                    'kind' => 'json_path',
                    'operator' => $operator,
                    'path' => $assertion['data_path'] ?? null,
                ];
                if (array_key_exists('expected_value', $assertion)) {
                    $item['operand'] = $assertion['expected_value'];
                } elseif (array_key_exists('expected_type', $assertion)) {
                    $item['operand'] = $assertion['expected_type'];
                } elseif (array_key_exists('regex_pattern', $assertion)) {
                    $item['operand'] = $assertion['regex_pattern'];
                }
            }

            $item['sort_order'] = $index + 1;
            $item['is_active'] = is_bool($assertion['is_active'] ?? null)
                ? $assertion['is_active']
                : true;
            $normalized[] = $item;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $check */
    private function normalizeCheck(string $type, array $check): array
    {
        $keys = match ($type) {
            'uptime' => ['name', 'url', 'interval', 'max_redirects', 'headers'],
            'ssl' => ['name', 'url', 'interval'],
            'api' => ['name', 'url', 'interval', 'headers', 'expected_status', 'max_latency_ms', 'retry_count'],
            'dead_links' => ['name', 'url', 'interval', 'max_depth', 'exclude_paths', 'headers'],
            'open_graph' => ['name', 'url', 'interval', 'required_tags', 'headers'],
            'domain_expiry' => ['name', 'url', 'interval', 'warn_days'],
            'response_time_budget' => ['name', 'url', 'interval', 'percentile', 'budget_ms'],
            default => ['name', 'url', 'interval'],
        };

        $normalized = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $check)) {
                $normalized[$key] = $check[$key];
            }
        }

        if ($type === 'domain_expiry') {
            $normalized['warn_days'] = $check['warn_days'] ?? 30;
        }
        if ($type === 'response_time_budget') {
            $normalized['percentile'] = $check['percentile'] ?? 95;
            $normalized['budget_ms'] = $check['budget_ms'] ?? 2000;
        }
        if ($type === 'api') {
            $assertions = is_array($check['assertions'] ?? null) ? array_values($check['assertions']) : [];
            $normalizedAssertions = $this->normalizeAssertions($assertions);

            if (array_key_exists('expected_status', $check) && ! $this->hasAssertionKind($normalizedAssertions, 'status')) {
                array_unshift($normalizedAssertions, [
                    'kind' => 'status',
                    'operator' => 'equals',
                    'operand' => $check['expected_status'],
                    'sort_order' => 0,
                    'is_active' => true,
                ]);
            }
            if (array_key_exists('max_latency_ms', $check) && ! $this->hasAssertionKind($normalizedAssertions, 'latency')) {
                $normalizedAssertions[] = [
                    'kind' => 'latency',
                    'operator' => 'less_than_or_equal',
                    'operand' => $check['max_latency_ms'],
                    'sort_order' => 0,
                    'is_active' => true,
                ];
            }

            foreach ($normalizedAssertions as $index => &$assertion) {
                $assertion['sort_order'] = $index + 1;
            }
            unset($assertion);

            if ($normalizedAssertions !== []) {
                $normalized['assertions'] = $normalizedAssertions;
            }
        }

        return $normalized;
    }

    /** @param list<array<string, mixed>> $assertions */
    private function hasAssertionKind(array $assertions, string $kind): bool
    {
        foreach ($assertions as $assertion) {
            if (($assertion['kind'] ?? null) === $kind) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<BaseCheck>  $checks
     * @return list<array<string, mixed>>
     */
    private function arrays(array $checks): array
    {
        return array_map(static fn (BaseCheck $check): array => $check->toArray(), $checks);
    }
}
