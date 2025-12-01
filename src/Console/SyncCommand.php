<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Console;

use Illuminate\Console\Command;
use MarinSolutions\CheckybotLaravel\ConfigValidator;
use MarinSolutions\CheckybotLaravel\Exceptions\CheckybotSyncException;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;
use MarinSolutions\CheckybotLaravel\Support\Constants;

/**
 * Artisan command to sync monitoring checks to Checkybot
 */
class SyncCommand extends Command
{
    protected $signature = 'checkybot:sync
                          {--dry-run : Show what would be synced without actually syncing}';

    protected $description = 'Sync monitoring checks to Checkybot';

    /**
     * Execute the console command
     */
    public function handle(CheckybotClient $client, ConfigValidator $validator): int
    {
        $this->info('Checkybot Sync Starting...');

        $config = config(Constants::CONFIG_KEY);

        if (! $this->validateConfiguration($validator, $config)) {
            return self::FAILURE;
        }

        $payload = $validator->transformPayload($config);
        $totalChecks = $this->countTotalChecks($payload);

        $this->comment("Found {$totalChecks} checks to sync");

        if ($this->option('dry-run')) {
            $this->displayDryRun($payload);

            return self::SUCCESS;
        }

        return $this->performSync($client, $payload);
    }

    /**
     * Validate configuration
     *
     * @param  array<string, mixed>  $config
     */
    protected function validateConfiguration(ConfigValidator $validator, array $config): bool
    {
        $validation = $validator->validate($config);

        if (! $validation['valid']) {
            $this->error('Configuration validation failed:');
            foreach ($validation['errors'] as $error) {
                $this->error('  - '.$error);
            }

            return false;
        }

        return true;
    }

    /**
     * Count total checks in payload
     *
     * @param  array<string, mixed>  $payload
     */
    protected function countTotalChecks(array $payload): int
    {
        return count($payload['uptime_checks'] ?? [])
            + count($payload['ssl_checks'] ?? [])
            + count($payload['api_checks'] ?? []);
    }

    /**
     * Perform actual sync operation
     *
     * @param  array<string, mixed>  $payload
     */
    protected function performSync(CheckybotClient $client, array $payload): int
    {
        try {
            $response = $client->syncChecks($payload);
            $this->displaySyncResults($response['summary'] ?? []);
            $this->info('✓ Sync completed successfully');

            return self::SUCCESS;
        } catch (CheckybotSyncException $e) {
            $this->error('✗ Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Display dry-run preview
     *
     * @param  array<string, array<int, mixed>>  $payload
     */
    protected function displayDryRun(array $payload): void
    {
        $this->line('');
        $this->comment('DRY RUN - No changes will be made');
        $this->line('');

        $checkTypes = [
            'uptime_checks' => 'Uptime Checks',
            'ssl_checks' => 'SSL Checks',
            'api_checks' => 'API Checks',
        ];

        foreach ($checkTypes as $type => $label) {
            if (! empty($payload[$type])) {
                $this->info($label.':');
                foreach ($payload[$type] as $check) {
                    $this->displayCheck($check);
                }
                $this->line('');
            }
        }
    }

    /**
     * Display individual check information
     *
     * @param  array<string, mixed>  $check
     */
    protected function displayCheck(array $check): void
    {
        $name = $check['name'] ?? 'Unknown';
        $url = $check['url'] ?? 'N/A';
        $interval = $check['interval'] ?? 'N/A';

        $this->line("  - {$name} ({$url}) every {$interval}");
    }

    /**
     * Display sync results summary
     *
     * @param  array<string, array{created: int, updated: int, deleted: int}>  $summary
     */
    protected function displaySyncResults(array $summary): void
    {
        $this->line('');
        $this->info('Sync Summary:');

        foreach ($summary as $type => $counts) {
            $label = $this->formatCheckTypeLabel($type);
            $this->line("  {$label}:");
            $this->line("    Created: {$counts['created']}");
            $this->line("    Updated: {$counts['updated']}");
            $this->line("    Deleted: {$counts['deleted']}");
        }

        $this->line('');
    }

    /**
     * Format check type label for display
     */
    protected function formatCheckTypeLabel(string $type): string
    {
        return ucwords(str_replace('_', ' ', $type));
    }
}
