<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

/** Blocking and warning codes of every performance figure; later PERF tickets add cases. */
enum PerformanceReason: string
{
    case NO_START_VALUATION = 'NO_START_VALUATION';
    case NO_END_VALUATION = 'NO_END_VALUATION';
    case START_VALUATION_PARTIAL = 'START_VALUATION_PARTIAL';
    case END_VALUATION_PARTIAL = 'END_VALUATION_PARTIAL';
    case UNVALUED_SECURITY_TRANSFER = 'UNVALUED_SECURITY_TRANSFER';
    case UNKNOWN_LOT_COST = 'UNKNOWN_LOT_COST';
    case STALE_PRICES = 'STALE_PRICES';
    case CASH_DIFFERENCE = 'CASH_DIFFERENCE';
    case OBSERVED_TOTAL_DIFFERENCE = 'OBSERVED_TOTAL_DIFFERENCE';
    case POSITIONS_CHANGED = 'POSITIONS_CHANGED';
    case NEGATIVE_SECURITIES_VALUE = 'NEGATIVE_SECURITIES_VALUE';
    case PERIOD_IN_PROGRESS = 'PERIOD_IN_PROGRESS';
    case DECOMPOSITION_RESIDUAL = 'DECOMPOSITION_RESIDUAL';

    public function isBlocking(): bool
    {
        return in_array($this, [
            self::NO_START_VALUATION, self::NO_END_VALUATION, self::START_VALUATION_PARTIAL,
            self::END_VALUATION_PARTIAL, self::UNVALUED_SECURITY_TRANSFER,
        ], true);
    }
}
