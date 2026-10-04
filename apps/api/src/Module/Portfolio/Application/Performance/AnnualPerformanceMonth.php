<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

/** One month of the annual table: only its status when it lies before the opening or in the future. */
final readonly class AnnualPerformanceMonth
{
    public const string CALCULATED = 'CALCULATED';
    public const string NOT_CALCULABLE = 'NOT_CALCULABLE';
    public const string BEFORE_OPENING = 'BEFORE_OPENING';
    public const string FUTURE = 'FUTURE';

    /**
     * @param self::* $status
     */
    public function __construct(
        public string $month,
        public string $status,
        public ?PortfolioPerformance $performance = null,
        public ?string $endCash = null,
        public ?string $modifiedDietzIndex = null,
    ) {
    }
}
