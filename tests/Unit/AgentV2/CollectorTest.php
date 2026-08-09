<?php

declare(strict_types=1);

use Checkybot\AgentV2\AgentV2Collector;
use Checkybot\AgentV2\AtomicStateStore;
use Checkybot\AgentV2\LocalRedactor;
use Checkybot\AgentV2\SystemProbe;
use Illuminate\Support\Str;

require_once dirname(__DIR__, 3).'/agent/src/AgentV2Collector.php';

function fixtureProbe(string $name): SystemProbe
{
    return new class($name) implements SystemProbe
    {
        public function __construct(private readonly string $name) {}

        public function snapshot(): array
        {
            return json_decode(
                (string) file_get_contents(__DIR__.'/fixtures/'.$this->name.'.json'),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
        }
    };
}

it('collects baseline, real elapsed deltas, hot plug, and reset observations through the locked state', function (): void {
    $directory = dirname(__DIR__, 3).'/build/agent-collector/'.Str::uuid();
    mkdir($directory, 0700, true);
    $statePath = $directory.'/state.json';
    $server = (string) Str::uuid();

    $baseline = (new AgentV2Collector(fixtureProbe('baseline'), new AtomicStateStore($statePath)))
        ->collect($server, (string) Str::uuid());
    $second = (new AgentV2Collector(fixtureProbe('second-run'), new AtomicStateStore($statePath)))
        ->collect($server, (string) Str::uuid());
    $hotPlug = (new AgentV2Collector(fixtureProbe('hot-plug'), new AtomicStateStore($statePath)))
        ->collect($server, (string) Str::uuid());
    $reset = (new AgentV2Collector(fixtureProbe('counter-reset'), new AtomicStateStore($statePath)))
        ->collect($server, (string) Str::uuid());

    expect($baseline['schema_version'])->toBe('agent-report.v2')
        ->and($baseline['agent_version'])->toMatch('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/')
        ->and($baseline['network_interfaces'][0])->toMatchArray([
            'sample_status' => 'baseline',
            'rx_delta_bytes' => null,
            'tx_delta_bytes' => null,
            'elapsed_seconds' => null,
        ])
        ->and($second['network_interfaces'][0])->toMatchArray([
            'sample_status' => 'ready',
            'rx_delta_bytes' => 125000,
            'tx_delta_bytes' => 62500,
            'elapsed_seconds' => 62.5,
        ])
        ->and($second['network_interfaces'][0]['rx_delta_bytes'] / $second['network_interfaces'][0]['elapsed_seconds'])->toBe(2000.0)
        ->and($second['network_interfaces'][0]['tx_delta_bytes'] / $second['network_interfaces'][0]['elapsed_seconds'])->toBe(1000.0)
        ->and($hotPlug['network_interfaces'][0]['sample_status'])->toBe('ready')
        ->and($hotPlug['network_interfaces'][1])->toMatchArray([
            'name' => 'eth1',
            'sample_status' => 'baseline',
            'rx_delta_bytes' => null,
            'tx_delta_bytes' => null,
            'elapsed_seconds' => null,
        ])
        ->and($reset['network_interfaces'][0])->toMatchArray([
            'name' => 'eth0',
            'sample_status' => 'reset',
            'rx_delta_bytes' => null,
            'tx_delta_bytes' => null,
            'elapsed_seconds' => null,
        ])
        ->and($reset['network_interfaces'][1]['sample_status'])->toBe('ready')
        ->and(fileperms($statePath) & 0777)->toBe(0600)
        ->and(json_decode((string) file_get_contents($statePath), true, 32, JSON_THROW_ON_ERROR))->toBeArray();
})->group('AC-agent-v2-expanded-monitors-1');

it('leaves the prior mode-0600 state valid when atomic replacement is interrupted', function (): void {
    $directory = dirname(__DIR__, 3).'/build/agent-collector/'.Str::uuid();
    mkdir($directory, 0700, true);
    $statePath = $directory.'/state.json';
    $server = (string) Str::uuid();
    (new AgentV2Collector(fixtureProbe('baseline'), new AtomicStateStore($statePath)))
        ->collect($server, (string) Str::uuid());
    $prior = (string) file_get_contents($statePath);

    $interrupted = new AtomicStateStore($statePath, static function (): void {
        throw new RuntimeException('simulated interruption before rename');
    });

    expect(fn () => (new AgentV2Collector(fixtureProbe('second-run'), $interrupted, new LocalRedactor))
        ->collect($server, (string) Str::uuid()))->toThrow(RuntimeException::class, 'simulated interruption')
        ->and((string) file_get_contents($statePath))->toBe($prior)
        ->and(json_decode((string) file_get_contents($statePath), true, 32, JSON_THROW_ON_ERROR))->toBeArray()
        ->and(fileperms($statePath) & 0777)->toBe(0600);
})->group('AC-agent-v2-expanded-monitors-1');
