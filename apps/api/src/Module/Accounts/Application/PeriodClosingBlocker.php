<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\PeriodClosingCondition;

/** One condition standing in the way of a closing, with how many items raise it. */
final readonly class PeriodClosingBlocker
{
    public function __construct(public PeriodClosingCondition $condition, public int $count)
    {
    }
}
