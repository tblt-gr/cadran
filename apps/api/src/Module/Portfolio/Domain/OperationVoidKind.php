<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

enum OperationVoidKind: string
{
    case VOIDED = 'VOIDED';
    case REPLACED = 'REPLACED';
}
