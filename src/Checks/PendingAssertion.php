<?php

namespace MarinSolutions\CheckybotLaravel\Checks;

/** Fluent JSON response-shape assertion builder. */
class PendingAssertion
{
    public function __construct(
        protected ApiCheck $check,
        protected string $path,
    ) {}

    public function toExist(): ApiCheck
    {
        return $this->add('exists');
    }

    public function exists(): ApiCheck
    {
        return $this->toExist();
    }

    public function toEqual(mixed $expected): ApiCheck
    {
        return $this->add('equals', $expected, true);
    }

    public function toBe(mixed $expected): ApiCheck
    {
        return $this->toEqual($expected);
    }

    public function equals(mixed $expected): ApiCheck
    {
        return $this->toEqual($expected);
    }

    public function notToEqual(mixed $expected): ApiCheck
    {
        return $this->add('not_equals', $expected, true);
    }

    public function notToBe(mixed $expected): ApiCheck
    {
        return $this->notToEqual($expected);
    }

    public function toBeGreaterThan(int|float $expected): ApiCheck
    {
        return $this->add('greater_than', $expected, true);
    }

    public function toBeGreaterThanOrEqual(int|float $expected): ApiCheck
    {
        return $this->add('greater_than_or_equal', $expected, true);
    }

    public function toBeLessThan(int|float $expected): ApiCheck
    {
        return $this->add('less_than', $expected, true);
    }

    public function toBeLessThanOrEqual(int|float $expected): ApiCheck
    {
        return $this->add('less_than_or_equal', $expected, true);
    }

    public function toBeTrue(): ApiCheck
    {
        return $this->toEqual(true);
    }

    public function toBeFalse(): ApiCheck
    {
        return $this->toEqual(false);
    }

    public function toBeType(string $type): ApiCheck
    {
        return $this->add('type', $type, true);
    }

    public function toBeString(): ApiCheck
    {
        return $this->toBeType('string');
    }

    public function toBeInteger(): ApiCheck
    {
        return $this->toBeType('integer');
    }

    public function toBeInt(): ApiCheck
    {
        return $this->toBeInteger();
    }

    public function toBeBoolean(): ApiCheck
    {
        return $this->toBeType('boolean');
    }

    public function toBeBool(): ApiCheck
    {
        return $this->toBeBoolean();
    }

    public function toBeArray(): ApiCheck
    {
        return $this->toBeType('array');
    }

    public function toBeObject(): ApiCheck
    {
        return $this->toBeType('object');
    }

    public function toMatch(string $pattern): ApiCheck
    {
        return $this->add('matches', $pattern, true);
    }

    public function toMatchRegex(string $pattern): ApiCheck
    {
        return $this->toMatch($pattern);
    }

    private function add(string $operator, mixed $operand = null, bool $hasOperand = false): ApiCheck
    {
        $assertion = [
            'kind' => 'json_path',
            'operator' => $operator,
            'path' => $this->path,
        ];

        if ($hasOperand) {
            $assertion['operand'] = $operand;
        }

        return $this->check->addAssertion($assertion);
    }
}
