<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\DecimalValue;

final readonly class MonthlySplit
{
    public function __construct(public string $categoryId, public DecimalValue $amount)
    {
    }
}
