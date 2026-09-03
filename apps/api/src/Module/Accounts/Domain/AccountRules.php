<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\EffectiveProduct;
use App\Module\Catalog\Domain\EffectiveRule;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\YieldKind;
use App\Module\Foundation\Domain\AssetCode;

/**
 * The rules in force for one account on one business date.
 *
 * The account stores none of this. Everything here is read from the catalogue
 * on the date asked for, so a regulatory revision reaches every account at
 * once and reading a 2023 statement still shows the 2023 ceiling and rate.
 *
 * The three lists are separate because the three answer different questions
 * and are checked against different figures. `unavailableRuleKinds` is the
 * fourth answer and the reason a screen shows a dash: the product is expected
 * to carry that rule and no sourced period covers this date. A gap is never
 * filled with zero, which would read as "no ceiling" or "no interest" when the
 * truth is "we do not know".
 */
final readonly class AccountRules
{
    /**
     * @param list<AccountCeiling> $ceilings
     * @param list<AccountRate>    $rates
     * @param list<AccountTerm>    $terms
     * @param list<RuleKind>       $unavailableRuleKinds
     */
    private function __construct(
        public string $accountId,
        public AssetCode $assetCode,
        public ?ProductCode $productCode,
        public AccountRulesOrigin $origin,
        public \DateTimeImmutable $asOf,
        public array $ceilings,
        public array $rates,
        public array $terms,
        public array $unavailableRuleKinds,
    ) {
    }

    /**
     * An account described by hand inherits nothing. It has no gaps either:
     * no product declares which rules it should carry, so there is nothing to
     * report as missing.
     */
    public static function withoutProduct(Account $account, \DateTimeImmutable $asOf): self
    {
        return new self(
            $account->id,
            $account->assetCode,
            null,
            AccountRulesOrigin::NO_PRODUCT,
            $asOf,
            [],
            [],
            [],
            [],
        );
    }

    /**
     * The catalogue stopped describing the product this account references. No
     * rule is resolved and no gap is reported: without the product, nothing
     * says which rules were expected in the first place.
     */
    public static function withWithdrawnProduct(Account $account, ProductCode $productCode, \DateTimeImmutable $asOf): self
    {
        return new self(
            $account->id,
            $account->assetCode,
            $productCode,
            AccountRulesOrigin::PRODUCT_WITHDRAWN,
            $asOf,
            [],
            [],
            [],
            [],
        );
    }

    public static function fromProduct(Account $account, EffectiveProduct $effective): self
    {
        $ceilings = [];
        $rates = [];
        $terms = [];

        foreach ($effective->rules as $resolved) {
            $rule = $resolved->rule;

            match (true) {
                $rule->kind->statesACeiling() => $ceilings[] = self::ceiling($resolved, $account->assetCode),
                $rule->kind->statesARate() => $rates[] = self::rate($resolved, $effective->product->yieldKind),
                default => $terms[] = self::term($resolved),
            };
        }

        return new self(
            accountId: $account->id,
            assetCode: $account->assetCode,
            productCode: $effective->product->code,
            origin: AccountRulesOrigin::SYSTEM_CATALOG,
            asOf: $effective->asOf,
            ceilings: $ceilings,
            rates: $rates,
            terms: $terms,
            unavailableRuleKinds: $effective->unavailableRuleKinds,
        );
    }

    private static function ceiling(EffectiveRule $resolved, AssetCode $accountAsset): AccountCeiling
    {
        $rule = $resolved->rule;

        return new AccountCeiling(
            kind: $rule->kind,
            amount: $rule->value->amount ?? throw self::malformed($rule->kind),
            period: $rule->period,
            verification: $resolved->verification,
            source: $rule->source,
            accountAsset: $accountAsset,
        );
    }

    /**
     * The yield travels here rather than a precomputed flag because whether a
     * rate is owed to the holder is decided by the rule kind and the yield
     * together: a minimum rate is a contractual floor even on a revisable
     * product, and reading the yield alone would present that floor as merely
     * the rate published today.
     */
    private static function rate(EffectiveRule $resolved, YieldKind $yieldKind): AccountRate
    {
        $rule = $resolved->rule;

        return new AccountRate(
            kind: $rule->kind,
            scale: $rule->rateScale() ?? throw self::malformed($rule->kind),
            guaranteed: $rule->kind->rateIsOwedToTheHolder($yieldKind),
            period: $rule->period,
            verification: $resolved->verification,
            source: $rule->source,
        );
    }

    private static function term(EffectiveRule $resolved): AccountTerm
    {
        $rule = $resolved->rule;

        return new AccountTerm(
            kind: $rule->kind,
            token: $rule->value->text ?? throw self::malformed($rule->kind),
            period: $rule->period,
            verification: $resolved->verification,
            source: $rule->source,
        );
    }

    /**
     * A catalogue rule always carries the value shape its kind declares; the
     * entry refuses to exist otherwise. Reaching here would mean the invariant
     * was bypassed, not that a client sent something wrong.
     */
    private static function malformed(RuleKind $kind): \LogicException
    {
        return new \LogicException(sprintf('A %s rule reached resolution without its value.', $kind->value));
    }
}
