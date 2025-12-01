# Checkybot Laravel Monitoring Package

Laravel package for defining and syncing monitoring checks to Checkybot.

## Installation

```bash
composer require checkybot/laravel-monitoring
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=checkybot-laravel-config
```

## Configuration

### 1. Get Your Credentials

1. Create a project in your Checkybot dashboard
2. Generate an API key
3. Note your Project ID

### 2. Environment Variables

Add to your `.env` file:

```env
CHECKYBOT_API_KEY=your-api-key-here
CHECKYBOT_PROJECT_ID=1
CHECKYBOT_URL=https://checkybot.com
```

### 3. Define Your Checks

Edit `config/checkybot-laravel.php` and define your monitoring checks.

## Check Types

### Uptime Checks

Monitor website availability and response times:

```php
'uptime' => [
    [
        'name' => 'homepage-uptime',
        'url' => env('APP_URL'),
        'interval' => '5m',
        'max_redirects' => 10, // Optional
    ],
],
```

### SSL Checks

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

### API Checks

Monitor API endpoints and validate responses:

```php
'api' => [
    [
        'name' => 'health-check',
        'url' => env('APP_URL').'/api/health',
        'interval' => '5m',
        'headers' => [ // Optional
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.env('HEALTH_CHECK_TOKEN'),
        ],
        'assertions' => [ // Optional
            [
                'data_path' => 'status',
                'assertion_type' => 'exists',
                'sort_order' => 1,
                'is_active' => true,
            ],
        ],
    ],
],
```

## Syncing Checks

### Sync to Checkybot

```bash
php artisan checkybot:sync
```

### Dry Run (Preview)

Preview what would be synced without making changes:

```bash
php artisan checkybot:sync --dry-run
```

## Interval Format

- `5m` = every 5 minutes
- `1h` = every hour
- `2h` = every 2 hours
- `1d` = once per day

## Assertion Types

### Exists

Check if a JSON path exists in the response:

```php
[
    'data_path' => 'status',
    'assertion_type' => 'exists',
    'sort_order' => 1,
    'is_active' => true,
]
```

### Comparison

Compare a value using an operator:

```php
[
    'data_path' => 'count',
    'assertion_type' => 'comparison',
    'comparison_operator' => '>=',
    'expected_value' => '1',
    'sort_order' => 1,
    'is_active' => true,
]
```

**Operators:** `==`, `!=`, `>`, `>=`, `<`, `<=`

### Type

Check if value matches expected type:

```php
[
    'data_path' => 'id',
    'assertion_type' => 'type',
    'expected_type' => 'integer',
    'sort_order' => 1,
    'is_active' => true,
]
```

**Types:** `string`, `integer`, `boolean`, `array`, `object`, `null`

### Regex

Match value against regex pattern:

```php
[
    'data_path' => 'email',
    'assertion_type' => 'regex',
    'regex_pattern' => '/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/',
    'sort_order' => 1,
    'is_active' => true,
]
```

## CI/CD Integration

### GitHub Actions

```yaml
- name: Sync Checkybot Monitors
  run: php artisan checkybot:sync
  env:
    CHECKYBOT_API_KEY: ${{ secrets.CHECKYBOT_API_KEY }}
    CHECKYBOT_PROJECT_ID: ${{ secrets.CHECKYBOT_PROJECT_ID }}
```

### GitLab CI

```yaml
sync_checkybot:
  script:
    - php artisan checkybot:sync
  variables:
    CHECKYBOT_API_KEY: $CHECKYBOT_API_KEY
    CHECKYBOT_PROJECT_ID: $CHECKYBOT_PROJECT_ID
```

## Troubleshooting

### Configuration Validation Failed

**Error:** `CHECKYBOT_API_KEY is not configured`

**Solution:** Ensure your `.env` file contains `CHECKYBOT_API_KEY` and `CHECKYBOT_PROJECT_ID`.

### Sync Failed: Validation failed

**Error:** API returns validation errors

**Solution:** Check your check definitions in `config/checkybot.php`. Ensure all required fields are present and URLs are valid.

### Sync Failed: Unauthorized

**Error:** `403 Forbidden` or `401 Unauthorized`

**Solution:** Verify your API key is correct and has permission to manage the project.

## Requirements

- PHP 8.1+
- Laravel 10.x or 11.x

## License

MIT

## Support

For issues and feature requests, please visit the [GitHub repository](https://github.com/checkybot/laravel-monitoring).
