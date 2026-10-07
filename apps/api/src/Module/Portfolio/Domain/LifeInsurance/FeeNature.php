<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;

enum FeeNature: string
{
    case ENTRY = 'ENTRY';
    case MANAGEMENT = 'MANAGEMENT';
    case SURRENDER = 'SURRENDER';
    case OTHER = 'OTHER';
    case SWITCH = 'SWITCH';

    public static function of(PortfolioOperation $operation): ?self
    {
        if (null === $operation->details->fees || \App\Module\Foundation\Domain\ExactDecimal::isZero($operation->details->fees)) {
            return null;
        }

        return match ($operation->details->type) {
            OperationType::PREMIUM => self::ENTRY,
            OperationType::MANAGEMENT_FEE => self::MANAGEMENT,
            OperationType::SWITCH => self::SWITCH,
            OperationType::PARTIAL_SURRENDER, OperationType::FULL_SURRENDER => self::SURRENDER,
            default => self::OTHER,
        };
    }
}
