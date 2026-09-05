<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\CatalogSource;
use App\Module\Catalog\Domain\CeilingBasis;
use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\VerificationState;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;

/**
 * A ceiling that applies to one account on one business date, together with
 * the figure it is measured against.
 *
 * The basis travels with the amount because the amount alone decides nothing:
 * 22 950 € checked against a total balance and 22 950 € checked against
 * deposits excluding interest give opposite verdicts on the same passbook.
 */
final readonly class AccountCeiling
{
    public CeilingBasis $basis;

    /**
     * `verification` and `source` are null for a ceiling that nobody
     * published: one read from a workspace product model, and one claimed by
     * an account override. Grading the freshness of a figure the holder typed
     * or naming a publication behind it would fabricate a provenance it never
     * had. A catalogue-sourced ceiling always carries both.
     *
     * `override` names the local claim when this ceiling is one, so a screen
     * can say who recorded it and why instead of showing an unexplained
     * divergence from the published figure beside it.
     */
    public function __construct(
        public RuleKind $kind,
        public AssetAmount $amount,
        public EffectivePeriod $period,
        public ?VerificationState $verification,
        public ?CatalogSource $source,
        private AssetCode $accountAsset,
        public ?AccountRuleOverride $override = null,
    ) {
        if (!$kind->statesACeiling()) {
            throw new InvalidAccount(sprintf('A %s rule states no ceiling.', $kind->value));
        }

        if ((null === $verification) !== (null === $source)) {
            throw new InvalidAccount('A rule is either published or declared, never half of each.');
        }

        if (null !== $override && null !== $source) {
            throw new InvalidAccount('A locally claimed ceiling names no publication.');
        }

        $this->basis = $kind->ceilingBasis();
    }

    /**
     * Whether the ceiling can be compared to this account at all. A ceiling
     * published in another unit than the account is denominated in would need
     * a conversion the application does not hold, so it is reported as not
     * comparable rather than checked against a figure it does not measure.
     */
    public function isMeasurable(): bool
    {
        return $this->amount->asset->equals($this->accountAsset);
    }

    /**
     * A combined ceiling is reached across the products sharing it, so reading
     * this account alone can never settle it.
     */
    public function spansSeveralAccounts(): bool
    {
        return $this->basis->spansSeveralAccounts();
    }
}
