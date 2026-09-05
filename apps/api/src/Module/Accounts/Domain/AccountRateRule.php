<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\RuleKind;

/**
 * One rate kind seen through every authority that states it on a business
 * date, and which of them wins.
 *
 * Keeping the layers apart matters most here. A rate the holder typed and a
 * rate the regulator published are worth different things, and showing only
 * the effective one would let a local claim inherit the credibility of the
 * publication it replaced.
 */
final readonly class AccountRateRule
{
    public AccountRuleLayer $effectiveLayer;

    public function __construct(
        public RuleKind $kind,
        public ?AccountRate $catalog,
        public ?AccountRate $inherited,
        public ?AccountRate $override,
    ) {
        if (!$kind->statesARate()) {
            throw new InvalidAccount(sprintf('A %s rule states no rate.', $kind->value));
        }

        $this->effectiveLayer = AccountRuleLayers::effectiveLayer(
            $kind,
            null !== $catalog,
            null !== $inherited,
            null !== $override,
        );
    }

    public function effective(): AccountRate
    {
        return $this->override ?? $this->inherited ?? $this->catalog
            ?? throw new \LogicException('A layered rule always carries at least one layer.');
    }
}
