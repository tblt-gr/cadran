<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\CeilingBasis;
use App\Module\Catalog\Domain\RuleKind;

/**
 * One ceiling kind seen through every authority that states it on a business
 * date, and which of them wins.
 *
 * The layers are not collapsed into the winning figure, because the point of
 * an override is that the divergence stays readable. A holder must be able to
 * see 22 950 € published, 25 000 € on the model they copied it into, and
 * 30 000 € claimed on this account alone, rather than one number with no way
 * of telling which of the three it is.
 *
 * `catalog` is present only when a system product is somewhere behind the
 * account: named by the account itself, or recorded as the provenance of the
 * workspace model it follows. `inherited` is present only when that authority
 * is a workspace model. They are never both a restatement of the same figure.
 */
final readonly class AccountCeilingRule
{
    public CeilingBasis $basis;
    public AccountRuleLayer $effectiveLayer;

    public function __construct(
        public RuleKind $kind,
        public ?AccountCeiling $catalog,
        public ?AccountCeiling $inherited,
        public ?AccountCeiling $override,
    ) {
        if (!$kind->statesACeiling()) {
            throw new InvalidAccount(sprintf('A %s rule states no ceiling.', $kind->value));
        }

        $this->effectiveLayer = AccountRuleLayers::effectiveLayer(
            $kind,
            null !== $catalog,
            null !== $inherited,
            null !== $override,
        );
        $this->basis = $kind->ceilingBasis();
    }

    /** The ceiling actually in force: the local claim first, then what the account follows. */
    public function effective(): AccountCeiling
    {
        return $this->override ?? $this->inherited ?? $this->catalog
            ?? throw new \LogicException('A layered rule always carries at least one layer.');
    }
}
