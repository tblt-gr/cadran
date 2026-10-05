<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

enum ManagementMode: string
{
    case FREE = 'FREE';
    case MANAGED = 'MANAGED';
    case PILOTED = 'PILOTED';
}
