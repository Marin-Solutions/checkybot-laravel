<?php

namespace MarinSolutions\CheckybotLaravel;

class ConfigValidator
{
    /**
     * Valid interval pattern: number followed by s, m, h, or d.
     */
    protected const INTERVAL_PATTERN = '/^\d+[smhd]$/';

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

        if (empty($config['project_id'])) {
            $errors[] = 'CHECKYBOT_PROJECT_ID is not configured';
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
            $interval = $check->getInterval();

            if (empty($url)) {
                $errors[] = "Check '{$name}' is missing a URL";
            } elseif (! $this->isValidUrl($url)) {
                $errors[] = "Check '{$name}' has an invalid URL: {$url}";
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
}
