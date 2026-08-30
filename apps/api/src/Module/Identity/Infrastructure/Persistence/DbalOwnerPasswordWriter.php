<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

use App\Module\Identity\Application\OwnerPasswordWriter;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(OwnerPasswordWriter::class)]
final readonly class DbalOwnerPasswordWriter implements OwnerPasswordWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function storeInitialHash(string $userId, string $passwordHash): bool
    {
        // The "password_hash IS NULL" predicate makes this a compare-and-set:
        // a second concurrent request updates zero rows and learns it lost.
        $affected = $this->connection->executeStatement(
            'UPDATE identity_users SET password_hash = ? WHERE id = ? AND password_hash IS NULL',
            [$passwordHash, $userId],
        );

        return 1 === $affected;
    }
}
