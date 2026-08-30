<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Infrastructure\Persistence;

use App\Module\Identity\Infrastructure\Persistence\DbalAuthenticationUserRepository;
use App\Module\Identity\Infrastructure\Persistence\DbalOwnerPasswordWriter;
use App\Module\Identity\Infrastructure\Persistence\DbalWorkspaceMembershipReader;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises the authentication read models and the compare-and-set password
 * writer against a real PostgreSQL schema.
 */
final class DbalAuthenticationReadModelTest extends KernelTestCase
{
    private const string CREATED_AT = '2026-08-30 12:00:00.000000+00';
    private const string USER_ID = '00000000-0000-7000-8000-000000000001';
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';

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

    public function testFindByEmailIsCaseInsensitiveAndHydratesEveryField(): void
    {
        $this->insertUser(email: 'Owner@Example.test', passwordHash: 'argon-hash', disabledAt: null);
        $repository = new DbalAuthenticationUserRepository($this->connection);

        $user = $repository->findByEmail('owner@example.TEST');

        self::assertNotNull($user);
        self::assertSame(self::USER_ID, $user->id);
        self::assertSame('Owner@Example.test', $user->email);
        self::assertSame('Owner', $user->displayName);
        self::assertTrue($user->hasPassword);
        self::assertNull($user->disabledAt);
    }

    public function testFindByEmailReturnsNullForAnUnknownAddress(): void
    {
        $repository = new DbalAuthenticationUserRepository($this->connection);

        self::assertNull($repository->findByEmail('ghost@example.test'));
    }

    public function testFindCredentialsByEmailCarriesTheHashCaseInsensitively(): void
    {
        $this->insertUser(email: 'Owner@Example.test', passwordHash: 'argon-hash', disabledAt: '2026-08-30 09:30:00+00');
        $repository = new DbalAuthenticationUserRepository($this->connection);

        $credentials = $repository->findCredentialsByEmail('owner@example.test');

        self::assertNotNull($credentials);
        self::assertSame('Owner@Example.test', $credentials->email);
        self::assertSame('argon-hash', $credentials->passwordHash);
        self::assertTrue($credentials->isDisabled());
    }

    public function testFindCredentialsByEmailIsNullWhenNoPasswordIsSet(): void
    {
        $this->insertUser(email: 'owner@example.test', passwordHash: null, disabledAt: null);
        $repository = new DbalAuthenticationUserRepository($this->connection);

        self::assertNull($repository->findCredentialsByEmail('owner@example.test'));
        self::assertNull($repository->findCredentialsByEmail('ghost@example.test'));
    }

    public function testItHydratesANullPasswordAndADisabledTimestamp(): void
    {
        $this->insertUser(email: 'owner@example.test', passwordHash: null, disabledAt: '2026-08-30 09:30:00+00');
        $repository = new DbalAuthenticationUserRepository($this->connection);

        $user = $repository->findByEmail('owner@example.test');

        self::assertNotNull($user);
        self::assertFalse($user->hasPassword);
        self::assertNotNull($user->disabledAt);
        self::assertTrue($user->isDisabled());
        self::assertSame('2026-08-30T09:30:00+00:00', $user->disabledAt->format('c'));
    }

    public function testFindProvisionedOwnerReturnsNullBeforeAnyUserExists(): void
    {
        self::assertNull((new DbalAuthenticationUserRepository($this->connection))->findProvisionedOwner());
    }

    public function testFindProvisionedOwnerReturnsTheEarliestRow(): void
    {
        $this->insertUser(email: 'owner@example.test', passwordHash: null, disabledAt: null);

        $owner = (new DbalAuthenticationUserRepository($this->connection))->findProvisionedOwner();

        self::assertNotNull($owner);
        self::assertSame(self::USER_ID, $owner->id);
    }

    public function testWorkspaceMembershipReaderReturnsTheScopedWorkspace(): void
    {
        $this->insertUser(email: 'owner@example.test', passwordHash: 'hash', disabledAt: null);
        $this->insertWorkspace();
        $this->insertMembership();
        $reader = new DbalWorkspaceMembershipReader($this->connection);

        $membership = $reader->findForUser(self::USER_ID);

        self::assertNotNull($membership);
        self::assertSame(self::WORKSPACE_ID, $membership->workspaceId);
        self::assertSame('OWNER', $membership->role);
        self::assertNull($reader->findForUser('00000000-0000-7000-8000-0000000000ff'));
    }

    public function testStoreInitialHashIsACompareAndSet(): void
    {
        $this->insertUser(email: 'owner@example.test', passwordHash: null, disabledAt: null);
        $writer = new DbalOwnerPasswordWriter($this->connection);

        self::assertTrue($writer->storeInitialHash(self::USER_ID, 'first-hash'));
        self::assertFalse($writer->storeInitialHash(self::USER_ID, 'second-hash'));

        self::assertSame('first-hash', $this->connection->fetchOne(
            'SELECT password_hash FROM identity_users WHERE id = ?',
            [self::USER_ID],
        ));
    }

    private function insertUser(string $email, ?string $passwordHash, ?string $disabledAt): void
    {
        $this->connection->insert('identity_users', [
            'id' => self::USER_ID,
            'email' => $email,
            'display_name' => 'Owner',
            'created_at' => self::CREATED_AT,
            'password_hash' => $passwordHash,
            'disabled_at' => $disabledAt,
        ]);
    }

    private function insertWorkspace(): void
    {
        $this->connection->insert('identity_workspaces', [
            'id' => self::WORKSPACE_ID,
            'name' => 'Household',
            'timezone' => 'Europe/Paris',
            'base_currency' => 'EUR',
            'created_at' => self::CREATED_AT,
        ]);
    }

    private function insertMembership(): void
    {
        $this->connection->insert('identity_workspace_memberships', [
            'id' => '00000000-0000-7000-8000-0000000000b1',
            'workspace_id' => self::WORKSPACE_ID,
            'user_id' => self::USER_ID,
            'role' => 'OWNER',
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
