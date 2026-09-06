<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountValuation;

/**
 * Figures that sit beside resolved rules: the latest valuation used to
 * compare ceilings and apply a rate, and the display names of whoever
 * recorded a local claim.
 */
final readonly class AccountRulePresentation
{
    /**
     * @param array<string, string> $authorNames
     */
    public function __construct(
        public ?AccountValuation $valuation,
        public array $authorNames,
    ) {
    }
}
