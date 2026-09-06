<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

use App\Module\Identity\Application\OwnerSessionRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Session revocation against the PostgreSQL session table written by
 * PdoSessionHandler (ADR-0024).
 *
 * The caller's own record is spared by identifier rather than by user: the
 * table has no owner column, because a session row is opaque serialized data
 * the handler owns. That is sound while the install has one account — see
 * {@see OwnerSessionRegistry} — and the exclusion also keeps this DELETE off
 * the very row the handler holds a transactional lock on for this request.
 */
#[AsAlias(OwnerSessionRegistry::class)]
final readonly class DbalOwnerSessionRegistry implements OwnerSessionRegistry
{
    /** Must match the db_table option given to PdoSessionHandler. */
    private const string TABLE = 'sessions';

    /**
     * Another browser with a request in flight holds a transactional lock on
     * its own session row for that request's duration, on the handler's
     * connection. Without a ceiling this DELETE would wait for it while holding
     * the row lock on identity_users, so the credential change would be as slow
     * as the slowest concurrent request. Timing out rolls the whole change back
     * — no partial write — and the owner simply retries.
     */
    private const string LOCK_TIMEOUT = '3s';

    public function __construct(private Connection $connection)
    {
    }

    public function revokeAllExcept(?string $sessionIdToKeep): void
    {
        $this->connection->executeStatement(sprintf("SET LOCAL lock_timeout = '%s'", self::LOCK_TIMEOUT));

        if (null === $sessionIdToKeep) {
            $this->connection->executeStatement('DELETE FROM '.self::TABLE);

            return;
        }

        $this->connection->executeStatement(
            'DELETE FROM '.self::TABLE.' WHERE sess_id <> ?',
            [$sessionIdToKeep],
        );
    }
}
