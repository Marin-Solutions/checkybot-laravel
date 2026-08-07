<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder;

use stdClass;

final readonly class JsonPathCatalog
{
    public function __construct(private JsonPathGrammar $grammar) {}

    /**
     * @return array{json: mixed, paths: list<array{path: string, inferred_type: string, preview: string}>}
     */
    public function build(mixed $json): array
    {
        $sanitized = $this->sanitize($json);
        $paths = [];
        $this->walk($sanitized, '$', $paths);

        return ['json' => $sanitized, 'paths' => $paths];
    }

    /** @param list<array{path: string, inferred_type: string, preview: string}> $paths */
    private function walk(mixed $value, string $path, array &$paths): void
    {
        $paths[] = [
            'path' => $path,
            'inferred_type' => $this->type($value),
            'preview' => $this->preview($value),
        ];

        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            foreach ($properties as $key => $child) {
                $this->walk($child, $this->grammar->appendKey($path, $key), $paths);
            }

            return;
        }
        if (is_array($value)) {
            foreach ($value as $index => $child) {
                $this->walk($child, $path.'['.$index.']', $paths);
            }
        }
    }

    private function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/(?:authorization|password|passwd|secret|token|api[-_]?key|cookie)/i', $key) === 1) {
            return '[REDACTED]';
        }
        if ($value instanceof stdClass) {
            $safe = new stdClass;
            foreach (get_object_vars($value) as $childKey => $child) {
                $safe->{$childKey} = $this->sanitize($child, $childKey);
            }

            return $safe;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $child): mixed => $this->sanitize($child), $value);
        }

        return $value;
    }

    private function type(mixed $value): string
    {
        return match (true) {
            $value instanceof stdClass => 'object',
            is_array($value) => 'array',
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            default => 'unknown',
        };
    }

    private function preview(mixed $value): string
    {
        if ($value instanceof stdClass) {
            return '{'.count(get_object_vars($value)).' keys}';
        }
        if (is_array($value)) {
            return '['.count($value).' items]';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $preview = (string) $value;

        return strlen($preview) <= 120 ? $preview : substr($preview, 0, 117).'...';
    }
}
