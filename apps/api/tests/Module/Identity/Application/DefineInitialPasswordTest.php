<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\AuthenticationUserRepository;
use App\Module\Identity\Application\DefineInitialPassword;
use App\Module\Identity\Application\DefineInitialPasswordInput;
use App\Module\Identity\Application\IdentityAuditEvents;
use App\Module\Identity\Application\InitialPasswordAlreadyDefined;
use App\Module\Identity\Application\OwnerAccountNotProvisioned;
use App\Module\Identity\Application\OwnerCredentials;
use App\Module\Identity\Application\OwnerPasswordWriter;
use App\Module\Identity\Application\WorkspaceMembership;
use App\Module\Identity\Application\WorkspaceMembershipReader;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Domain\PlainPassword;
use App\Module\Identity\Domain\WeakPassword;
use PHPUnit\Framework\TestCase;

final class DefineInitialPasswordTest extends TestCase
{
    private const string VALID_PASSWORD = 'correct horse battery staple';
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';

    public function testItHashesAndStoresThePasswordForAnOwnerWithoutOne(): void
    {
        $writer = new RecordingOwnerPasswordWriter(applied: true);
        $auditEvents = new CollectingAuditEventRepository();
        $define = self::useCase(self::owner(hasPassword: false), $writer, auditEvents: $auditEvents);

        $define(new DefineInitialPasswordInput(self::VALID_PASSWORD));

        self::assertSame('00000000-0000-7000-8000-000000000001', $writer->userId);
        self::assertSame('hashed:'.self::VALID_PASSWORD, $writer->passwordHash);

        // The event states that a password was defined and nothing about it.
        self::assertCount(1, $auditEvents->events);
        self::assertSame(IdentityAuditEvents::PASSWORD_DEFINED, $auditEvents->events[0]->eventType);
        self::assertSame(self::WORKSPACE_ID, $auditEvents->events[0]->workspace->id);
        self::assertNull($auditEvents->events[0]->actorId, 'The first-run request carries no session.');
        self::assertTrue($auditEvents->events[0]->diff->isEmpty());
    }

    public function testAConcurrentLoserWritesNoAuditEventEither(): void
    {
        $auditEvents = new CollectingAuditEventRepository();
        $define = self::useCase(
            self::owner(hasPassword: false),
            new RecordingOwnerPasswordWriter(applied: false),
            auditEvents: $auditEvents,
        );

        $this->expectException(InitialPasswordAlreadyDefined::class);

        try {
            $define(new DefineInitialPasswordInput(self::VALID_PASSWORD));
        } finally {
            self::assertSame([], $auditEvents->events);
        }
    }

    public function testItRejectsAnOwnerThatAlreadyHasAPassword(): void
    {
        $writer = new RecordingOwnerPasswordWriter(applied: true);
        $define = self::useCase(self::owner(hasPassword: true), $writer);

        $this->expectException(InitialPasswordAlreadyDefined::class);

        try {
            $define(new DefineInitialPasswordInput(self::VALID_PASSWORD));
        } finally {
            self::assertNull($writer->userId, 'The writer must not be touched.');
        }
    }

    public function testItRejectsWhenNoOwnerHasBeenProvisioned(): void
    {
        $define = self::useCase(null, new RecordingOwnerPasswordWriter(applied: true));

        $this->expectException(OwnerAccountNotProvisioned::class);

        $define(new DefineInitialPasswordInput(self::VALID_PASSWORD));
    }

    public function testItRejectsAPasswordThatBreaksThePolicyWithoutHashingOrWriting(): void
    {
        $writer = new RecordingOwnerPasswordWriter(applied: true);
        $hasher = new PrefixPasswordHasher();
        $define = self::useCase(self::owner(hasPassword: false), $writer, $hasher);

        $this->expectException(WeakPassword::class);

        try {
            $define(new DefineInitialPasswordInput('short'));
        } finally {
            self::assertSame(0, $hasher->calls);
            self::assertNull($writer->userId);
        }
    }

    public function testItReportsAConcurrentCallThatWonTheCompareAndSet(): void
    {
        $define = self::useCase(self::owner(hasPassword: false), new RecordingOwnerPasswordWriter(applied: false));

        $this->expectException(InitialPasswordAlreadyDefined::class);

        $define(new DefineInitialPasswordInput(self::VALID_PASSWORD));
    }

    public function testAnOwnerWithoutAWorkspaceIsNotAUsableInstall(): void
    {
        // A provisioned owner always has a workspace. Without one there is no
        // scope to audit into, so the flow refuses rather than storing a
        // password no trail can account for.
        $writer = new RecordingOwnerPasswordWriter(applied: true);
        $define = self::useCase(self::owner(hasPassword: false), $writer, workspaceId: null);

        $this->expectException(OwnerAccountNotProvisioned::class);

        try {
            $define(new DefineInitialPasswordInput(self::VALID_PASSWORD));
        } finally {
            self::assertNull($writer->userId, 'No password may be stored.');
        }
    }

    private static function useCase(
        ?AuthenticatedUser $owner,
        RecordingOwnerPasswordWriter $writer,
        ?PrefixPasswordHasher $hasher = null,
        ?CollectingAuditEventRepository $auditEvents = null,
        ?string $workspaceId = self::WORKSPACE_ID,
    ): DefineInitialPassword {
        return new DefineInitialPassword(
            new RecordingTransactionManager(),
            new StubAuthenticationUserRepository($owner),
            new StubWorkspaceMembershipReader($workspaceId),
            $writer,
            $hasher ?? new PrefixPasswordHasher(),
            new RecordAuditEvent($auditEvents ?? new CollectingAuditEventRepository(), new SequenceUuidGenerator()),
        );
    }

    private static function owner(bool $hasPassword): AuthenticatedUser
    {
        return new AuthenticatedUser(
            id: '00000000-0000-7000-8000-000000000001',
            email: 'owner@example.test',
            displayName: 'Owner',
            hasPassword: $hasPassword,
            disabledAt: null,
        );
    }
}

final class StubAuthenticationUserRepository implements AuthenticationUserRepository
{
    public function __construct(private readonly ?AuthenticatedUser $owner)
    {
    }

    public function findByEmail(string $email): ?AuthenticatedUser
    {
        return null !== $this->owner && 0 === strcasecmp($email, $this->owner->email) ? $this->owner : null;
    }

    public function findCredentialsByEmail(string $email): ?OwnerCredentials
    {
        $user = $this->findByEmail($email);

        return null === $user || !$user->hasPassword ? null : new OwnerCredentials($user->email, 'hash', null);
    }

    public function findProvisionedOwner(): ?AuthenticatedUser
    {
        return $this->owner;
    }
}

final class StubWorkspaceMembershipReader implements WorkspaceMembershipReader
{
    public function __construct(private readonly ?string $workspaceId)
    {
    }

    public function findForUser(string $userId): ?WorkspaceMembership
    {
        return null === $this->workspaceId ? null : new WorkspaceMembership(WorkspaceScope::fromString($this->workspaceId), 'OWNER');
    }
}

final class RecordingOwnerPasswordWriter implements OwnerPasswordWriter
{
    public ?string $userId = null;
    public ?string $passwordHash = null;

    public function __construct(private readonly bool $applied)
    {
    }

    public function storeInitialHash(string $userId, string $passwordHash): bool
    {
        $this->userId = $userId;
        $this->passwordHash = $passwordHash;

        return $this->applied;
    }
}

final class PrefixPasswordHasher implements PasswordHasher
{
    public int $calls = 0;

    public function hash(PlainPassword $password): string
    {
        ++$this->calls;

        return 'hashed:'.$password->value;
    }
}
