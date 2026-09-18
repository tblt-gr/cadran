<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Reconciliation;

final readonly class ReconcileAccountBalanceInput
{
    public function __construct(
        public string $snapshotId,
        public int $snapshotVersion,
        public string $periodStart,
        public string $resolution,
    ) {
    }
}
