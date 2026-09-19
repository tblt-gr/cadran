<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\NetWorthDelta;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\RoundingMode;

/**
 * The movement between two business days. The amount and the rate carry
 * their own reason: a real movement against a zero base has no percentage,
 * and publishing one anyway would be an invented figure.
 */
final readonly class NetWorthDeltaView
{
    /**
     * Presentation scale of the change rate. The exact ratio stays available
     * beside it: a rate is almost never a terminating decimal, so a headline
     * that printed all 24 places would be unreadable, and rounding it in the
     * interface would move a rounding decision out of the backend.
     */
    public const int RATE_DISPLAY_SCALE = 2;

    public function __construct(
        public string $comparedOn,
        public ?NetWorthAmountView $previousTotal,
        public ?string $previousReason,
        public ?NetWorthAmountView $amount,
        public ?string $amountReason,
        public ?string $rate,
        public ?string $ratePercent,
        public ?string $ratePercentDisplay,
        public ?string $rateReason,
    ) {
    }

    public static function fromDelta(NetWorthDelta $delta, NetWorthAssetReferences $references): self
    {
        return new self(
            comparedOn: $delta->comparedOn->format('Y-m-d'),
            previousTotal: NetWorthAmountView::of(
                $delta->previousTotal,
                $delta->previousAsset,
                $references->for($delta->previousAsset),
            ),
            previousReason: $delta->previousReason?->value,
            amount: NetWorthAmountView::of($delta->amount, $delta->asset, $references->for($delta->asset)),
            amountReason: $delta->amountReason?->value,
            rate: $delta->rate?->toString(),
            ratePercent: $delta->ratePercent?->toString(),
            ratePercentDisplay: null === $delta->ratePercent
                ? null
                : ExactDecimal::round($delta->ratePercent, self::RATE_DISPLAY_SCALE, RoundingMode::HALF_UP)->toString(),
            rateReason: $delta->rateReason?->value,
        );
    }
}
