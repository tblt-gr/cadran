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
     * `verification` and `source` are null for a ceiling read from a
     * workspace product model: nobody published it, so grading how fresh it
     * is and naming a publication would fabricate a provenance the model
     * never had. A catalogue-sourced ceiling always carries both.
     */
    public function __construct(
        public RuleKind $kind,
        public AssetAmount $amount,
        public EffectivePeriod $period,
        public ?VerificationState $verification,
        public ?CatalogSource $source,
        private AssetCode $accountAsset,
    ) {
        if (!$kind->statesACeiling()) {
            throw new InvalidAccount(sprintf('A %s rule states no ceiling.', $kind->value));
        }

        if ((null === $verification) !== (null === $source)) {
            throw new InvalidAccount('A rule is either published or declared, never half of each.');
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
