<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\AuthenticationUserRepository;
use App\Module\Identity\Application\ChangeOwnerPassword;
use App\Module\Identity\Application\ChangeOwnerPasswordInput;
use App\Module\Identity\Application\CurrentSessionRenewal;
use App\Module\Identity\Application\IdentityAuditEvents;
use App\Module\Identity\Application\InvalidCurrentPassword;
use App\Module\Identity\Application\NewPasswordReused;
use App\Module\Identity\Application\OwnerAccountNotProvisioned;
use App\Module\Identity\Application\OwnerCredentials;
use App\Module\Identity\Application\OwnerPasswordWriter;
use App\Module\Identity\Application\OwnerSessionRegistry;
use App\Module\Identity\Domain\WeakPassword;
use PHPUnit\Framework\TestCase;

final class ChangeOwnerPasswordTest extends TestCase
{
    private const string USER_ID = '00000000-0000-7000-8000-000000000001';
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';
    private const string CURRENT_PASSWORD = 'correct horse battery staple';
    private const string NEW_PASSWORD = 'a different long passphrase';
    private const string SESSION_ID = 'the-callers-own-session';

    public function testItReplacesTheHashRevokesOtherSessionsAndKeepsTheCallersOwn(): void
    {
        $writer = new ReplacingOwnerPasswordWriter(applied: true);
        $sessions = new CountingOwnerSessionRegistry();
        $renewal = new CountingCurrentSessionRenewal();
        $auditEvents = new CollectingAuditEventRepository();
        $change = self::useCase($writer, $sessions, $renewal, auditEvents: $auditEvents);

        $change(self::request());

        // Compare-and-set against the hash that was just verified, never a
        // blind overwrite.
        self::assertSame(self::USER_ID, $writer->userId);
        self::assertSame('hashed:'.self::CURRENT_PASSWORD, $writer->expectedHash);
        self::assertSame('hashed:'.self::NEW_PASSWORD, $writer->newHash);
        self::assertSame(1, $sessions->revocations);
        self::assertSame(self::SESSION_ID, $sessions->keptSessionId, 'The caller keeps its own session.');
        self::assertSame(1, $renewal->renewals);

        self::assertCount(1, $auditEvents->events);
        self::assertSame(IdentityAuditEvents::PASSWORD_CHANGED, $auditEvents->events[0]->eventType);
        self::assertSame(self::WORKSPACE_ID, $auditEvents->events[0]->workspace->id);
        self::assertSame(self::USER_ID, $auditEvents->events[0]->actorId);
        self::assertTrue($auditEvents->events[0]->diff->isEmpty());
    }

    public function testTheAuditTrailNeverCarriesEitherPassword(): void
    {
        $auditEvents = new CollectingAuditEventRepository();
        $change = self::useCase(new ReplacingOwnerPasswordWriter(applied: true), auditEvents: $auditEvents);

        $change(self::request());

        $encoded = (string) json_encode($auditEvents->events);
        self::assertStringNotContainsString(self::CURRENT_PASSWORD, $encoded);
        self::assertStringNotContainsString(self::NEW_PASSWORD, $encoded);
    }

    public function testAWrongCurrentPasswordWritesNothing(): void
    {
        $writer = new ReplacingOwnerPasswordWriter(applied: true);
        $sessions = new CountingOwnerSessionRegistry();
        $renewal = new CountingCurrentSessionRenewal();
        $auditEvents = new CollectingAuditEventRepository();
        $change = self::useCase($writer, $sessions, $renewal, auditEvents: $auditEvents);

        $this->expectException(InvalidCurrentPassword::class);

        try {
            $change(self::request(currentPassword: 'not the current password'));
        } finally {
            self::assertNull($writer->userId, 'The stored hash must be left alone.');
            self::assertSame(0, $sessions->revocations);
            self::assertSame(0, $renewal->renewals);
        }
    }

