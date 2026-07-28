<?php

namespace MarinSolutions\CheckybotLaravel;

class ConfigValidator
{
    /**
     * Valid interval pattern: number followed by s, m, h, or d.
     */
    protected const INTERVAL_PATTERN = '/^\d+[smhd]$/';

    /**
     * Supported check types in the v1 sync contract.
     */
    protected const CHECK_TYPES = ['api', 'uptime', 'ssl', 'links', 'opengraph'];

    /**
     * Supported HTTP methods for API endpoint checks.
     */
    protected const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * Validate config-based checks (legacy array format).
     *
     * @param  array<string, mixed>  $config
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validate(array $config): array
    {
        $errors = [];

        $this->validateCredentials($config, $errors);

        if (! empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        $checks = $config['checks'] ?? [];
        $this->validateCheckNames($checks, $errors);
        $this->validateCheckFields($checks, $errors);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate registry-based checks (fluent API).
     *
     * @param  array<string, mixed>  $config
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateWithRegistry(array $config, CheckRegistry $registry): array
    {
        $errors = [];

        $this->validateCredentials($config, $errors);

        if (! empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        $this->validateRegistryCheckNames($registry, $errors);
        $this->validateRegistryCheckFields($registry, $errors);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<int, string>  $errors
     */
    protected function validateCredentials(array $config, array &$errors): void
    {
        if (empty($config['api_key'])) {
            $errors[] = 'CHECKYBOT_API_KEY is not configured';
        }

        if (array_key_exists('base_url', $config) && empty($config['base_url'])) {
            $errors[] = 'CHECKYBOT_URL is not configured';
        }

        if (empty($this->projectIdentifier($config))) {
            $errors[] = array_key_exists('project_identifier', $config)
                ? 'CHECKYBOT_PROJECT_IDENTIFIER is not configured'
                : 'CHECKYBOT_PROJECT_ID is not configured';
        }
    }

    /**
     * @param  array<int, string>  $errors
     */
    protected function validateRegistryCheckNames(CheckRegistry $registry, array &$errors): void
    {
        $checkTypes = [
            'uptime' => $registry->getUptimeChecks(),
            'ssl' => $registry->getSslChecks(),
            'api' => $registry->getApiChecks(),
            'link' => $registry->getLinkChecks(),
            'open_graph' => $registry->getOpenGraphChecks(),
        ];

        foreach ($checkTypes as $type => $checks) {
            $names = array_map(fn ($check) => $check->getName(), $checks);
            $duplicates = array_diff_assoc($names, array_unique($names));

            if (! empty($duplicates)) {
                $errors[] = "Duplicate {$type} check names found: ".implode(', ', array_unique($duplicates));
            }
        }
    }

    /**
     * @param  array<int, string>  $errors
     */
    protected function validateRegistryCheckFields(CheckRegistry $registry, array &$errors): void
    {
        $allChecks = array_merge(
            $registry->getUptimeChecks(),
            $registry->getSslChecks(),
            $registry->getApiChecks(),
            $registry->getLinkChecks(),
            $registry->getOpenGraphChecks(),
        );

        foreach ($allChecks as $check) {
            $name = $check->getName();
            $url = $check->getUrl();
            $supportsPath = method_exists($check, 'getPath');
            $path = $supportsPath ? $check->getPath() : null;
            $interval = $check->getInterval();

            if (empty($url) && empty($path)) {
                $errors[] = $supportsPath
                    ? "Check '{$name}' is missing a URL or path"
                    : "Check '{$name}' is missing a URL";
            } elseif (! empty($url) && ! $this->isValidUrl($url)) {
                $errors[] = "Check '{$name}' has an invalid URL: {$url}";
            }

            if (! empty($path) && ! str_starts_with((string) $path, '/')) {
                $errors[] = "Check '{$name}' has an invalid path: {$path}";
            }

            if (empty($interval)) {
                $errors[] = "Check '{$name}' is missing an interval";
            } elseif (! $this->isValidInterval($interval)) {
                $errors[] = "Check '{$name}' has an invalid interval: {$interval}";
            }
        }
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $checks
     * @param  array<int, string>  $errors
     */
    protected function validateCheckNames(array $checks, array &$errors): void
    {
        if ($this->isFlatChecksArray($checks)) {
            $this->validateFlatCheckNames($checks, $errors);

            return;
        }

        foreach (['uptime', 'ssl', 'api', 'dead_links', 'open_graph'] as $type) {
            $names = array_column($checks[$type] ?? [], 'name');
            $duplicates = array_diff_assoc($names, array_unique($names));

            if (! empty($duplicates)) {
                $errors[] = "Duplicate {$type} check names found: ".implode(', ', array_unique($duplicates));
            }
        }
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $checks
     * @param  array<int, string>  $errors
     */
    protected function validateCheckFields(array $checks, array &$errors): void
    {
        if ($this->isFlatChecksArray($checks)) {
            foreach ($checks as $index => $check) {
                $this->validateFlatCheck($check, $index, $errors);
            }

            return;
        }

        foreach (['uptime', 'ssl', 'api', 'dead_links', 'open_graph'] as $type) {
            foreach ($checks[$type] ?? [] as $index => $check) {
                $name = $check['name'] ?? "{$type}.{$index}";

                if (empty($check['name'])) {
                    $errors[] = "{$type} check at index {$index} is missing a name";
                }

                if (empty($check['url'])) {
                    $errors[] = "Check '{$name}' is missing a URL";
                } elseif (! $this->isValidUrl($check['url'])) {
                    $errors[] = "Check '{$name}' has an invalid URL: {$check['url']}";
                }

                if (empty($check['interval'])) {
                    $errors[] = "Check '{$name}' is missing an interval";
                } elseif (! $this->isValidInterval($check['interval'])) {
                    $errors[] = "Check '{$name}' has an invalid interval: {$check['interval']}";
                }
            }
        }
    }

    protected function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    protected function isValidInterval(string $interval): bool
    {
        return (bool) preg_match(self::INTERVAL_PATTERN, $interval);
    }

    /**
     * Transform config-based checks to payload format.
     *
     * @param  array<string, mixed>  $config
     * @return array{uptime_checks: array<int, mixed>, ssl_checks: array<int, mixed>, api_checks: array<int, mixed>, link_checks: array<int, mixed>, open_graph_checks: array<int, mixed>}
     */
    public function transformPayload(array $config): array
    {
        return [
            'uptime_checks' => $config['checks']['uptime'] ?? [],
            'ssl_checks' => $config['checks']['ssl'] ?? [],
            'api_checks' => $config['checks']['api'] ?? [],
            'link_checks' => $config['checks']['dead_links'] ?? [],
            'open_graph_checks' => $config['checks']['open_graph'] ?? [],
        ];
    }

    /**
     * Build the v1 sync payload consumed by the main Checkybot app.
     *
     * @param  array<string, mixed>  $config
     * @return array{project_identifier: string, environment: string, checks: array<int, array<string, mixed>>}
     */
    public function buildSyncPayload(array $config, ?CheckRegistry $registry = null): array
    {
        $checks = $registry !== null && $registry->count() > 0
            ? $this->checksFromLegacyPayload($registry->toArray())
            : $this->checksFromConfig($config['checks'] ?? []);

        $defaultHeaders = $this->headers($config['default_headers'] ?? []);

        return [
            'project_identifier' => $this->projectIdentifier($config),
            'environment' => (string) ($config['environment'] ?? 'production'),
            'checks' => array_map(
                fn (array $check): array => $this->normalizeCheckForSync($check, $defaultHeaders),
                $checks
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function projectIdentifier(array $config): string
    {
        return (string) ($config['project_identifier'] ?? $config['project_id'] ?? '');
    }

    /**
     * @param  array<int|string, mixed>  $checks
     */
    protected function isFlatChecksArray(array $checks): bool
    {
        return array_is_list($checks) && ($checks === [] || array_key_exists('type', $checks[0]));
    }

    /**
     * @param  array<int|string, mixed>  $checks
     * @param  array<int, string>  $errors
     */
    protected function validateFlatCheckNames(array $checks, array &$errors): void
    {
        $namesByType = [];

        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }

            $type = $this->normalizeType((string) ($check['type'] ?? ''));

            if (! isset($namesByType[$type])) {
                $namesByType[$type] = [];
            }

            $namesByType[$type][] = $check['name'] ?? null;
        }

        foreach ($namesByType as $type => $names) {
            $names = array_filter($names);
            $duplicates = array_diff_assoc($names, array_unique($names));

            if (! empty($duplicates)) {
                $errors[] = "Duplicate {$type} check names found: ".implode(', ', array_unique($duplicates));
            }
        }
    }

    /**
     * @param  array<int, string>  $errors
     */
    protected function validateFlatCheck(mixed $check, int|string $index, array &$errors): void
    {
        if (! is_array($check)) {
            $errors[] = "Check at index {$index} must be an array";

            return;
        }

        $type = $this->normalizeType((string) ($check['type'] ?? ''));
        $name = $check['name'] ?? "checks.{$index}";

        if (! in_array($type, self::CHECK_TYPES, true)) {
            $errors[] = "Check '{$name}' has an unsupported type: {$type}";
        }

        if (empty($check['name'])) {
            $errors[] = "Check at index {$index} is missing a name";
        }

        if (empty($check['url']) && empty($check['path'])) {
            $errors[] = "Check '{$name}' is missing a URL or path";
        }

        if (! empty($check['url']) && ! $this->isValidUrl((string) $check['url'])) {
            $errors[] = "Check '{$name}' has an invalid URL: {$check['url']}";
        }

        if (! empty($check['path']) && ! str_starts_with((string) $check['path'], '/')) {
            $errors[] = "Check '{$name}' has an invalid path: {$check['path']}";
        }

        if (empty($check['interval'])) {
            $errors[] = "Check '{$name}' is missing an interval";
        } elseif (! $this->isValidInterval((string) $check['interval'])) {
            $errors[] = "Check '{$name}' has an invalid interval: {$check['interval']}";
        }

        if ($type !== 'api') {
            return;
        }

        $method = strtoupper((string) ($check['method'] ?? 'GET'));
        if (! in_array($method, self::HTTP_METHODS, true)) {
            $errors[] = "Check '{$name}' has an unsupported method: {$method}";
        }

        if (isset($check['expected_status']) && (! is_int($check['expected_status']) || $check['expected_status'] < 100 || $check['expected_status'] > 599)) {
            $errors[] = "Check '{$name}' has an invalid expected status";
        }

        if (isset($check['timeout']) && (! is_int($check['timeout']) || $check['timeout'] < 1)) {
            $errors[] = "Check '{$name}' has an invalid timeout";
        }
    }

    /**
     * @param  array<int|string, mixed>  $checks
     * @return array<int, array<string, mixed>>
     */
    protected function checksFromConfig(array $checks): array
    {
        if ($this->isFlatChecksArray($checks)) {
            return $checks;
        }

        return $this->checksFromLegacyPayload([
            'uptime_checks' => $checks['uptime'] ?? [],
            'ssl_checks' => $checks['ssl'] ?? [],
            'api_checks' => $checks['api'] ?? [],
            'link_checks' => $checks['dead_links'] ?? [],
            'open_graph_checks' => $checks['open_graph'] ?? [],
        ]);
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $payload
     * @return array<int, array<string, mixed>>
     */
    protected function checksFromLegacyPayload(array $payload): array
    {
        $checks = [];
        $typeMap = [
            'uptime_checks' => 'uptime',
            'ssl_checks' => 'ssl',
            'api_checks' => 'api',
            'link_checks' => 'links',
            'open_graph_checks' => 'opengraph',
        ];

        foreach ($typeMap as $key => $type) {
            foreach ($payload[$key] ?? [] as $check) {
                $checks[] = ['type' => $type] + $check;
            }
        }

        return $checks;
    }

    /**
     * @param  array<string, mixed>  $check
     * @param  array<string, string>  $defaultHeaders
     * @return array<string, mixed>
     */
    protected function normalizeCheckForSync(array $check, array $defaultHeaders): array
    {
        $check['type'] = $this->normalizeType((string) $check['type']);

        if ($check['type'] === 'api') {
            $check['method'] = strtoupper((string) ($check['method'] ?? 'GET'));
            $check['expected_status'] = $check['expected_status'] ?? 200;
        }

        $headers = array_merge($defaultHeaders, $this->headers($check['headers'] ?? []));

        if ($headers !== []) {
            $check['headers'] = $headers;
        } else {
            unset($check['headers']);
        }

        return $check;
    }

    protected function normalizeType(string $type): string
    {
        return match ($type) {
            'dead_links', 'link', 'links' => 'links',
            'open_graph', 'openGraph', 'opengraph' => 'opengraph',
            default => $type,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function headers(mixed $headers): array
    {
        if (! is_array($headers)) {
            return [];
        }

        return array_filter($headers, fn ($value): bool => is_string($value));
    }
}
