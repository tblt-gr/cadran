<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * A dated observation of a portfolio account, frozen when it is recorded. It is never recomputed:
 * a change of history flags it, and only a new valuation replaces it. The one change a stored
 * valuation receives is its supersession marker.
 */
final readonly class Valuation
{
    /** @param list<PositionSnapshot> $positions */
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $accountId,
        public \DateTimeImmutable $valuedOn,
        public AssetCode $assetCode,
        public DecimalValue $cash,
        public DecimalValue $derivedCash,
        public CashSource $cashSource,
        public ?DecimalValue $securitiesValue,
        public ?DecimalValue $totalValue,
        public ?DecimalValue $observedTotal,
        public ValuationDetail $detail,
        public ValuationCompleteness $completeness,
        public ValuationSource $source,
        public ?string $note,
        public ?string $supersedesId,
        public ?\DateTimeImmutable $supersededAt,
        public bool $withdrawn,
        public \DateTimeImmutable $recordedAt,
        public string $recordedBy,
        public int $version,
        public array $positions,
    ) {
        if ($version < 1) {
            throw new \InvalidArgumentException('A valuation version is positive.');
        }
        if (null !== $note && ('' === $note || mb_strlen($note) > ValuationRules::MAX_NOTE_LENGTH)) {
            throw new \InvalidArgumentException('A valuation note holds 1 to 200 characters.');
        }
        if (ValuationCompleteness::COMPLETE === $completeness && (null === $totalValue || null === $securitiesValue)) {
            throw new \InvalidArgumentException('A complete valuation has a total and a securities value.');
        }
    }

    public function isActive(): bool
    {
        return null === $this->supersededAt;
    }

    /** The row leaves the floor untouched: only its supersession marker and version change. */
    public function supersededAt(\DateTimeImmutable $at, bool $withdrawn): self
    {
        return new self(
            $this->id, $this->workspace, $this->accountId, $this->valuedOn, $this->assetCode, $this->cash, $this->derivedCash,
            $this->cashSource, $this->securitiesValue, $this->totalValue, $this->observedTotal, $this->detail, $this->completeness,
            $this->source, $this->note, $this->supersedesId, $at, $withdrawn, $this->recordedAt, $this->recordedBy, $this->version + 1,
            $this->positions,
        );
    }

    /** Observed cash minus derived cash; null when the cash is the derived one. */
    public function cashDifference(): ?DecimalValue
    {
        return CashSource::OBSERVED === $this->cashSource ? ExactDecimal::subtract($this->cash, $this->derivedCash) : null;
    }

    /** Statement total minus the computed total, exact; null when either is missing. */
    public function observedTotalDifference(): ?DecimalValue
    {
        return null === $this->observedTotal || null === $this->totalValue ? null : ExactDecimal::subtract($this->observedTotal, $this->totalValue);
    }

    public function tolerance(int $displayPrecision): DecimalValue
    {
        $priced = count(array_filter($this->positions, static fn (PositionSnapshot $line): bool => null !== $line->unitPrice));

        return ValuationRules::roundingTolerance($priced, $displayPrecision);
    }

    public function priceQuality(): PriceQuality
    {
        if (ValuationDetail::TOTAL === $this->detail) {
            return PriceQuality::NOT_DETAILED;
        }

        return PriceQuality::worst(array_map(static fn (PositionSnapshot $line): PriceQuality => $line->priceQuality, $this->positions));
    }

    /**
     * The flags a stored valuation carries by itself; POSITIONS_CHANGED needs the operations and is added on read.
     *
     * @return list<ValuationFlag>
     */
    public function flags(int $displayPrecision): array
    {
        $flags = [];
        if (in_array(PriceQuality::STALE, array_map(static fn (PositionSnapshot $line): PriceQuality => $line->priceQuality, $this->positions), true)) {
            $flags[] = ValuationFlag::STALE_PRICES;
        }
        $cashDifference = $this->cashDifference();
        if (null !== $cashDifference && !ExactDecimal::isZero($cashDifference)) {
            $flags[] = ValuationFlag::CASH_DIFFERENCE;
        }
        $difference = $this->observedTotalDifference();
        if (null !== $difference && ExactDecimal::absolute($difference)->compareTo($this->tolerance($displayPrecision)) > 0) {
            $flags[] = ValuationFlag::OBSERVED_TOTAL_DIFFERENCE;
        }
        if (null !== $this->securitiesValue && $this->securitiesValue->isNegative()) {
            $flags[] = ValuationFlag::NEGATIVE_SECURITIES_VALUE;
        }

        return $flags;
    }
}
