<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\Takeover\PortfolioTakeover;
use App\Module\Portfolio\Domain\Takeover\PortfolioTakeoverRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PortfolioTakeoverRepository::class)]
final readonly class DbalPortfolioTakeoverRepository implements PortfolioTakeoverRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findByAccount(WorkspaceScope $workspace, string $accountId): ?PortfolioTakeover
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, account_id, taken_over_on, valuation_id, created_at FROM portfolio_takeovers WHERE workspace_id = :workspace_id AND account_id = :account_id',
            ['workspace_id' => $workspace->id, 'account_id' => $accountId],
        );
        if (false === $row) {
            return null;
        }

        return new PortfolioTakeover(
            PortfolioOperationRow::text($row['id']), $workspace, PortfolioOperationRow::text($row['account_id']),
            new \DateTimeImmutable(PortfolioOperationRow::text($row['taken_over_on']), new \DateTimeZone('UTC')),
            PortfolioOperationRow::text($row['valuation_id']),
            new \DateTimeImmutable(PortfolioOperationRow::text($row['created_at'])),
        );
    }

    public function add(PortfolioTakeover $takeover): void
    {
        $this->connection->insert('portfolio_takeovers', [
            'id' => $takeover->id,
            'workspace_id' => $takeover->workspace->id,
            'account_id' => $takeover->accountId,
            'taken_over_on' => $takeover->takenOverOn->format('Y-m-d'),
            'valuation_id' => $takeover->valuationId,
            'created_at' => $takeover->createdAt->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
