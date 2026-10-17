<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Sheet;

enum CellKind
{
    case NUMBER;
    case TEXT;
    case BOOLEAN;
    case ERROR;
}
