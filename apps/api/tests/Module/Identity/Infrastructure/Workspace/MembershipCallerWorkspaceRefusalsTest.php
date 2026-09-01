<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Infrastructure\Workspace;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\AuthenticationUserRepository;
use App\Module\Identity\Application\OwnerCredentials;
use App\Module\Identity\Application\WorkspaceMembership;
use App\Module\Identity\Application\WorkspaceMembershipReader;
use App\Module\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Identity\Infrastructure\Workspace\MembershipCallerWorkspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Every way the resolver refuses. Each one is what stops a use case from
 * running unscoped, so none of them may quietly become a fallback.
 */
final class MembershipCallerWorkspaceRefusalsTest extends TestCase
{
    private const string EMAIL = 'owner@example.test';
    private const string USER_ID = '00000000-0000-7000-8000-000000000001';
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';

    public function testAnAnonymousCallerIsDenied(): void
    {
        $this->expectException(WorkspaceAccessDenied::class);

        self::resolver(self::user(), self::WORKSPACE_ID, authenticated: false)->resolve();
    }

    public function testAnUnknownIdentifierIsDenied(): void
    {
        $this->expectException(WorkspaceAccessDenied::class);

        self::resolver(null, self::WORKSPACE_ID)->resolve();
    }

    public function testADisabledAccountLosesItsScopeEvenWithAValidSession(): void
    {
        $this->expectException(WorkspaceAccessDenied::class);

        self::resolver(self::user(disabled: true), self::WORKSPACE_ID)->resolve();
    }

    /**
     * A session must not outlive the credential it was opened with: once the
     * password is gone, so is the scope.
     */
    public function testAnAccountWithoutACredentialLosesItsScope(): void
    {
        $this->expectException(WorkspaceAccessDenied::class);

        self::resolver(self::user(hasPassword: false), self::WORKSPACE_ID)->resolve();
    }

    public function testAMemberOfNoWorkspaceIsDeniedRatherThanGivenEverything(): void
    {
        $this->expectException(WorkspaceAccessDenied::class);

        self::resolver(self::user(), null)->resolve();
    }

    private static function resolver(?AuthenticatedUser $user, ?string $workspaceId, bool $authenticated = true): MembershipCallerWorkspace
    {
        $tokenStorage = new TokenStorage();
        if ($authenticated) {
            $tokenStorage->setToken(new UsernamePasswordToken(
                new SecurityUser(self::EMAIL, 'irrelevant-hash', false),
                'main',
            ));
        }

        return new MembershipCallerWorkspace(
            $tokenStorage,
            new StubAuthenticationUserRepository($user),
            new StubMembershipReader($workspaceId),
        );
    }

    private static function user(bool $disabled = false, bool $hasPassword = true): AuthenticatedUser
    {
        return new AuthenticatedUser(
            id: self::USER_ID,
            email: self::EMAIL,
            displayName: 'Owner',
            hasPassword: $hasPassword,
            disabledAt: $disabled ? new \DateTimeImmutable('2026-08-31T10:00:00+00:00') : null,
        );
    }
}

final class StubAuthenticationUserRepository implements AuthenticationUserRepository
{
    public function __construct(private readonly ?AuthenticatedUser $user)
    {
    }

    public function findByEmail(string $email): ?AuthenticatedUser
    {
        return $this->user;
    }

    public function findCredentialsByEmail(string $email): ?OwnerCredentials
    {
        throw new \LogicException('The workspace resolver never reads credentials.');
    }

    public function findProvisionedOwner(): ?AuthenticatedUser
    {
        return $this->user;
    }
}

final class StubMembershipReader implements WorkspaceMembershipReader
{
    public function __construct(private readonly ?string $workspaceId)
    {
    }

    public function findForUser(string $userId): ?WorkspaceMembership
    {
        return null === $this->workspaceId
            ? null
            : new WorkspaceMembership(WorkspaceScope::fromString($this->workspaceId), 'OWNER');
    }
}
