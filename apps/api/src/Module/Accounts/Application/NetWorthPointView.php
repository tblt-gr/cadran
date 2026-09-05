<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\NetWorth;
use App\Module\Reference\Domain\Asset;

/**
 * One point of the history curve. A month without a computable figure keeps
 * its place with a null total and a reason, so a gap reads as a gap instead
 * of a fall to zero.
 */
final readonly class NetWorthPointView
{
    public function __construct(
        public string $on,
        public ?NetWorthAmountView $total,
        public ?string $reason,
        public string $quality,
    ) {
    }

    public static function fromNetWorth(NetWorth $netWorth, ?Asset $reference): self
    {
        return new self(
            on: $netWorth->asOf->format('Y-m-d'),
            total: NetWorthAmountView::of($netWorth->total, $netWorth->asset, $reference),
            reason: $netWorth->reason?->value,
            quality: $netWorth->quality->value,
        );
    }
}
