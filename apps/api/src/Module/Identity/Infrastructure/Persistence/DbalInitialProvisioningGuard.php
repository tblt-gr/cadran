<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

use App\Module\Identity\Application\InitialProvisioningGuard;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(InitialProvisioningGuard::class)]
final readonly class DbalInitialProvisioningGuard implements InitialProvisioningGuard
{
    public function __construct(private Connection $connection)
    {
    }

    public function tryAcquire(): bool
    {
        // Speculative insert: a concurrent caller blocks on the primary key until
        // this transaction ends, then sees the conflict and gets zero rows.
        return 1 === (int) $this->connection->executeStatement(
            'INSERT INTO identity_initial_provisionings (id) VALUES (true) ON CONFLICT DO NOTHING',
        );
    }
}
