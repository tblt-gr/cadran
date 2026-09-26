<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

enum AggregateQuality: string
{
    case COMPLETE = 'COMPLETE';
    case PROVISIONAL = 'PROVISIONAL';
    case PARTIAL = 'PARTIAL';
    case EMPTY = 'EMPTY';
}
