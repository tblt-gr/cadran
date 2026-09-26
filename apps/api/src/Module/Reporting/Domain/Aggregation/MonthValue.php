<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

/** One month of one column. A null value is unknown, never zero. */
final readonly class MonthValue
{
    public function __construct(
        public CalendarMonth $month,
        public MonthState $state,
        public ?DecimalValue $value,
        public ?AssetCode $asset = null,
        public ?string $reason = null,
        public ?int $policyVersion = null,
    ) {
    }
}
