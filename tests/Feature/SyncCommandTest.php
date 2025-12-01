<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Tests\Feature;

use MarinSolutions\CheckybotLaravel\Exceptions\CheckybotSyncException;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;
use MarinSolutions\CheckybotLaravel\Tests\TestCase;
use Mockery;

class SyncCommandTest extends TestCase
{
    public function test_sync_command_validates_configuration(): void
    {
        config([
            'checkybot-laravel.api_key' => '',
            'checkybot-laravel.project_id' => '',
            'checkybot-laravel.checks' => [
                'uptime' => [],
                'ssl' => [],
                'api' => [],
            ],
        ]);

        $this->artisan('checkybot:sync')
            ->expectsOutput('Configuration validation failed:')
            ->assertExitCode(1);
    }

    public function test_sync_command_displays_dry_run(): void
    {
        config([
            'checkybot-laravel.api_key' => 'test-key',
            'checkybot-laravel.project_id' => '1',
            'checkybot-laravel.checks' => [
                'uptime' => [
                    ['name' => 'test', 'url' => 'https://example.com', 'interval' => '5m'],
                ],
                'ssl' => [],
                'api' => [],
            ],
        ]);

        $this->artisan('checkybot:sync', ['--dry-run' => true])
            ->expectsOutput('DRY RUN - No changes will be made')
            ->expectsOutput('  - test (https://example.com) every 5m')
            ->assertExitCode(0);
    }

    public function test_sync_command_sends_checks_to_checkybot(): void
    {
        // This test matches the spec example
        config([
            'checkybot-laravel.api_key' => 'test-key',
            'checkybot-laravel.project_id' => '1',
            'checkybot-laravel.checks' => [
                'uptime' => [
                    ['name' => 'test', 'url' => 'https://example.com', 'interval' => '5m'],
                ],
                'ssl' => [],
                'api' => [],
            ],
        ]);

        $mockClient = Mockery::mock(CheckybotClient::class);
        $mockClient->shouldReceive('syncChecks')
            ->once()
            ->andReturn([
                'message' => 'Checks synced successfully',
                'summary' => [
                    'uptime_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                    'ssl_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                    'api_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                ],
            ]);

        $this->app->instance(CheckybotClient::class, $mockClient);

        $this->artisan('checkybot:sync')
            ->expectsOutput('✓ Sync completed successfully')
            ->assertExitCode(0);
    }

    public function test_sync_command_handles_errors(): void
    {
        config([
            'checkybot-laravel.api_key' => 'test-key',
            'checkybot-laravel.project_id' => '1',
            'checkybot-laravel.checks' => [
                'uptime' => [],
                'ssl' => [],
                'api' => [],
            ],
        ]);

        $mockClient = Mockery::mock(CheckybotClient::class);
        $mockClient->shouldReceive('syncChecks')
            ->once()
            ->andThrow(new CheckybotSyncException('API Error', 500));

        $this->app->instance(CheckybotClient::class, $mockClient);

        $this->artisan('checkybot:sync')
            ->expectsOutput('✗ Sync failed: API Error')
            ->assertExitCode(1);
    }
}
