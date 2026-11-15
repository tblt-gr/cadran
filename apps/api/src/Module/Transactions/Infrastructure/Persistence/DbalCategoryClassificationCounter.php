<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Categories\Application\CategoryClassificationCounter;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\Connection;

final readonly class DbalCategoryClassificationCounter implements CategoryClassificationCounter
{
    public function __construct(private Connection $connection)
    {
    }

    public function countForCategory(WorkspaceScope $workspace, string $categoryId): int
    {
        return (int) TransactionRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM transaction_splits WHERE workspace_id = :workspace_id AND category_id = :category_id',
            ['workspace_id' => $workspace->id, 'category_id' => $categoryId],
        ));
    }
}
