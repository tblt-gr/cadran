<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\EmergencyFund;

use App\Module\Reporting\Domain\Aggregation\ExcludedMonth;

/**
 * Covered months, required amount and gap of one as-of date. Figures are exact canonical strings
 * that may exceed the storage width, since none of them is ever stored; an unknown figure is null
 * with a reason, never zero.
 */
final readonly class EmergencyFundCoverage
{
    /**
     * @param list<LiquidAccount> $accounts
     * @param list<ExcludedMonth> $excludedMonths
     */
    public function __construct(
        public array $accounts,
        public ?string $liquidAssets,
        public ?string $liquidAssetCode,
        public ?string $liquidReason,
        public ?string $average,
        public ?string $averageReason,
        public ?string $referenceAssetCode,
        public int $usedMonths,
        public int $expectedMonths,
        public array $excludedMonths,
        public ?string $requiredAmount,
        public ?string $gap,
        public ?string $coveredMonths,
        public ?string $coveredMonthsDisplay,
        public ?string $reason,
        public string $quality,
    ) {
    }
}
