<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Reconciliation;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;
use App\Module\Transactions\Domain\Reconciliation\ReconciliationResolution;

/**
 * Structural facts only: no figure, no label. Whether the discrepancy was
 * zero is the single signal a reviewer needs to tell an override from a match.
 */
final readonly class AccountReconciliationAuditFingerprint
{
    /** @return array<string, scalar|null> */
    public static function before(AccountBalanceSnapshot $snapshot): array
    {
        return [
            'asOf' => $snapshot->asOf->format('Y-m-d'),
            'reconciliationStatus' => $snapshot->reconciliationStatus->value,
            'version' => $snapshot->version,
        ];
    }

    /** @return array<string, scalar|null> */
    public static function after(
        AccountBalanceSnapshot $snapshot,
        ReconciliationResolution $mode,
        \DateTimeImmutable $periodStart,
        int $pendingCount,
        bool $discrepancyWasZero,
        ?string $adjustmentId = null,
    ): array {
        return [
            ...self::before($snapshot),
            'mode' => $mode->value,
            'periodStart' => $periodStart->format('Y-m-d'),
            'pendingCount' => $pendingCount,
            'discrepancyWasZero' => $discrepancyWasZero,
            'adjusted' => ReconciliationResolution::ADJUST === $mode,
            'adjustmentTransactionId' => $adjustmentId,
        ];
    }
}
