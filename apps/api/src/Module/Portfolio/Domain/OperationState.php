<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

enum OperationState: string
{
    case ACTIVE = 'ACTIVE';
    case VOIDED = 'VOIDED';
}
