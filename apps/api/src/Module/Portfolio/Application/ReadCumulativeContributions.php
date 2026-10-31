<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\Cash\CashLedger;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;

/** Sum of the active deposits of a portfolio account up to a date, withdrawals not deducted. */
final readonly class ReadCumulativeContributions
{
    public function __construct(private PortfolioOperationRepository $operations)
    {
    }

    public function __invoke(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $asOf): DecimalValue
    {
        return CashLedger::replay([], $this->operations->activeForAccount($workspace, $accountId, $asOf))->totals->deposits;
    }
}
