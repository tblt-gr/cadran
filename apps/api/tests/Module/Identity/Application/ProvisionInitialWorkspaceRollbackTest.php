<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Infrastructure\Persistence\DbalAuditEventRepository;
use App\Module\Foundation\Infrastructure\Uuid\UuidV7Generator;
use App\Module\Identity\Application\InitialWorkspaceProvisioningInput;
use App\Module\Identity\Application\ProvisionInitialWorkspace;
use App\Module\Identity\Domain\Membership;
use App\Module\Identity\Domain\MembershipRepository;
use App\Module\Identity\Infrastructure\Persistence\DbalInitialProvisioningGuard;
use App\Module\Identity\Infrastructure\Persistence\DbalTransactionManager;
use App\Module\Identity\Infrastructure\Persistence\DbalUserRepository;
use App\Module\Identity\Infrastructure\Persistence\DbalWorkspaceRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the "atomically" in the acceptance criteria: a failure between the
 * three persistence writes must leave every identity table untouched, and in
 * particular must not leave the one-time provisioning latch set, nor an audit
 * event describing an install that does not exist, so the operator can retry.
 */
final class ProvisionInitialWorkspaceRollbackTest extends KernelTestCase
{
    private Connection $connection;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        if (false === getenv('DATABASE_URL')) {
            if (false !== getenv('CI')) {
                self::fail('DATABASE_URL must be set in CI; PostgreSQL integration tests may not be skipped there.');
            }

            self::markTestSkipped('This PostgreSQL integration test requires DATABASE_URL.');
        }

        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->databaseReady = true;
        $this->clearIdentityData();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->clearIdentityData();
        }

        parent::tearDown();
    }

    public function testAFailureAfterPartialWritesRollsBackEveryIdentityTable(): void
    {
        $failingMemberships = new class implements MembershipRepository {
            public function save(Membership $membership): void
            {
                throw new \RuntimeException('Simulated persistence failure.');
            }
        };

        $provision = new ProvisionInitialWorkspace(
            new DbalTransactionManager($this->connection),
            new DbalInitialProvisioningGuard($this->connection),
            new DbalUserRepository($this->connection),
            new DbalWorkspaceRepository($this->connection),
            $failingMemberships,
            new UuidV7Generator(),
            new RecordAuditEvent(new DbalAuditEventRepository($this->connection), new UuidV7Generator()),
        );

        $input = new InitialWorkspaceProvisioningInput(
            email: 'owner@example.test',
            displayName: 'Owner',
            workspaceName: 'Household',
            baseCurrency: 'EUR',
        );

        try {
            $provision($input);
            self::fail('Provisioning should have propagated the persistence failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated persistence failure.', $exception->getMessage());
        }

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM identity_users'));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM identity_workspaces'));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM identity_workspace_memberships'));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM identity_initial_provisionings'));
        // The trail is part of the same invariant: a rolled-back provisioning
        // leaves no event claiming it happened.
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM audit_events'));
    }

    private function clearIdentityData(): void
    {
        // TRUNCATE, not DELETE: the append-only trigger on audit_events rejects
        // row deletion, and the workspace and actor foreign keys make the trail
        // block the identity cleanup that follows.
        $this->connection->executeStatement('TRUNCATE TABLE audit_events');
        $this->connection->executeStatement('DELETE FROM identity_initial_provisionings');
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships');
        $this->connection->executeStatement('DELETE FROM identity_workspaces');
        $this->connection->executeStatement('DELETE FROM identity_users');
    }
}
