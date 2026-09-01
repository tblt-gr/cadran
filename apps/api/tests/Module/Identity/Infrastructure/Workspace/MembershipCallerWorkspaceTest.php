<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Infrastructure\Workspace;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Infrastructure\Persistence\DbalAuthenticationUserRepository;
use App\Module\Identity\Infrastructure\Persistence\DbalWorkspaceMembershipReader;
use App\Module\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Identity\Infrastructure\Workspace\MembershipCallerWorkspace;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The one query the workspace-scope guard exempts by name is the one that
 * resolves the scope itself, so it is proved here against a real schema rather
 * than against a stub: two owners, two workspaces, and neither reaching the
 * other. The refusal paths stay in the unit test next door.
 */
final class MembershipCallerWorkspaceTest extends KernelTestCase
{
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();

        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->databaseReady = true;
        $this->fixture->reset();
        $this->fixture->seed(self::hasher());
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }

        parent::tearDown();
    }

    public function testEachOwnerResolvesToItsOwnWorkspaceAndNoOther(): void
    {
        self::assertSame(
            WorkspaceFixture::OWN_WORKSPACE,
            $this->resolveFor(WorkspaceFixture::OWNER_EMAIL)->id,
        );
        self::assertSame(
            WorkspaceFixture::OTHER_WORKSPACE,
            $this->resolveFor(WorkspaceFixture::OTHER_OWNER_EMAIL)->id,
        );
    }

    public function testAnOwnerWhoseMembershipIsRevokedLosesItsScope(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM identity_workspace_memberships WHERE user_id = ?',
            [WorkspaceFixture::OWNER_ID],
        );

        $this->expectException(WorkspaceAccessDenied::class);

        $this->resolveFor(WorkspaceFixture::OWNER_EMAIL);
    }

    public function testADisabledOwnerLosesItsScopeEvenWithAValidToken(): void
    {
        $this->connection->executeStatement(
            'UPDATE identity_users SET disabled_at = now() WHERE id = ?',
            [WorkspaceFixture::OWNER_ID],
        );

        $this->expectException(WorkspaceAccessDenied::class);

        $this->resolveFor(WorkspaceFixture::OWNER_EMAIL);
    }

    private function resolveFor(string $email): WorkspaceScope
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(
            new SecurityUser($email, 'irrelevant-hash', false),
            'main',
        ));

        return (new MembershipCallerWorkspace(
            $tokenStorage,
            new DbalAuthenticationUserRepository($this->connection),
            new DbalWorkspaceMembershipReader($this->connection),
        ))->resolve();
    }

    private static function hasher(): PasswordHasher
    {
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);

        return $hasher;
    }
}
