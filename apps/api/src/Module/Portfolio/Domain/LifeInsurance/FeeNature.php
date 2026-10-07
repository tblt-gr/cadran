<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;

enum FeeNature: string
{
    case SWITCH = 'SWITCH';

    public static function of(PortfolioOperation $operation): ?self
    {
        return OperationType::SWITCH === $operation->details->type ? self::SWITCH : null;
    }
}
