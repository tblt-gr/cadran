<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\DecimalValue;

final readonly class MonthlySplit
{
    /** @param list<string> $analyticAxes */
    public function __construct(
        public string $categoryId,
        public DecimalValue $amount,
        public array $analyticAxes = [],
    ) {
    }
}
