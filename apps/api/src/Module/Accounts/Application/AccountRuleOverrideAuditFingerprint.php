<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountRuleOverride;

/**
 * What the audit trail is allowed to remember about an override.
 *
 * The value and the reason are deliberately absent. A ceiling amount, a rate
 * scale and the sentence explaining them are financial data, and the trail is
 * read in contexts the account itself is not. What a reviewer needs in order
 * to explain a change is which rule was claimed, over which dates, by whom,
 * and whether it still stands — all of which is here.
 */
final class AccountRuleOverrideAuditFingerprint
{
    /** @return array<string, bool|string|null> */
    public static function of(AccountRuleOverride $override): array
    {
        return [
            'accountId' => $override->accountId,
            'ruleKind' => $override->kind->value,
            'valueType' => $override->value->type->value,
            'validFrom' => $override->period->validFrom->format('Y-m-d'),
            'validTo' => $override->period->validTo?->format('Y-m-d'),
            'authorId' => $override->authorId,
            'reasonRecorded' => '' !== $override->reason,
            'standing' => $override->isStanding(),
            'withdrawnBy' => $override->withdrawnBy,
        ];
    }
}
