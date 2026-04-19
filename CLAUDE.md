# Checkybot Laravel — Claude Code Guide

## Project Overview
A Laravel package that lets developers define monitoring checks (uptime, SSL, API, dead links, OpenGraph) via a fluent API and sync them to a Checkybot instance with a single Artisan command.

## Architecture
- **CheckRegistry** — Singleton that stores check definitions in memory
- **BaseCheck** — Abstract base with common fields (`name`, `url`, `interval`, `headers`)
- **Check types:** `UptimeCheck`, `SslCheck`, `ApiCheck`, `LinkCheck`, `OpenGraphCheck`
- **CheckybotClient** — Guzzle-based HTTP client that sends payloads to `/api/v1/projects/{id}/checks/sync`
- **ConfigValidator** — Validates local definitions before API calls (URL format, interval regex, duplicates)
- **Commands:** `checkybot:sync` (with `--dry-run` flag)

## Code Style
- PHP 8.2+ with named arguments and readonly properties where applicable
- Fluent API builder pattern for all check types
- Pest PHP for testing
- Use `filter_var($url, FILTER_VALIDATE_URL)` for URL validation
- Interval format: `/^\d+[smhd]$/`

## Testing
- Run full suite: `./vendor/bin/pest`
- All check types need unit tests in `tests/Unit/Checks/`
- Feature tests for command output use `Artisan::call()` + `Artisan::output()` when `expectsOutputToContain()` fails due to buffering

## Adding New Check Types
1. Extend `BaseCheck` in `src/Checks/`
2. Add registry array + factory method in `CheckRegistry`
3. Add config section in `config/checkybot-laravel.php`
4. Add validation in `ConfigValidator`
5. Update `CheckybotCommand` dry-run and sync summary output
6. Update facade docblock
7. Add unit and feature tests
