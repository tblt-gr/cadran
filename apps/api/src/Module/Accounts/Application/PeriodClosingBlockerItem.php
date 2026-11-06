<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\PeriodClosingReason;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\AssetAmount;

/** One account standing in the way of a closing and the precise reason why. */
final readonly class PeriodClosingBlockerItem
{
    /**
     * @param ?\DateTimeImmutable $snapshotAsOf the latest balance of the month examined, null when there is none
     * @param ?AssetAmount        $discrepancy  signed `observed - computed` in the account's asset, only for a discrepancy
     */
    public function __construct(
        public string $accountId,
        public string $label,
        public AccountKind $kind,
        public AccountValuationMode $valuationMode,
        public PeriodClosingReason $reason,
        public ?\DateTimeImmutable $snapshotAsOf,
        public ?AssetAmount $discrepancy,
    ) {
    }
}
