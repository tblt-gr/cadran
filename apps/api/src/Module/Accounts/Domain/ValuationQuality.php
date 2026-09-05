<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * How the latest valid snapshot sits relative to the requested date.
 *
 * CURRENT: the snapshot is dated on that day.
 * STALE: the snapshot is the latest valid one but was recorded earlier, so
 * the figure is carried forward. Age says by how many days.
 * MISSING: nothing exists on or before that day. The amount stays null.
 */
enum ValuationQuality: string
{
    case CURRENT = 'CURRENT';
    case STALE = 'STALE';
    case MISSING = 'MISSING';
}
