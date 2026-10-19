<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\FirstNetWorthDataDayReader;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\FirstPortfolioValuationDayReader;
use App\Module\Transactions\Application\FirstBookedTransactionDayReader;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(FirstNetWorthDataDayReader::class)]
final readonly class DbalFirstNetWorthDataDayReader implements FirstNetWorthDataDayReader
{
    public function __construct(
        private Connection $connection,
        private FirstBookedTransactionDayReader $transactions,
        private FirstPortfolioValuationDayReader $valuations,
    ) {
    }

    public function onOrBefore(WorkspaceScope $workspace, \DateTimeImmutable $asOf): ?\DateTimeImmutable
    {
        $snapshot = $this->connection->fetchOne(
            'SELECT min(as_of) FROM account_balance_snapshots WHERE workspace_id = :workspace_id AND superseded_at IS NULL AND as_of <= :as_of',
            ['workspace_id' => $workspace->id, 'as_of' => $asOf->format('Y-m-d')],
        );
        $days = array_filter([
            is_string($snapshot) ? $snapshot : null,
            $this->transactions->onOrBefore($workspace, $asOf)?->format('Y-m-d'),
            $this->valuations->onOrBefore($workspace, $asOf)?->format('Y-m-d'),
        ], static fn (?string $day): bool => null !== $day);

        return [] === $days ? null : new \DateTimeImmutable(min($days), new \DateTimeZone('UTC'));
    }
}
