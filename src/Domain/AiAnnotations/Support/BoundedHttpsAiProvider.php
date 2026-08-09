<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Contracts\AiAnnotationProvider;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderFailureCode;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderRequest;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderResult;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\RecursiveRedactor;
use Throwable;

/**
 * A deliberately non-retrying, non-logging egress boundary. Every failure is
 * collapsed to a bounded code so provider payloads never enter logs or reports.
 */
final readonly class BoundedHttpsAiProvider implements AiAnnotationProvider
{
    private const SOURCES = ['nginx', 'fpm', 'mysql'];

    private const SYSTEM_INSTRUCTION = 'Return exactly one short paragraph describing the probable technical cause. Do not include personal data, addresses, credentials, quoted log lines, or remediation steps.';

    public function __construct(
        private AiConfiguration $configuration,
        private RecursiveRedactor $redactor,
    ) {}

    public function annotate(ProviderRequest $request): ProviderResult
    {
        if (! $this->configuration->providerConfigured()) {
            return ProviderResult::failure(ProviderFailureCode::NotConfigured);
        }

        $body = $this->body($request);
        if ($body === null) {
            return ProviderResult::failure(ProviderFailureCode::InvalidResponse);
        }

        try {
            $response = Http::acceptJson()
                ->withToken((string) config('ai-annotations.provider.credential'))
                ->withHeader('Idempotency-Key', $request->operationId)
                ->connectTimeout((float) config('ai-annotations.provider.connect_timeout_seconds'))
                ->timeout((float) config('ai-annotations.provider.timeout_seconds'))
                ->withOptions([
                    'allow_redirects' => false,
                    'http_errors' => false,
                ])
                ->post((string) config('ai-annotations.provider.url'), $body);
        } catch (ConnectionException $exception) {
            $message = strtolower($exception->getMessage());

            return ProviderResult::failure(
                str_contains($message, 'timed out') || str_contains($message, 'timeout')
                    ? ProviderFailureCode::Timeout
                    : ProviderFailureCode::Transport,
            );
        } catch (Throwable) {
            return ProviderResult::failure(ProviderFailureCode::Transport);
        }

        if ($response->status() === 429) {
            return ProviderResult::failure(ProviderFailureCode::RateLimited);
        }
        if ($response->redirect() || ! $response->successful()) {
            return ProviderResult::failure(ProviderFailureCode::Transport);
        }

        $raw = $response->body();
        $maxBytes = max(1, (int) config('ai-annotations.provider.max_response_bytes', 65536));
        if (strlen($raw) > $maxBytes) {
            return ProviderResult::failure(ProviderFailureCode::InvalidResponse);
        }

        try {
            $json = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ProviderResult::failure(ProviderFailureCode::InvalidResponse);
        }

        return $this->result(is_array($json) ? $json : [], $request->reservationMicrousd);
    }

    /** @return array<string, mixed>|null */
    private function body(ProviderRequest $request): ?array
    {
        $maximumLines = max(1, min(200, (int) config('ai-annotations.limits.max_lines', 100)));
        $maximumLineChars = max(1, (int) config('ai-annotations.limits.max_line_chars', 1000));
        $maximumInputChars = max(1, (int) config('ai-annotations.limits.max_input_chars', 30000));
        $secretRedactor = new RecursiveRedactor(array_values(array_filter([
            (string) config('ai-annotations.provider.credential', ''),
            ...array_map('strval', (array) config('checkybot.monitor_foundation.redaction.secret_literals', [])),
        ])));
        $lines = [];

        foreach (array_slice($request->lines, 0, $maximumLines) as $line) {
            if (! in_array($line['source'], self::SOURCES, true)) {
                continue;
            }
            $observedAt = $line['observed_at'];
            $text = $line['redacted_line'];

            // The inherited redactor is applied again at the last possible point,
            // followed by one carrying the runtime provider credential itself.
            $text = (string) $secretRedactor->redact((string) $this->redactor->redact($text));
            $candidate = [
                'source' => (string) $line['source'],
                'observed_at' => $observedAt,
                'redacted_line' => substr($text, 0, $maximumLineChars),
            ];
            $encoded = json_encode([...$lines, $candidate]);
            if (! is_string($encoded) || strlen($encoded) > $maximumInputChars) {
                break;
            }
            $lines[] = $candidate;
        }

        if ($lines === []) {
            return null;
        }

        return [
            'model' => (string) config('ai-annotations.provider.model'),
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_INSTRUCTION],
                ['role' => 'user', 'content' => json_encode(['context' => $lines], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            ],
            'temperature' => 0,
            'max_output_tokens' => max(1, (int) config('ai-annotations.limits.max_output_tokens', 300)),
        ];
    }

    /** @param array<string, mixed> $json */
    private function result(array $json, int $reservation): ProviderResult
    {
        $cause = data_get($json, 'choices.0.message.content');
        $input = data_get($json, 'usage.prompt_tokens');
        $output = data_get($json, 'usage.completion_tokens');
        if (! is_string($cause) || ! is_int($input) || ! is_int($output) || $input < 0 || $output < 0) {
            return ProviderResult::failure(ProviderFailureCode::InvalidResponse);
        }
        if ($input > (int) config('ai-annotations.limits.max_input_tokens', 12000)
            || $output > (int) config('ai-annotations.limits.max_output_tokens', 300)) {
            return ProviderResult::failure(ProviderFailureCode::InvalidResponse);
        }

        $cause = trim((string) $this->redactor->redact($cause));
        $cause = trim((string) (new RecursiveRedactor([(string) config('ai-annotations.provider.credential', '')]))->redact($cause));
        if ($cause === ''
            || preg_match('/\R\s*\R/u', $cause) === 1
            || strlen($cause) > (int) config('ai-annotations.limits.max_root_cause_chars', 1200)) {
            return ProviderResult::failure(ProviderFailureCode::InvalidResponse);
        }

        $reportedBill = data_get($json, 'usage.billed_microusd', data_get($json, 'billed_microusd'));
        $billed = is_int($reportedBill)
            ? $reportedBill
            : ($input * max(0, (int) config('ai-annotations.budget.input_token_microusd', 0)))
                + ($output * max(0, (int) config('ai-annotations.budget.output_token_microusd', 0)));
        if ($billed < 0 || $billed > $reservation) {
            return ProviderResult::failure(ProviderFailureCode::OverReservation);
        }

        return ProviderResult::success($cause, $input, $output, $billed);
    }
}
