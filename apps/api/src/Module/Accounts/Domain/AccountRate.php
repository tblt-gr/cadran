<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\CatalogSource;
use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\RateScale;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\VerificationState;

/**
 * A rate that applies to one account on one business date, always as a bracket
 * scale.
 *
 * `guaranteed` is stated here rather than left to the reader: a regulated or
 * contractually fixed rate is owed to the holder, while a revisable one is
 * merely the rate published today and must never be shown as an acquired
 * return.
 */
final readonly class AccountRate
{
    /**
     * `verification` and `source` are null for a rate that nobody published:
     * one read from a workspace product model, and one claimed by an account
     * override. Grading the freshness of a figure the holder typed or naming a
     * publication behind it would fabricate a provenance it never had. A
     * catalogue-sourced rate always carries both.
     *
     * `override` names the local claim when this rate is one. It matters more
     * here than anywhere else: a rate typed by the holder and a rate published
     * by the regulator must never read the same, whatever `guaranteed` says.
     */
    public function __construct(
        public RuleKind $kind,
        public RateScale $scale,
        public bool $guaranteed,
        public EffectivePeriod $period,
        public ?VerificationState $verification,
        public ?CatalogSource $source,
        public ?AccountRuleOverride $override = null,
    ) {
        if (!$kind->statesARate()) {
            throw new InvalidAccount(sprintf('A %s rule states no rate.', $kind->value));
        }

        if ((null === $verification) !== (null === $source)) {
            throw new InvalidAccount('A rule is either published or declared, never half of each.');
        }

        if (null !== $override && null !== $source) {
            throw new InvalidAccount('A locally claimed rate names no publication.');
        }
    }
}
