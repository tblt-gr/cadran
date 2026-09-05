<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reference\Domain\Asset;

/**
 * One published figure: the exact source decimal, its asset, and the same
 * figure rounded at the asset display boundary.
 *
 * Both forms travel together so an interface can show a readable amount
 * without ever rounding, dividing or multiplying a decimal itself.
 */
final readonly class NetWorthAmountView
{
    public function __construct(
        public string $amount,
        public string $asset,
        public ?string $display,
        public bool $belowDisplayStep,
    ) {
    }

    public static function of(?DecimalValue $value, ?AssetCode $asset, ?Asset $reference): ?self
    {
        if (null === $value || null === $asset) {
            return null;
        }

        $display = $reference?->displayed($value);

        return new self(
            amount: $value->toString(),
            asset: $asset->toString(),
            display: $display?->value?->toString(),
            belowDisplayStep: null !== $display && $display->belowStep,
        );
    }
}
