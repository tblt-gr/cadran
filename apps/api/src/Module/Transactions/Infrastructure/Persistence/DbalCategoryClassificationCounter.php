<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Categories\Application\CategoryClassificationCounter;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Foundation\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalCategoryClassificationCounter implements CategoryClassificationCounter
{
    public function __construct(private Connection $connection)
    {
    }

    public function countForCategory(WorkspaceScope $workspace, string $categoryId): int
    {
        return (int) DbalValue::text($this->connection->fetchOne(
            'SELECT count(*) FROM transaction_splits WHERE workspace_id = :workspace_id AND category_id = :category_id',
            ['workspace_id' => $workspace->id, 'category_id' => $categoryId],
        ));
    }
}
