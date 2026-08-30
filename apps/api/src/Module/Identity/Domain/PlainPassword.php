<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

/**
 * A candidate password held only long enough to be hashed. The policy is length
 * in Unicode code points; no composition rule and no trimming, per OWASP ASVS
 * 5.0 guidance. The value is never logged, serialized, or exposed.
 */
final readonly class PlainPassword
{
    public const int MIN_LENGTH = 12;
    public const int MAX_LENGTH = 128;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $length = mb_strlen($value);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new WeakPassword(sprintf('A password must be between %d and %d characters.', self::MIN_LENGTH, self::MAX_LENGTH));
        }

        return new self($value);
    }
}
