<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * Builds the session handler from the Doctrine connection's own parameters
 * (ADR-0024).
 *
 * It rebuilds a URL rather than handing over the Doctrine connection: the
 * handler must keep its own connection, so that a rolled-back business
 * transaction never takes the session with it and its row lock never joins the
 * application's transaction. But it must reach the *same* database, and the raw
 * DATABASE_URL is not that database — DoctrineBundle appends `dbname_suffix`
 * to it, which is how the test suite is isolated. A handler wired to the raw
 * URL writes its sessions into the production database while the suite asserts
 * on the test one.
 */
final readonly class SessionHandlerFactory
{
    /** Must match the table the migration creates. */
    private const array OPTIONS = [
        'db_table' => 'sessions',
        'db_id_col' => 'sess_id',
        'db_data_col' => 'sess_data',
        'db_lifetime_col' => 'sess_lifetime',
        'db_time_col' => 'sess_time',
    ];

    public static function create(Connection $connection): PdoSessionHandler
    {
        // A URL, not a PDO instance: PdoSessionHandler connects lazily from a
        // URL, so a request that never touches a session never opens a second
        // connection.
        return new PdoSessionHandler(self::url($connection), self::OPTIONS);
    }

    private static function url(Connection $connection): string
    {
        $params = $connection->getParams();

        return sprintf(
            'postgresql://%s:%s@%s:%d/%s',
            rawurlencode(self::text($params['user'] ?? null)),
            rawurlencode(self::text($params['password'] ?? null)),
            self::text($params['host'] ?? null),
            is_numeric($params['port'] ?? null) ? (int) $params['port'] : 5432,
            rawurlencode(self::text($params['dbname'] ?? null)),
        );
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
