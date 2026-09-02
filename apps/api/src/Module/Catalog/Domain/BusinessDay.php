<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * The calendar day a catalogue read is answered for.
 *
 * A rule is selected by the business date, never by the current date: reading
 * a 2023 statement must show the 2023 ceiling. The day is normalised to UTC
 * midnight so a caller's timezone cannot shift which period applies.
 */
final readonly class BusinessDay
{
    public const string EARLIEST = '1900-01-01';
    public const string LATEST = '2100-12-31';

    private function __construct(public \DateTimeImmutable $date)
    {
    }

    public static function fromIsoDate(string $value): self
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        // createFromFormat accepts overflow such as 2026-02-31 and silently
        // rolls it into March, so the parsed day is compared back to the input.
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidCatalogEntry('A business date is an ISO 8601 calendar day.');
        }

        if ($value < self::EARLIEST || $value > self::LATEST) {
            throw new InvalidCatalogEntry(sprintf('A business date is between %s and %s.', self::EARLIEST, self::LATEST));
        }

        return new self($date);
    }

    public static function fromDateTime(\DateTimeImmutable $moment): self
    {
        return self::fromIsoDate($moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'));
    }

    public function toString(): string
    {
        return $this->date->format('Y-m-d');
    }
}
