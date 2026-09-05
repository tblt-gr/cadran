<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Domain\AccountRuleOverride;
use App\Module\Accounts\Domain\AccountRuleOverrides;
use App\Module\Catalog\Domain\RateBracket;

/**
 * The wire shape of the dated rule values recorded on one account.
 *
 * An override travels in two forms. Whole, in the history of the account: the
 * value it claims, the dates it covers, who recorded it and why, and whether
 * it still stands. Reduced to a claim, beside a resolved rule: who and why,
 * without repeating the value the rule already carries.
 */
final readonly class AccountRuleOverrideRepresentation
{
    /** @return array<string, mixed> */
    public static function list(AccountRuleOverrides $overrides): array
    {
        return [
            'overrides' => array_map(self::one(...), $overrides->overrides),
        ];
    }

    /** @return array<string, mixed> */
    public static function one(AccountRuleOverride $override): array
    {
        $amount = $override->value->amount;
        $scale = $override->value->scale;

        return [
            'id' => $override->id,
            'accountId' => $override->accountId,
            'kind' => $override->kind->value,
            'valueType' => $override->value->type->value,
            // Exactly one of the three is set, decided by the rule kind. A
            // client reads the one its kind declares rather than guessing.
            'amount' => null === $amount ? null : [
                'value' => $amount->value->toString(),
                'assetCode' => $amount->asset->toString(),
            ],
            'text' => $override->value->text,
            'application' => $scale?->application->value,
            'brackets' => null === $scale ? null : array_map(self::bracket(...), $scale->brackets),
            'validFrom' => $override->period->validFrom->format('Y-m-d'),
            // An open end is in force for every later date, not an expiry.
            'validTo' => $override->period->validTo?->format('Y-m-d'),
            // A withdrawn override no longer answers on any date, including
            // past ones, and the inherited rule of each of those dates takes
            // over again. It stays here because the trail of what was claimed
            // is the provenance an override exists to preserve.
            'standing' => $override->isStanding(),
            'withdrawnAt' => $override->withdrawnAt?->format(\DATE_ATOM),
            'withdrawnBy' => $override->withdrawnBy,
            'reason' => $override->reason,
            'authorId' => $override->authorId,
            'recordedAt' => $override->recordedAt->format(\DATE_ATOM),
        ];
    }

    /**
     * Who claimed this value and why, without the value itself.
     *
     * @return array<string, mixed>
     */
    public static function claim(AccountRuleOverride $override): array
    {
        return [
            'overrideId' => $override->id,
            'reason' => $override->reason,
            'authorId' => $override->authorId,
            'recordedAt' => $override->recordedAt->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private static function bracket(RateBracket $bracket): array
    {
        return [
            'percentage' => $bracket->percentage->toString(),
            'lowerBound' => $bracket->lowerBound->toString(),
            'upperBound' => $bracket->upperBound?->toString(),
        ];
    }
}
