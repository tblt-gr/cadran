<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\ExactDecimal;

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
        public ?ValuationBasis $basis = null,
        public ?ValuationDerivation $derivation = null,
        public ?string $reason = null,
    ) {
    }

    public static function of(AccountBalanceSnapshots $snapshots, \DateTimeImmutable $asOf, ?AccountValuationMode $mode = null): self
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
            basis: AccountValuationMode::PORTFOLIO === $mode && BalanceSnapshotSource::CALCULATED === $latest->source ? ValuationBasis::PORTFOLIO_VALUATION : ValuationBasis::SNAPSHOT,
        );
    }

    public static function derived(AccountBalanceSnapshot $anchor, \DateTimeImmutable $on, MovementTotal $movements): self
    {
        if ($movements->foreignAsset) {
            return new self(null, $anchor->source, null, ValuationQuality::MISSING, $on, $anchor, reason: 'MIXED_ASSETS');
        }
        if (0 === $movements->bookedCount) {
            return self::of(new AccountBalanceSnapshots([$anchor]), $on);
        }
        if (null === $movements->total) {
            throw new \LogicException('A booked movement total must carry its amount.');
        }

        return new self(
            new AssetAmount(ExactDecimal::add($anchor->amount->value, $movements->total), $anchor->amount->asset),
            $anchor->source,
            0,
            ValuationQuality::CURRENT,
            $on,
            $anchor,
            ValuationBasis::SNAPSHOT_PLUS_MOVEMENTS,
            new ValuationDerivation($anchor->asOf, (int) $anchor->asOf->diff($on)->format('%a'), $movements->bookedCount, $movements->pendingCount),
        );
    }
}
