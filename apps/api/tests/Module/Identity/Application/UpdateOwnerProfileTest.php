<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\AuthenticationUserRepository;
use App\Module\Identity\Application\IdentityAuditEvents;
use App\Module\Identity\Application\OwnerAccountNotProvisioned;
use App\Module\Identity\Application\OwnerCredentials;
use App\Module\Identity\Application\OwnerProfileWriter;
use App\Module\Identity\Application\UpdateOwnerProfile;
use App\Module\Identity\Application\UpdateOwnerProfileInput;
use App\Module\Identity\Domain\DisplayName;
use App\Module\Identity\Domain\InvalidDisplayName;
use PHPUnit\Framework\TestCase;

final class UpdateOwnerProfileTest extends TestCase
{
    private const string USER_ID = '00000000-0000-7000-8000-000000000001';
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';
    private const string CURRENT_NAME = 'Owner';

    public function testItStoresTheNewNameAndReturnsTheUpdatedProfile(): void
    {
        $writer = new RecordingOwnerProfileWriter(applied: true);
        $auditEvents = new CollectingAuditEventRepository();
        $update = self::useCase($writer, $auditEvents);

        $profile = $update(new UpdateOwnerProfileInput('Marie Dupont'));

        self::assertSame(self::USER_ID, $profile->id);
        self::assertSame('owner@example.test', $profile->email);
        self::assertSame('Marie Dupont', $profile->displayName);
        self::assertSame('Marie Dupont', $writer->displayName);

        self::assertCount(1, $auditEvents->events);
        self::assertSame(IdentityAuditEvents::PROFILE_UPDATED, $auditEvents->events[0]->eventType);
        self::assertSame(self::USER_ID, $auditEvents->events[0]->actorId);
        self::assertSame(['displayName' => self::CURRENT_NAME], $auditEvents->events[0]->diff->before);
        self::assertSame(['displayName' => 'Marie Dupont'], $auditEvents->events[0]->diff->after);
    }

    public function testItStoresTheNameWithoutItsSurroundingWhitespace(): void
    {
        $writer = new RecordingOwnerProfileWriter(applied: true);
        $update = self::useCase($writer);

        $profile = $update(new UpdateOwnerProfileInput('  Marie Dupont  '));

        self::assertSame('Marie Dupont', $writer->displayName);
        self::assertSame('Marie Dupont', $profile->displayName);
    }

    public function testItRejectsAWhitespaceOnlyNameWithoutWriting(): void
    {
        $writer = new RecordingOwnerProfileWriter(applied: true);
        $auditEvents = new CollectingAuditEventRepository();
        $update = self::useCase($writer, $auditEvents);

        $this->expectException(InvalidDisplayName::class);

        try {
            $update(new UpdateOwnerProfileInput('   '));
        } finally {
            self::assertNull($writer->displayName);
            self::assertSame([], $auditEvents->events);
        }
    }

    public function testItRejectsAnOverLongNameWithoutWriting(): void
    {
        $writer = new RecordingOwnerProfileWriter(applied: true);
        $update = self::useCase($writer);

        $this->expectException(InvalidDisplayName::class);

        try {
            $update(new UpdateOwnerProfileInput(str_repeat('a', DisplayName::MAX_LENGTH + 1)));
        } finally {
            self::assertNull($writer->displayName);
        }
    }

    public function testAnUnchangedNameWritesNothingAndRecordsNoEvent(): void
    {
        $writer = new RecordingOwnerProfileWriter(applied: true);
        $auditEvents = new CollectingAuditEventRepository();
        $update = self::useCase($writer, $auditEvents);

        $profile = $update(new UpdateOwnerProfileInput('  '.self::CURRENT_NAME.'  '));

        self::assertSame(self::CURRENT_NAME, $profile->displayName);
        self::assertNull($writer->displayName, 'An identical name is not a change.');
        self::assertSame([], $auditEvents->events);
    }

    public function testAnAccountThatVanishedMidRequestIsRefused(): void
    {
        $update = self::useCase(new RecordingOwnerProfileWriter(applied: false));

        $this->expectException(OwnerAccountNotProvisioned::class);

        $update(new UpdateOwnerProfileInput('Marie Dupont'));
    }

    public function testACallerWithoutAWorkspaceIsRefused(): void
    {
        $writer = new RecordingOwnerProfileWriter(applied: true);
        $update = self::useCase($writer, authorized: false);

        $this->expectException(WorkspaceAccessDenied::class);

        try {
            $update(new UpdateOwnerProfileInput('Marie Dupont'));
        } finally {
            self::assertNull($writer->displayName);
        }
    }

    private static function useCase(
        RecordingOwnerProfileWriter $writer,
        ?CollectingAuditEventRepository $auditEvents = null,
        bool $authorized = true,
    ): UpdateOwnerProfile {
        return new UpdateOwnerProfile(
            new RecordingTransactionManager(),
            new StubCallerWorkspaceContext($authorized
                ? new WorkspaceContext(WorkspaceScope::fromString(self::WORKSPACE_ID), self::USER_ID)
                : null),
            new SingleOwnerRepository(self::USER_ID, self::CURRENT_NAME),
            $writer,
            new RecordAuditEvent($auditEvents ?? new CollectingAuditEventRepository(), new SequenceUuidGenerator()),
        );
    }
}

final class RecordingOwnerProfileWriter implements OwnerProfileWriter
{
    public ?string $userId = null;
    public ?string $displayName = null;

    public function __construct(private readonly bool $applied)
    {
    }

    public function updateDisplayName(string $userId, string $displayName): bool
    {
        $this->userId = $userId;
        $this->displayName = $displayName;

        return $this->applied;
    }
}

final class SingleOwnerRepository implements AuthenticationUserRepository
{
    public function __construct(
        private readonly string $userId,
        private readonly string $displayName,
    ) {
    }

    public function findByEmail(string $email): ?AuthenticatedUser
    {
        throw new \LogicException('The profile flow looks the account up by identifier.');
    }

    public function findById(string $userId): ?AuthenticatedUser
    {
        return $userId === $this->userId
            ? new AuthenticatedUser($this->userId, 'owner@example.test', $this->displayName, true, null)
            : null;
    }

    public function findCredentialsByEmail(string $email): ?OwnerCredentials
    {
        throw new \LogicException('The profile flow reads no credential.');
    }

    public function findCredentialsById(string $userId): ?OwnerCredentials
    {
        throw new \LogicException('The profile flow reads no credential.');
    }

    public function findProvisionedOwner(): ?AuthenticatedUser
    {
        throw new \LogicException('The profile flow never asks for the provisioned owner.');
    }
}
