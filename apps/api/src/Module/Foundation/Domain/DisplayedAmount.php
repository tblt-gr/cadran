<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

/**
 * A figure rounded at a named presentation boundary.
 *
 * The source literal stays on the snapshot. This object is only what a screen
 * or export may show. A non-zero source that rounds to zero is {@see $belowStep}:
 * showing `0.00` would invent a nil the holder never recorded.
 */
final readonly class DisplayedAmount
{
    public function __construct(
        public ?DecimalValue $value,
        public AssetCode $asset,
        public bool $belowStep,
    ) {
        if ($belowStep && null !== $value) {
            throw new \InvalidArgumentException('A value below the display step has no rounded figure.');
        }

        if (!$belowStep && null === $value) {
            throw new \InvalidArgumentException('A displayable amount needs a rounded figure.');
        }
    }
}
