<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\EffectiveProduct;
use App\Module\Catalog\Domain\EffectiveRule;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\YieldKind;
use App\Module\Foundation\Domain\AssetCode;

/**
 * The rules in force for one account on one business date, each shown through
 * every authority that states it.
 *
 * The account stores no figure of its own except its overrides. A ceiling or a
 * rate is read on the date asked for, so a regulatory revision reaches every
 * account at once and reading a 2023 statement still shows the 2023 value.
 *
 * Three authorities can answer for the same rule and they are never merged
 * into one number:
 *
 * - `catalog` is what the system catalogue publishes, reached either through
 *   the account's own product reference or through the provenance of the
 *   workspace model it follows;
 * - `inherited` is what that workspace model says, present only when a model
 *   sits between the account and the catalogue;
 * - `override` is what was recorded on this account alone.
 *
 * The three lists are separate because ceilings, rates and terms answer
 * different questions and are checked against different figures.
 * `unavailableRuleKinds` is the fourth answer and the reason a screen shows a
 * dash: the account is expected to carry that rule and no layer covers this
 * date. A gap is never filled with zero, which would read as "no ceiling" or
 * "no interest" when the truth is "we do not know".
 */
final readonly class AccountRules
{
    /**
     * @param list<AccountCeilingRule> $ceilings
     * @param list<AccountRateRule>    $rates
     * @param list<AccountTermRule>    $terms
     * @param list<RuleKind>           $unavailableRuleKinds
     */
    private function __construct(
        public string $accountId,
        public AssetCode $assetCode,
        public ?ProductCode $productCode,
        public ?string $productModelId,
        public AccountRulesOrigin $origin,
        public \DateTimeImmutable $asOf,
        public array $ceilings,
        public array $rates,
        public array $terms,
        public array $unavailableRuleKinds,
    ) {
    }

    /**
     * An account described by hand inherits nothing. It reports no gap either:
     * no authority declares which rules it should carry, so there is nothing
     * to report as missing. It may still carry overrides recorded while it did
     * follow a product, and those keep answering.
     */
    public static function withoutProduct(Account $account, \DateTimeImmutable $asOf, AccountRuleOverrides $overrides): self
    {
        return self::build($account, AccountRulesOrigin::NO_PRODUCT, $asOf, null, null, $overrides);
    }

    /**
     * The catalogue stopped describing the product this account references. No
     * inherited rule is resolved and no gap is reported: without the product,
     * nothing says which rules were expected in the first place. Overrides
     * recorded while the product was still described keep answering, which is
     * the whole reason they are stored on the account rather than derived.
     */
    public static function withWithdrawnProduct(Account $account, \DateTimeImmutable $asOf, AccountRuleOverrides $overrides): self
    {
        return self::build($account, AccountRulesOrigin::PRODUCT_WITHDRAWN, $asOf, null, null, $overrides);
    }

    public static function fromProduct(Account $account, EffectiveProduct $effective, AccountRuleOverrides $overrides): self
    {
        return self::build(
            $account,
            AccountRulesOrigin::SYSTEM_CATALOG,
            $effective->asOf,
            $effective,
            null,
            $overrides,
        );
    }

    /**
     * Resolves the rules of a workspace product model, the counterpart of
     * {@see self::fromProduct()}. A model rule carries no publication, so
     * every ceiling, rate and term it states reads as self-declared: null
     * verification, null source. Archiving the model changes nothing here —
     * the account keeps resolving the same periods either way.
     *
     * `$catalogBehindModel` is the system product the model was copied from,
     * resolved on the same date. It is what lets a holder see that the model
     * they edited has drifted from the publication it started at, which a
     * model that stopped following its source cannot say on its own.
     */
    public static function fromModel(
        Account $account,
        EffectiveModel $effective,
        ?EffectiveProduct $catalogBehindModel,
        AccountRuleOverrides $overrides,
    ): self {
        return self::build(
            $account,
            AccountRulesOrigin::WORKSPACE_MODEL,
            $effective->asOf,
            $catalogBehindModel,
            $effective,
            $overrides,
        );
    }

    private static function build(
        Account $account,
        AccountRulesOrigin $origin,
        \DateTimeImmutable $asOf,
        ?EffectiveProduct $catalog,
        ?EffectiveModel $inherited,
        AccountRuleOverrides $overrides,
    ): self {
        $catalogRules = [];
        foreach (null === $catalog ? [] : $catalog->rules as $resolved) {
            $catalogRules[$resolved->rule->kind->value] = $resolved;
        }

        $inheritedRules = [];
        foreach (null === $inherited ? [] : $inherited->rules as $rule) {
            $inheritedRules[$rule->kind->value] = $rule;
        }

        $overrideRules = $overrides->effectiveOn($asOf);

        // The yield of the authority the account actually follows. It decides
        // whether a rate is owed to the holder, so an override is graded by
        // the same promise as the value it replaces rather than by its own
        // say-so. A minimum rate stays a contractual floor even with no
        // authority left; only the yield-derived kinds fall back to not
        // guaranteed when the product has been withdrawn.
        $authorityYield = $inherited?->model->yieldKind ?? $catalog?->product->yieldKind;

        $ceilings = [];
        $rates = [];
        $terms = [];
        foreach (RuleKind::cases() as $kind) {
            $catalogRule = $catalogRules[$kind->value] ?? null;
            $inheritedRule = $inheritedRules[$kind->value] ?? null;
            $override = $overrideRules[$kind->value] ?? null;

            if (null === $catalogRule && null === $inheritedRule && null === $override) {
                continue;
            }

            // A model-backed account stopped following the catalogue the day
            // the model was copied. A catalogue figure beside a model or
            // override figure is comparison. A catalogue figure for a kind
            // neither of them states is a gap, not a silent fallback onto a
            // publication the model never carried.
            if (null !== $inherited && null === $inheritedRule && null === $override) {
                continue;
            }

            match (true) {
                $kind->statesACeiling() => $ceilings[] = new AccountCeilingRule(
                    $kind,
                    null === $catalogRule ? null : self::publishedCeiling($catalogRule, $account->assetCode),
                    null === $inheritedRule ? null : self::declaredCeiling($inheritedRule->kind, $inheritedRule->value, $inheritedRule->period, $account->assetCode, null),
                    null === $override ? null : self::declaredCeiling($override->kind, $override->value, $override->period, $account->assetCode, $override),
                ),
                $kind->statesARate() => $rates[] = new AccountRateRule(
                    $kind,
                    null === $catalogRule ? null : self::publishedRate($catalogRule, $catalog?->product->yieldKind ?? YieldKind::NONE),
                    null === $inheritedRule ? null : self::declaredRate($inheritedRule->kind, $inheritedRule->value, $inheritedRule->period, $inherited?->model->yieldKind ?? YieldKind::NONE, null),
                    null === $override ? null : self::declaredRate($override->kind, $override->value, $override->period, $authorityYield ?? YieldKind::NONE, $override),
                ),
                default => $terms[] = new AccountTermRule(
                    $kind,
                    null === $catalogRule ? null : self::publishedTerm($catalogRule),
                    null === $inheritedRule ? null : self::declaredTerm($inheritedRule->kind, $inheritedRule->value, $inheritedRule->period, null),
                    null === $override ? null : self::declaredTerm($override->kind, $override->value, $override->period, $override),
                ),
            };
        }

        // The gaps belong to the authority the account follows, which is the
        // model when there is one: a catalogue read only for comparison does
        // not decide what this account is expected to carry.
        $expectedGaps = [];
        if (null !== $inherited) {
            $expectedGaps = $inherited->unavailableRuleKinds;
        } elseif (null !== $catalog) {
            $expectedGaps = $catalog->unavailableRuleKinds;
        }

        // Minus every kind an override answers for on this date: a rule the
        // holder recorded locally is known, whatever the catalogue failed to
        // publish.
        $unavailable = array_values(array_filter(
            $expectedGaps,
            static fn (RuleKind $kind): bool => !isset($overrideRules[$kind->value]),
        ));

        return new self(
            accountId: $account->id,
            assetCode: $account->assetCode,
            productCode: $account->productCode ?? $catalog?->product->code,
            productModelId: $account->productModelId,
            origin: $origin,
            asOf: $asOf,
            ceilings: $ceilings,
            rates: $rates,
            terms: $terms,
            unavailableRuleKinds: $unavailable,
        );
    }

    private static function publishedCeiling(EffectiveRule $resolved, AssetCode $accountAsset): AccountCeiling
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
    private static function publishedRate(EffectiveRule $resolved, YieldKind $yieldKind): AccountRate
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

    private static function publishedTerm(EffectiveRule $resolved): AccountTerm
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

    private static function declaredCeiling(
        RuleKind $kind,
        DeclaredRuleValue $value,
        EffectivePeriod $period,
        AssetCode $accountAsset,
        ?AccountRuleOverride $override,
    ): AccountCeiling {
        return new AccountCeiling(
            kind: $kind,
            amount: $value->amount ?? throw self::malformed($kind),
            period: $period,
            verification: null,
            source: null,
            accountAsset: $accountAsset,
            override: $override,
        );
    }

    private static function declaredRate(
        RuleKind $kind,
        DeclaredRuleValue $value,
        EffectivePeriod $period,
        YieldKind $yieldKind,
        ?AccountRuleOverride $override,
    ): AccountRate {
        return new AccountRate(
            kind: $kind,
            scale: $value->scale ?? throw self::malformed($kind),
            guaranteed: $kind->rateIsOwedToTheHolder($yieldKind),
            period: $period,
            verification: null,
            source: null,
            override: $override,
        );
    }

    private static function declaredTerm(
        RuleKind $kind,
        DeclaredRuleValue $value,
        EffectivePeriod $period,
        ?AccountRuleOverride $override,
    ): AccountTerm {
        return new AccountTerm(
            kind: $kind,
            token: $value->text ?? throw self::malformed($kind),
            period: $period,
            verification: null,
            source: null,
            override: $override,
        );
    }

    /**
     * A catalogue rule, a model period and an override all carry the value
     * shape their kind declares; each refuses to exist otherwise. Reaching
     * here would mean the invariant was bypassed, not that a client sent
     * something wrong.
     */
    private static function malformed(RuleKind $kind): \LogicException
    {
        return new \LogicException(sprintf('A %s rule reached resolution without its value.', $kind->value));
    }
}