    public function testAWrongCurrentPasswordIsRecordedAsAFailedAttempt(): void
    {
        // A burst of guesses against a hijacked session is what the owner needs
        // to see in the trail; the trail must still carry no credential.
        $auditEvents = new CollectingAuditEventRepository();
        $change = self::useCase(new ReplacingOwnerPasswordWriter(applied: true), auditEvents: $auditEvents);

        try {
            $change(self::request(currentPassword: 'not the current password'));
        } catch (InvalidCurrentPassword) {
            // Asserted below.
        }

        self::assertCount(1, $auditEvents->events);
        self::assertSame(IdentityAuditEvents::PASSWORD_CHANGE_FAILED, $auditEvents->events[0]->eventType);
        self::assertSame(self::USER_ID, $auditEvents->events[0]->actorId);
        self::assertTrue($auditEvents->events[0]->diff->isEmpty());
        self::assertStringNotContainsString(
            'not the current password',
            (string) json_encode($auditEvents->events),
        );
    }

    public function testANewPasswordThatBreaksThePolicyIsRefusedBeforeAnyCredentialWork(): void
    {
        $writer = new ReplacingOwnerPasswordWriter(applied: true);
        $hasher = new PrefixPasswordHasher();
        $change = self::useCase($writer, hasher: $hasher);

        $this->expectException(WeakPassword::class);

        try {
            $change(self::request(newPassword: 'short'));
        } finally {
            // Neither verification nor hashing: a malformed request must not be
            // able to spend the server's Argon2id budget.
            self::assertSame(0, $hasher->verifyCalls);
            self::assertSame(0, $hasher->calls);
            self::assertNull($writer->userId);
        }
    }

    public function testANewPasswordEqualToTheCurrentOneIsRefused(): void
    {
        $writer = new ReplacingOwnerPasswordWriter(applied: true);
        $sessions = new CountingOwnerSessionRegistry();
        $change = self::useCase($writer, $sessions);

        $this->expectException(NewPasswordReused::class);

        try {
            $change(self::request(newPassword: self::CURRENT_PASSWORD));
        } finally {
            self::assertNull($writer->userId);
            self::assertSame(0, $sessions->revocations);
        }
    }

    public function testAConcurrentChangeThatLandedFirstKeepsItsOwnPassword(): void
    {
        $sessions = new CountingOwnerSessionRegistry();
        $renewal = new CountingCurrentSessionRenewal();
        $auditEvents = new CollectingAuditEventRepository();
        $change = self::useCase(
            new ReplacingOwnerPasswordWriter(applied: false),
            $sessions,
            $renewal,
            auditEvents: $auditEvents,
        );

        $this->expectException(InvalidCurrentPassword::class);

        try {
            $change(self::request());
        } finally {
            // The compare-and-set is the first statement in the transaction:
            // the loser revokes no session and leaves no event behind.
            self::assertSame(0, $sessions->revocations);
            self::assertSame(0, $renewal->renewals);
            self::assertSame([], $auditEvents->events);
        }
    }

    public function testAnAccountThatVanishedMidSessionIsRefused(): void
    {
        $change = self::useCase(new ReplacingOwnerPasswordWriter(applied: true), credentials: null);

        $this->expectException(OwnerAccountNotProvisioned::class);

        $change(self::request());
    }

    public function testACallerWithoutAWorkspaceIsRefusedBeforeAnythingIsRead(): void
    {
        $writer = new ReplacingOwnerPasswordWriter(applied: true);
        $change = self::useCase($writer, authorized: false);

        $this->expectException(WorkspaceAccessDenied::class);

        try {
            $change(self::request());
        } finally {
            self::assertNull($writer->userId);
        }
    }

    private static function request(
        string $currentPassword = self::CURRENT_PASSWORD,
        string $newPassword = self::NEW_PASSWORD,
    ): ChangeOwnerPasswordInput {
        return new ChangeOwnerPasswordInput($currentPassword, $newPassword, self::SESSION_ID);
    }

