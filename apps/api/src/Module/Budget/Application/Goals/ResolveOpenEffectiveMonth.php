<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\PeriodClosed;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The month a definition takes effect in when the caller did not choose it. It must run inside the
 * caller's transaction: the closing guard it uses takes a transaction-scoped lock.
 */
final readonly class ResolveOpenEffectiveMonth
{
    public function __construct(private AssertPeriodOpen $periods)
    {
    }

    /**
     * The first month from `$from` through `$last` that is not closed, null when all are.
     * A single-month range checks exactly that month.
     */
    public function __invoke(WorkspaceScope $workspace, string $from, string $last): ?string
    {
        for ($month = CalendarMonth::fromString($from); $month->key() <= $last; $month = CalendarMonth::containing($month->firstDay()->modify('+1 month'))) {
            try {
                ($this->periods)($workspace, $month->firstDay());

                return $month->key();
            } catch (PeriodClosed) {
            }
        }

        return null;
    }
}
