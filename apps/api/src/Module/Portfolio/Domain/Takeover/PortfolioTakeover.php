<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Takeover;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The day an account took over the positions it already held. It starts the performance history of the account:
 * nothing is known, so nothing is computed, before it.
 */
final readonly class PortfolioTakeover
{
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $accountId,
        public \DateTimeImmutable $takenOverOn,
        public string $valuationId,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /** Whether the takeover valuation closes its month, so that the history starts with the next month. */
    public function atMonthEnd(): bool
    {
        return $this->takenOverOn->format('Y-m-d') === $this->takenOverOn->modify('last day of this month')->format('Y-m-d');
    }

    /** The first day of the first month with a performance: the next month after a month-end takeover, else the takeover month. */
    public function historyFirstMonth(): \DateTimeImmutable
    {
        $month = $this->takenOverOn->modify('first day of this month');

        return $this->atMonthEnd() ? $month->modify('+1 month') : $month;
    }

    /** A period ending on or before the takeover day is older than the history. */
    public function precedes(\DateTimeImmutable $lastDay): bool
    {
        return $lastDay <= $this->takenOverOn;
    }
}
