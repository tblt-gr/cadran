<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The workspace-scope guard reads migration DDL statically, so it can run
 * without a database. That shortcut is only safe while something proves it
 * still agrees with the schema it claims to describe: a table the guard fails
 * to see is a table it never checks, and it fails towards green.
 *
 * This test is that proof. It holds the guard's discovered set against the
 * migrated database itself.
 */
final class WorkspaceScopedTableDiscoveryTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();

        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testTheGuardSeesExactlyTheWorkspaceScopedTablesTheSchemaHas(): void
    {
        $inSchema = $this->connection->fetchFirstColumn(
            "SELECT table_name FROM information_schema.columns
             WHERE table_schema = current_schema() AND column_name = 'workspace_id'
             ORDER BY table_name",
        );
        sort($inSchema);

        self::assertNotSame([], $inSchema, 'The migrated schema must contain at least one workspace-scoped table.');
        self::assertSame(
            $inSchema,
            $this->discoveredByTheGuard(),
            'The guard reads migration DDL statically; a divergence here means it stopped seeing a scoped table.',
        );
    }

    /**
     * @return list<string>
     */
    private function discoveredByTheGuard(): array
    {
        $repositoryRoot = dirname(__DIR__, 4);
        $command = sprintf(
            '%s %s --list-tables %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($repositoryRoot.'/scripts/check-workspace-scope.php'),
            escapeshellarg($repositoryRoot.'/apps/api/migrations'),
        );

        $output = [];
        $status = 0;
        exec($command.' 2>&1', $output, $status);
        self::assertSame(0, $status, 'Listing the guard tables failed: '.implode("\n", $output));

        $tables = array_values(array_filter(array_map('trim', $output), static fn (string $line): bool => '' !== $line));
        sort($tables);

        return $tables;
    }
}
