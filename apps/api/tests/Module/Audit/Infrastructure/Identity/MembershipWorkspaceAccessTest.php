<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit\Infrastructure\Identity;

use App\Module\Audit\Infrastructure\Identity\MembershipWorkspaceAccess;
use App\Module\Identity\Infrastructure\Persistence\DbalAuthenticationUserRepository;
use App\Module\Identity\Infrastructure\Persistence\DbalWorkspaceMembershipReader;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The single component that turns an authenticated identity into a read scope.
 * Two identities in two workspaces must never resolve to each other's scope.
 */
final class MembershipWorkspaceAccessTest extends KernelTestCase
{
    private const string CREATED_AT = '2026-08-31 12:00:00.000000+00';
    private const string OWNER_ID = '00000000-0000-7000-8000-000000000001';
    private const string NEIGHBOUR_ID = '00000000-0000-7000-8000-000000000002';
    private const string DISABLED_ID = '00000000-0000-7000-8000-000000000003';
    private const string ORPHAN_ID = '00000000-0000-7000-8000-000000000004';
    private const string OWNER_WORKSPACE = '00000000-0000-7000-8000-0000000000a1';
    private const string NEIGHBOUR_WORKSPACE = '00000000-0000-7000-8000-0000000000a2';

    private Connection $connection;
    private MembershipWorkspaceAccess $access;
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
        $this->access = new MembershipWorkspaceAccess(
            new DbalAuthenticationUserRepository($connection),
            new DbalWorkspaceMembershipReader($connection),
        );
        $this->databaseReady = true;
        $this->clearData();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->clearData();
        }

        parent::tearDown();
    }

    public function testEachIdentityResolvesToItsOwnWorkspaceAndNoOther(): void
    {
        self::assertSame(self::OWNER_WORKSPACE, $this->access->readableWorkspaceFor('owner@example.test'));
        self::assertSame(self::NEIGHBOUR_WORKSPACE, $this->access->readableWorkspaceFor('neighbour@example.test'));
    }

    public function testTheLookupIsCaseInsensitiveLikeTheFirewall(): void
    {
        self::assertSame(self::OWNER_WORKSPACE, $this->access->readableWorkspaceFor('Owner@Example.test'));
    }

    public function testADisabledAccountResolvesToNoWorkspace(): void
    {
        self::assertNull($this->access->readableWorkspaceFor('disabled@example.test'));
    }

    public function testAnAccountWithoutAMembershipResolvesToNoWorkspace(): void
    {
        self::assertNull($this->access->readableWorkspaceFor('orphan@example.test'));
    }

    public function testAnUnknownIdentityResolvesToNoWorkspace(): void
    {
        self::assertNull($this->access->readableWorkspaceFor('ghost@example.test'));
    }

    private function seed(): void
    {
        $users = [
            [self::OWNER_ID, 'owner@example.test', null],
            [self::NEIGHBOUR_ID, 'neighbour@example.test', null],
            [self::DISABLED_ID, 'disabled@example.test', '2026-08-31 09:00:00+00'],
            [self::ORPHAN_ID, 'orphan@example.test', null],
        ];
        foreach ($users as [$id, $email, $disabledAt]) {
            $this->connection->insert('identity_users', [
                'id' => $id,
                'email' => $email,
                'display_name' => 'User',
                'created_at' => self::CREATED_AT,
                'password_hash' => 'argon-hash',
                'disabled_at' => $disabledAt,
            ]);
        }

        foreach ([self::OWNER_WORKSPACE, self::NEIGHBOUR_WORKSPACE] as $index => $workspaceId) {
            $this->connection->insert('identity_workspaces', [
                'id' => $workspaceId,
                'name' => 'Workspace '.$index,
                'timezone' => 'Europe/Paris',
                'base_currency' => 'EUR',
                'created_at' => self::CREATED_AT,
            ]);
        }

        $memberships = [
            ['00000000-0000-7000-8000-0000000000b1', self::OWNER_WORKSPACE, self::OWNER_ID],
            ['00000000-0000-7000-8000-0000000000b2', self::NEIGHBOUR_WORKSPACE, self::NEIGHBOUR_ID],
        ];
        foreach ($memberships as [$id, $workspaceId, $userId]) {
            $this->connection->insert('identity_workspace_memberships', [
                'id' => $id,
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'role' => 'OWNER',
                'created_at' => self::CREATED_AT,
            ]);
        }
    }

    private function clearData(): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE audit_events');
        $this->connection->executeStatement('DELETE FROM identity_initial_provisionings');
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships');
        $this->connection->executeStatement('DELETE FROM identity_workspaces');
        $this->connection->executeStatement('DELETE FROM identity_users');
    }
}
