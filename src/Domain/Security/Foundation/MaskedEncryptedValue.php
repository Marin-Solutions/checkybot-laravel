<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Security\Foundation;

use Illuminate\Contracts\Encryption\Encrypter;
use JsonSerializable;
use Stringable;

/**
 * An encrypted value that is deliberately safe in JSON, logs and debugger output.
 * Plaintext is available only through the explicit reveal() boundary.
 */
abstract class MaskedEncryptedValue implements JsonSerializable, Stringable
{
    final protected function __construct(
        private readonly string $encrypted,
        private readonly Encrypter $encrypter,
    ) {}

    public static function encrypt(string $plaintext, ?Encrypter $encrypter = null): static
    {
        $resolved = self::encrypter($encrypter);

        return new static($resolved->encrypt($plaintext, false), $resolved);
    }

    public static function restore(string $ciphertext, ?Encrypter $encrypter = null): static
    {
        return new static($ciphertext, self::encrypter($encrypter));
    }

    public function reveal(): string
    {
        return (string) $this->encrypter->decrypt($this->encrypted, false);
    }

    /** The persistence boundary intentionally exposes ciphertext, never plaintext. */
    public function ciphertext(): string
    {
        return $this->encrypted;
    }

    public function jsonSerialize(): string
    {
        return self::MASK;
    }

    public function __toString(): string
    {
        return self::MASK;
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => self::MASK];
    }

    private static function encrypter(?Encrypter $encrypter): Encrypter
    {
        return $encrypter ?? app(Encrypter::class);
    }

    private const MASK = '[REDACTED]';
}
