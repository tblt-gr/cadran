<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

use App\Module\Identity\Application\OwnerProfileWriter;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(OwnerProfileWriter::class)]
final readonly class DbalOwnerProfileWriter implements OwnerProfileWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function updateDisplayName(string $userId, string $displayName): bool
    {
        $affected = $this->connection->executeStatement(
            'UPDATE identity_users SET display_name = ? WHERE id = ?',
            [$displayName, $userId],
        );

        return 1 === $affected;
    }
}
