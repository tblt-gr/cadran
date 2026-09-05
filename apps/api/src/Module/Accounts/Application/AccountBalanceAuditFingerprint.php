<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountBalanceSnapshot;

/**
 * What the audit trail may remember about a snapshot.
 *
 * The amount and the comment are financial data and stay out of the trail.
 * Only the structural attributes a reviewer needs — which day, which source,
 * whether it still answers — travel here.
 */
final class AccountBalanceAuditFingerprint
{
    /** @return array<string, bool|int|string|null> */
    public static function of(AccountBalanceSnapshot $snapshot): array
    {
        return [
            'asOf' => $snapshot->asOf->format('Y-m-d'),
            'source' => $snapshot->source->value,
            'reconciliationStatus' => $snapshot->reconciliationStatus->value,
            'commented' => null !== $snapshot->comment,
            'active' => $snapshot->isActive(),
            'version' => $snapshot->version,
        ];
    }
}
