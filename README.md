# Checkybot Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/marin-solutions/checkybot-laravel.svg?style=flat-square)](https://packagist.org/packages/marin-solutions/checkybot-laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/Marin-Solutions/checkybot-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/Marin-Solutions/checkybot-laravel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/marin-solutions/checkybot-laravel.svg?style=flat-square)](https://packagist.org/packages/marin-solutions/checkybot-laravel)

A Laravel package for defining and syncing monitoring checks to your Checkybot instance. Define uptime, SSL certificate, and API endpoint monitors in your Laravel config and sync them with a single command.

## Installation

Install the package via Composer:

```bash
composer require marin-solutions/checkybot-laravel
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="checkybot-laravel-config"
```

## Configuration

Add your Checkybot credentials to your `.env` file:

```env
CHECKYBOT_API_KEY=your-api-key
CHECKYBOT_PROJECT_ID=1
CHECKYBOT_URL=https://checkybot.com
```

## Defining Checks

Edit `config/checkybot-laravel.php` to define your monitoring checks:

### Uptime Checks

Monitor website uptime and response times:

```php
'uptime' => [
    [
        'name' => 'homepage-uptime',
        'url' => env('APP_URL'),
        'interval' => '5m',
        'max_redirects' => 10,
    ],
],
```

### SSL Certificate Checks

Monitor SSL certificate expiration:

```php
'ssl' => [
    [
        'name' => 'homepage-ssl',
        'url' => env('APP_URL'),
        'interval' => '1d',
    ],
],
```

### API Endpoint Checks

Monitor API endpoints with optional response validation:

```php
'api' => [
    [
        'name' => 'health-check',
        'url' => env('APP_URL').'/api/health',
        'interval' => '5m',
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.env('HEALTH_CHECK_TOKEN'),
        ],
        'assertions' => [
            [
                'data_path' => 'status',
                'assertion_type' => 'exists',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'data_path' => 'status',
                'assertion_type' => 'comparison',
                'comparison_operator' => '==',
                'expected_value' => 'healthy',
                'sort_order' => 2,
                'is_active' => true,
            ],
        ],
    ],
],
```

## Interval Format

- `5m` = every 5 minutes
- `1h` = every hour
- `2h` = every 2 hours
- `1d` = once per day

## Assertion Types

For API checks, you can define assertions to validate responses:

### Exists
Check if a JSON path exists in the response:
```php
['data_path' => 'status', 'assertion_type' => 'exists']
```

### Comparison
Compare a value using an operator (`==`, `!=`, `>`, `>=`, `<`, `<=`):
```php
[
    'data_path' => 'count',
    'assertion_type' => 'comparison',
    'comparison_operator' => '>=',
    'expected_value' => '1',
]
```

### Type
Check if value matches expected type (`string`, `integer`, `boolean`, `array`, `object`):
```php
['data_path' => 'id', 'assertion_type' => 'type', 'expected_type' => 'integer']
```

### Regex
Match value against a regex pattern:
```php
[
    'data_path' => 'email',
    'assertion_type' => 'regex',
    'regex_pattern' => '/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/',
]
```

## Syncing Checks

Sync your monitoring checks to Checkybot:

```bash
php artisan checkybot:sync
```

Preview what would be synced without making changes:

```bash
php artisan checkybot:sync --dry-run
```

## CI/CD Integration

Add to your deployment pipeline to automatically sync monitors after deployment:

```yaml
# GitHub Actions example
- name: Sync Checkybot Monitors
  run: php artisan checkybot:sync
  env:
    CHECKYBOT_API_KEY: ${{ secrets.CHECKYBOT_API_KEY }}
    CHECKYBOT_PROJECT_ID: ${{ secrets.CHECKYBOT_PROJECT_ID }}
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