    private static function useCase(
        ReplacingOwnerPasswordWriter $writer,
        ?CountingOwnerSessionRegistry $sessions = null,
        ?CountingCurrentSessionRenewal $renewal = null,
        ?PrefixPasswordHasher $hasher = null,
        ?CollectingAuditEventRepository $auditEvents = null,
        ?OwnerCredentials $credentials = new OwnerCredentials(
            'owner@example.test',
            'hashed:'.self::CURRENT_PASSWORD,
            null,
        ),
        bool $authorized = true,
    ): ChangeOwnerPassword {
        return new ChangeOwnerPassword(
            new RecordingTransactionManager(),
            new StubCallerWorkspaceContext($authorized ? self::context() : null),
            new CredentialLookupRepository(self::USER_ID, $credentials),
            $writer,
            $sessions ?? new CountingOwnerSessionRegistry(),
            $renewal ?? new CountingCurrentSessionRenewal(),
            $hasher ?? new PrefixPasswordHasher(),
            new RecordAuditEvent($auditEvents ?? new CollectingAuditEventRepository(), new SequenceUuidGenerator()),
        );
    }

    private static function context(): WorkspaceContext
    {
        return new WorkspaceContext(WorkspaceScope::fromString(self::WORKSPACE_ID), self::USER_ID);
    }
}

final class StubCallerWorkspaceContext implements CallerWorkspaceContext
{
    public function __construct(private readonly ?WorkspaceContext $context)
    {
    }

    public function resolveContext(): WorkspaceContext
    {
        return $this->context ?? throw new WorkspaceAccessDenied('The caller belongs to no workspace.');
    }
}

final class CountingOwnerSessionRegistry implements OwnerSessionRegistry
{
    public int $revocations = 0;
    public ?string $keptSessionId = null;

    public function revokeAllExcept(?string $sessionIdToKeep): void
    {
        ++$this->revocations;
        $this->keptSessionId = $sessionIdToKeep;
    }
}

final class CountingCurrentSessionRenewal implements CurrentSessionRenewal
{
    public int $renewals = 0;

    public function renew(): void
    {
        ++$this->renewals;
    }
}

final class ReplacingOwnerPasswordWriter implements OwnerPasswordWriter
{
    public ?string $userId = null;
    public ?string $expectedHash = null;
    public ?string $newHash = null;

    public function __construct(private readonly bool $applied)
    {
    }

    public function storeInitialHash(string $userId, string $passwordHash): bool
    {
        throw new \LogicException('The change flow never sets an initial hash.');
    }

    public function replaceHash(string $userId, string $expectedHash, string $newPasswordHash): bool
    {
        $this->userId = $userId;
        $this->expectedHash = $expectedHash;
        $this->newHash = $newPasswordHash;

        return $this->applied;
    }
}

final class CredentialLookupRepository implements AuthenticationUserRepository
{
    public function __construct(
        private readonly string $userId,
        private readonly ?OwnerCredentials $credentials,
    ) {
    }

    public function findByEmail(string $email): ?AuthenticatedUser
    {
        throw new \LogicException('The change flow looks the account up by identifier.');
    }

    public function findById(string $userId): ?AuthenticatedUser
    {
        if (null === $this->credentials || $userId !== $this->userId) {
            return null;
        }

        return new AuthenticatedUser(
            id: $this->userId,
            email: $this->credentials->email,
            displayName: 'Owner',
            hasPassword: true,
            disabledAt: null,
        );
    }

    public function findCredentialsByEmail(string $email): ?OwnerCredentials
    {
        throw new \LogicException('The change flow looks the account up by identifier.');
    }

    public function findCredentialsById(string $userId): ?OwnerCredentials
    {
        return $userId === $this->userId ? $this->credentials : null;
    }

    public function findProvisionedOwner(): ?AuthenticatedUser
    {
        throw new \LogicException('The change flow never asks for the provisioned owner.');
    }
}
