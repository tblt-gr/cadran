<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

/**
 * The human-readable name shown for an account. Surrounding whitespace is
 * normalised away rather than rejected, because a name pasted from another
 * field is still the name its owner meant; an entirely blank one is not a name
 * at all and is refused.
 */
final readonly class DisplayName
{
    public const int MAX_LENGTH = 100;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = trim($value);
        if ('' === $normalized || mb_strlen($normalized) > self::MAX_LENGTH) {
            throw new InvalidDisplayName(sprintf('A user display name must be between 1 and %d characters.', self::MAX_LENGTH));
        }

        return new self($normalized);
    }
}
