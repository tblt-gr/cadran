<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditEvent;
use App\Module\Audit\Domain\AuditEventRepository;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Identity\Application\IdentityAuditEvents;
use App\Module\Identity\Application\InitialProvisioningAlreadyCompleted;
use App\Module\Identity\Application\InitialProvisioningGuard;
use App\Module\Identity\Application\InitialWorkspaceProvisioningInput;
use App\Module\Identity\Application\ProvisionInitialWorkspace;
use App\Module\Identity\Application\TransactionManager;
use App\Module\Identity\Domain\Membership;
use App\Module\Identity\Domain\MembershipRepository;
use App\Module\Identity\Domain\User;
use App\Module\Identity\Domain\UserRepository;
use App\Module\Identity\Domain\Workspace;
use App\Module\Identity\Domain\WorkspaceRepository;
use PHPUnit\Framework\TestCase;

final class ProvisionInitialWorkspaceTest extends TestCase
{
    public function testItAtomicallyCreatesAnOwnerAndAnIsolatedWorkspace(): void
    {
        $users = new InMemoryUserRepository();
        $workspaces = new InMemoryWorkspaceRepository();
        $memberships = new InMemoryMembershipRepository();
        $transactionManager = new RecordingTransactionManager();
        $auditEvents = new CollectingAuditEventRepository();
        $uuidGenerator = new SequenceUuidGenerator();

        $provision = new ProvisionInitialWorkspace(
            transactionManager: $transactionManager,
            provisioningGuard: new InMemoryInitialProvisioningGuard(),
            userRepository: $users,
            workspaceRepository: $workspaces,
            membershipRepository: $memberships,
            uuidGenerator: $uuidGenerator,
            recordAuditEvent: new RecordAuditEvent($auditEvents, $uuidGenerator),
        );

        $result = $provision(new InitialWorkspaceProvisioningInput(
            email: 'owner@example.test',
            displayName: 'Owner',
            workspaceName: 'Household',
            baseCurrency: 'EUR',
        ));

        self::assertSame(1, $transactionManager->transactionCount);
        self::assertSame('00000000-0000-7000-8000-000000000002', $result->workspaceId);
        self::assertSame('Europe/Paris', $workspaces->workspaces[0]->timezone);
        self::assertSame('EUR', $workspaces->workspaces[0]->baseCurrency);
        self::assertSame('OWNER', $memberships->memberships[0]->role);
        self::assertSame($users->users[0]->id, $memberships->memberships[0]->userId);
        self::assertSame($workspaces->workspaces[0]->id, $memberships->memberships[0]->workspaceId);

        // Provisioning is auditable from the first install: three events, all
        // scoped to the new workspace and none carrying an authenticated actor,
        // because the console command runs before any session can exist.
        self::assertSame([
            IdentityAuditEvents::WORKSPACE_CREATED,
            IdentityAuditEvents::USER_CREATED,
            IdentityAuditEvents::MEMBERSHIP_GRANTED,
        ], array_column($auditEvents->events, 'eventType'));
        self::assertSame(
            [$workspaces->workspaces[0]->id],
            array_unique(array_column($auditEvents->events, 'workspaceId')),
        );
        self::assertSame([null], array_unique(array_column($auditEvents->events, 'actorId')));
        self::assertSame(
            ['name' => 'Household', 'baseCurrency' => 'EUR', 'timezone' => 'Europe/Paris'],
            $auditEvents->events[0]->diff->after,
        );
    }

    public function testTheAuditTrailNeverCarriesTheOwnerEmail(): void
    {
        $auditEvents = new CollectingAuditEventRepository();
        $uuidGenerator = new SequenceUuidGenerator();
        $provision = new ProvisionInitialWorkspace(
            transactionManager: new RecordingTransactionManager(),
            provisioningGuard: new InMemoryInitialProvisioningGuard(),
            userRepository: new InMemoryUserRepository(),
            workspaceRepository: new InMemoryWorkspaceRepository(),
            membershipRepository: new InMemoryMembershipRepository(),
            uuidGenerator: $uuidGenerator,
            recordAuditEvent: new RecordAuditEvent($auditEvents, $uuidGenerator),
        );

        $provision(new InitialWorkspaceProvisioningInput(
            email: 'owner@example.test',
            displayName: 'Owner',
            workspaceName: 'Household',
            baseCurrency: 'EUR',
        ));

        foreach ($auditEvents->events as $event) {
            self::assertStringNotContainsString(
                'owner@example.test',
                (string) json_encode([$event->diff->before, $event->diff->after]),
            );
        }
    }

