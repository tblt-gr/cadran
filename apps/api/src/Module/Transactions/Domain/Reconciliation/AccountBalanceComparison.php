<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Reconciliation;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * An observed closing balance set against the movements that explain it.
 *
 * discrepancy = closing - (opening + Σ booked movements). A positive figure
 * means the account holds more than the movements explain. When a side is
 * missing or the assets differ the discrepancy is null and a reason says why;
 * it is never zero by default.
 */
final readonly class AccountBalanceComparison
{
    private function __construct(
        public AssetCode $asset,
        public DecimalValue $closing,
        public ?DecimalValue $opening,
        public ?DecimalValue $movements,
        public ?DecimalValue $discrepancy,
        public ?ReconciliationReason $reason,
    ) {
    }

    /** @param list<AssetAmount> $movementSums one entry per asset found in the period */
    public static function of(
        AccountBalanceSnapshot $closing,
        bool $closingIsCurrent,
        ?AccountBalanceSnapshot $opening,
        array $movementSums,
    ): self {
        $asset = $closing->amount->asset;
        $openingValue = $opening?->amount->value;
        $mixed = (null !== $opening && !$opening->amount->asset->equals($asset))
            || array_any($movementSums, static fn (AssetAmount $sum): bool => !$sum->asset->equals($asset));

        $movements = $mixed ? null : ([] === $movementSums ? DecimalValue::zero() : $movementSums[0]->value);

        $reason = match (true) {
            !$closingIsCurrent => ReconciliationReason::STALE_CLOSING_BALANCE,
            null === $opening => ReconciliationReason::MISSING_OPENING_BALANCE,
            $mixed => ReconciliationReason::MIXED_ASSETS,
            default => null,
        };

        $discrepancy = null === $reason && null !== $openingValue && null !== $movements
            ? ExactDecimal::subtract($closing->amount->value, ExactDecimal::add($openingValue, $movements))
            : null;

        return new self($asset, $closing->amount->value, $mixed ? null : $openingValue, $movements, $discrepancy, $reason);
    }

    public function isBalanced(): bool
    {
        return null !== $this->discrepancy && ExactDecimal::isZero($this->discrepancy);
    }

    /** @return list<ReconciliationResolution> */
    public function availableResolutions(): array
    {
        if (null === $this->discrepancy) {
            return [];
        }

        return $this->isBalanced()
            ? [ReconciliationResolution::MATCH]
            : [ReconciliationResolution::OVERRIDE, ReconciliationResolution::ADJUST];
    }
}
