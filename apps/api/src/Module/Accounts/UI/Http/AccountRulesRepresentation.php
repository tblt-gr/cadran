<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Domain\AccountCeiling;
use App\Module\Accounts\Domain\AccountCeilingRule;
use App\Module\Accounts\Domain\AccountRate;
use App\Module\Accounts\Domain\AccountRateRule;
use App\Module\Accounts\Domain\AccountRuleOverride;
use App\Module\Accounts\Domain\AccountRules;
use App\Module\Accounts\Domain\AccountTerm;
use App\Module\Accounts\Domain\AccountTermRule;
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
 *
 * Each entry states the same rule as every authority sees it. The catalogue
 * layer, the inherited layer and the local override travel side by side and
 * are never merged into the winning figure: a client that received one number
 * could not tell a published ceiling from one the holder typed, which is the
 * confusion an override must not create. `effectiveLayer` names the one that
 * wins, so no client re-implements the precedence either.
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
            'productModelId' => $rules->productModelId,
            'origin' => $rules->origin->value,
            'asOf' => $rules->asOf->format('Y-m-d'),
            'ceilings' => array_map(self::ceilingRule(...), $rules->ceilings),
            'rates' => array_map(self::rateRule(...), $rules->rates),
            'terms' => array_map(self::termRule(...), $rules->terms),
            // The kinds this account is expected to carry and that no layer
            // covers on this date. A screen shows them as unavailable; it never
            // shows them as zero.
            'unavailableRuleKinds' => array_map(
                static fn (RuleKind $kind): string => $kind->value,
                $rules->unavailableRuleKinds,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private static function ceilingRule(AccountCeilingRule $rule): array
    {
        return [
            'kind' => $rule->kind->value,
            // The figure the amounts below are compared to. Without it, the
            // same 22 950 € could be checked against a total balance and refuse
            // a passbook that only earned interest. It is a property of the
            // rule kind, so it is identical on every layer.
            'basis' => $rule->basis->value,
            'countsCreditedInterest' => $rule->basis->countsCreditedInterest(),
            'spansSeveralAccounts' => $rule->basis->spansSeveralAccounts(),
            'effectiveLayer' => $rule->effectiveLayer->value,
            'catalog' => null === $rule->catalog ? null : self::ceiling($rule->catalog),
            'inherited' => null === $rule->inherited ? null : self::ceiling($rule->inherited),
            'override' => null === $rule->override ? null : self::ceiling($rule->override),
        ];
    }

    /** @return array<string, mixed> */
    private static function rateRule(AccountRateRule $rule): array
    {
        return [
            'kind' => $rule->kind->value,
            'effectiveLayer' => $rule->effectiveLayer->value,
            'catalog' => null === $rule->catalog ? null : self::rate($rule->catalog),
            'inherited' => null === $rule->inherited ? null : self::rate($rule->inherited),
            'override' => null === $rule->override ? null : self::rate($rule->override),
        ];
    }

    /** @return array<string, mixed> */
    private static function termRule(AccountTermRule $rule): array
    {
        return [
            'kind' => $rule->kind->value,
            'effectiveLayer' => $rule->effectiveLayer->value,
            'catalog' => null === $rule->catalog ? null : self::term($rule->catalog),
            'inherited' => null === $rule->inherited ? null : self::term($rule->inherited),
            'override' => null === $rule->override ? null : self::term($rule->override),
        ];
    }

    /** @return array<string, mixed> */
    private static function ceiling(AccountCeiling $ceiling): array
    {
        return [
            'measurable' => $ceiling->isMeasurable(),
            'amount' => [
                'value' => $ceiling->amount->value->toString(),
                'assetCode' => $ceiling->amount->asset->toString(),
            ],
            ...self::provenance($ceiling->period, $ceiling->verification, $ceiling->source, $ceiling->override),
        ];
    }

    /** @return array<string, mixed> */
    private static function rate(AccountRate $rate): array
    {
        return [
            'guaranteed' => $rate->guaranteed,
            'application' => $rate->scale->application->value,
            'brackets' => array_map(self::bracket(...), $rate->scale->brackets),
            ...self::provenance($rate->period, $rate->verification, $rate->source, $rate->override),
        ];
    }

    /** @return array<string, mixed> */
    private static function term(AccountTerm $term): array
    {
        return [
            'token' => $term->token,
            ...self::provenance($term->period, $term->verification, $term->source, $term->override),
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
     * The period a value covers, how fresh its verification is, where it was
     * read from, and — when nobody read it anywhere — who claimed it.
     *
     * A catalogue-sourced rule carries the verification and the source; a rule
     * read from a workspace product model carries neither, because nobody
     * published it and grading its freshness would fabricate a provenance it
     * never had. A local override carries neither either, and carries instead
     * the claim that explains it: who recorded it, when, and why.
     *
     * @return array<string, mixed>
     */
    private static function provenance(
        EffectivePeriod $period,
        ?VerificationState $verification,
        ?CatalogSource $source,
        ?AccountRuleOverride $override,
    ): array {
        return [
            'validFrom' => $period->validFrom->format('Y-m-d'),
            // An open end is in force for every later date, not an expiry.
            'validTo' => $period->validTo?->format('Y-m-d'),
            'verification' => $verification?->value,
            'source' => null === $source ? null : [
                'publisher' => $source->publisher,
                'title' => $source->title,
                'url' => $source->url,
                'publishedOn' => $source->publishedOn?->format('Y-m-d'),
                'retrievedOn' => $source->retrievedOn->format('Y-m-d'),
            ],
            'claim' => null === $override ? null : AccountRuleOverrideRepresentation::claim($override),
        ];
    }
}
