<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Actions;

use Composer\InstalledVersions;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\HeartbeatClient;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\HeartbeatTransportException;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\WatchdogPingResult;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class PingWatchdogHeartbeat
{
    private const DEFAULT_TIMEOUT_SECONDS = 5;

    private const MAX_TIMEOUT_SECONDS = 30;

    public function __construct(
        private HeartbeatClient $client,
        private LoggerInterface $logger,
    ) {}

    public function execute(): WatchdogPingResult
    {
        $configuredUrl = config('checkybot.alerting.watchdog.url');

        if (! is_string($configuredUrl) || trim($configuredUrl) === '') {
            $this->logger->info('Checkybot watchdog is disabled.', [
                'watchdog_status' => 'disabled',
            ]);

            return WatchdogPingResult::disabled();
        }

        $url = trim($configuredUrl);
        $endpoint = $this->redactedEndpoint($url);

        if (! $this->isHttpsUrl($url)) {
            return $this->recordFailure('invalid_configuration', $endpoint);
        }

        $timeout = $this->boundedTimeout(config('checkybot.alerting.watchdog.timeout_seconds'));

        try {
            $response = $this->client->get($url, $timeout, $this->userAgent());
        } catch (HeartbeatTransportException $exception) {
            return $this->recordFailure($exception->reason, $endpoint);
        } catch (Throwable) {
            // Do not copy exception text into diagnostics: HTTP exception messages can
            // contain the credential-bearing URL supplied by the heartbeat provider.
            return $this->recordFailure('transport', $endpoint);
        }

        if (! $response->isSuccessful()) {
            return $this->recordFailure('http_status', $endpoint, $response->statusCode);
        }

        $this->logger->info('Checkybot watchdog heartbeat succeeded.', [
            'watchdog_status' => 'success',
            'endpoint' => $endpoint,
            'http_status' => $response->statusCode,
        ]);

        return WatchdogPingResult::succeeded($response->statusCode);
    }

    private function boundedTimeout(mixed $configured): int
    {
        $timeout = filter_var($configured, FILTER_VALIDATE_INT);

        if ($timeout === false) {
            return self::DEFAULT_TIMEOUT_SECONDS;
        }

        return max(1, min(self::MAX_TIMEOUT_SECONDS, $timeout));
    }

    private function isHttpsUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host']);
    }

    private function redactedEndpoint(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        // Origin-only diagnostics omit URL user-info, path, query, and fragment. Many
        // heartbeat vendors put opaque credentials in more than one of those fields.
        return strtolower($parts['scheme']).'://'.$parts['host'].$port;
    }

    private function userAgent(): string
    {
        try {
            $version = InstalledVersions::getPrettyVersion('marin-solutions/checkybot-laravel') ?? 'unknown';
        } catch (Throwable) {
            $version = 'unknown';
        }

        return 'Checkybot watchdog/'.$version;
    }

    private function recordFailure(string $reason, ?string $endpoint, ?int $httpStatus = null): WatchdogPingResult
    {
        $context = [
            'watchdog_status' => 'failure',
            'reason' => $reason,
            'endpoint' => $endpoint,
        ];

        if ($httpStatus !== null) {
            $context['http_status'] = $httpStatus;
        }

        $this->logger->warning('Checkybot watchdog heartbeat failed.', $context);

        return WatchdogPingResult::failed($reason, $httpStatus);
    }
}
