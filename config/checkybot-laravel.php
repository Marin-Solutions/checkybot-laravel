<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Checkybot API Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your Checkybot instance URL and authentication credentials.
    | You must create a project in Checkybot first and obtain the Project ID.
    |
    */

    'api_key' => env('CHECKYBOT_API_KEY'),
    'project_id' => env('CHECKYBOT_PROJECT_ID'),
    'base_url' => env('CHECKYBOT_URL', 'https://checkybot.com'),

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
    | Define your monitoring checks below. Each check must have a unique name
    | within its type (uptime, ssl, api, dead_links, open_graph,
    | domain_expiry, response_time_budget). Names are
    | used to identify checks during sync operations.
    |
    | Common intervals:
    |   - '1m', '5m', '10m', '15m', '30m'  (minutes)
    |   - '1h', '2h', '6h', '12h'          (hours)
    |   - '1d', '7d'                       (days)
    |
    */

    'checks' => [

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

        /* Domain expiry checks default to warning 30 days before expiry. */
        'domain_expiry' => [
            // [
            //     'name' => 'primary-domain-expiry',
            //     'url' => env('APP_URL'),
            //     'interval' => '1d',
            //     'warn_days' => 30,
            // ],
        ],

        /* Response-time budgets default to p95 <= 2000 milliseconds. */
        'response_time_budget' => [
            // [
            //     'name' => 'homepage-p95',
            //     'url' => env('APP_URL'),
            //     'interval' => '5m',
            //     'percentile' => 95,
            //     'budget_ms' => 2000,
            // ],
        ],
    ],
];
