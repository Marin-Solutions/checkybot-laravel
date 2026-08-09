<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\ApiMonitorBuilder;

final class JsonPathGrammar
{
    public function valid(string $path): bool
    {
        if ($path === '' || strlen($path) > 512) {
            return false;
        }

        return preg_match("/^\\$(?:(?:\\.[A-Za-z_][A-Za-z0-9_]*)|(?:\\[(?:0|[1-9]\\d*)\\])|(?:\\['(?:[^'\\\\]|\\\\['\\\\])*'\\]))*$/D", $path) === 1;
    }

    public function appendKey(string $path, string $key): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) === 1) {
            return $path.'.'.$key;
        }

        return $path."['".str_replace(['\\', "'"], ['\\\\', "\\'"], $key)."']";
    }
}
