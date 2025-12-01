<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel;

use MarinSolutions\CheckybotLaravel\Support\Constants;

/**
 * Validates and transforms monitoring check configuration
 */
class ConfigValidator
{
    /**
     * Validate configuration structure and content
     *
     * @param  array<string, mixed>  $config
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validate(array $config): array
    {
        $errors = [];

        $this->validateCredentials($config, $errors);

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        $this->validateCheckNames($config['checks'] ?? [], $errors);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate API credentials
     *
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $errors
     */
    protected function validateCredentials(array $config, array &$errors): void
    {
        if (empty($config['api_key'])) {
            $errors[] = Constants::ERROR_API_KEY_MISSING;
        }

        if (empty($config['project_id'])) {
            $errors[] = Constants::ERROR_PROJECT_ID_MISSING;
        }
    }

    /**
     * Validate check names for duplicates
     *
     * @param  array<string, mixed>  $checks
     * @param  array<int, string>  $errors
     */
    protected function validateCheckNames(array $checks, array &$errors): void
    {
        $checkTypes = [
            Constants::CHECK_TYPE_UPTIME,
            Constants::CHECK_TYPE_SSL,
            Constants::CHECK_TYPE_API,
        ];

        foreach ($checkTypes as $type) {
            $names = array_column($checks[$type] ?? [], 'name');
            $nameCounts = array_count_values($names);
            $duplicates = array_filter($nameCounts, fn(int $count): bool => $count > 1);

            if (!empty($duplicates)) {
                $duplicateNames = array_keys($duplicates);
                $errors[] = sprintf(
                    Constants::ERROR_DUPLICATE_CHECK_NAMES,
                    $type,
                    implode(', ', $duplicateNames)
                );
            }
        }
    }

    /**
     * Transform configuration to API payload format
     *
     * @param  array<string, mixed>  $config
     * @return array{uptime_checks: array<int, mixed>, ssl_checks: array<int, mixed>, api_checks: array<int, mixed>}
     */
    public function transformPayload(array $config): array
    {
        $checks = $config['checks'] ?? [];

        return [
            'uptime_checks' => $checks[Constants::CHECK_TYPE_UPTIME] ?? [],
            'ssl_checks' => $checks[Constants::CHECK_TYPE_SSL] ?? [],
            'api_checks' => $checks[Constants::CHECK_TYPE_API] ?? [],
        ];
    }
}
