<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/** Closing needs an explicit confirmation for each condition listed here. */
final class PeriodClosingBlocked extends \RuntimeException
{
    /** @param list<PeriodClosingBlocker> $blockers */
    public function __construct(public array $blockers)
    {
        parent::__construct('The period cannot be closed without confirming each blocking condition.');
    }
}
