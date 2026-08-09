<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Exceptions\SampleFetchException;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Exceptions\UnsafeEndpoint;
use Throwable;

/**
 * Injectable outbound boundary. Redirects are intentionally handled here rather
 * than by Guzzle so every hop is re-resolved and checked by EndpointPolicy.
 */
class BoundedHttpClient
{
    private const MAX_BYTES = 262144;

    private const MAX_REDIRECTS = 3;

    private const TIMEOUT_SECONDS = 8;

    public function __construct(private readonly EndpointPolicy $endpoints) {}

    /** @param array<string, string> $headers */
    public function fetch(string $endpoint, string $method, array $headers, mixed $requestBody): SampleHttpResponse
    {
        $url = $endpoint;
        $outboundMethod = $method;
        $body = $requestBody;
        $started = hrtime(true);

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            try {
                $safe = $this->endpoints->guard($url);
                $options = [
                    'allow_redirects' => false,
                    'stream' => true,
                ];
                if ($safe->pinnedAddress !== null && defined('CURLOPT_RESOLVE')) {
                    $options['curl'] = [CURLOPT_RESOLVE => [$safe->host.':'.$safe->port.':'.$safe->pinnedAddress]];
                }

                $pending = Http::withHeaders($headers)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->connectTimeout(3)
                    ->withOptions($options);
                $sendOptions = [];
                if (! in_array($outboundMethod, ['GET', 'DELETE'], true) || $body !== null) {
                    $sendOptions['body'] = json_encode($body, JSON_THROW_ON_ERROR);
                    $pending = $pending->withHeader('Content-Type', 'application/json');
                }
                $response = $pending->send($outboundMethod, $safe->url, $sendOptions);
            } catch (UnsafeEndpoint) {
                throw SampleFetchException::transport();
            } catch (ConnectionException $exception) {
                if (str_contains(strtolower($exception->getMessage()), 'timed out') || str_contains(strtolower($exception->getMessage()), 'timeout')) {
                    throw SampleFetchException::timeout();
                }

                throw SampleFetchException::transport();
            } catch (SampleFetchException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw SampleFetchException::transport();
            }

            $status = $response->status();
            if (in_array($status, [401, 403], true)) {
                throw SampleFetchException::upstreamAuth($status);
            }

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($redirects === self::MAX_REDIRECTS) {
                    throw SampleFetchException::transport();
                }
                $location = $response->header('Location');
                if ($location === '') {
                    throw SampleFetchException::transport();
                }
                try {
                    $url = (string) UriResolver::resolve(new Uri($safe->url), new Uri($location));
                } catch (Throwable) {
                    throw SampleFetchException::transport();
                }
                if ($status === 303 || (in_array($status, [301, 302], true) && $outboundMethod === 'POST')) {
                    $outboundMethod = 'GET';
                    $body = null;
                }

                continue;
            }

            $length = $response->header('Content-Length');
            if (ctype_digit($length) && (int) $length > self::MAX_BYTES) {
                throw SampleFetchException::tooLarge($status);
            }

            $stream = $response->toPsrResponse()->getBody();
            $contents = '';
            while (! $stream->eof()) {
                $contents .= $stream->read(8192);
                if (strlen($contents) > self::MAX_BYTES) {
                    throw SampleFetchException::tooLarge($status);
                }
            }

            try {
                $json = json_decode($contents, false, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw SampleFetchException::nonJson($status);
            }

            return new SampleHttpResponse(
                status: $status,
                latencyMs: max(0, (int) round((hrtime(true) - $started) / 1_000_000)),
                json: $json,
            );
        }

        throw SampleFetchException::transport();
    }
}
