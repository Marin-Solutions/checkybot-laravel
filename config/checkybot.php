<?php

declare(strict_types=1);

return [
    'monitor_foundation' => [
        'contract_version' => 'monitor-foundation.v1',
        'check_sync_version' => 'check-sync.v1',
        'relay_batch_size' => 100,
        'relay_claim_seconds' => 60,
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
