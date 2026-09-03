<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Domain\AccountCeiling;
use App\Module\Accounts\Domain\AccountRate;
use App\Module\Accounts\Domain\AccountRules;
use App\Module\Accounts\Domain\AccountTerm;
use App\Module\Catalog\Domain\CatalogSource;
use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\RateBracket;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\VerificationState;

/**
 * The wire shape of the rules in force for an account on a business date.
 *
 * Ceilings, rates and terms travel in three lists rather than one, because a
 * client that had to sort them by kind would be re-deriving the basis a
 * ceiling is measured on and whether a percentage is a scale — decisions the
 * server already made and must not delegate.
 */
final readonly class AccountRulesRepresentation
{
    /** @return array<string, mixed> */
    public static function of(AccountRules $rules): array
    {
        return [
            'accountId' => $rules->accountId,
            'assetCode' => $rules->assetCode->toString(),
            'productCode' => $rules->productCode?->toString(),
            'origin' => $rules->origin->value,
            'asOf' => $rules->asOf->format('Y-m-d'),
            'ceilings' => array_map(self::ceiling(...), $rules->ceilings),
            'rates' => array_map(self::rate(...), $rules->rates),
            'terms' => array_map(self::term(...), $rules->terms),
            // The kinds this account is expected to carry and that no sourced
            // period covers on this date. A screen shows them as unavailable;
            // it never shows them as zero.
            'unavailableRuleKinds' => array_map(
                static fn (RuleKind $kind): string => $kind->value,
                $rules->unavailableRuleKinds,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private static function ceiling(AccountCeiling $ceiling): array
    {
        return [
            'kind' => $ceiling->kind->value,
            // The figure this amount is compared to. Without it, the same
            // 22 950 € could be checked against a total balance and refuse a
            // passbook that only earned interest.
            'basis' => $ceiling->basis->value,
            'countsCreditedInterest' => $ceiling->basis->countsCreditedInterest(),
            'spansSeveralAccounts' => $ceiling->spansSeveralAccounts(),
            'measurable' => $ceiling->isMeasurable(),
            // Stated with the ceiling so no importer or screen decides on its
            // own to refuse a figure that legitimately passed it.
            'breachPolicy' => $ceiling->breachPolicy()->value,
            'amount' => [
                'value' => $ceiling->amount->value->toString(),
                'assetCode' => $ceiling->amount->asset->toString(),
            ],
            ...self::provenance($ceiling->period, $ceiling->verification, $ceiling->source),
        ];
    }

    /** @return array<string, mixed> */
    private static function rate(AccountRate $rate): array
    {
        return [
            'kind' => $rate->kind->value,
            'guaranteed' => $rate->guaranteed,
            'application' => $rate->scale->application->value,
            'brackets' => array_map(self::bracket(...), $rate->scale->brackets),
            ...self::provenance($rate->period, $rate->verification, $rate->source),
        ];
    }

    /** @return array<string, mixed> */
    private static function term(AccountTerm $term): array
    {
        return [
            'kind' => $term->kind->value,
            'token' => $term->token,
            ...self::provenance($term->period, $term->verification, $term->source),
        ];
    }

    /** @return array<string, mixed> */
    private static function bracket(RateBracket $bracket): array
    {
        return [
            // Expressed in percent as the source published it: `1.7` reads
            // 1.7 %, as a canonical string so no binary float touches a rate.
            'percentage' => $bracket->percentage->toString(),
            'lowerBound' => $bracket->lowerBound->toString(),
            'upperBound' => $bracket->upperBound?->toString(),
        ];
    }

    /**
     * The period a value covers, how fresh its verification is and where it
     * was read from. Every resolved rule carries all three: a figure without
     * its period and its publication cannot be checked by the holder.
     *
     * @return array<string, mixed>
     */
    private static function provenance(
        EffectivePeriod $period,
        VerificationState $verification,
        CatalogSource $source,
    ): array {
        return [
            'validFrom' => $period->validFrom->format('Y-m-d'),
            // An open end is in force for every later date, not an expiry.
            'validTo' => $period->validTo?->format('Y-m-d'),
            'verification' => $verification->value,
            'source' => [
                'publisher' => $source->publisher,
                'title' => $source->title,
                'url' => $source->url,
                'publishedOn' => $source->publishedOn?->format('Y-m-d'),
                'retrievedOn' => $source->retrievedOn->format('Y-m-d'),
            ],
        ];
    }
}
