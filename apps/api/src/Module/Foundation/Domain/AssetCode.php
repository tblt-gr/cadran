<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

/**
 * The stable identifier of an asset: an ISO 4217 alphabetic code for a
 * currency, a ticker for a crypto-asset. Uppercase is the canonical form and
 * lowercase is refused rather than corrected, so a code travels through the
 * API, the database and the audit trail as exactly one string.
 */
final readonly class AssetCode
{
    public const int MIN_LENGTH = 2;
    public const int MAX_LENGTH = 12;

    private function __construct(private string $code)
    {
    }

    public static function fromString(string $code): self
    {
        // Built from the bounds themselves, so a changed limit cannot leave the
        // message and the check disagreeing. The D modifier stops PCRE from
        // accepting a trailing newline as part of an otherwise canonical code.
        $pattern = sprintf('/^[A-Z][A-Z0-9]{%d,%d}$/D', self::MIN_LENGTH - 1, self::MAX_LENGTH - 1);

        if (1 !== preg_match($pattern, $code)) {
            throw new \InvalidArgumentException(sprintf('An asset code is %d to %d uppercase letters or digits, starting with a letter.', self::MIN_LENGTH, self::MAX_LENGTH));
        }

        return new self($code);
    }

    public function toString(): string
    {
        return $this->code;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
