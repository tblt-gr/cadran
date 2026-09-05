<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

/**
 * The net worth of one workspace on one business day, with everything a
 * reader needs to challenge it: the contributing accounts, how fresh their
 * valuations are, and — when there is no figure — why.
 */
final readonly class NetWorth
{
    /**
     * @param list<NetWorthContribution> $contributions every account considered,
     *                                                  eligible or not
     */
    public function __construct(
        public \DateTimeImmutable $asOf,
        public ?DecimalValue $total,
        public ?AssetCode $asset,
        public ?NetWorthReason $reason,
        public ValuationQuality $quality,
        public ?int $stalestAgeDays,
        public int $eligibleAccountCount,
        public int $valuedAccountCount,
        public int $missingValuationCount,
        public int $staleValuationCount,
        public array $contributions,
    ) {
        if (null !== $reason && null !== $total) {
            throw new \InvalidArgumentException('A non-calculable net worth cannot carry a figure.');
        }

        if (null === $reason && (null === $total || null === $asset)) {
            throw new \InvalidArgumentException('A calculable net worth carries a total and its asset.');
        }
    }
}
