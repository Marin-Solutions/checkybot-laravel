<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\HeartbeatClient;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Support\LaravelHeartbeatClient;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class ExternalWatchdogTestLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

beforeEach(function (): void {
    $this->watchdogLogger = new ExternalWatchdogTestLogger;
    app()->instance(LoggerInterface::class, $this->watchdogLogger);
    config()->set('checkybot.alerting.watchdog.url');
    config()->set('checkybot.alerting.watchdog.timeout_seconds', 5);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('dispatches one bounded GET each due minute, accepts every 2xx, and coexists with all alerting schedules', function (): void {
    $url = 'https://watchdog.example.test/heartbeat?token=top-secret';
    config()->set('checkybot.alerting.watchdog.url', $url);
    config()->set('checkybot.alerting.watchdog.timeout_seconds', 3);

    $sequence = Http::sequence()
        ->pushStatus(200)
        ->pushStatus(201)
        ->pushStatus(204)
        ->pushStatus(299);
    Http::fake(['watchdog.example.test/*' => $sequence]);

    $requestOptions = [];
    Http::globalMiddleware(function (callable $handler) use (&$requestOptions): callable {
        return function ($request, array $options) use ($handler, &$requestOptions) {
            $requestOptions[] = $options;

            return $handler($request, $options);
        };
    });

    $schedule = app(Schedule::class);
    $events = collect($schedule->events());
    $watchdogEvent = $events->first(static fn ($event): bool => $event->description === 'checkybot:watchdog');

    expect(app(HeartbeatClient::class))->toBeInstanceOf(LaravelHeartbeatClient::class)
        ->and($watchdogEvent)->not->toBeNull()
        ->and($watchdogEvent->expression)->toBe('* * * * *')
        ->and($watchdogEvent->withoutOverlapping)->toBeTrue()
        ->and($watchdogEvent->expiresAt)->toBe(1);

    foreach ([0, 1, 2, 3] as $minute) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07T12:00:00Z')->addMinutes($minute));
        expect($watchdogEvent->isDue(app()))->toBeTrue()
            ->and(Artisan::call('checkybot:watchdog'))->toBe(0);
    }

    Http::assertSentCount(4);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === $url
        && str_starts_with($request->header('User-Agent')[0] ?? '', 'Checkybot watchdog/'));

    expect(array_column($requestOptions, 'timeout'))->toBe([3, 3, 3, 3])
        ->and(array_column($requestOptions, 'connect_timeout'))->toBe([3, 3, 3, 3])
        ->and(array_column($this->watchdogLogger->records, 'level'))->toBe(['info', 'info', 'info', 'info'])
        ->and(array_column(array_column($this->watchdogLogger->records, 'context'), 'http_status'))->toBe([200, 201, 204, 299]);

    $scheduleNames = $events->pluck('description');
    expect($scheduleNames)->toContain(
        'checkybot:foundation-relay',
        'checkybot:alerting-retries',
        'checkybot:alerting-groups',
        'checkybot:maintenance-expire',
        'checkybot:watchdog',
    );

    // Reserve the named mutex as if another scheduler process owned this minute.
    expect($watchdogEvent->mutex->create($watchdogEvent))->toBeTrue()
        ->and($watchdogEvent->shouldSkipDueToOverlapping())->toBeTrue();
    $watchdogEvent->mutex->forget($watchdogEvent);
})->group('AC-alerting-reliability-core-16');

it('reports missing configuration as disabled without sending a request', function (): void {
    expect(Artisan::call('checkybot:watchdog'))->toBe(0)
        ->and(Artisan::output())->toContain('Checkybot watchdog is disabled.')
        ->and($this->watchdogLogger->records)->toHaveCount(1)
        ->and($this->watchdogLogger->records[0]['context'])->toBe([
            'watchdog_status' => 'disabled',
        ]);

    Http::assertNothingSent();
})->group('AC-alerting-reliability-core-17');

it('redacts timeout, transport, and HTTP failures while the next minute and other tasks remain runnable', function (): void {
    $url = 'https://api-user:api-password@watchdog.example.test/private-heartbeat?token=query-secret&signature=other-secret';
    config()->set('checkybot.alerting.watchdog.url', $url);
    config()->set('checkybot.alerting.watchdog.timeout_seconds', 999);

    Http::fake([
        'watchdog.example.test/*' => Http::sequence()
            ->pushFailedConnection('Operation timed out for '.$url)
            ->pushFailedConnection('Transport failed for '.$url)
            ->pushStatus(503)
            ->pushStatus(204),
    ]);

    $requestTimeouts = [];
    Http::globalMiddleware(function (callable $handler) use (&$requestTimeouts): callable {
        return function ($request, array $options) use ($handler, &$requestTimeouts) {
            $requestTimeouts[] = $options['timeout'];

            return $handler($request, $options);
        };
    });

    $otherRuns = 0;
    $otherTask = app(Schedule::class)
        ->call(function () use (&$otherRuns): void {
            $otherRuns++;
        })
        ->name('watchdog-test-unrelated-task')
        ->everyMinute();

    foreach ([0, 1, 2, 3] as $minute) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07T13:00:00Z')->addMinutes($minute));
        expect(Artisan::call('checkybot:watchdog'))->toBe(0);
        $otherTask->run(app());
    }

    expect($otherRuns)->toBe(4)
        ->and($requestTimeouts)->toBe([30, 30, 30, 30])
        ->and(array_column(array_column($this->watchdogLogger->records, 'context'), 'watchdog_status'))
        ->toBe(['failure', 'failure', 'failure', 'success'])
        ->and(array_column(array_slice(array_column($this->watchdogLogger->records, 'context'), 0, 3), 'reason'))
        ->toBe(['timeout', 'transport', 'http_status'])
        ->and($this->watchdogLogger->records[2]['context']['http_status'])->toBe(503)
        ->and($this->watchdogLogger->records[3]['context']['http_status'])->toBe(204);

    Http::assertSentCount(4);

    $diagnostics = json_encode($this->watchdogLogger->records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    expect($diagnostics)->toContain('https://watchdog.example.test')
        ->not->toContain('api-user')
        ->not->toContain('api-password')
        ->not->toContain('private-heartbeat')
        ->not->toContain('query-secret')
        ->not->toContain('other-secret');
})->group('AC-alerting-reliability-core-17');
