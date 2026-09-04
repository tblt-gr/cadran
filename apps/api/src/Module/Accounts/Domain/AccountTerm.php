<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\CatalogSource;
use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\VerificationState;

/**
 * A dated rule of an account that states neither a ceiling nor a rate: how
 * interest accrues, who is eligible, which tax regime applies.
 *
 * The value stays the uppercase token the catalogue records. Regulatory
 * wording belongs to the publication it was read from and to the translation
 * catalogue, never to the API payload.
 */
final readonly class AccountTerm
{
    /**
     * `verification` and `source` are null for a term read from a workspace
     * product model: nobody published it, so grading how fresh it is and
     * naming a publication would fabricate a provenance the model never had.
     * A catalogue-sourced term always carries both.
     */
    public function __construct(
        public RuleKind $kind,
        public string $token,
        public EffectivePeriod $period,
        public ?VerificationState $verification,
        public ?CatalogSource $source,
    ) {
        if ($kind->statesACeiling() || $kind->statesARate()) {
            throw new InvalidAccount(sprintf('A %s rule is a ceiling or a rate, not a term.', $kind->value));
        }

        if ((null === $verification) !== (null === $source)) {
            throw new InvalidAccount('A rule is either published or declared, never half of each.');
        }
    }
}
