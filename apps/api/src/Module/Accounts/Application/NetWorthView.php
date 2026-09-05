<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\NetWorth;
use App\Module\Reference\Domain\Asset;

/**
 * The explainable net worth of one workspace on one business day: the figure,
 * why it is missing when it is, how fresh it is, what moved since the compared
 * day, and every source behind it.
 */
final readonly class NetWorthView
{
    /**
     * @param list<NetWorthContributionView> $contributions
     * @param list<NetWorthAllocationView>   $allocation
     */
    public function __construct(
        public string $asOf,
        public ?NetWorthAmountView $total,
        public ?string $reason,
        public string $quality,
        public ?int $stalestAgeDays,
        public int $eligibleAccountCount,
        public int $valuedAccountCount,
        public int $missingValuationCount,
        public int $staleValuationCount,
        public NetWorthDeltaView $delta,
        public array $contributions,
        public array $allocation,
    ) {
    }

    /**
     * @param list<NetWorthContributionView> $contributions
     * @param list<NetWorthAllocationView>   $allocation
     */
    public static function of(
        NetWorth $netWorth,
        NetWorthDeltaView $delta,
        array $contributions,
        array $allocation,
        ?Asset $reference,
    ): self {
        return new self(
            asOf: $netWorth->asOf->format('Y-m-d'),
            total: NetWorthAmountView::of($netWorth->total, $netWorth->asset, $reference),
            reason: $netWorth->reason?->value,
            quality: $netWorth->quality->value,
            stalestAgeDays: $netWorth->stalestAgeDays,
            eligibleAccountCount: $netWorth->eligibleAccountCount,
            valuedAccountCount: $netWorth->valuedAccountCount,
            missingValuationCount: $netWorth->missingValuationCount,
            staleValuationCount: $netWorth->staleValuationCount,
            delta: $delta,
            contributions: $contributions,
            allocation: $allocation,
        );
    }
}
