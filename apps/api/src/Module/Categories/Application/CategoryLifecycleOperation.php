<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

/**
 * The lifecycle changes a used category supports. Renaming is absent on
 * purpose: it is a display change served by {@see UpdateCategory}, while every
 * operation listed here changes where history is counted and therefore needs an
 * impact preview and an explicit confirmation.
 */
enum CategoryLifecycleOperation: string
{
    case ARCHIVE = 'ARCHIVE';
    case MERGE = 'MERGE';
    case MOVE = 'MOVE';
    case REPLACE = 'REPLACE';
}