    public function testItRejectsAReplayWithoutCreatingASecondWorkspace(): void
    {
        $guard = new InMemoryInitialProvisioningGuard();
        $provision = new ProvisionInitialWorkspace(
            transactionManager: new RecordingTransactionManager(),
            provisioningGuard: $guard,
            userRepository: new InMemoryUserRepository(),
            workspaceRepository: new InMemoryWorkspaceRepository(),
            membershipRepository: new InMemoryMembershipRepository(),
            uuidGenerator: new SequenceUuidGenerator(),
            recordAuditEvent: new RecordAuditEvent(new CollectingAuditEventRepository(), new SequenceUuidGenerator()),
        );
        $input = new InitialWorkspaceProvisioningInput(
            email: 'owner@example.test',
            displayName: 'Owner',
            workspaceName: 'Household',
            baseCurrency: 'EUR',
        );

        $provision($input);

        $this->expectException(InitialProvisioningAlreadyCompleted::class);

        $provision($input);
    }

    public function testItRejectsAMalformedInputBeforeOpeningATransactionOrClaimingTheLatch(): void
    {
        $transactionManager = new RecordingTransactionManager();
        $guard = new InMemoryInitialProvisioningGuard();
        $users = new InMemoryUserRepository();

        $provision = new ProvisionInitialWorkspace(
            transactionManager: $transactionManager,
            provisioningGuard: $guard,
            userRepository: $users,
            workspaceRepository: new InMemoryWorkspaceRepository(),
            membershipRepository: new InMemoryMembershipRepository(),
            uuidGenerator: new SequenceUuidGenerator(),
            recordAuditEvent: new RecordAuditEvent(new CollectingAuditEventRepository(), new SequenceUuidGenerator()),
        );

        try {
            $provision(new InitialWorkspaceProvisioningInput(
                email: 'not-an-email',
                displayName: 'Owner',
                workspaceName: 'Household',
                baseCurrency: 'EUR',
            ));
            self::fail('A malformed email should have been rejected.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(0, $transactionManager->transactionCount);
        self::assertTrue($guard->tryAcquire(), 'The provisioning latch must still be free.');
        self::assertSame([], $users->users);
    }
}

final class RecordingTransactionManager implements TransactionManager
{
    public int $transactionCount = 0;

    public function transactional(\Closure $callback): mixed
    {
        ++$this->transactionCount;

        return $callback();
    }
}

final class InMemoryInitialProvisioningGuard implements InitialProvisioningGuard
{
    private bool $acquired = false;

    public function tryAcquire(): bool
    {
        if ($this->acquired) {
            return false;
        }

        $this->acquired = true;

        return true;
    }
}

final class InMemoryUserRepository implements UserRepository
{
    /** @var list<User> */
    public array $users = [];

    public function save(User $user): void
    {
        $this->users[] = $user;
    }
}

final class InMemoryWorkspaceRepository implements WorkspaceRepository
{
    /** @var list<Workspace> */
    public array $workspaces = [];

    public function save(Workspace $workspace): void
    {
        $this->workspaces[] = $workspace;
    }
}

final class InMemoryMembershipRepository implements MembershipRepository
{
    /** @var list<Membership> */
    public array $memberships = [];

    public function save(Membership $membership): void
    {
        $this->memberships[] = $membership;
    }
}

final class CollectingAuditEventRepository implements AuditEventRepository
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function append(AuditEvent $event): void
    {
        $this->events[] = $event;
    }
}

final class SequenceUuidGenerator implements UuidGenerator
{
    private int $sequence = 0;

    public function generate(): string
    {
        ++$this->sequence;

        return sprintf('00000000-0000-7000-8000-%012d', $this->sequence);
    }
}
