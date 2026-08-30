<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Application;

use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\AuthenticationUserRepository;
use App\Module\Identity\Application\DescribeSession;
use App\Module\Identity\Application\OwnerCredentials;
use App\Module\Identity\Application\WorkspaceMembership;
use App\Module\Identity\Application\WorkspaceMembershipReader;
use PHPUnit\Framework\TestCase;

final class DescribeSessionTest extends TestCase
{
    public function testAnonymousRequestReportsSetupRequiredWhenTheOwnerHasNoPassword(): void
    {
        $describe = new DescribeSession(
            new InMemoryAuthenticationUserRepository(owner: self::owner(hasPassword: false)),
            new InMemoryWorkspaceMembershipReader(),
        );

        $view = $describe(null);

        self::assertTrue($view->provisioned);
        self::assertFalse($view->authenticated);
        self::assertTrue($view->setupRequired);
        self::assertNull($view->user);
        self::assertNull($view->workspace);
    }

    public function testAnonymousRequestDoesNotRequireSetupOnceTheOwnerHasAPassword(): void
    {
        $describe = new DescribeSession(
            new InMemoryAuthenticationUserRepository(owner: self::owner(hasPassword: true)),
            new InMemoryWorkspaceMembershipReader(),
        );

        $view = $describe(null);

        self::assertTrue($view->provisioned);
        self::assertFalse($view->authenticated);
        self::assertFalse($view->setupRequired);
    }

    public function testAnonymousRequestBeforeProvisioningReportsNotProvisioned(): void
    {
        $describe = new DescribeSession(
            new InMemoryAuthenticationUserRepository(owner: null),
            new InMemoryWorkspaceMembershipReader(),
        );

        $view = $describe(null);

        self::assertFalse($view->provisioned);
        self::assertFalse($view->setupRequired);
    }

    public function testAuthenticatedRequestReturnsTheUserAndScopedWorkspace(): void
    {
        $owner = self::owner(hasPassword: true);
        $describe = new DescribeSession(
            new InMemoryAuthenticationUserRepository(owner: $owner),
            new InMemoryWorkspaceMembershipReader([$owner->id => new WorkspaceMembership('workspace-1', 'OWNER')]),
        );

        $view = $describe($owner->email);

        self::assertTrue($view->provisioned);
        self::assertTrue($view->authenticated);
        self::assertFalse($view->setupRequired);
        self::assertNotNull($view->user);
        self::assertSame($owner->id, $view->user->id);
        self::assertSame('owner@example.test', $view->user->email);
        self::assertNotNull($view->workspace);
        self::assertSame('workspace-1', $view->workspace->id);
        self::assertSame('OWNER', $view->workspace->role);
    }

    public function testAuthenticatedRequestWithoutMembershipReturnsNoWorkspace(): void
    {
        $owner = self::owner(hasPassword: true);
        $describe = new DescribeSession(
            new InMemoryAuthenticationUserRepository(owner: $owner),
            new InMemoryWorkspaceMembershipReader(),
        );

        $view = $describe($owner->email);

        self::assertTrue($view->authenticated);
        self::assertNull($view->workspace);
    }

    public function testASessionPointingAtADisabledUserIsTreatedAsAnonymous(): void
    {
        $owner = self::owner(hasPassword: true, disabledAt: new \DateTimeImmutable('2026-08-30T10:00:00+00:00'));
        $describe = new DescribeSession(
            new InMemoryAuthenticationUserRepository(owner: $owner),
            new InMemoryWorkspaceMembershipReader([$owner->id => new WorkspaceMembership('workspace-1', 'OWNER')]),
        );

        $view = $describe($owner->email);

        self::assertFalse($view->authenticated);
        self::assertNull($view->user);
    }

    public function testASessionPointingAtAnUnknownUserIsTreatedAsAnonymous(): void
    {
        $describe = new DescribeSession(
            new InMemoryAuthenticationUserRepository(owner: self::owner(hasPassword: true)),
            new InMemoryWorkspaceMembershipReader(),
        );

        self::assertFalse($describe('ghost@example.test')->authenticated);
    }

    private static function owner(bool $hasPassword, ?\DateTimeImmutable $disabledAt = null): AuthenticatedUser
    {
        return new AuthenticatedUser(
            id: '00000000-0000-7000-8000-000000000001',
            email: 'owner@example.test',
            displayName: 'Owner',
            hasPassword: $hasPassword,
            disabledAt: $disabledAt,
        );
    }
}

final class InMemoryAuthenticationUserRepository implements AuthenticationUserRepository
{
    public function __construct(private readonly ?AuthenticatedUser $owner)
    {
    }

    public function findByEmail(string $email): ?AuthenticatedUser
    {
        if (null === $this->owner) {
            return null;
        }

        return 0 === strcasecmp($email, $this->owner->email) ? $this->owner : null;
    }

    public function findCredentialsByEmail(string $email): ?OwnerCredentials
    {
        $user = $this->findByEmail($email);
        if (null === $user || !$user->hasPassword) {
            return null;
        }

        return new OwnerCredentials($user->email, 'hash', $user->disabledAt);
    }

    public function findProvisionedOwner(): ?AuthenticatedUser
    {
        return $this->owner;
    }
}

final class InMemoryWorkspaceMembershipReader implements WorkspaceMembershipReader
{
    /**
     * @param array<string, WorkspaceMembership> $membershipsByUserId
     */
    public function __construct(private readonly array $membershipsByUserId = [])
    {
    }

    public function findForUser(string $userId): ?WorkspaceMembership
    {
        return $this->membershipsByUserId[$userId] ?? null;
    }
}
