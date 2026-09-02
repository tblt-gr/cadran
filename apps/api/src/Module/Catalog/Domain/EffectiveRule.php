<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * A rule applying on a business date, paired with how fresh its verification
 * is today. Both figures are produced here so no consumer has to decide what
 * "stale" means.
 */
final readonly class EffectiveRule
{
    public function __construct(
        public ProductRule $rule,
        public VerificationState $verification,
    ) {
    }
}
