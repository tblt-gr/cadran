<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\EmergencyFund;

/** The emergency-fund ratio of one as-of date with every source account and reference month it read. */
final readonly class EmergencyFundCoverageView
{
    /**
     * @param list<array{id: string, label: string, value: ?string, assetCode: ?string, valuedOn: ?string, stale: bool}> $accounts
     * @param list<array{month: string, reason: string}>                                                                 $excludedMonths
     * @param list<array{month: string, value: ?string}>                                                                 $months
     */
    public function __construct(
        public string $asOf,
        public ?string $liquidAssets,
        public ?string $liquidAssetCode,
        public ?string $liquidReason,
        public array $accounts,
        public string $referenceStart,
        public string $referenceEnd,
        public int $usedMonths,
        public int $expectedMonths,
        public array $excludedMonths,
        public ?string $average,
        public ?string $averageReason,
        public ?string $referenceAssetCode,
        public array $months,
        public string $targetMonths,
        public ?string $requiredAmount,
        public ?string $gap,
        public ?string $coveredMonths,
        public ?string $coveredMonthsDisplay,
        public ?string $reason,
        public string $quality,
    ) {
    }
}
