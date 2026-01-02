# Checkybot Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/marin-solutions/checkybot-laravel.svg?style=flat-square)](https://packagist.org/packages/marin-solutions/checkybot-laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/Marin-Solutions/checkybot-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/Marin-Solutions/checkybot-laravel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/marin-solutions/checkybot-laravel.svg?style=flat-square)](https://packagist.org/packages/marin-solutions/checkybot-laravel)

A Laravel package for defining and syncing monitoring checks to your Checkybot instance. Define uptime, SSL certificate, and API endpoint monitors in your Laravel config and sync them with a single command.

## Quick Start

Get up and running in under 2 minutes:

```bash
# 1. Install the package
composer require marin-solutions/checkybot-laravel

# 2. Publish the config
php artisan vendor:publish --tag="checkybot-laravel-config"

# 3. Add credentials to .env
echo "CHECKYBOT_API_KEY=your-api-key" >> .env
echo "CHECKYBOT_PROJECT_ID=1" >> .env

# 4. Preview your checks
php artisan checkybot:sync --dry-run

# 5. Sync to Checkybot
php artisan checkybot:sync
```

## Installation

### Step 1: Install via Composer

```bash
composer require marin-solutions/checkybot-laravel
```

### Step 2: Publish Configuration

```bash
php artisan vendor:publish --tag="checkybot-laravel-config"
```

This creates `config/checkybot-laravel.php` where you'll define your monitoring checks.

### Step 3: Set Environment Variables

Add to your `.env` file:

```env
CHECKYBOT_API_KEY=your-api-key
CHECKYBOT_PROJECT_ID=1
CHECKYBOT_URL=https://checkybot.com
```

| Variable | Description |
|----------|-------------|
| `CHECKYBOT_API_KEY` | Your Checkybot API key (found in account settings) |
| `CHECKYBOT_PROJECT_ID` | The project ID to sync checks to |
| `CHECKYBOT_URL` | Checkybot instance URL (default: `https://checkybot.com`) |

### Step 4: Verify Installation

```bash
php artisan checkybot:sync --dry-run
```

You should see output like:
```
Checkybot Sync Starting...
DRY RUN - No changes will be made
Found 3 checks to sync
...
```

## Usage Examples

### Basic Usage

```bash
# Sync all checks to Checkybot
php artisan checkybot:sync

# Preview changes without syncing
php artisan checkybot:sync --dry-run
```

### Example Configuration

Here's a complete `config/checkybot-laravel.php` example:

```php
<?php

return [
    'api_key' => env('CHECKYBOT_API_KEY'),
    'project_id' => env('CHECKYBOT_PROJECT_ID'),
    'base_url' => env('CHECKYBOT_URL', 'https://checkybot.com'),

    'checks' => [
        'uptime' => [
            // Monitor your homepage
            [
                'name' => 'homepage',
                'url' => env('APP_URL'),
                'interval' => '5m',
            ],
            // Monitor your API
            [
                'name' => 'api-server',
                'url' => env('APP_URL') . '/api',
                'interval' => '1m',
            ],
        ],

        'ssl' => [
            // Check SSL certificate expiration
            [
                'name' => 'main-ssl',
                'url' => env('APP_URL'),
                'interval' => '1d',
            ],
        ],

        'api' => [
            // Monitor health endpoint with assertions
            [
                'name' => 'health-check',
                'url' => env('APP_URL') . '/api/health',
                'interval' => '5m',
                'assertions' => [
                    [
                        'data_path' => 'status',
                        'assertion_type' => 'comparison',
                        'comparison_operator' => '==',
                        'expected_value' => 'healthy',
                    ],
                ],
            ],
        ],
    ],
];
```

## Defining Checks

### Uptime Checks

Monitor website availability and response times:

```php
'uptime' => [
    // Simple check - just URL and interval
    [
        'name' => 'homepage',
        'url' => 'https://example.com',
        'interval' => '5m',
    ],

    // With max redirects
    [
        'name' => 'blog',
        'url' => 'https://blog.example.com',
        'interval' => '10m',
        'max_redirects' => 5,
    ],

    // Monitor multiple pages
    [
        'name' => 'pricing-page',
        'url' => 'https://example.com/pricing',
        'interval' => '15m',
    ],
    [
        'name' => 'contact-page',
        'url' => 'https://example.com/contact',
        'interval' => '15m',
    ],
],
```

### SSL Certificate Checks

Monitor SSL certificate expiration dates:

