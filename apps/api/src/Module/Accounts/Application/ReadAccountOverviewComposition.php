<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Foundation\Application\CallerWorkspace;

/**
 * How the whole eligible net worth splits between accounts on one day.
 *
 * It reads the same {@see AccountOverviewFigures} as the overview headings and
 * rows, so a slice share is the very share of the matching row.
 */
final readonly class ReadAccountOverviewComposition
{
    public function __construct(
        private CallerWorkspace $caller,
        private AccountOverviewFigures $figures,
        private ComposeAccountOverviewComposition $compose,
    ) {
    }

    /**
     * @throws AccountOverviewCompositionTooLarge beyond {@see ComposeAccountOverviewComposition::MAX_ACCOUNTS} eligible accounts
     * @throws InvalidAccountOverviewQuery        on a malformed date
     */
    public function __invoke(?string $asOf): AccountOverviewCompositionView
    {
        return ($this->compose)($this->figures->on($this->caller->resolve(), $asOf));
    }
}
