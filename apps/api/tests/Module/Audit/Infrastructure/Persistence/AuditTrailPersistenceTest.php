<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit\Infrastructure\Persistence;

use App\Module\Audit\Application\AuditTrailCursor;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Audit\Domain\AuditEvent;
use App\Module\Audit\Infrastructure\Persistence\DbalAuditEventRepository;
use App\Module\Audit\Infrastructure\Persistence\DbalAuditTrailReader;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The append-only, workspace-scoped guarantees of AUD-001 against a real
 * PostgreSQL schema: what the trail refuses to forget, and what it refuses to
 * show to the wrong workspace.
 */
final class AuditTrailPersistenceTest extends KernelTestCase
{
    private const string CREATED_AT = '2026-08-31 12:00:00.000000+00';
    private const string ACTOR_ID = WorkspaceFixture::OWNER_ID;

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private DbalAuditEventRepository $repository;
    private DbalAuditTrailReader $reader;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();

        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->repository = new DbalAuditEventRepository($connection);
        $this->reader = new DbalAuditTrailReader($connection);
        $this->databaseReady = true;
        $this->fixture->reset();
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }

        parent::tearDown();
    }

    public function testAnAppendedEventIsReadBackWithItsDiffAndActor(): void
    {
        $this->repository->append($this->event(
            id: '00000000-0000-7000-8000-000000000001',
            actorId: self::ACTOR_ID,
            diff: AuditDiff::change(['role' => 'VIEWER'], ['role' => 'OWNER']),
        ));

        $entries = $this->reader->readPage(WorkspaceFixture::own(), 10, null);

        self::assertCount(1, $entries);
        self::assertSame(self::ACTOR_ID, $entries[0]->actorId);
        self::assertSame(['role' => 'VIEWER'], $entries[0]->before);
        self::assertSame(['role' => 'OWNER'], $entries[0]->after);
    }

    public function testAnEventWithoutADiffStoresBothSidesAsNull(): void
    {
        $this->repository->append($this->event(id: '00000000-0000-7000-8000-000000000001'));

        $entries = $this->reader->readPage(WorkspaceFixture::own(), 10, null);

        self::assertNull($entries[0]->before);
        self::assertNull($entries[0]->after);
    }

    public function testAnotherWorkspaceTrailIsInvisible(): void
    {
        $this->repository->append($this->event(id: '00000000-0000-7000-8000-000000000001'));
        $this->repository->append($this->event(
            id: '00000000-0000-7000-8000-000000000002',
            workspaceId: WorkspaceFixture::OTHER_WORKSPACE,
        ));

        $own = $this->reader->readPage(WorkspaceFixture::own(), 10, null);
        $other = $this->reader->readPage(WorkspaceFixture::other(), 10, null);

        self::assertSame(['00000000-0000-7000-8000-000000000001'], array_column($own, 'id'));
        self::assertSame(['00000000-0000-7000-8000-000000000002'], array_column($other, 'id'));
    }

    public function testAnEventIsFoundByIdentifierInsideItsOwnWorkspace(): void
    {
        $this->repository->append($this->event(id: '00000000-0000-7000-8000-000000000001'));

        $entry = $this->reader->findEvent(WorkspaceFixture::own(), '00000000-0000-7000-8000-000000000001');

        self::assertNotNull($entry);
        self::assertSame('00000000-0000-7000-8000-000000000001', $entry->id);
    }

    public function testAnEventOfAnotherWorkspaceIsNotFoundByItsExactIdentifier(): void
    {
        // The identifier is correct and the row exists: only the workspace
        // predicate stands between the caller and someone else's record.
        $this->repository->append($this->event(
            id: '00000000-0000-7000-8000-000000000002',
            workspaceId: WorkspaceFixture::OTHER_WORKSPACE,
        ));

        self::assertNull($this->reader->findEvent(WorkspaceFixture::own(), '00000000-0000-7000-8000-000000000002'));
        self::assertNotNull($this->reader->findEvent(WorkspaceFixture::other(), '00000000-0000-7000-8000-000000000002'));
    }

    public function testACursorFromAnotherWorkspaceStillReadsNothingExtra(): void
    {
        $this->repository->append($this->event(
            id: '00000000-0000-7000-8000-000000000002',
            workspaceId: WorkspaceFixture::OTHER_WORKSPACE,
            occurredAt: '2026-08-31T12:00:02+00:00',
        ));
        $foreign = new AuditTrailCursor(
            new \DateTimeImmutable('2026-08-31T12:00:03+00:00'),
            '00000000-0000-7000-8000-000000000009',
        );

        self::assertSame([], $this->reader->readPage(WorkspaceFixture::own(), 10, $foreign));
    }

    public function testThePageIsOrderedNewestFirstAndResumesExactlyAfterTheCursor(): void
    {
        // Two events share a timestamp on purpose: only the identifier
        // separates them, which is why the cursor carries both.
        foreach ([
            ['00000000-0000-7000-8000-000000000001', '2026-08-31T12:00:01+00:00'],
            ['00000000-0000-7000-8000-000000000002', '2026-08-31T12:00:02+00:00'],
            ['00000000-0000-7000-8000-000000000003', '2026-08-31T12:00:02+00:00'],
        ] as [$id, $occurredAt]) {
            $this->repository->append($this->event(id: $id, occurredAt: $occurredAt));
        }

        $first = $this->reader->readPage(WorkspaceFixture::own(), 2, null);
        self::assertSame([
            '00000000-0000-7000-8000-000000000003',
            '00000000-0000-7000-8000-000000000002',
        ], array_column($first, 'id'));

        $next = $this->reader->readPage(
            WorkspaceFixture::own(),
            2,
            new AuditTrailCursor($first[1]->occurredAt, $first[1]->id),
        );

        self::assertSame(['00000000-0000-7000-8000-000000000001'], array_column($next, 'id'));
    }

    public function testHistoryCannotBeRewritten(): void
    {
        $this->repository->append($this->event(id: '00000000-0000-7000-8000-000000000001'));

        $this->expectException(DbalException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        $this->connection->executeStatement(
            "UPDATE audit_events SET event_type = 'session.closed' WHERE id = ?",
            ['00000000-0000-7000-8000-000000000001'],
        );
    }

    public function testHistoryCannotBeDeleted(): void
    {
        $this->repository->append($this->event(id: '00000000-0000-7000-8000-000000000001'));

        $this->expectException(DbalException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        $this->connection->executeStatement('DELETE FROM audit_events WHERE id = ?', ['00000000-0000-7000-8000-000000000001']);
    }

    public function testAnEventCannotNameAWorkspaceThatDoesNotExist(): void
    {
        $this->expectException(DbalException::class);
        $this->expectExceptionMessageMatches('/audit_events_workspace_fk/');

        $this->repository->append($this->event(
            id: '00000000-0000-7000-8000-000000000001',
            workspaceId: '00000000-0000-7000-8000-0000000000ff',
        ));
    }

    public function testTheDatabaseAlsoRefusesANonCanonicalEventType(): void
    {
        // The domain rejects it first; this proves a direct writer cannot slip
        // structured text past the schema either.
        $this->expectException(DbalException::class);
        $this->expectExceptionMessageMatches('/audit_events_event_type_format/');

        $this->connection->insert('audit_events', [
            'id' => '00000000-0000-7000-8000-000000000001',
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'actor_id' => null,
            'event_type' => "session.opened\nsession.closed",
            'entity_type' => 'user',
            'entity_id' => self::ACTOR_ID,
            'before_json' => null,
            'after_json' => null,
            'occurred_at' => self::CREATED_AT,
        ]);
    }

    /**
     * The complement of the CHECK test below: whatever AuditDiff accepts must
     * always be storable, or an audit write could abort the business operation
     * it belongs to.
     */
    public function testTheLargestDiffTheDomainAcceptsIsStorable(): void
    {
        $attributes = [];
        for ($index = 1; $index <= 12; ++$index) {
            $attributes['attribute'.$index] = str_repeat('x', AuditDiff::MAX_VALUE_LENGTH);
        }

        $this->repository->append($this->event(
            id: '00000000-0000-7000-8000-000000000001',
            diff: AuditDiff::change($attributes, $attributes),
        ));

        $entries = $this->reader->readPage(WorkspaceFixture::own(), 10, null);

        self::assertCount(12, $entries[0]->after ?? []);
    }

    public function testTheDatabaseRefusesAnUnboundedDiff(): void
    {
        $this->expectException(DbalException::class);
        $this->expectExceptionMessageMatches('/audit_events_diff_is_bounded/');

        $this->connection->insert('audit_events', [
            'id' => '00000000-0000-7000-8000-000000000001',
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'actor_id' => null,
            'event_type' => 'user.created',
            'entity_type' => 'user',
            'entity_id' => self::ACTOR_ID,
            'before_json' => null,
            'after_json' => json_encode(['name' => str_repeat('x', 5000)], JSON_THROW_ON_ERROR),
            'occurred_at' => self::CREATED_AT,
        ]);
    }

    private function event(
        string $id,
        string $workspaceId = WorkspaceFixture::OWN_WORKSPACE,
        ?string $actorId = null,
        ?AuditDiff $diff = null,
        string $occurredAt = '2026-08-31T12:00:00+00:00',
    ): AuditEvent {
        return new AuditEvent(
            id: $id,
            workspace: WorkspaceScope::fromString($workspaceId),
            actorId: $actorId,
            eventType: 'session.opened',
            entityType: 'user',
            entityId: self::ACTOR_ID,
            diff: $diff ?? AuditDiff::none(),
            occurredAt: new \DateTimeImmutable($occurredAt),
        );
    }
}
