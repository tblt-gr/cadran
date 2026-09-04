<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Catalog\Domain\RateBracket;

/**
 * One slice of a rate scale on the wire: canonical decimal strings, never
 * numbers, so no client turns a rate into a binary float on the way in.
 */
final readonly class ProductModelRateBracketView
{
    public function __construct(
        public string $lowerBound,
        public ?string $upperBound,
        public string $percentage,
    ) {
    }

    public static function of(RateBracket $bracket): self
    {
        return new self(
            lowerBound: $bracket->lowerBound->toString(),
            upperBound: $bracket->upperBound?->toString(),
            percentage: $bracket->percentage->toString(),
        );
    }
}
