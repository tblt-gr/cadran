<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Application;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\IdentityAuditEvents;
use App\Module\Identity\Application\RecordSessionEvent;
use App\Module\Identity\Application\SessionAuditIntent;
use PHPUnit\Framework\TestCase;

final class RecordSessionEventTest extends TestCase
{
    private const string EMAIL = 'owner@example.test';
    private const string USER_ID = '00000000-0000-7000-8000-000000000001';
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';

    public function testAKnownAccountIsRecordedAgainstItsWorkspaceWithoutADiff(): void
    {
        $auditEvents = new CollectingAuditEventRepository();

        $this->recorder($auditEvents)(new SessionAuditIntent(IdentityAuditEvents::SESSION_OPENED, self::EMAIL));

        self::assertCount(1, $auditEvents->events);
        $event = $auditEvents->events[0];
        self::assertSame(IdentityAuditEvents::SESSION_OPENED, $event->eventType);
        self::assertSame(self::WORKSPACE_ID, $event->workspace->id);
        self::assertSame(self::USER_ID, $event->actorId);
        self::assertSame(self::USER_ID, $event->entityId);
        self::assertTrue($event->diff->isEmpty());
    }

    public function testAnUnknownEmailIsNeverWrittenIntoTheTrail(): void
    {
        // The submitted string is attacker-controlled and belongs to no
        // workspace, so there is nothing to attribute and nothing to store.
        $auditEvents = new CollectingAuditEventRepository();

        $this->recorder($auditEvents)(new SessionAuditIntent(IdentityAuditEvents::SIGN_IN_FAILED, 'ghost@example.test'));

        self::assertSame([], $auditEvents->events);
    }

    public function testAnAccountWithoutAWorkspaceRecordsNothing(): void
    {
        $auditEvents = new CollectingAuditEventRepository();

        $this->recorder($auditEvents, workspaceId: null)(
            new SessionAuditIntent(IdentityAuditEvents::SESSION_CLOSED, self::EMAIL),
        );

        self::assertSame([], $auditEvents->events);
    }

    public function testAnIntentOnlyCarriesASessionEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SessionAuditIntent(IdentityAuditEvents::PASSWORD_DEFINED, self::EMAIL);
    }

    public function testAnIntentNamesTheAccountItConcerns(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SessionAuditIntent(IdentityAuditEvents::SESSION_OPENED, '');
    }

    private function recorder(
        CollectingAuditEventRepository $auditEvents,
        ?string $workspaceId = self::WORKSPACE_ID,
    ): RecordSessionEvent {
        $owner = new AuthenticatedUser(self::USER_ID, self::EMAIL, 'Owner', true, null);

        return new RecordSessionEvent(
            new StubAuthenticationUserRepository($owner),
            new StubWorkspaceMembershipReader($workspaceId),
            new RecordAuditEvent($auditEvents, new SequenceUuidGenerator()),
        );
    }
}
