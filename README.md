# CheckyBot Laravel Package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/marin-solutions/checkybot-laravel.svg?style=flat-square)](https://packagist.org/packages/marin-solutions/checkybot-laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/marin-solutions/checkybot-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/Marin-Solutions/checkybot-laravel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/marin-solutions/checkybot-laravel.svg?style=flat-square)](https://packagist.org/packages/marin-solutions/checkybot-laravel)

Seamlessly integrate your Laravel application with CheckyBot monitoring platform. Monitor uptime, SSL certificates, APIs, and more directly from your Laravel app.

## Features

- 🚀 **Easy Integration** - Simple fluent API for defining monitoring checks
- ⏱️ **Uptime Monitoring** - Track website availability with customizable intervals
- 🔒 **SSL Monitoring** - Monitor SSL certificate expiration
- 🔌 **API Monitoring** - Monitor API endpoints with custom assertions
- 🔄 **Auto-Sync** - Automatically sync checks with CheckyBot platform
- 🎯 **Type-Safe** - Full IDE autocomplete support
- ✅ **Tested** - Comprehensive test coverage with Pest

## Installation

Install via composer:

```bash
composer require marin-solutions/checkybot-laravel
```

Publish the config file:

```bash
php artisan vendor:publish --tag="checkybot-config"
```

Add your CheckyBot credentials to `.env`:

```env
CHECKYBOT_API_KEY=your-api-key-here
CHECKYBOT_PROJECT_ID=your-project-id
CHECKYBOT_API_URL=https://your-checkybot-instance.com
```

## Quick Start

Define your monitoring checks and sync with CheckyBot:

```php
// app/CheckybotChecks.php
use MarinSolutions\CheckybotLaravel\Checks\UptimeCheck;

class CheckybotChecks
{
    public static function define(): array
    {
        return [
            UptimeCheck::make('homepage')
                ->url(config('app.url'))
                ->interval('5m'),
        ];
    }
}
```

```bash
php artisan checkybot:sync
```

## License

MIT License. See [LICENSE](LICENSE.md) for details.
