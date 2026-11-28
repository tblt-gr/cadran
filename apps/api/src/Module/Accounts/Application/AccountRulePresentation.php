<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\CeilingComparison;
use App\Module\Catalog\Domain\AppliedRate;

/**
 * What sits beside resolved rules: the ceiling comparisons and applied rates
 * computed on the latest valuation, and the display names of whoever recorded
 * a local claim.
 */
final readonly class AccountRulePresentation
{
    /**
     * @param array<string, string>            $authorNames
     * @param array<string, CeilingComparison> $ceilingChecks keyed by rule kind
     * @param array<string, ?AppliedRate>      $appliedRates  keyed by rule kind
     */
    public function __construct(
        public array $authorNames,
        public array $ceilingChecks,
        public array $appliedRates,
    ) {
    }
}
