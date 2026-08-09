<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder;

use Illuminate\Validation\ValidationException;
use MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder\Data\BuilderInput;

final readonly class BuilderInputValidator
{
    public function __construct(private JsonPathGrammar $paths) {}

    /** @param array<string, mixed> $input */
    public function validate(array $input, bool $withAssertions): BuilderInput
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];
        $endpoint = $this->endpoint($input['endpoint'] ?? null, $errors);
        $method = is_string($input['method'] ?? null) ? strtoupper($input['method']) : '';
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $errors['method'][] = 'The method field must be GET, POST, PUT, PATCH, or DELETE.';
        }

        $version = $input['configuration_version'] ?? null;
        if (! is_int($version) || $version < 1) {
            $errors['configuration_version'][] = 'The configuration version must be a positive integer.';
            $version = 1;
        }

        $headers = $this->headers($input['headers'] ?? null, $errors);
        $assertions = $withAssertions ? $this->assertions($input['assertions'] ?? null, $errors) : [];

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new BuilderInput(
            endpoint: $endpoint,
            method: $method,
            headers: $headers,
            assertions: $assertions,
            configurationVersion: $version,
            requestBody: $input['request_body'] ?? null,
        );
    }

    /** @param array<string, list<string>> $errors */
    private function endpoint(mixed $value, array &$errors): string
    {
        if (! is_string($value) || strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $errors['endpoint'][] = 'The endpoint must be a valid HTTP or HTTPS URL.';

            return '';
        }

        $parts = parse_url($value);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            $errors['endpoint'][] = 'The endpoint must be an HTTP or HTTPS URL without user information.';

            return '';
        }
        if (isset($parts['fragment'])) {
            $errors['endpoint'][] = 'The endpoint must not contain a URL fragment.';

            return '';
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        $authority = str_contains($host, ':') && ! str_starts_with($host, '[') ? '['.$host.']' : $host;
        if ($port !== null && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $authority .= ':'.$port;
        }
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }

        return $scheme.'://'.$authority.$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<array{name: string, normalized_name: string, action: 'preserve'|'set'|'remove', value?: string}>
     */
    private function headers(mixed $value, array &$errors): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $errors['headers'][] = 'The headers field must be an array.';

            return [];
        }
        if (count($value) > 50) {
            $errors['headers'][] = 'The headers field must not contain more than 50 entries.';
        }

        $result = [];
        $seen = [];
        $prohibited = ['host', 'content-length', 'transfer-encoding', 'connection', 'proxy-authorization', 'proxy-connection'];
        foreach ($value as $index => $header) {
            $prefix = 'headers.'.$index;
            if (! is_array($header)) {
                $errors[$prefix][] = 'Each header must be an object.';

                continue;
            }
            $name = $header['name'] ?? null;
            if (! is_string($name) || strlen($name) > 128 || preg_match("/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/D", $name) !== 1) {
                $errors[$prefix.'.name'][] = 'The header name is invalid.';

                continue;
            }
            $normalized = strtolower($name);
            if (isset($seen[$normalized])) {
                $errors[$prefix.'.name'][] = 'Header names must be unique.';
            }
            $seen[$normalized] = true;
            if (in_array($normalized, $prohibited, true)) {
                $errors[$prefix.'.name'][] = 'This request-controlled header is not allowed.';
            }

            $action = $header['action'] ?? null;
            if (! is_string($action) || ! in_array($action, ['preserve', 'set', 'remove'], true)) {
                $errors[$prefix.'.action'][] = 'The header action must be preserve, set, or remove.';

                continue;
            }

            $hasValue = array_key_exists('value', $header);
            if ($action === 'set') {
                $setValue = $header['value'] ?? null;
                if (! is_string($setValue) || $setValue === '' || strlen($setValue) > 8192 || str_contains($setValue, "\r") || str_contains($setValue, "\n")) {
                    $errors[$prefix.'.value'][] = 'A bounded single-line value is required when setting a header.';
                } else {
                    $result[] = ['name' => $name, 'normalized_name' => $normalized, 'action' => 'set', 'value' => $setValue];
                }
            } else {
                if ($hasValue) {
                    $errors[$prefix.'.value'][] = 'A value is prohibited unless the header action is set.';
                }
                $result[] = ['name' => $name, 'normalized_name' => $normalized, 'action' => $action];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<array<string, mixed>>
     */
    private function assertions(mixed $value, array &$errors): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $errors['assertions'][] = 'The assertions field must be an array.';

            return [];
        }
        if (count($value) > 50) {
            $errors['assertions'][] = 'The assertions field must not contain more than 50 entries.';
        }

        $normalized = [];
        foreach ($value as $index => $assertion) {
            $prefix = 'assertions.'.$index;
            if (! is_array($assertion)) {
                $errors[$prefix][] = 'Each assertion must be an object.';

                continue;
            }
            $kind = $assertion['kind'] ?? null;
            $operator = $assertion['operator'] ?? null;
            if (! is_string($kind) || ! in_array($kind, ['status', 'latency', 'json_path'], true)) {
                $errors[$prefix.'.kind'][] = 'The assertion kind is invalid.';

                continue;
            }
            if (! is_string($operator)) {
                $errors[$prefix.'.operator'][] = 'The assertion operator is invalid.';

                continue;
            }

            $hasExpected = array_key_exists('expected', $assertion);
            $expected = $assertion['expected'] ?? null;
            $item = ['kind' => $kind, 'operator' => $operator];
            if ($kind === 'status') {
                if (! in_array($operator, ['equals', 'in'], true)) {
                    $errors[$prefix.'.operator'][] = 'The status assertion operator must be equals or in.';
                } elseif (! $hasExpected || ($operator === 'equals' && ! $this->httpCode($expected))) {
                    $errors[$prefix.'.expected'][] = 'The status equals operand must be an HTTP status code.';
                } elseif ($operator === 'in' && (! is_array($expected) || ! array_is_list($expected) || $expected === [] || count($expected) > 20 || array_filter($expected, fn (mixed $code): bool => ! $this->httpCode($code)) !== [])) {
                    $errors[$prefix.'.expected'][] = 'The status in operand must contain between 1 and 20 HTTP status codes.';
                }
            } elseif ($kind === 'latency') {
                if ($operator !== 'less_than_or_equal') {
                    $errors[$prefix.'.operator'][] = 'The latency assertion operator must be less_than_or_equal.';
                }
                if (! $hasExpected || ! is_int($expected) || $expected < 0 || $expected > 3_600_000) {
                    $errors[$prefix.'.expected'][] = 'The latency operand must be bounded non-negative milliseconds.';
                }
            } else {
                $path = $assertion['path'] ?? null;
                if (! is_string($path) || ! $this->paths->valid($path)) {
                    $errors[$prefix.'.path'][] = 'The JSON path is not canonical or exceeds 512 characters.';
                } else {
                    $item['path'] = $path;
                }
                if (! in_array($operator, ['exists', 'not_null', 'equals', 'not_equals', 'type', 'non_empty'], true)) {
                    $errors[$prefix.'.operator'][] = 'The JSON path assertion operator is invalid.';
                } elseif (in_array($operator, ['exists', 'not_null', 'non_empty'], true) && $hasExpected) {
                    $errors[$prefix.'.expected'][] = 'This JSON path operator does not accept an operand.';
                } elseif (in_array($operator, ['equals', 'not_equals'], true) && (! $hasExpected || (! is_scalar($expected) && $expected !== null))) {
                    $errors[$prefix.'.expected'][] = 'This JSON path operator requires a JSON scalar or null operand.';
                } elseif ($operator === 'type' && (! is_string($expected) || ! in_array($expected, ['string', 'integer', 'number', 'boolean', 'null', 'array', 'object'], true))) {
                    $errors[$prefix.'.expected'][] = 'The JSON path type operand is invalid.';
                }
            }

            if ($hasExpected) {
                $item['expected'] = $expected;
            }
            $normalized[] = $item;
        }

        return $normalized;
    }

    private function httpCode(mixed $value): bool
    {
        return is_int($value) && $value >= 100 && $value <= 599;
    }
}
