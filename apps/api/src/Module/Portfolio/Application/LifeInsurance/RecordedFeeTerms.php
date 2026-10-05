<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Portfolio\Domain\LifeInsurance\FeeTerms;

final readonly class RecordedFeeTerms
{
    public function __construct(public FeeTerms $terms, public string $assetCode)
    {
    }
}
