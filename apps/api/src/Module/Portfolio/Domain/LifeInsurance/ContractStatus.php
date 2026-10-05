<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

enum ContractStatus: string
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
}
