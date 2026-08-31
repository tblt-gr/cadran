<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Audit\Domain\AuditEvent;
use App\Module\Audit\Domain\AuditEventRepository;
use App\Module\Foundation\Domain\UuidGenerator;
use PHPUnit\Framework\TestCase;

final class RecordAuditEventTest extends TestCase
{
    public function testItStampsTheEventWithItsOwnIdentifierAndTime(): void
    {
        $events = new InMemoryAuditEventRepository();
        $before = new \DateTimeImmutable();

        (new RecordAuditEvent($events, new FixedUuidGenerator()))(new AuditEventRecord(
            workspaceId: '00000000-0000-7000-8000-0000000000a1',
            actorId: '00000000-0000-7000-8000-0000000000c1',
            eventType: 'session.opened',
            entityType: 'user',
            entityId: '00000000-0000-7000-8000-0000000000c1',
            diff: AuditDiff::none(),
        ));

        self::assertCount(1, $events->events);
        $event = $events->events[0];
        self::assertSame(FixedUuidGenerator::VALUE, $event->id);
        self::assertGreaterThanOrEqual($before, $event->occurredAt);
        self::assertSame('00000000-0000-7000-8000-0000000000c1', $event->actorId);
    }

    public function testAMalformedRecordNeverReachesTheRepository(): void
    {
        $events = new InMemoryAuditEventRepository();

        try {
            (new RecordAuditEvent($events, new FixedUuidGenerator()))(new AuditEventRecord(
                workspaceId: '',
                actorId: null,
                eventType: 'session.opened',
                entityType: 'user',
                entityId: '00000000-0000-7000-8000-0000000000c1',
                diff: AuditDiff::none(),
            ));
            self::fail('An event without a workspace must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], $events->events);
    }
}

final class InMemoryAuditEventRepository implements AuditEventRepository
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function append(AuditEvent $event): void
    {
        $this->events[] = $event;
    }
}

final class FixedUuidGenerator implements UuidGenerator
{
    public const string VALUE = '00000000-0000-7000-8000-0000000000e1';

    public function generate(): string
    {
        return self::VALUE;
    }
}
