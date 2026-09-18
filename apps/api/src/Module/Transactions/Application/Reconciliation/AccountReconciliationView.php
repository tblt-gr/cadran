<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Reconciliation;

use App\Module\Transactions\Application\TransactionView;

/**
 * Every figure is null when it cannot be calculated; {@see $reason} then says
 * why. Nothing here is a default zero.
 */
final readonly class AccountReconciliationView
{
    /**
     * @param array{value: string, assetCode: string}|null $opening
     * @param array{value: string, assetCode: string}|null $movements
     * @param array{value: string, assetCode: string}      $closing
     * @param array{value: string, assetCode: string}|null $discrepancy
     * @param list<TransactionView>                        $pendingTransactions
     * @param list<string>                                 $availableResolutions
     */
    public function __construct(
        public string $accountId,
        public string $snapshotId,
        public int $snapshotVersion,
        public string $reconciliationStatus,
        public string $periodStart,
        public string $periodEnd,
        public ?array $opening,
        public ?array $movements,
        public array $closing,
        public ?array $discrepancy,
        public ?string $reason,
        public int $pendingCount,
        public array $pendingTransactions,
        public array $availableResolutions,
    ) {
    }
}