```php
'ssl' => [
    // Main domain
    [
        'name' => 'main-ssl',
        'url' => 'https://example.com',
        'interval' => '1d',
    ],

    // Subdomain
    [
        'name' => 'api-ssl',
        'url' => 'https://api.example.com',
        'interval' => '1d',
    ],

    // Third-party service
    [
        'name' => 'cdn-ssl',
        'url' => 'https://cdn.example.com',
        'interval' => '1d',
    ],
],
```

### API Endpoint Checks

Monitor API endpoints with optional response validation:

```php
'api' => [
    // Simple health check
    [
        'name' => 'health-check',
        'url' => env('APP_URL') . '/api/health',
        'interval' => '5m',
    ],

    // With custom headers
    [
        'name' => 'authenticated-endpoint',
        'url' => env('APP_URL') . '/api/status',
        'interval' => '5m',
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . env('MONITORING_TOKEN'),
        ],
    ],

    // With response assertions
    [
        'name' => 'database-health',
        'url' => env('APP_URL') . '/api/health/database',
        'interval' => '5m',
        'assertions' => [
            // Check that status exists
            [
                'data_path' => 'status',
                'assertion_type' => 'exists',
            ],
            // Check that status is "connected"
            [
                'data_path' => 'status',
                'assertion_type' => 'comparison',
                'comparison_operator' => '==',
                'expected_value' => 'connected',
            ],
        ],
    ],

    // Complex assertions example
    [
        'name' => 'queue-health',
        'url' => env('APP_URL') . '/api/health/queue',
        'interval' => '10m',
        'assertions' => [
            // Check queue size is under threshold
            [
                'data_path' => 'queue_size',
                'assertion_type' => 'comparison',
                'comparison_operator' => '<',
                'expected_value' => '1000',
            ],
            // Check workers are running
            [
                'data_path' => 'workers',
                'assertion_type' => 'comparison',
                'comparison_operator' => '>=',
                'expected_value' => '1',
            ],
        ],
    ],
],
```

## Interval Format

| Format | Description |
|--------|-------------|
| `1m` | Every minute |
| `5m` | Every 5 minutes |
| `10m` | Every 10 minutes |
| `15m` | Every 15 minutes |
| `30m` | Every 30 minutes |
| `1h` | Every hour |
| `2h` | Every 2 hours |
| `6h` | Every 6 hours |
| `12h` | Every 12 hours |
| `1d` | Once per day |

## Assertion Types

For API checks, define assertions to validate responses:

### Exists

Check if a JSON path exists in the response:

```php
[
    'data_path' => 'status',
    'assertion_type' => 'exists',
]
```

### Comparison

Compare values using operators (`==`, `!=`, `>`, `>=`, `<`, `<=`):

```php
// Equal to
[
    'data_path' => 'status',
    'assertion_type' => 'comparison',
    'comparison_operator' => '==',
    'expected_value' => 'healthy',
]

// Greater than
[
    'data_path' => 'uptime_percentage',
    'assertion_type' => 'comparison',
    'comparison_operator' => '>=',
    'expected_value' => '99.9',
]

// Less than (queue size under threshold)
[
    'data_path' => 'pending_jobs',
    'assertion_type' => 'comparison',
    'comparison_operator' => '<',
    'expected_value' => '100',
]
```

### Type

Check if value matches expected type (`string`, `integer`, `boolean`, `array`, `object`):

```php
[
    'data_path' => 'user_count',
    'assertion_type' => 'type',
    'expected_type' => 'integer',
]
```

### Regex

Match value against a regex pattern:

```php
[
    'data_path' => 'version',
    'assertion_type' => 'regex',
    'regex_pattern' => '/^v\d+\.\d+\.\d+$/',
]
```

## Real-World Examples

### E-commerce Application

```php
'checks' => [
    'uptime' => [
        ['name' => 'storefront', 'url' => env('APP_URL'), 'interval' => '1m'],
        ['name' => 'checkout', 'url' => env('APP_URL') . '/checkout', 'interval' => '1m'],
        ['name' => 'cart', 'url' => env('APP_URL') . '/cart', 'interval' => '5m'],
    ],
    'ssl' => [
        ['name' => 'store-ssl', 'url' => env('APP_URL'), 'interval' => '1d'],
    ],
    'api' => [
        [
            'name' => 'payment-gateway',
            'url' => env('APP_URL') . '/api/health/payments',
            'interval' => '5m',
            'assertions' => [
                ['data_path' => 'stripe_connected', 'assertion_type' => 'comparison', 'comparison_operator' => '==', 'expected_value' => 'true'],
            ],
        ],
        [
            'name' => 'inventory-service',
            'url' => env('APP_URL') . '/api/health/inventory',
            'interval' => '5m',
        ],
    ],
],
```

### SaaS Application

