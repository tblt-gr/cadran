<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application\Facade;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

/** One figure of one month. A null value is unknown, never zero; the reason says why. */
final readonly class ReportFigure
{
    public function __construct(
        public ?DecimalValue $value,
        public ?AssetCode $asset,
        public ?string $reason,
        /** The value is null only because nothing moved, which totals count as an exact zero. */
        public bool $noMovements,
    ) {
    }
}
