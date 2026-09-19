<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Reconciliation;

use App\Module\Accounts\Application\PeriodClosingFacts;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\TransactionRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Answers Accounts' closing questions from the movements this module owns.
 * The dependency runs Transactions to Accounts only: Accounts states the port,
 * this class implements it.
 */
#[AsAlias(PeriodClosingFacts::class)]
final readonly class TransactionPeriodClosingFacts implements PeriodClosingFacts
{
    public function __construct(
        private TransactionRepository $transactions,
        private AccountReconciliationCalculator $calculator,
    ) {
    }

    public function pendingTransactionCount(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return $this->transactions->countPendingInWorkspace($workspace, $from, $to);
    }

    public function unexplainedDiscrepancyCount(WorkspaceScope $workspace, array $closings, \DateTimeImmutable $from): int
    {
        $count = 0;
        foreach ($closings as $closing) {
            $comparison = $this->calculator->compare($workspace, $closing, $from)->comparison;
            // A figure that cannot be calculated is not a discrepancy: the
            // account is already reported as unreconciled by its own condition.
            if (null !== $comparison->discrepancy && !$comparison->isBalanced()) {
                ++$count;
            }
        }

        return $count;
    }
}
