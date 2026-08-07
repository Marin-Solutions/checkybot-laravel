<?php

namespace MarinSolutions\CheckybotLaravel;

class ConfigValidator
{
    protected const INTERVAL_PATTERN = '/^\d+[smhd]$/';

    public function __construct(
        private readonly CheckSyncPayloadSerializer $serializer = new CheckSyncPayloadSerializer,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     * @return array{valid: bool, errors: list<string>}
     */
    public function validate(array $config): array
    {
        $errors = [];
        $this->validateCredentials($config, $errors);
        if ($errors === []) {
            $this->validateDefinitions($this->serializer->configDefinitions($config), $errors);
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{valid: bool, errors: list<string>}
     */
    public function validateWithRegistry(array $config, CheckRegistry $registry): array
    {
        $errors = [];
        $this->validateCredentials($config, $errors);
        if ($errors === []) {
            $this->validateDefinitions($this->serializer->registryDefinitions($registry), $errors);
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /** @param array<mixed> $errors */
    private function validateCredentials(array $config, array &$errors): void
    {
        if (! is_string($config['api_key'] ?? null) || trim($config['api_key']) === '') {
            $errors[] = 'CHECKYBOT_API_KEY is not configured';
        }
        if ((! is_string($config['project_id'] ?? null) && ! is_int($config['project_id'] ?? null)) || trim((string) $config['project_id']) === '') {
            $errors[] = 'CHECKYBOT_PROJECT_ID is not configured';
        }
    }

    /**
     * @param  array<string, list<mixed>>  $definitions
     * @param  array<mixed>  $errors
     */
    private function validateDefinitions(array $definitions, array &$errors): void
    {
        foreach ($definitions as $type => $checks) {
            $seen = [];
            foreach ($checks as $index => $check) {
                if (! is_array($check)) {
                    $errors[] = "{$type} check at index {$index} must be an array";

                    continue;
                }

                $name = $check['name'] ?? null;
                $displayName = is_string($name) && $name !== '' ? $name : "{$type}.{$index}";
                if (! is_string($name) || trim($name) === '') {
                    $errors[] = "{$type} check at index {$index} is missing a name";
                } elseif (isset($seen[$name])) {
                    $errors[] = "Duplicate {$type} check names found: {$name}";
                } else {
                    $seen[$name] = true;
                }

                $url = $check['url'] ?? null;
                if (! is_string($url) || $url === '') {
                    $errors[] = "Check '{$displayName}' is missing a URL";
                } elseif (! $this->isValidUrl($url)) {
                    $errors[] = "Check '{$displayName}' has an invalid URL: {$url}";
                }

                $interval = $check['interval'] ?? null;
                if (! is_string($interval) || $interval === '') {
                    $errors[] = "Check '{$displayName}' is missing an interval";
                } elseif (! $this->isValidInterval($interval)) {
                    $errors[] = "Check '{$displayName}' has an invalid interval: {$interval}";
                }

                $this->validateTypeOptions($type, $displayName, $check, $errors);
            }
        }
    }

    /** @param array<string, mixed> $check @param array<mixed> $errors */
    private function validateTypeOptions(string $type, string $name, array $check, array &$errors): void
    {
        if ($type === 'domain_expiry' && array_key_exists('warn_days', $check)) {
            $this->boundedInteger($check['warn_days'], 1, 365, "Check '{$name}' warn_days", $errors);
        }

        if ($type === 'response_time_budget') {
            if (array_key_exists('percentile', $check)) {
                $this->boundedInteger($check['percentile'], 1, 100, "Check '{$name}' percentile", $errors);
            }
            if (array_key_exists('budget_ms', $check)) {
                $this->boundedInteger($check['budget_ms'], 1, 3_600_000, "Check '{$name}' budget_ms", $errors);
            }
        }

        if ($type !== 'api') {
            return;
        }

        if (array_key_exists('expected_status', $check)) {
            $this->boundedInteger($check['expected_status'], 100, 599, "Check '{$name}' expected_status", $errors);
        }
        if (array_key_exists('max_latency_ms', $check)) {
            $this->boundedInteger($check['max_latency_ms'], 1, 3_600_000, "Check '{$name}' max_latency_ms", $errors);
        }
        if (array_key_exists('retry_count', $check)) {
            $this->boundedInteger($check['retry_count'], 0, 10, "Check '{$name}' retry_count", $errors);
        }

        $assertions = $check['assertions'] ?? [];
        if (! is_array($assertions) || ! array_is_list($assertions)) {
            $errors[] = "Check '{$name}' assertions must be a list";

            return;
        }
        if (count($assertions) > 50) {
            $errors[] = "Check '{$name}' assertions may contain at most 50 entries";
        }

        foreach ($assertions as $index => $assertion) {
            if (! is_array($assertion)) {
                $errors[] = "Check '{$name}' assertion {$index} must be an array";

                continue;
            }
            $normalized = $this->serializer->normalizeAssertions([$assertion])[0];
            $this->validateAssertion($name, $index, $normalized, $errors);
        }
    }

    /** @param array<string, mixed> $assertion @param array<mixed> $errors */
    private function validateAssertion(string $name, int $index, array $assertion, array &$errors): void
    {
        $prefix = "Check '{$name}' assertion {$index}";
        $kind = $assertion['kind'] ?? null;
        $operator = $assertion['operator'] ?? null;
        $hasOperand = array_key_exists('operand', $assertion);
        $operand = $assertion['operand'] ?? null;

        if (! is_string($kind) || ! in_array($kind, ['status', 'latency', 'json_path'], true)) {
            $errors[] = "{$prefix} has an invalid kind";

            return;
        }
        if (! is_string($operator)) {
            $errors[] = "{$prefix} has an invalid operator";

            return;
        }

        if ($kind === 'status') {
            if (! in_array($operator, ['equals', 'in'], true)) {
                $errors[] = "{$prefix} has an invalid status operator";
            } elseif ($operator === 'equals' && (! $hasOperand || ! $this->isHttpStatus($operand))) {
                $errors[] = "{$prefix} has an invalid status operand";
            } elseif ($operator === 'in' && (! is_array($operand) || ! array_is_list($operand) || $operand === [] || count($operand) > 20 || array_filter($operand, fn (mixed $value): bool => ! $this->isHttpStatus($value)) !== [])) {
                $errors[] = "{$prefix} has an invalid status operand";
            }
            if (array_key_exists('path', $assertion)) {
                $errors[] = "{$prefix} status assertions cannot have a path";
            }

            return;
        }

        if ($kind === 'latency') {
            if ($operator !== 'less_than_or_equal') {
                $errors[] = "{$prefix} has an invalid latency operator";
            }
            if (! $hasOperand || ! is_int($operand) || $operand < 1 || $operand > 3_600_000) {
                $errors[] = "{$prefix} has an invalid latency operand";
            }
            if (array_key_exists('path', $assertion)) {
                $errors[] = "{$prefix} latency assertions cannot have a path";
            }

            return;
        }

        $path = $assertion['path'] ?? null;
        if (! is_string($path) || ! $this->isValidJsonPath($path)) {
            $errors[] = "{$prefix} has an invalid JSON path";
        }

        $withoutOperand = ['exists', 'not_null', 'non_empty'];
        $scalarOperand = ['equals', 'not_equals', 'greater_than', 'greater_than_or_equal', 'less_than', 'less_than_or_equal'];
        if (in_array($operator, $withoutOperand, true)) {
            if ($hasOperand) {
                $errors[] = "{$prefix} operator {$operator} does not accept an operand";
            }
        } elseif (in_array($operator, $scalarOperand, true)) {
            if (! $hasOperand || (! is_scalar($operand) && $operand !== null)) {
                $errors[] = "{$prefix} operator {$operator} requires a scalar operand";
            }
        } elseif ($operator === 'type') {
            if (! is_string($operand) || ! in_array($operand, ['string', 'integer', 'number', 'boolean', 'null', 'array', 'object'], true)) {
                $errors[] = "{$prefix} has an invalid type operand";
            }
        } elseif ($operator === 'matches') {
            if (! is_string($operand) || $operand === '') {
                $errors[] = "{$prefix} has an invalid regex operand";
            }
        } else {
            $errors[] = "{$prefix} has an invalid JSON operator";
        }
    }

    /** @param array<mixed> $errors */
    private function boundedInteger(mixed $value, int $minimum, int $maximum, string $field, array &$errors): void
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            $errors[] = "{$field} must be an integer between {$minimum} and {$maximum}";
        }
    }

    private function isHttpStatus(mixed $value): bool
    {
        return is_int($value) && $value >= 100 && $value <= 599;
    }

    protected function isValidUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    protected function isValidInterval(string $interval): bool
    {
        return preg_match(self::INTERVAL_PATTERN, $interval) === 1;
    }

    private function isValidJsonPath(string $path): bool
    {
        if ($path === '' || strlen($path) > 512 || str_contains($path, '..')) {
            return false;
        }

        return preg_match('/^(?:[A-Za-z_][A-Za-z0-9_-]*(?:\.[A-Za-z_][A-Za-z0-9_-]*)*|\$(?:(?:\.[A-Za-z_][A-Za-z0-9_-]*)|(?:\[\d+\])|(?:\[\'[^\'\\\\]+\'\]))*)$/', $path) === 1;
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    public function transformPayload(array $config): array
    {
        return $this->serializer->fromConfig($config);
    }
}
