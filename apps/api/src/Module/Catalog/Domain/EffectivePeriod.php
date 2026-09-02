<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * The closed date interval a catalogue rule applies over, `validTo` included.
 *
 * An open end means "until the next sourced revision closes it". Regulated
 * French rates are set for a fixed semester, so their periods are seeded with
 * both bounds: the next revision then appends a period instead of editing one,
 * which is what keeps the catalogue's history intact.
 */
final readonly class EffectivePeriod
{
    public function __construct(
        public \DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validTo,
    ) {
        if (null !== $validTo && $validTo < $validFrom) {
            throw new InvalidCatalogEntry('A rule period ends on or after the day it starts.');
        }
    }

    public static function openEndedFrom(\DateTimeImmutable $validFrom): self
    {
        return new self($validFrom, null);
    }

    public function covers(\DateTimeImmutable $businessDate): bool
    {
        if ($businessDate < $this->validFrom) {
            return false;
        }

        return null === $this->validTo || $businessDate <= $this->validTo;
    }

    public function overlaps(self $other): bool
    {
        $startsBeforeOtherEnds = null === $other->validTo || $this->validFrom <= $other->validTo;
        $endsAfterOtherStarts = null === $this->validTo || $this->validTo >= $other->validFrom;

        return $startsBeforeOtherEnds && $endsAfterOtherStarts;
    }
}
