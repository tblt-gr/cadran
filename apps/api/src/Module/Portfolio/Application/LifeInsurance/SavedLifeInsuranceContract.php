<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContract;

final readonly class SavedLifeInsuranceContract
{
    public function __construct(public LifeInsuranceContract $contract, public bool $created)
    {
    }
}
