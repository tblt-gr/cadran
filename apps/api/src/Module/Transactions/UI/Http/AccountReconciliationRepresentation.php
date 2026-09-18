<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Transactions\Application\Reconciliation\AccountReconciliationView;

final readonly class AccountReconciliationRepresentation
{
    /** @return array<string, mixed> */
    public static function one(AccountReconciliationView $view): array
    {
        return [
            'accountId' => $view->accountId,
            'snapshotId' => $view->snapshotId,
            'snapshotVersion' => $view->snapshotVersion,
            'reconciliationStatus' => $view->reconciliationStatus,
            'periodStart' => $view->periodStart,
            'periodEnd' => $view->periodEnd,
            'openingBalance' => $view->opening,
            'movementsTotal' => $view->movements,
            'closingBalance' => $view->closing,
            'discrepancy' => $view->discrepancy,
            'nonCalculableReason' => $view->reason,
            'pendingCount' => $view->pendingCount,
            'pendingTransactions' => array_map(TransactionRepresentation::one(...), $view->pendingTransactions),
            'availableResolutions' => $view->availableResolutions,
        ];
    }
}
