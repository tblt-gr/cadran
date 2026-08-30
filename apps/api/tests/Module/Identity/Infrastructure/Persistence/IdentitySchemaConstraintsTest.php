<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises the workspace-scoped uniqueness, singleton and referential
 * constraints created by the identity migration against a real PostgreSQL
 * schema. These are the negative isolation guarantees behind IDN-001.
 */
final class IdentitySchemaConstraintsTest extends KernelTestCase
{
    private const string CREATED_AT = '2026-08-30 12:00:00.000000+00';

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

    public function testEmailUniquenessIsCaseInsensitive(): void
    {
        $this->insertUser('00000000-0000-7000-8000-000000000001', 'Owner@Example.test');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertUser('00000000-0000-7000-8000-000000000002', 'owner@example.test');
    }

    public function testAWorkspaceCannotHaveTwoOwners(): void
    {
        $workspaceId = '00000000-0000-7000-8000-0000000000a0';
        $this->insertWorkspace($workspaceId);
        $this->insertUser('00000000-0000-7000-8000-000000000001', 'first@example.test');
        $this->insertUser('00000000-0000-7000-8000-000000000002', 'second@example.test');

        $this->insertMembership('00000000-0000-7000-8000-0000000000b1', $workspaceId, '00000000-0000-7000-8000-000000000001');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertMembership('00000000-0000-7000-8000-0000000000b2', $workspaceId, '00000000-0000-7000-8000-000000000002');
    }

    public function testTheSameUserCannotJoinAWorkspaceTwice(): void
    {
        $workspaceId = '00000000-0000-7000-8000-0000000000a0';
        $userId = '00000000-0000-7000-8000-000000000001';
        $this->insertWorkspace($workspaceId);
        $this->insertUser($userId, 'owner@example.test');

        $this->insertMembership('00000000-0000-7000-8000-0000000000b1', $workspaceId, $userId);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertMembership('00000000-0000-7000-8000-0000000000b2', $workspaceId, $userId);
    }

    public function testOwnershipIsScopedPerWorkspace(): void
    {
        $userId = '00000000-0000-7000-8000-000000000001';
        $this->insertUser($userId, 'owner@example.test');
        $this->insertWorkspace('00000000-0000-7000-8000-0000000000a1');
        $this->insertWorkspace('00000000-0000-7000-8000-0000000000a2');

        $this->insertMembership('00000000-0000-7000-8000-0000000000b1', '00000000-0000-7000-8000-0000000000a1', $userId);
        $this->insertMembership('00000000-0000-7000-8000-0000000000b2', '00000000-0000-7000-8000-0000000000a2', $userId);

        self::assertSame(2, $this->connection->fetchOne(
            "SELECT COUNT(*) FROM identity_workspace_memberships WHERE role = 'OWNER'",
        ));
    }

    public function testMembershipRoleIsConstrainedToOwner(): void
    {
        $workspaceId = '00000000-0000-7000-8000-0000000000a0';
        $userId = '00000000-0000-7000-8000-000000000001';
        $this->insertWorkspace($workspaceId);
        $this->insertUser($userId, 'owner@example.test');

        $this->expectException(DbalException::class);

        $this->insertMembership('00000000-0000-7000-8000-0000000000b1', $workspaceId, $userId, 'MEMBER');
    }

    public function testInitialProvisioningTableHoldsASingleRow(): void
    {
        $this->connection->executeStatement('INSERT INTO identity_initial_provisionings (id) VALUES (true)');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection->executeStatement('INSERT INTO identity_initial_provisionings (id) VALUES (true)');
    }

    public function testOwnerPasswordAndDisableColumnsAreNullableAndPersisted(): void
    {
        $withoutAuth = '00000000-0000-7000-8000-000000000001';
        $withAuth = '00000000-0000-7000-8000-000000000002';

        // Nullable: the owner exists before a password or a disable timestamp.
        $this->insertUser($withoutAuth, 'fresh@example.test');
        self::assertNull($this->connection->fetchOne(
            'SELECT password_hash FROM identity_users WHERE id = ?',
            [$withoutAuth],
        ));

        $this->connection->insert('identity_users', [
            'id' => $withAuth,
            'email' => 'active@example.test',
            'display_name' => 'Owner',
            'created_at' => self::CREATED_AT,
            'password_hash' => 'argon-hash',
            'disabled_at' => '2026-08-30 09:00:00+00',
        ]);

        $row = $this->connection->fetchAssociative(
            'SELECT password_hash, disabled_at FROM identity_users WHERE id = ?',
            [$withAuth],
        );
        self::assertIsArray($row);
        self::assertSame('argon-hash', $row['password_hash']);
        self::assertNotNull($row['disabled_at']);
    }

    public function testAWorkspaceWithAMembershipCannotBeDeleted(): void
    {
        $workspaceId = '00000000-0000-7000-8000-0000000000a0';
        $userId = '00000000-0000-7000-8000-000000000001';
        $this->insertWorkspace($workspaceId);
        $this->insertUser($userId, 'owner@example.test');
        $this->insertMembership('00000000-0000-7000-8000-0000000000b1', $workspaceId, $userId);

        // ON DELETE RESTRICT surfaces as SQLSTATE 23001, which DBAL reports as a
        // generic driver exception rather than a mapped foreign-key exception.
        $this->expectException(DbalException::class);
        $this->expectExceptionMessageMatches('/identity_workspace_memberships_workspace_fk/');

        $this->connection->executeStatement('DELETE FROM identity_workspaces WHERE id = ?', [$workspaceId]);
    }

    private function insertUser(string $id, string $email): void
    {
        $this->connection->insert('identity_users', [
            'id' => $id,
            'email' => $email,
            'display_name' => 'Owner',
            'created_at' => self::CREATED_AT,
        ]);
    }

    private function insertWorkspace(string $id): void
    {
        $this->connection->insert('identity_workspaces', [
            'id' => $id,
            'name' => 'Household',
            'timezone' => 'Europe/Paris',
            'base_currency' => 'EUR',
            'created_at' => self::CREATED_AT,
        ]);
    }

    private function insertMembership(string $id, string $workspaceId, string $userId, string $role = 'OWNER'): void
    {
        $this->connection->insert('identity_workspace_memberships', [
            'id' => $id,
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'role' => $role,
            'created_at' => self::CREATED_AT,
        ]);
    }

    private function clearIdentityData(): void
    {
        $this->connection->executeStatement('DELETE FROM identity_initial_provisionings');
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships');
        $this->connection->executeStatement('DELETE FROM identity_workspaces');
        $this->connection->executeStatement('DELETE FROM identity_users');
    }
}