```php
'checks' => [
    'uptime' => [
        ['name' => 'app', 'url' => env('APP_URL'), 'interval' => '1m'],
        ['name' => 'api', 'url' => env('APP_URL') . '/api', 'interval' => '1m'],
        ['name' => 'dashboard', 'url' => env('APP_URL') . '/dashboard', 'interval' => '5m'],
    ],
    'ssl' => [
        ['name' => 'app-ssl', 'url' => env('APP_URL'), 'interval' => '1d'],
        ['name' => 'api-ssl', 'url' => env('API_URL', env('APP_URL')), 'interval' => '1d'],
    ],
    'api' => [
        [
            'name' => 'database',
            'url' => env('APP_URL') . '/api/health/database',
            'interval' => '5m',
            'assertions' => [
                ['data_path' => 'status', 'assertion_type' => 'comparison', 'comparison_operator' => '==', 'expected_value' => 'connected'],
            ],
        ],
        [
            'name' => 'redis',
            'url' => env('APP_URL') . '/api/health/redis',
            'interval' => '5m',
            'assertions' => [
                ['data_path' => 'status', 'assertion_type' => 'comparison', 'comparison_operator' => '==', 'expected_value' => 'connected'],
            ],
        ],
        [
            'name' => 'queue',
            'url' => env('APP_URL') . '/api/health/queue',
            'interval' => '10m',
            'assertions' => [
                ['data_path' => 'workers', 'assertion_type' => 'comparison', 'comparison_operator' => '>=', 'expected_value' => '1'],
                ['data_path' => 'failed_jobs', 'assertion_type' => 'comparison', 'comparison_operator' => '<', 'expected_value' => '10'],
            ],
        ],
    ],
],
```

### Multi-tenant Application

```php
'checks' => [
    'uptime' => [
        ['name' => 'main-app', 'url' => env('APP_URL'), 'interval' => '1m'],
        ['name' => 'tenant-portal', 'url' => env('TENANT_URL', env('APP_URL') . '/tenant'), 'interval' => '5m'],
        ['name' => 'admin-panel', 'url' => env('ADMIN_URL', env('APP_URL') . '/admin'), 'interval' => '5m'],
    ],
    'ssl' => [
        ['name' => 'wildcard-ssl', 'url' => env('APP_URL'), 'interval' => '1d'],
    ],
    'api' => [
        [
            'name' => 'tenant-resolution',
            'url' => env('APP_URL') . '/api/health/tenants',
            'interval' => '5m',
            'assertions' => [
                ['data_path' => 'resolver_status', 'assertion_type' => 'comparison', 'comparison_operator' => '==', 'expected_value' => 'operational'],
            ],
        ],
    ],
],
```

## CI/CD Integration

### GitHub Actions

```yaml
name: Deploy

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Deploy Application
        run: # your deployment steps

      - name: Sync Checkybot Monitors
        run: php artisan checkybot:sync
        env:
          CHECKYBOT_API_KEY: ${{ secrets.CHECKYBOT_API_KEY }}
          CHECKYBOT_PROJECT_ID: ${{ secrets.CHECKYBOT_PROJECT_ID }}
```

### GitLab CI

```yaml
deploy:
  stage: deploy
  script:
    - # your deployment steps
    - php artisan checkybot:sync
  variables:
    CHECKYBOT_API_KEY: $CHECKYBOT_API_KEY
    CHECKYBOT_PROJECT_ID: $CHECKYBOT_PROJECT_ID
```

### Laravel Forge (Post-Deployment Script)

```bash
cd /home/forge/example.com
php artisan checkybot:sync
```

### Laravel Envoyer (Deployment Hook)

Add as an "After" hook on the "Activate New Release" step:

```bash
cd {{ release }}
php artisan checkybot:sync
```

## Troubleshooting

### "CHECKYBOT_API_KEY is not configured"

Make sure your `.env` file has the API key set:

```env
CHECKYBOT_API_KEY=your-api-key-here
```

Then clear the config cache:

```bash
php artisan config:clear
```

### "Duplicate check names found"

Each check name must be unique within its type. Change duplicates:

```php
// Wrong - duplicate names
['name' => 'homepage', 'url' => 'https://example.com', ...],
['name' => 'homepage', 'url' => 'https://example.com/blog', ...],

// Correct - unique names
['name' => 'homepage', 'url' => 'https://example.com', ...],
['name' => 'blog', 'url' => 'https://example.com/blog', ...],
```

### "Connection timed out"

Check your `CHECKYBOT_URL` is correct and accessible. You can also increase the timeout:

```php
// config/checkybot-laravel.php
'timeout' => 60, // seconds
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
