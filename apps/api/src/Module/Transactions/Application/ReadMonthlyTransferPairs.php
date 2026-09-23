<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;

/** Bounded transfer-pair read port consumed by monthly reporting. */
final readonly class ReadMonthlyTransferPairs
{
    public function __construct(private MonthlyTransferPairReader $pairs)
    {
    }

    /** @return list<MonthlyTransferPairFact> */
    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month): array
    {
        $pairs = $this->pairs->read(
            $workspace,
            $month->firstDay(),
            $month->lastDay(),
            ReadMonthlyTransactionFacts::MAX_TRANSACTIONS + 1,
        );
        if (count($pairs) > ReadMonthlyTransactionFacts::MAX_TRANSACTIONS) {
            throw new MonthlyTransactionScopeTooLarge('A monthly projection reads at most 500 transfer pairs.');
        }

        return $pairs;
    }
}
