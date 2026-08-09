<?php

declare(strict_types=1);

namespace Checkybot\AgentV2;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

interface SystemProbe
{
    /**
     * @return array{
     *   captured_at:float,
     *   cpu_five_min_percent:float,
     *   memory_used_percent:float,
     *   disks:list<array{mount:string,used_percent:float,predicted_days_to_full:?float}>,
     *   network:array<string,array{rx:int,tx:int}>,
     *   php_fpm_pools:list<array{pool:string,active_workers:int,max_children:int,max_children_reached_5m:int}>,
     *   nginx_window:array{total_requests:int,five_xx_count:int,upstream_timeout_count:int},
     *   prerequisites:list<array{kind:string,path_hint:string,status:string}>,
     *   relevant_log_lines?:list<array{source:string,observed_at:string,line:string}>
     * }
     */
    public function snapshot(): array;
}

final class AtomicStateStore
{
    private readonly ?Closure $beforeReplace;

    public function __construct(
        private readonly string $path,
        ?callable $beforeReplace = null,
    ) {
        $this->beforeReplace = $beforeReplace === null ? null : Closure::fromCallable($beforeReplace);
    }

    /** @template T @param callable(array<string, mixed>):array{0:array<string, mixed>,1:T} $mutator @return T */
    public function update(callable $mutator): mixed
    {
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the agent state directory.');
        }
        chmod($directory, 0700);

        $lockPath = $this->path.'.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false) {
            throw new RuntimeException('Unable to open the agent state lock.');
        }
        chmod($lockPath, 0600);

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the agent state.');
            }

            $state = $this->readState();
            [$replacement, $result] = $mutator($state);
            $this->replace($replacement);

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed> */
    public function readState(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($this->path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The agent state file is invalid.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('The agent state file is invalid.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $state */
    private function replace(array $state): void
    {
        $temporary = tempnam(dirname($this->path), '.checkybot-state-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to allocate an agent state replacement.');
        }

        try {
            chmod($temporary, 0600);
            $handle = fopen($temporary, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Unable to open an agent state replacement.');
            }
            try {
                $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                if (fwrite($handle, $json."\n") === false || ! fflush($handle)) {
                    throw new RuntimeException('Unable to write the agent state replacement.');
                }
                if (function_exists('fsync') && ! fsync($handle)) {
                    throw new RuntimeException('Unable to sync the agent state replacement.');
                }
            } finally {
                fclose($handle);
            }

            ($this->beforeReplace) && ($this->beforeReplace)($temporary, $this->path);

            if (! rename($temporary, $this->path)) {
                throw new RuntimeException('Unable to atomically replace the agent state.');
            }
            chmod($this->path, 0600);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}

final readonly class LocalRedactor
{
    /** @param list<string> $secrets */
    public function __construct(private array $secrets = []) {}

    public function redact(string $line): string
    {
        $line = (string) preg_replace('/\b(Authorization|Proxy-Authorization|Cookie|Set-Cookie)\s*:\s*[^\r\n]+/iu', '$1: [REDACTED]', $line);
        $line = (string) preg_replace_callback('/([?&][^\s&#=]+)=([^\s&#]*)/u', static fn (array $match): string => $match[1].'=[REDACTED]', $line);
        foreach ($this->secrets as $secret) {
            if ($secret !== '') {
                $line = str_replace($secret, '[REDACTED]', $line);
            }
        }
        $line = (string) preg_replace('/(?<![\pL\pN._%+\-])[\pL\pN._%+\-]+@[\pL\pN.\-]+\.[\pL]{2,}(?![\pL\pN._%+\-])/iu', '[REDACTED]', $line);
        $line = (string) preg_replace('/(?<![\d.])(?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3}(?![\d.])/u', '[REDACTED]', $line);
        $line = (string) preg_replace('/(?<![\pL\pN:])(?:(?:[A-F\d]{1,4}:){7}[A-F\d]{1,4}|(?:[A-F\d]{1,4}:){1,7}:|(?:[A-F\d]{1,4}:){1,6}:[A-F\d]{1,4}|:(?::[A-F\d]{1,4}){1,7}|::)(?![\pL\pN:])/iu', '[REDACTED]', $line);

        return $line;
    }
}

final readonly class AgentV2Collector
{
    public const SCHEMA_VERSION = 'agent-report.v2';

    public const AGENT_VERSION = '2.0.0';

    public function __construct(
        private SystemProbe $probe,
        private AtomicStateStore $state,
        private LocalRedactor $redactor = new LocalRedactor,
    ) {}

    /** @return array<string, mixed> */
    public function collect(string $serverUuid, string $operationId): array
    {
        $snapshot = $this->probe->snapshot();

        return $this->state->update(function (array $previous) use ($snapshot, $serverUuid, $operationId): array {
            $capturedAt = (float) $snapshot['captured_at'];
            $previousCapturedAt = isset($previous['captured_at']) ? (float) $previous['captured_at'] : null;
            $previousNetwork = is_array($previous['network'] ?? null) ? $previous['network'] : [];
            $interfaces = [];

            foreach ($snapshot['network'] as $name => $counters) {
                $rx = (int) $counters['rx'];
                $tx = (int) $counters['tx'];
                $prior = is_array($previousNetwork[$name] ?? null) ? $previousNetwork[$name] : null;
                $elapsed = $previousCapturedAt === null ? null : $capturedAt - $previousCapturedAt;
                $reset = $prior !== null && ($rx < (int) $prior['rx'] || $tx < (int) $prior['tx']);
                $ready = $prior !== null && ! $reset && $elapsed !== null && $elapsed > 0;

                $interfaces[] = [
                    'name' => (string) $name,
                    'rx_bytes_total' => $rx,
                    'tx_bytes_total' => $tx,
                    'rx_delta_bytes' => $ready ? $rx - (int) $prior['rx'] : null,
                    'tx_delta_bytes' => $ready ? $tx - (int) $prior['tx'] : null,
                    'elapsed_seconds' => $ready ? $elapsed : null,
                    'sample_status' => $ready ? 'ready' : ($reset ? 'reset' : 'baseline'),
                ];
            }

            $observedAt = (new DateTimeImmutable('@'.(string) $capturedAt))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z');
            $logs = array_map(fn (array $line): array => [
                'source' => $line['source'],
                'observed_at' => $line['observed_at'],
                'line' => $this->redactor->redact($line['line']),
            ], array_slice($snapshot['relevant_log_lines'] ?? [], 0, 200));

            $payload = [
                'schema_version' => self::SCHEMA_VERSION,
                'operation_id' => $operationId,
                'agent_version' => self::AGENT_VERSION,
                'server_uuid' => $serverUuid,
                'observed_at' => $observedAt,
                'reporting_interval_seconds' => 60,
                'cpu' => ['five_min_percent' => (float) $snapshot['cpu_five_min_percent']],
                'memory' => ['used_percent' => (float) $snapshot['memory_used_percent']],
                'disks' => $snapshot['disks'],
                'network_interfaces' => $interfaces,
                'php_fpm_pools' => $snapshot['php_fpm_pools'],
                'nginx_window' => ['window_seconds' => 300, ...$snapshot['nginx_window']],
                'prerequisites' => array_map(fn (array $prerequisite): array => [
                    ...$prerequisite,
                    'path_hint' => $this->redactor->redact($prerequisite['path_hint']),
                ], $snapshot['prerequisites']),
                'relevant_log_lines' => $logs,
            ];

            return [[
                'captured_at' => $capturedAt,
                'network' => $snapshot['network'],
            ], $payload];
        });
    }
}
