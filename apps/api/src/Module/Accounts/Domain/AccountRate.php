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
    public function __construct(
        public RuleKind $kind,
        public RateScale $scale,
        public bool $guaranteed,
        public EffectivePeriod $period,
        public VerificationState $verification,
        public CatalogSource $source,
    ) {
        if (!$kind->statesARate()) {
            throw new InvalidAccount(sprintf('A %s rule states no rate.', $kind->value));
        }
    }
}
