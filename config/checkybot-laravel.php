<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Checkybot API Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your Checkybot instance URL and authentication credentials.
    | The project identifier should be stable for this repository, such as
    | "vendor/repository" or your production app slug.
    |
    */

    'api_key' => env('CHECKYBOT_API_KEY'),
    'project_identifier' => env('CHECKYBOT_PROJECT_IDENTIFIER', env('CHECKYBOT_PROJECT_ID')),
    'project_id' => env('CHECKYBOT_PROJECT_ID'),
    'base_url' => env('CHECKYBOT_URL'),
    'environment' => env('CHECKYBOT_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Check Definition Location
    |--------------------------------------------------------------------------
    |
    | The package loads this file before syncing so deployments can keep fluent
    | check definitions in source control.
    |
    */

    'checks_path' => base_path('routes/checkybot.php'),

    /*
    |--------------------------------------------------------------------------
    | Default Check Headers
    |--------------------------------------------------------------------------
    |
    | These headers are merged into every check payload. Per-check headers win.
    | Values are sent to Checkybot but redacted from command output.
    |
    */

    'default_headers' => [
        // 'Accept' => 'application/json',
        // 'X-Monitoring-Key' => env('CHECKYBOT_MONITORING_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Configuration
    |--------------------------------------------------------------------------
    */

    'timeout' => 30,
    'retry_times' => 3,
    'retry_delay' => 1000, // milliseconds

    /*
    |--------------------------------------------------------------------------
    | Monitoring Checks
    |--------------------------------------------------------------------------
    |
    | Define your monitoring checks below. The v1 contract supports a flat array
    | of checks with a "type" field. The legacy grouped arrays are still accepted.
    | Names are used to identify checks during sync operations.
    |
    | Common intervals:
    |   - '1m', '5m', '10m', '15m', '30m'  (minutes)
    |   - '1h', '2h', '6h', '12h'          (hours)
    |   - '1d', '7d'                       (days)
    |
    */

    'checks' => [
        // [
        //     'type' => 'api',
        //     'name' => 'health-check',
        //     'method' => 'GET',
        //     'path' => '/api/health',
        //     'interval' => '5m',
        //     'headers' => [
        //         'X-Scrappa-Key' => env('SCRAPPA_HEALTH_KEY'),
        //     ],
        //     'expected_status' => 200,
        //     'timeout' => 10,
        //     'required_json_paths' => ['status'],
        //     'body_assertions' => [
        //         ['path' => 'status', 'operator' => 'equals', 'value' => 'ok'],
        //     ],
        // ],

        /*
        |--------------------------------------------------------------------------
        | Uptime Checks
        |--------------------------------------------------------------------------
        |
        | Monitor website uptime and response times.
        |
        | Required: name, url, interval
        | Optional: max_redirects, headers
        |
        */

        'uptime' => [
            // [
            //     'name' => 'homepage-uptime',
            //     'url' => env('APP_URL'),
            //     'interval' => '5m',
            //     'max_redirects' => 10,
            //     'headers' => [
            //         'Authorization' => 'Bearer ' . env('MONITORING_TOKEN'),
            //     ],
            // ],
        ],

        /*
        |--------------------------------------------------------------------------
        | SSL Certificate Checks
        |--------------------------------------------------------------------------
        |
        | Monitor SSL certificate expiration.
        |
        | Required: name, url, interval
        |
        */

        'ssl' => [
            // [
            //     'name' => 'homepage-ssl',
            //     'url' => env('APP_URL'),
            //     'interval' => '1d',
            // ],
        ],

        /*
        |--------------------------------------------------------------------------
        | API Endpoint Checks
        |--------------------------------------------------------------------------
        |
        | Monitor API endpoints and validate JSON responses.
        |
        | Required: name, url, interval
        | Optional: headers, assertions
        |
        */

        'api' => [
            // [
            //     'name' => 'health-check',
            //     'url' => env('APP_URL').'/api/health',
            //     'interval' => '5m',
            //     'headers' => [
            //         'Accept' => 'application/json',
            //     ],
            //     'assertions' => [
            //         [
            //             'data_path' => 'status',
            //             'assertion_type' => 'exists',
            //             'sort_order' => 1,
            //             'is_active' => true,
            //         ],
            //     ],
            // ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Dead Link Checks
        |--------------------------------------------------------------------------
        |
        | Monitor pages for broken or dead links.
        |
        | Required: name, url, interval
        | Optional: max_depth, exclude_paths, headers
        |
        */

        'dead_links' => [
            // [
            //     'name' => 'homepage-links',
            //     'url' => env('APP_URL'),
            //     'interval' => '1d',
            //     'max_depth' => 1,
            //     'exclude_paths' => ['/admin/*', '/logout'],
            // ],
        ],

        /*
        |--------------------------------------------------------------------------
        | OpenGraph Checks
        |--------------------------------------------------------------------------
        |
        | Validate OpenGraph meta tags on pages.
        |
        | Required: name, url, interval
        | Optional: required_tags, headers
        |
        */

        'open_graph' => [
            // [
            //     'name' => 'homepage-og',
            //     'url' => env('APP_URL'),
            //     'interval' => '1d',
            //     'required_tags' => ['og:title', 'og:description', 'og:image'],
            // ],
        ],
    ],
];
