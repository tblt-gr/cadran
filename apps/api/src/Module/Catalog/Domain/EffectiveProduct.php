<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * What the catalogue says about one product on one business date.
 *
 * `unavailableRuleKinds` is the reason a screen shows a dash rather than a
 * figure: the product is expected to carry that rule, and no sourced period
 * covers the date asked for. It is never rendered as zero.
 */
final readonly class EffectiveProduct
{
    /**
     * @param list<EffectiveRule> $rules
     * @param list<RuleKind>      $unavailableRuleKinds
     */
    public function __construct(
        public FinancialProduct $product,
        public \DateTimeImmutable $asOf,
        public array $rules,
        public array $unavailableRuleKinds,
    ) {
    }
}
