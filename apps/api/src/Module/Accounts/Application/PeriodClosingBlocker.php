<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\PeriodClosingCondition;

/**
 * One condition standing in the way of a closing, with the accounts that raise
 * it. The count is the number of items, except for pending transactions which
 * are counted but not listed.
 */
final readonly class PeriodClosingBlocker
{
    /** @param list<PeriodClosingBlockerItem> $items */
    public function __construct(public PeriodClosingCondition $condition, public int $count, public array $items = [])
    {
    }
}
