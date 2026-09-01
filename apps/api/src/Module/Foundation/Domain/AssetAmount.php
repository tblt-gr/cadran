<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

/**
 * A decimal figure together with the asset it is denominated in. A financial
 * value never travels alone: "230.5688" alone is not an amount.
 */
final readonly class AssetAmount
{
    public function __construct(
        public DecimalValue $value,
        public AssetCode $asset,
    ) {
    }
}
