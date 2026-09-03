<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

use App\Module\Foundation\Domain\DecimalValue;

/**
 * One slice of a rate scale: the amounts it covers and the rate they earn.
 *
 * The lower bound is included and the upper bound excluded, so two adjacent
 * brackets meeting on the same figure leave no amount uncovered and none
 * counted twice. An open upper bound means the last slice runs without limit.
 */
final readonly class RateBracket
{
    public function __construct(
        public DecimalValue $lowerBound,
        public ?DecimalValue $upperBound,
        public DecimalValue $percentage,
    ) {
        if ($lowerBound->isNegative()) {
            throw new InvalidCatalogEntry('A rate bracket starts at zero or above.');
        }

        if (null !== $upperBound && $upperBound->compareTo($lowerBound) <= 0) {
            throw new InvalidCatalogEntry('A rate bracket ends above the amount it starts at.');
        }
    }

    public function coversWithoutLimit(): bool
    {
        return null === $this->upperBound;
    }
}
