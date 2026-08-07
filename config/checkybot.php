<?php

declare(strict_types=1);

return [
    'expanded_checks' => [
        'domain_lookup_timeout_seconds' => (float) env('CHECKYBOT_DOMAIN_LOOKUP_TIMEOUT', 5),
    ],
    'push' => [
        'proving_enabled' => env('CHECKYBOT_PUSH_PROVING_ENABLED', true),
        'expo' => [
            'endpoint' => env('CHECKYBOT_EXPO_ENDPOINT', 'https://exp.host/--/api/v2/push/send'),
            'access_token' => env('CHECKYBOT_EXPO_ACCESS_TOKEN'),
            'timeout_seconds' => (int) env('CHECKYBOT_EXPO_TIMEOUT', 10),
            'max_attempts' => 4,
            'backoff_seconds' => [5, 30, 120],
        ],
        'legacy_webhook' => [
            'url' => env('CHECKYBOT_LEGACY_ALERT_WEBHOOK_URL'),
            'timeout_seconds' => (int) env('CHECKYBOT_LEGACY_ALERT_WEBHOOK_TIMEOUT', 10),
        ],
    ],
    'monitor_foundation' => [
        'contract_version' => 'monitor-foundation.v1',
        'check_sync_version' => 'check-sync.v1',
        'relay_batch_size' => 100,
        'relay_claim_seconds' => 60,
        'status_summary' => [
            'stale_after_seconds' => 900,
        ],
        'delivery' => [
            'max_attempts' => 3,
            'backoff_seconds' => [5, 30, 120],
        ],
        'redaction' => [
            'secret_literals' => array_values(array_filter(array_map(
                static fn (string $value): string => trim($value),
                explode(',', (string) env('CHECKYBOT_REDACTION_SECRETS', '')),
            ))),
        ],
    ],
];
