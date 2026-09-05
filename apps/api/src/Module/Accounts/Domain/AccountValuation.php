<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\AssetAmount;

/**
 * The latest valid snapshot on or before a requested date, with age and
 * quality. Absence is {@see ValuationQuality::MISSING} and a null amount:
 * inventing 0 would make an unvalued account look empty.
 */
final readonly class AccountValuation
{
    private function __construct(
        public ?AssetAmount $amount,
        public ?BalanceSnapshotSource $source,
        public ?int $ageDays,
        public ValuationQuality $quality,
        public \DateTimeImmutable $asOf,
        public ?AccountBalanceSnapshot $snapshot,
    ) {
    }

    public static function of(AccountBalanceSnapshots $snapshots, \DateTimeImmutable $asOf): self
    {
        $latest = $snapshots->latestActiveOn($asOf);
        if (null === $latest) {
            return new self(null, null, null, ValuationQuality::MISSING, $asOf, null);
        }

        $ageDays = (int) $latest->asOf->diff($asOf)->format('%a');

        return new self(
            amount: $latest->amount,
            source: $latest->source,
            ageDays: $ageDays,
            quality: 0 === $ageDays ? ValuationQuality::CURRENT : ValuationQuality::STALE,
            asOf: $latest->asOf,
            snapshot: $latest,
        );
    }
}
