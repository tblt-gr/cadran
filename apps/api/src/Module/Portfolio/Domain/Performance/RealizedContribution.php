<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Portfolio\Domain\LegDirection;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\Positions\PositionLedgerResult;

/** The same realized contribution for the account decomposition and an individual instrument. */
final class RealizedContribution
{
    public static function of(PortfolioOperation $operation, PositionLedgerResult $ledger, ?string $instrumentId = null): ?CalculatedDecimal
    {
        $details = $operation->details;
        $realized = CalculatedDecimal::zero();
        foreach ($details->legs as $leg) {
            if (LegDirection::OUT !== $leg->direction || (null !== $instrumentId && $leg->instrumentId !== $instrumentId)) {
                continue;
            }
            $released = $ledger->averageCostReleased[$operation->id.':'.$leg->index] ?? null;
            if (null === $released) {
                return null;
            }
            $realized = $realized->add(CalculatedDecimal::fromDecimal($leg->amount)->subtract($released));
        }
        if (null !== $instrumentId && $details->instrumentId !== $instrumentId) {
            return $realized;
        }
        if (null !== $details->supportDeduction()) {
            $released = $ledger->averageCostReleased[$operation->id] ?? null;
            $amount = $details->fees ?? $details->taxes;
            if (null === $released || null === $amount) {
                return null;
            }
            $realized = $realized->add(CalculatedDecimal::fromDecimal($amount)->subtract($released));
        }
        if (OperationType::SELL === $details->type) {
            $released = $ledger->averageCostReleased[$operation->id] ?? null;
            if (null === $released || null === $details->grossAmount) {
                return null;
            }
            $realized = $realized->add(CalculatedDecimal::fromDecimal($details->grossAmount)->subtract($released));
        }

        return $realized;
    }
}
