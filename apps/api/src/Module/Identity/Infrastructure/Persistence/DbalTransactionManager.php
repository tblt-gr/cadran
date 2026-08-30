<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

use App\Module\Identity\Application\TransactionManager;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TransactionManager::class)]
final readonly class DbalTransactionManager implements TransactionManager
{
    public function __construct(private Connection $connection)
    {
    }

    public function transactional(\Closure $callback): mixed
    {
        return $this->connection->transactional(static fn (Connection $_): mixed => $callback());
    }
}
