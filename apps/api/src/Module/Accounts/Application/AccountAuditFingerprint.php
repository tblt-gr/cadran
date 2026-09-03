<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;

/**
 * What the audit trail is allowed to remember about an account.
 *
 * The label, the masked identifier and any future balance are financial data
 * and stay out of the trail; only the structural attributes a reviewer needs
 * to explain a change travel here. Keeping the projection in one place is what
 * stops a new writer from adding a field to its own diff without noticing.
 */
final class AccountAuditFingerprint
{
    /** @return array<string, bool|int|string|null> */
    public static function of(Account $account): array
    {
        return [
            'kind' => $account->kind->value,
            // The catalogue code is a system reference, not workspace data, so
            // it is the one product attribute a reviewer may read here. The
            // institution is not: it names where the money sits.
            'productCode' => $account->productCode?->toString(),
            'institutionKnown' => null !== $account->institution,
            'assetCode' => $account->assetCode->toString(),
            'valuationMode' => $account->valuationMode->value,
            'liquidityLevel' => $account->liquidityLevel->value,
            'includeInNetWorth' => $account->includeInNetWorth,
            'includeInEmergencyFund' => $account->includeInEmergencyFund,
            'identified' => null !== $account->maskedIdentifier,
            'closed' => $account->isClosed(),
            'version' => $account->version,
        ];
    }
}
