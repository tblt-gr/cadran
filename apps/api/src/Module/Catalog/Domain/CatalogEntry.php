<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * A product together with every dated rule the catalogue records for it.
 *
 * This is where the promise-of-return invariant lives: a market or
 * manually-valued product may not carry a rate rule at all, so no screen and
 * no later estimation can turn an assumption into a guaranteed yield.
 */
final readonly class CatalogEntry
{
    public function __construct(
        public FinancialProduct $product,
        public RuleSchedule $schedule,
    ) {
        if (!$product->yieldKind->acceptsRateRule() && $schedule->hasRateRule()) {
            throw new InvalidCatalogEntry(sprintf('A %s product carries no rate rule.', $product->yieldKind->value));
        }

        foreach ($schedule->rules as $rule) {
            $required = $rule->kind->requiredCapability();
            if (null !== $required && !$product->capabilities->contains($required)) {
                throw new InvalidCatalogEntry(sprintf('%s rules require %s.', $rule->kind->value, $required->value));
            }
        }
    }

    /**
     * Resolves the rules applying on $businessDate. The business date drives
     * the selection; $today only decides how fresh each verification looks.
     */
    public function effectiveOn(\DateTimeImmutable $businessDate, \DateTimeImmutable $today): EffectiveProduct
    {
        $effective = $this->schedule->effectiveOn($businessDate);

        $resolved = [];
        $kindsFound = [];
        foreach ($effective as $rule) {
            $resolved[] = new EffectiveRule($rule, $rule->verificationOn($today));
            $kindsFound[] = $rule->kind;
        }

        $unavailable = [];
        $expectedKinds = [
            ...$this->product->wrapperKind->expectedRuleKinds(),
            ...$this->product->yieldKind->expectedRuleKinds(),
        ];
        foreach ($expectedKinds as $expected) {
            if (!in_array($expected, $kindsFound, true)) {
                $unavailable[] = $expected;
            }
        }

        return new EffectiveProduct(
            product: $this->product,
            asOf: $businessDate,
            rules: $resolved,
            unavailableRuleKinds: $unavailable,
        );
    }
}
