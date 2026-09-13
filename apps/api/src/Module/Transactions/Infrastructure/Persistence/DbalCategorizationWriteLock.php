<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\Categorization\CategorizationWriteLock;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CategorizationWriteLock::class)]
final readonly class DbalCategorizationWriteLock implements CategorizationWriteLock
{
    public function __construct(private Connection $connection)
    {
    }

    public function acquire(WorkspaceScope $workspace): void
    {
        // NO KEY UPDATE still serializes lock holders but stays compatible with the KEY SHARE lock
        // every foreign-key insert takes on the workspace row; FOR UPDATE would open deadlock cycles.
        $id = $this->connection->fetchOne(
            'SELECT id FROM identity_workspaces WHERE id = :workspace_id FOR NO KEY UPDATE',
            ['workspace_id' => $workspace->id],
        );
        if ($workspace->id !== $id) {
            throw new \UnexpectedValueException('The categorization workspace no longer exists.');
        }
    }
}
