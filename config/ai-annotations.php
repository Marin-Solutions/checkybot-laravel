<?php

declare(strict_types=1);

return [
    'provider' => [
        'url' => env('AI_ANNOTATIONS_PROVIDER_URL'),
        'credential' => env('AI_ANNOTATIONS_PROVIDER_CREDENTIAL'),
        'model' => env('AI_ANNOTATIONS_PROVIDER_MODEL'),
        'connect_timeout_seconds' => (float) env('AI_ANNOTATIONS_CONNECT_TIMEOUT', 3),
        'timeout_seconds' => (float) env('AI_ANNOTATIONS_REQUEST_TIMEOUT', 10),
        'max_response_bytes' => (int) env('AI_ANNOTATIONS_MAX_RESPONSE_BYTES', 65536),
    ],
    'budget' => [
        'global_monthly_limit_microusd' => (int) env('AI_ANNOTATIONS_GLOBAL_MONTHLY_LIMIT_MICROUSD', 0),
        'project_monthly_limit_microusd' => (int) env('AI_ANNOTATIONS_PROJECT_MONTHLY_LIMIT_MICROUSD', 0),
        'max_request_microusd' => (int) env('AI_ANNOTATIONS_MAX_REQUEST_MICROUSD', 0),
        'input_token_microusd' => (int) env('AI_ANNOTATIONS_INPUT_TOKEN_MICROUSD', 0),
        'output_token_microusd' => (int) env('AI_ANNOTATIONS_OUTPUT_TOKEN_MICROUSD', 0),
    ],
    'limits' => [
        'max_lines' => (int) env('AI_ANNOTATIONS_MAX_LINES', 100),
        'max_line_chars' => (int) env('AI_ANNOTATIONS_MAX_LINE_CHARS', 1000),
        'max_input_chars' => (int) env('AI_ANNOTATIONS_MAX_INPUT_CHARS', 30000),
        'max_input_tokens' => (int) env('AI_ANNOTATIONS_MAX_INPUT_TOKENS', 12000),
        'max_output_tokens' => (int) env('AI_ANNOTATIONS_MAX_OUTPUT_TOKENS', 300),
        'max_root_cause_chars' => (int) env('AI_ANNOTATIONS_MAX_ROOT_CAUSE_CHARS', 1200),
    ],
];
