<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\RuleKind;

/**
 * One term kind — how interest accrues, who is eligible, which tax regime
 * applies — seen through every authority that states it on a business date,
 * and which of them wins.
 */
final readonly class AccountTermRule
{
    public AccountRuleLayer $effectiveLayer;

    public function __construct(
        public RuleKind $kind,
        public ?AccountTerm $catalog,
        public ?AccountTerm $inherited,
        public ?AccountTerm $override,
    ) {
        if ($kind->statesACeiling() || $kind->statesARate()) {
            throw new InvalidAccount(sprintf('A %s rule is a ceiling or a rate, not a term.', $kind->value));
        }

        $this->effectiveLayer = AccountRuleLayers::effectiveLayer(
            $kind,
            null !== $catalog,
            null !== $inherited,
            null !== $override,
        );
    }

    public function effective(): AccountTerm
    {
        return $this->override ?? $this->inherited ?? $this->catalog
            ?? throw new \LogicException('A layered rule always carries at least one layer.');
    }
}
