<?php

declare(strict_types=1);

namespace App\Module\Reference\Domain;

use App\Module\Foundation\Domain\DecimalValue;

/**
 * How many decimals an asset stores and how many it shows.
 *
 * Storage is the scale a submitted figure may carry; display is the scale a
 * screen or export shows. Display never exceeds storage, otherwise the
 * interface would invent digits the source never provided.
 */
final readonly class AssetPrecision
{
    public function __construct(
        public int $storage,
        public int $display,
    ) {
        if ($storage < 0 || $storage > DecimalValue::MAX_SCALE) {
            throw new \InvalidArgumentException(sprintf('A storage precision is between 0 and %d decimal places.', DecimalValue::MAX_SCALE));
        }

        if ($display < 0 || $display > $storage) {
            throw new \InvalidArgumentException('A display precision is between 0 and the storage precision.');
        }
    }
}
