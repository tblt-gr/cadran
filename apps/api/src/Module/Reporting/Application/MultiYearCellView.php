<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/**
 * One year's cell of one column: its value with its reason, the STOCK period-end month, and the
 * change against the previous year. `changeReason` explains whichever of `change`/`changeRatio` is
 * null: `change` is checked first, so a null `change` always carries the reason for both.
 */
final readonly class MultiYearCellView
{
    public function __construct(
        public ?string $value,
        public ?string $reason,
        public ?string $asOf,
        public ?string $change,
        public ?string $changeRatio,
        public ?string $changeReason,
    ) {
    }
}
