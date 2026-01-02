<?php

namespace MarinSolutions\CheckybotLaravel\Commands;

use Illuminate\Console\Command;
use MarinSolutions\CheckybotLaravel\ConfigValidator;
use MarinSolutions\CheckybotLaravel\Exceptions\CheckybotSyncException;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;

class CheckybotCommand extends Command
{
    public $signature = 'checkybot:sync
                        {--dry-run : Show what would be synced without actually syncing}';

    public $description = 'Sync monitoring checks with CheckyBot platform';

    public function handle(ConfigValidator $validator): int
    {
        $this->info('Checkybot Sync Starting...');

        $config = config('checkybot-laravel');

        $validation = $validator->validate($config);

        if (! $validation['valid']) {
            $this->error('Configuration validation failed:');
            foreach ($validation['errors'] as $error) {
                $this->error('  - '.$error);
            }

            return self::FAILURE;
        }

        $payload = $validator->transformPayload($config);

        $totalChecks = count($payload['uptime_checks'])
            + count($payload['ssl_checks'])
            + count($payload['api_checks']);

        $this->comment("Found {$totalChecks} checks to sync");

        if ($this->option('dry-run')) {
            $this->displayDryRun($payload);

            return self::SUCCESS;
        }

        try {
            /** @var CheckybotClient $client */
            $client = app(CheckybotClient::class);
            $response = $client->syncChecks($payload);

            $this->displaySyncResults($response['summary'] ?? []);

            $this->info('Sync completed successfully');

            return self::SUCCESS;
        } catch (CheckybotSyncException $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $payload
     */
    protected function displayDryRun(array $payload): void
    {
        $this->line('');
        $this->comment('DRY RUN - No changes will be made');
        $this->line('');

        foreach (['uptime_checks', 'ssl_checks', 'api_checks'] as $type) {
            if (! empty($payload[$type])) {
                $this->info(ucwords(str_replace('_', ' ', $type)).':');
                foreach ($payload[$type] as $check) {
                    $this->line("  - {$check['name']} ({$check['url']}) every {$check['interval']}");
                }
                $this->line('');
            }
        }
    }

    /**
     * @param  array<string, array<string, int>>  $summary
     */
    protected function displaySyncResults(array $summary): void
    {
        $this->line('');
        $this->info('Sync Summary:');

        foreach ($summary as $type => $counts) {
            $label = ucwords(str_replace('_', ' ', $type));
            $this->line("  {$label}:");
            $this->line("    Created: {$counts['created']}");
            $this->line("    Updated: {$counts['updated']}");
            $this->line("    Deleted: {$counts['deleted']}");
        }

        $this->line('');
    }
}
