<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\FirstNetWorthDataMonthReader;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\FirstPortfolioValuationDayReader;
use App\Module\Transactions\Application\FirstBookedTransactionDayReader;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(FirstNetWorthDataMonthReader::class)]
final readonly class DbalFirstNetWorthDataMonthReader implements FirstNetWorthDataMonthReader
{
    public function __construct(
        private Connection $connection,
        private FirstBookedTransactionDayReader $transactions,
        private FirstPortfolioValuationDayReader $valuations,
    ) {
    }

    public function onOrBefore(WorkspaceScope $workspace, \DateTimeImmutable $asOf): ?string
    {
        $snapshot = $this->connection->fetchOne(
            'SELECT min(as_of) FROM account_balance_snapshots WHERE workspace_id = :workspace_id AND superseded_at IS NULL AND as_of <= :as_of',
            ['workspace_id' => $workspace->id, 'as_of' => $asOf->format('Y-m-d')],
        );
        $months = array_filter([
            is_string($snapshot) ? substr($snapshot, 0, 7) : null,
            $this->transactions->onOrBefore($workspace, $asOf)?->format('Y-m'),
            $this->valuations->onOrBefore($workspace, $asOf)?->format('Y-m'),
        ], static fn (?string $month): bool => null !== $month);

        return [] === $months ? null : min($months);
    }
}
