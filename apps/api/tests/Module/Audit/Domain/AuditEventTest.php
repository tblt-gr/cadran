<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit\Domain;

use App\Module\Audit\Domain\AuditDiff;
use App\Module\Audit\Domain\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditEventTest extends TestCase
{
    private const string EVENT_ID = '00000000-0000-7000-8000-000000000001';
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';
    private const string ENTITY_ID = '00000000-0000-7000-8000-0000000000b1';

    public function testAnEventWithoutAnAuthenticatedActorIsValid(): void
    {
        $event = self::event(actorId: null);

        self::assertNull($event->actorId);
        self::assertSame('workspace.created', $event->eventType);
    }

    public function testAnEventRequiresAWorkspace(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::event(workspaceId: '');
    }

    public function testAnEventRequiresAnEntity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::event(entityId: '');
    }

    public function testAnActorIsEitherAbsentOrIdentified(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::event(actorId: '');
    }

    #[DataProvider('malformedEventTypes')]
    public function testItRefusesAnEventTypeThatIsNotACanonicalName(string $eventType): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::event(eventType: $eventType);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedEventTypes(): iterable
    {
        yield 'no context' => ['created'];
        yield 'uppercase' => ['Workspace.Created'];
        yield 'newline injection' => ["workspace.created\nsession.opened"];
        yield 'spaces' => ['workspace created'];
        yield 'empty' => [''];
    }

    public function testItRefusesAnEntityTypeThatIsNotACanonicalName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::event(entityType: 'Workspace Record');
    }

    private static function event(
        string $workspaceId = self::WORKSPACE_ID,
        ?string $actorId = null,
        string $eventType = 'workspace.created',
        string $entityType = 'workspace',
        string $entityId = self::ENTITY_ID,
    ): AuditEvent {
        return new AuditEvent(
            id: self::EVENT_ID,
            workspaceId: $workspaceId,
            actorId: $actorId,
            eventType: $eventType,
            entityType: $entityType,
            entityId: $entityId,
            diff: AuditDiff::none(),
            occurredAt: new \DateTimeImmutable('2026-08-31T10:00:00+00:00'),
        );
    }
}
