<?php

namespace MarinSolutions\CheckybotLaravel;

class ConfigValidator
{
    /**
     * @param  array<string, mixed>  $config
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validate(array $config): array
    {
        $errors = [];

        if (empty($config['api_key'])) {
            $errors[] = 'CHECKYBOT_API_KEY is not configured';
        }

        if (empty($config['project_id'])) {
            $errors[] = 'CHECKYBOT_PROJECT_ID is not configured';
        }

        if (! empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        $checks = $config['checks'] ?? [];
        $this->validateCheckNames($checks, $errors);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $checks
     * @param  array<int, string>  $errors
     */
    protected function validateCheckNames(array $checks, array &$errors): void
    {
        foreach (['uptime', 'ssl', 'api'] as $type) {
            $names = array_column($checks[$type] ?? [], 'name');
            $duplicates = array_diff_assoc($names, array_unique($names));

            if (! empty($duplicates)) {
                $errors[] = "Duplicate {$type} check names found: ".implode(', ', array_unique($duplicates));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{uptime_checks: array<int, mixed>, ssl_checks: array<int, mixed>, api_checks: array<int, mixed>}
     */
    public function transformPayload(array $config): array
    {
        return [
            'uptime_checks' => $config['checks']['uptime'] ?? [],
            'ssl_checks' => $config['checks']['ssl'] ?? [],
            'api_checks' => $config['checks']['api'] ?? [],
        ];
    }
}
