<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit\Application;

use App\Module\Audit\Application\AuditEventNotFound;
use App\Module\Audit\Application\AuditTrailEntry;
use App\Module\Audit\Application\ReadAuditEvent;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Object-level access control: the identifier comes from the URL, the scope
 * comes from the session, and only their conjunction returns anything.
 */
final class ReadAuditEventTest extends TestCase
{
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';
    private const string EVENT_ID = '00000000-0000-7000-8000-000000000001';

    public function testItReturnsAnEventOfTheCallerWorkspace(): void
    {
        $reader = new RecordingAuditTrailReader([self::entry()]);
        $read = new ReadAuditEvent(new StubCallerWorkspace(self::WORKSPACE_ID), $reader);

        $entry = $read(self::EVENT_ID);

        self::assertSame(self::EVENT_ID, $entry->id);
        self::assertNotNull($reader->workspace);
        self::assertSame(self::WORKSPACE_ID, $reader->workspace->id);
    }

    public function testACallerWithoutAWorkspaceIsDeniedBeforeTheIdentifierIsEvenLookedAt(): void
    {
        $reader = new RecordingAuditTrailReader([self::entry()]);
        $read = new ReadAuditEvent(new StubCallerWorkspace(null), $reader);

        try {
            $read(self::EVENT_ID);
            self::fail('A caller with no workspace must be denied.');
        } catch (WorkspaceAccessDenied) {
        }

        self::assertNull($reader->workspace, 'The reader must never be queried without a workspace.');
    }

    public function testAnIdentifierTheWorkspaceDoesNotOwnIsSimplyNotFound(): void
    {
        $read = new ReadAuditEvent(
            new StubCallerWorkspace(self::WORKSPACE_ID),
            new RecordingAuditTrailReader([]),
        );

        $this->expectException(AuditEventNotFound::class);

        $read(self::EVENT_ID);
    }

    /**
     * Nothing that is not a UUID reaches the reader: an identifier from a URL
     * is caller-controlled input, not a query fragment.
     */
    #[DataProvider('malformedIdentifiers')]
    public function testAMalformedIdentifierNeverReachesTheReader(string $eventId): void
    {
        $reader = new RecordingAuditTrailReader([self::entry()]);
        $read = new ReadAuditEvent(new StubCallerWorkspace(self::WORKSPACE_ID), $reader);

        try {
            $read($eventId);
            self::fail('A malformed identifier must be refused.');
        } catch (AuditEventNotFound) {
        }

        self::assertSame(0, $reader->findEventCalls, 'The reader must never see a malformed identifier.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'not a uuid' => ['latest'];
        yield 'sql fragment' => ["' OR '1'='1"];
        yield 'trailing predicate' => [self::EVENT_ID.' OR 1=1'];
    }

    private static function entry(): AuditTrailEntry
    {
        return new AuditTrailEntry(
            id: self::EVENT_ID,
            actorId: null,
            eventType: 'session.opened',
            entityType: 'user',
            entityId: '00000000-0000-7000-8000-0000000000c1',
            before: null,
            after: null,
            occurredAt: new \DateTimeImmutable('2026-08-31T10:00:00+00:00'),
        );
    }
}
