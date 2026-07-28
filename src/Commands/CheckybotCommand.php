<?php

namespace MarinSolutions\CheckybotLaravel\Commands;

use Illuminate\Console\Command;
use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\ConfigValidator;
use MarinSolutions\CheckybotLaravel\Exceptions\CheckybotSyncException;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;

class CheckybotCommand extends Command
{
    public $signature = 'checkybot:sync
                        {--dry-run : Show what would be synced without actually syncing}';

    public $description = 'Sync monitoring checks with CheckyBot platform';

    public function handle(ConfigValidator $validator, CheckRegistry $registry): int
    {
        $this->info('Checkybot Sync Starting...');

        $config = config('checkybot-laravel');
        $this->loadConfiguredDefinitions($registry);

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
        $payload = $validator->buildSyncPayload($config, $useRegistry ? $registry : null);
        $totalChecks = count($payload['checks']);
        $endpoint = $this->syncEndpoint((string) $config['base_url']);

        $this->line('Project: '.$payload['project_identifier']);
        $this->line('Environment: '.$payload['environment']);
        $this->line('Endpoint: '.$endpoint);
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
     * @param  array{checks: array<int, array<string, mixed>>}  $payload
     */
    protected function displayDryRun(array $payload): void
    {
        $this->line('');
        $this->comment('DRY RUN - No changes will be made');
        $this->line('');

        $checksByType = collect($payload['checks'])->groupBy('type');

        foreach ($checksByType as $type => $checks) {
            $this->info($this->labelForType((string) $type).':');

            foreach ($checks as $check) {
                $target = $check['url'] ?? $check['path'] ?? '';
                $this->line("  - {$check['name']} ({$target}) every {$check['interval']}");

                if (! empty($check['headers']) && is_array($check['headers'])) {
                    foreach ($this->redactedHeaders($check['headers']) as $name => $value) {
                        $this->line("      {$name}: {$value}");
                    }
                }
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

        foreach ($summary as $type => $counts) {
            $this->line("  {$this->labelForType($type)}:");
            $this->line("    Created: {$counts['created']}");
            $this->line("    Updated: {$counts['updated']}");
            $this->line("    Deleted: {$counts['deleted']}");
        }

        $this->line('');
    }

    protected function labelForType(string $type): string
    {
        return match ($type) {
            'api' => 'Api Checks',
            'ssl' => 'Ssl Checks',
            'uptime' => 'Uptime Checks',
            'links' => 'Link Checks',
            'opengraph' => 'OpenGraph Checks',
            'link_checks' => 'Link Checks',
            'open_graph_checks' => 'OpenGraph Checks',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }

    protected function syncEndpoint(string $baseUrl): string
    {
        return rtrim($baseUrl, '/').'/api/v1/checks/sync';
    }

    protected function loadConfiguredDefinitions(CheckRegistry $registry): void
    {
        if ($registry->count() > 0) {
            return;
        }

        $path = config('checkybot-laravel.checks_path');

        if (is_string($path) && file_exists($path)) {
            include_once $path;
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    protected function redactedHeaders(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $name => $value) {
            $redacted[$name] = $this->isSensitiveHeader((string) $name) ? '[redacted]' : $value;
        }

        return $redacted;
    }

    protected function isSensitiveHeader(string $name): bool
    {
        $name = strtolower($name);

        return str_contains($name, 'authorization')
            || str_contains($name, 'token')
            || str_contains($name, 'key')
            || str_contains($name, 'secret');
    }
}
