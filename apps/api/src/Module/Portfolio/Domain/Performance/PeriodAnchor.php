<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\Valuation\PositionSnapshot;

/** A dated, complete valuation bounding a performance period, or the empty account an opening stands for. */
final readonly class PeriodAnchor
{
    /**
     * @param list<string>           $flags     valuation flags inherited as warnings
     * @param list<PositionSnapshot> $positions
     */
    private function __construct(
        public AnchorKind $kind,
        public ?string $valuationId,
        public \DateTimeImmutable $date,
        public DecimalValue $totalValue,
        public DecimalValue $securitiesValue,
        public ?string $detail,
        public array $flags,
        public array $positions,
    ) {
    }

    /**
     * @param list<string>           $flags
     * @param list<PositionSnapshot> $positions
     */
    public static function valuation(string $valuationId, \DateTimeImmutable $date, DecimalValue $totalValue, DecimalValue $securitiesValue, string $detail, array $flags, array $positions): self
    {
        return new self(AnchorKind::VALUATION, $valuationId, $date, $totalValue, $securitiesValue, $detail, $flags, $positions);
    }

    public static function opening(\DateTimeImmutable $date): self
    {
        return new self(AnchorKind::OPENING, null, $date, DecimalValue::zero(), DecimalValue::zero(), null, [], []);
    }

    /** Whether every held position carries a known average cost, the condition of an unrealized gain. */
    public function hasKnownCosts(): bool
    {
        if (AnchorKind::OPENING === $this->kind) {
            return true;
        }
        if ([] === $this->positions) {
            return 0 === $this->securitiesValue->compareTo(DecimalValue::zero());
        }
        foreach ($this->positions as $position) {
            if (null === $position->averageCostBasis) {
                return false;
            }
        }

        return true;
    }
}
