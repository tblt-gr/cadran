<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * The stable identifier of a catalogue product, such as `FR_LIVRET_A`.
 *
 * The code is the primary key of the catalogue and travels in URLs, so it is
 * constrained to an uppercase, underscore-separated form: no locale, no
 * punctuation and no case variant can produce two codes for one product.
 */
final readonly class ProductCode
{
    public const int MIN_LENGTH = 3;
    public const int MAX_LENGTH = 32;

    private const string PATTERN = '/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/D';

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $length = strlen($value);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidCatalogEntry(sprintf('A product code is between %d and %d characters.', self::MIN_LENGTH, self::MAX_LENGTH));
        }

        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new InvalidCatalogEntry('A product code is uppercase alphanumeric, separated by single underscores.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
