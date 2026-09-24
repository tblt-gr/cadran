<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\NetWorthAmountView;
use App\Module\Accounts\Application\NetWorthView;

/**
 * The wealth card of one month: where the workspace stood at N−1, where it
 * stands at N, and what moved in between.
 *
 * Each of the three figures carries its own reason, because they fail for
 * different causes: a zero or negative base leaves the percentage absent while
 * the currency difference stays exact, and a missing valuation removes the
 * totals themselves. None of them is ever replaced by a zero.
 */
final readonly class MonthlyRecapNetWorthView
{
    /**
     * @param list<string> $previousSourceAccountIds
     * @param list<string> $currentSourceAccountIds
     */
    public function __construct(
        public ?NetWorthAmountView $previous,
        public ?string $previousReason,
        public ?NetWorthAmountView $current,
        public ?string $currentReason,
        public ?NetWorthAmountView $difference,
        public ?string $differenceReason,
        public ?string $changeRatio,
        public ?string $changePercent,
        public ?string $changePercentDisplay,
        public ?string $changeReason,
        public string $previousQuality,
        public ?int $previousStalestAgeDays,
        public int $previousMissingValuationCount,
        public int $previousStaleValuationCount,
        public string $quality,
        public ?int $stalestAgeDays,
        public int $eligibleAccountCount,
        public int $missingValuationCount,
        public int $staleValuationCount,
        public array $previousSourceAccountIds,
        public array $currentSourceAccountIds,
    ) {
    }

    public static function of(NetWorthView $netWorth): self
    {
        return new self(
            previous: $netWorth->delta->previousTotal,
            previousReason: $netWorth->delta->previousReason,
            current: $netWorth->total,
            currentReason: $netWorth->reason,
            difference: $netWorth->delta->amount,
            differenceReason: $netWorth->delta->amountReason,
            changeRatio: $netWorth->delta->rate,
            changePercent: $netWorth->delta->ratePercent,
            changePercentDisplay: $netWorth->delta->ratePercentDisplay,
            changeReason: $netWorth->delta->rateReason,
            previousQuality: $netWorth->delta->previousQuality,
            previousStalestAgeDays: $netWorth->delta->previousStalestAgeDays,
            previousMissingValuationCount: $netWorth->delta->previousMissingValuationCount,
            previousStaleValuationCount: $netWorth->delta->previousStaleValuationCount,
            quality: $netWorth->quality,
            stalestAgeDays: $netWorth->stalestAgeDays,
            eligibleAccountCount: $netWorth->eligibleAccountCount,
            missingValuationCount: $netWorth->missingValuationCount,
            staleValuationCount: $netWorth->staleValuationCount,
            previousSourceAccountIds: $netWorth->delta->previousSourceAccountIds,
            currentSourceAccountIds: $netWorth->delta->currentSourceAccountIds,
        );
    }
}
