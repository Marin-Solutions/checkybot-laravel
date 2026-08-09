<?php

namespace MarinSolutions\CheckybotLaravel\Commands;

use Illuminate\Console\Command;
use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\ConfigValidator;
use MarinSolutions\CheckybotLaravel\Exceptions\CheckybotSyncException;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;

class CheckybotCommand extends Command
{
    /** @var array<string, list<string>> */
    private const SUMMARY_ALIASES = [
        'uptime' => ['uptime', 'uptime_checks'],
        'ssl' => ['ssl', 'ssl_checks'],
        'api' => ['api', 'api_checks'],
        'dead_links' => ['dead_links', 'link_checks'],
        'open_graph' => ['open_graph', 'open_graph_checks'],
        'domain_expiry' => ['domain_expiry', 'domain_expiry_checks'],
        'response_time_budget' => ['response_time_budget', 'response_time_budget_checks'],
    ];

    public $signature = 'checkybot:sync
                        {--dry-run : Show what would be synced without actually syncing}';

    public $description = 'Sync monitoring checks with CheckyBot platform';

    public function handle(ConfigValidator $validator, CheckRegistry $registry): int
    {
        $this->info('Checkybot Sync Starting...');

        $config = config('checkybot-laravel');

        // Use registry if checks are defined there, otherwise fall back to config
        $useRegistry = $registry->count() > 0;

        if ($useRegistry) {
            $validation = $validator->validateWithRegistry($config, $registry);
        } else {
            $validation = $validator->validate($config);
        }

        if (! $validation['valid']) {
            $this->error('Configuration validation failed:');
            foreach ($validation['errors'] as $error) {
                $this->error('  - '.$error);
            }

            return self::FAILURE;
        }

        // Get payload from registry or config
        $payload = $useRegistry
            ? $registry->toArray()
            : $validator->transformPayload($config);

        $totalChecks = count($payload['uptime'])
            + count($payload['ssl'])
            + count($payload['api'])
            + count($payload['dead_links'])
            + count($payload['open_graph'])
            + count($payload['domain_expiry'])
            + count($payload['response_time_budget']);

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

        foreach (array_keys(self::SUMMARY_ALIASES) as $type) {
            $checks = $payload[$type] ?? [];
            $this->info($this->labelForType($type).' ('.count($checks).'):');
            foreach ($checks as $check) {
                // Deliberately keep dry-run output to the safe declaration fields.
                $this->line("  - {$check['name']} ({$check['url']}) every {$check['interval']}");
            }
            $this->line('');
        }
    }

    /**
     * @param  array<string, array<string, int>>  $summary
     */
    protected function displaySyncResults(array $summary): void
    {
        $this->line('');
        $this->info('Sync Summary:');

        foreach ($this->normalizeSummary($summary) as $type => $counts) {
            $this->line("  {$this->labelForType($type)}:");
            $this->line("    Created: {$counts['created']}");
            $this->line("    Updated: {$counts['updated']}");
            $this->line("    Deleted: {$counts['deleted']}");
        }

        $this->line('');
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, array{created: int, updated: int, deleted: int}>
     */
    private function normalizeSummary(array $summary): array
    {
        $normalized = [];

        foreach (self::SUMMARY_ALIASES as $type => $aliases) {
            $counts = [];
            foreach ($aliases as $alias) {
                if (is_array($summary[$alias] ?? null)) {
                    $counts = $summary[$alias];
                    break;
                }
            }

            $normalized[$type] = [];
            foreach (['created', 'updated', 'deleted'] as $operation) {
                $value = $counts[$operation] ?? 0;
                $normalized[$type][$operation] = is_int($value) && $value >= 0 ? $value : 0;
            }
        }

        return $normalized;
    }

    protected function labelForType(string $type): string
    {
        return match ($type) {
            'uptime', 'uptime_checks' => 'Uptime Checks',
            'ssl', 'ssl_checks' => 'Ssl Checks',
            'api', 'api_checks' => 'Api Checks',
            'dead_links', 'link_checks' => 'Link Checks',
            'open_graph', 'open_graph_checks' => 'OpenGraph Checks',
            'domain_expiry', 'domain_expiry_checks' => 'Domain Expiry Checks',
            'response_time_budget', 'response_time_budget_checks' => 'Response Time Budget Checks',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }
}
