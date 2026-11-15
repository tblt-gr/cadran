<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\IdentityAuditEvents;
use App\Module\Identity\Application\NewPasswordReused;
use App\Module\Identity\Application\OwnerAccountNotProvisioned;
use App\Module\Identity\Application\OwnerCredentials;
use App\Module\Identity\Application\OwnerPasswordPolicy;
use App\Module\Identity\Application\ResetOwnerPassword;
use App\Module\Identity\Application\ResetOwnerPasswordInput;
use App\Module\Identity\Domain\CommonPassword;
use App\Module\Identity\Domain\WeakPassword;
use App\Module\Identity\Infrastructure\Security\FilePasswordList;
use PHPUnit\Framework\TestCase;

final class ResetOwnerPasswordTest extends TestCase
{
    private const string USER_ID = '00000000-0000-7000-8000-000000000001';
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';
    private const string CURRENT_PASSWORD = 'correct horse battery staple';
    private const string NEW_PASSWORD = 'a different long passphrase';

    public function testItReplacesTheHashClosesEverySessionAndAuditsOnce(): void
    {
        $writer = new ReplacingOwnerPasswordWriter(applied: true);
        $sessions = new CountingOwnerSessionRegistry();
        $auditEvents = new CollectingAuditEventRepository();
        $reset = self::useCase($writer, $sessions, auditEvents: $auditEvents);

        $reset(new ResetOwnerPasswordInput(self::NEW_PASSWORD));

        self::assertSame(self::USER_ID, $writer->userId);
        self::assertSame('hashed:'.self::CURRENT_PASSWORD, $writer->expectedHash);
        self::assertSame('hashed:'.self::NEW_PASSWORD, $writer->newHash);
        self::assertSame(1, $sessions->revocations);
        self::assertNull($sessions->keptSessionId, 'Every session is closed, none is spared.');

        self::assertCount(1, $auditEvents->events);
        self::assertSame(IdentityAuditEvents::PASSWORD_RESET, $auditEvents->events[0]->eventType);
        self::assertSame('user.password_reset', $auditEvents->events[0]->eventType);
        self::assertSame(self::WORKSPACE_ID, $auditEvents->events[0]->workspace->id);
        self::assertSame(self::USER_ID, $auditEvents->events[0]->entityId);
        self::assertTrue($auditEvents->events[0]->diff->isEmpty());

        $encoded = (string) json_encode($auditEvents->events);
        self::assertStringNotContainsString(self::NEW_PASSWORD, $encoded);
        self::assertStringNotContainsString(self::CURRENT_PASSWORD, $encoded);
    }

    public function testATooShortPasswordIsRefusedAndNothingChanges(): void
    {
        $this->assertRefused(WeakPassword::class, 'short');
    }

    public function testATooLongPasswordIsRefusedAndNothingChanges(): void
    {
        $this->assertRefused(WeakPassword::class, str_repeat('a', 129));
    }

    public function testACommonPasswordIsRefusedAndNothingChanges(): void
    {
        $this->assertRefused(CommonPassword::class, 'MotherFucker');
    }

    public function testThePasswordAlreadyInUseIsRefusedAndNothingChanges(): void
    {
        $this->assertRefused(NewPasswordReused::class, self::CURRENT_PASSWORD);
    }

    public function testAnInstallWithoutAnOwnerIsRefused(): void
    {
        $writer = new ReplacingOwnerPasswordWriter(applied: true);
        $reset = self::useCase($writer, owner: null);

        $this->expectException(OwnerAccountNotProvisioned::class);

        try {
            $reset(new ResetOwnerPasswordInput(self::NEW_PASSWORD));
        } finally {
            self::assertNull($writer->userId);
        }
    }

    public function testAConcurrentChangeLeavesNoSessionRevocationNorEvent(): void
    {
        $sessions = new CountingOwnerSessionRegistry();
        $auditEvents = new CollectingAuditEventRepository();
        $reset = self::useCase(new ReplacingOwnerPasswordWriter(applied: false), $sessions, auditEvents: $auditEvents);

        try {
            $reset(new ResetOwnerPasswordInput(self::NEW_PASSWORD));
            self::fail('The lost compare-and-set must abort the reset.');
        } catch (\RuntimeException) {
            self::assertSame(0, $sessions->revocations);
            self::assertSame([], $auditEvents->events);
        }
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    private function assertRefused(string $exception, string $password): void
    {
        $writer = new ReplacingOwnerPasswordWriter(applied: true);
        $sessions = new CountingOwnerSessionRegistry();
        $auditEvents = new CollectingAuditEventRepository();
        $reset = self::useCase($writer, $sessions, auditEvents: $auditEvents);

        try {
            $reset(new ResetOwnerPasswordInput($password));
            self::fail('The password must be refused.');
        } catch (\Throwable $caught) {
            self::assertInstanceOf($exception, $caught);
        }

        self::assertNull($writer->userId);
        self::assertSame(0, $sessions->revocations);
        self::assertSame([], $auditEvents->events);
    }

    private static function useCase(
        ReplacingOwnerPasswordWriter $writer,
        ?CountingOwnerSessionRegistry $sessions = null,
        ?CollectingAuditEventRepository $auditEvents = null,
        ?AuthenticatedUser $owner = new AuthenticatedUser(
            self::USER_ID,
            'owner@example.test',
            'Owner',
            true,
            null,
        ),
    ): ResetOwnerPassword {
        $hasher = new PrefixPasswordHasher();

        return new ResetOwnerPassword(
            new RecordingTransactionManager(),
            new ProvisionedOwnerRepository($owner, new OwnerCredentials('owner@example.test', 'hashed:'.self::CURRENT_PASSWORD, null)),
            new StubWorkspaceMembershipReader(self::WORKSPACE_ID),
            $writer,
            $sessions ?? new CountingOwnerSessionRegistry(),
            $hasher,
            new RecordAuditEvent($auditEvents ?? new CollectingAuditEventRepository(), new SequenceUuidGenerator()),
            new OwnerPasswordPolicy(new FilePasswordList(), $hasher),
        );
    }
}

final class ProvisionedOwnerRepository implements \App\Module\Identity\Application\AuthenticationUserRepository
{
    public function __construct(
        private readonly ?AuthenticatedUser $owner,
        private readonly OwnerCredentials $credentials,
    ) {
    }

    public function findByEmail(string $email): ?AuthenticatedUser
    {
        throw new \LogicException('The reset flow starts from the provisioned owner.');
    }

    public function findById(string $userId): ?AuthenticatedUser
    {
        throw new \LogicException('The reset flow starts from the provisioned owner.');
    }

    public function findCredentialsByEmail(string $email): ?OwnerCredentials
    {
        throw new \LogicException('The reset flow looks credentials up by identifier.');
    }

    public function findCredentialsById(string $userId): ?OwnerCredentials
    {
        return null !== $this->owner && $userId === $this->owner->id ? $this->credentials : null;
    }

    public function findProvisionedOwner(): ?AuthenticatedUser
    {
        return $this->owner;
    }
}
