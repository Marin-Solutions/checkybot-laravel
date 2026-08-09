<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Security\Foundation;

final readonly class RecursiveRedactor
{
    /** @param list<string> $secretLiterals */
    public function __construct(private array $secretLiterals = []) {}

    public static function fromConfiguration(): self
    {
        $secrets = config('checkybot.monitor_foundation.redaction.secret_literals', []);
        $configured = is_array($secrets) ? array_values(array_filter($secrets, 'is_string')) : [];
        $environment = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) getenv('CHECKYBOT_REDACTION_SECRETS')),
        )));

        return new self(array_values(array_unique([...$configured, ...$environment])));
    }

    public function redact(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $childKey => $child) {
                $redacted[$childKey] = $this->redact($child, is_string($childKey) ? $childKey : null);
            }

            return $redacted;
        }

        if (is_object($value)) {
            return $this->redact((array) $value, $key);
        }

        if (! is_string($value)) {
            return $value;
        }

        if ($key !== null && in_array(strtolower(str_replace('_', '-', $key)), [
            'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
        ], true)) {
            return '[REDACTED]';
        }

        if ($key !== null && in_array(strtolower($key), ['query', 'query_string', 'query-string'], true)) {
            $value = $this->redactQueryString($value);
        }

        return $this->redactString($value);
    }

    private function redactString(string $value): string
    {
        // Header-shaped values also occur inside log lines rather than keyed arrays.
        $value = (string) preg_replace('/\b(Authorization|Proxy-Authorization)\s*:\s*[^\r\n]+/iu', '$1: [REDACTED]', $value);
        $value = (string) preg_replace('/\b(Set-Cookie|Cookie)\s*:\s*[^\r\n]+/iu', '$1: [REDACTED]', $value);

        // Redact every query parameter value while retaining the URL/path and parameter names.
        $value = (string) preg_replace_callback(
            '/([?&][^\s&#=]+)=([^\s&#]*)/u',
            static fn (array $match): string => $match[1].'=[REDACTED]',
            $value,
        );

        foreach ($this->secretLiterals as $secret) {
            if ($secret !== '') {
                $value = str_replace($secret, '[REDACTED]', $value);
            }
        }

        $value = (string) preg_replace(
            '/(?<![\pL\pN._%+\-])[\pL\pN._%+\-]+@[\pL\pN.\-]+\.[\pL]{2,}(?![\pL\pN._%+\-])/iu',
            '[REDACTED]',
            $value,
        );
        $value = (string) preg_replace(
            '/(?<![\d.])(?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3}(?![\d.])/u',
            '[REDACTED]',
            $value,
        );
        // Fully expanded, compressed and IPv4-mapped IPv6 forms.
        $value = (string) preg_replace(
            '/(?<![\pL\pN:])(?:(?:[A-F\d]{1,4}:){7}[A-F\d]{1,4}|(?:[A-F\d]{1,4}:){1,7}:|(?:[A-F\d]{1,4}:){1,6}:[A-F\d]{1,4}|(?:[A-F\d]{1,4}:){1,4}(?::[A-F\d]{1,4}){1,3}|(?:[A-F\d]{1,4}:){1,4}:(?:\d{1,3}\.){3}\d{1,3}|:(?::[A-F\d]{1,4}){1,7}|::)(?![\pL\pN:])/iu',
            '[REDACTED]',
            $value,
        );

        return $value;
    }

    private function redactQueryString(string $query): string
    {
        return (string) preg_replace('/(^|&)([^=&\s]+)=([^&\s]*)/u', '$1$2=[REDACTED]', $query);
    }
}
