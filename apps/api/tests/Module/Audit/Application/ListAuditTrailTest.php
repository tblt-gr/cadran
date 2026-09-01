<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit\Application;

use App\Module\Audit\Application\AuditTrailCursor;
use App\Module\Audit\Application\AuditTrailEntry;
use App\Module\Audit\Application\AuditTrailReader;
use App\Module\Audit\Application\InvalidAuditTrailQuery;
use App\Module\Audit\Application\ListAuditTrail;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListAuditTrailTest extends TestCase
{
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';

    public function testItReadsOnlyTheWorkspaceResolvedFromTheCaller(): void
    {
        $reader = new RecordingAuditTrailReader(self::entries(3));
        $list = new ListAuditTrail(new StubCallerWorkspace(self::WORKSPACE_ID), $reader);

        $page = $list(null, null);

        self::assertNotNull($reader->workspace);
        self::assertSame(self::WORKSPACE_ID, $reader->workspace->id);
        self::assertCount(3, $page->entries);
        self::assertNull($page->nextCursor);
    }

    public function testACallerWithoutAMembershipIsDeniedRatherThanShownEverything(): void
    {
        $reader = new RecordingAuditTrailReader(self::entries(3));
        $list = new ListAuditTrail(new StubCallerWorkspace(null), $reader);

        try {
            $list(null, null);
            self::fail('A caller with no workspace must be denied.');
        } catch (WorkspaceAccessDenied) {
        }

        self::assertNull($reader->workspace, 'The reader must never be queried without a workspace.');
    }

    public function testAFullPageAdvertisesTheCursorOfItsLastEntry(): void
    {
        // The reader is asked for one row more than the page size, and that
        // extra row must not leak into the response.
        $reader = new RecordingAuditTrailReader(self::entries(3));
        $list = new ListAuditTrail(new StubCallerWorkspace(self::WORKSPACE_ID), $reader);

        $page = $list(2, null);

        self::assertSame(3, $reader->limit);
        self::assertCount(2, $page->entries);
        self::assertNotNull($page->nextCursor);

        $cursor = AuditTrailCursor::decode($page->nextCursor);
        self::assertSame($page->entries[1]->id, $cursor->eventId);
    }

    public function testItDefaultsToTheDocumentedPageSize(): void
    {
        $reader = new RecordingAuditTrailReader([]);
        $list = new ListAuditTrail(new StubCallerWorkspace(self::WORKSPACE_ID), $reader);

        $list(null, null);

        self::assertSame(ListAuditTrail::DEFAULT_PAGE_SIZE + 1, $reader->limit);
    }

    #[DataProvider('outOfBoundsPageSizes')]
    public function testItRefusesAnUnboundedPageSize(int $limit): void
    {
        $list = new ListAuditTrail(new StubCallerWorkspace(self::WORKSPACE_ID), new RecordingAuditTrailReader([]));

        $this->expectException(InvalidAuditTrailQuery::class);

        $list($limit, null);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfBoundsPageSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above the ceiling' => [ListAuditTrail::MAX_PAGE_SIZE + 1];
    }

    public function testItRefusesACursorItDidNotIssue(): void
    {
        $list = new ListAuditTrail(new StubCallerWorkspace(self::WORKSPACE_ID), new RecordingAuditTrailReader([]));

        $this->expectException(InvalidAuditTrailQuery::class);

        $list(null, 'forged');
    }

    /**
     * @return list<AuditTrailEntry>
     */
    private static function entries(int $count): array
    {
        $entries = [];
        for ($index = 1; $index <= $count; ++$index) {
            $entries[] = new AuditTrailEntry(
                id: sprintf('00000000-0000-7000-8000-%012d', $index),
                actorId: null,
                eventType: 'session.opened',
                entityType: 'user',
                entityId: '00000000-0000-7000-8000-0000000000c1',
                before: null,
                after: null,
                occurredAt: new \DateTimeImmutable(sprintf('2026-08-31T10:00:%02d+00:00', $index)),
            );
        }

        return $entries;
    }
}

final class StubCallerWorkspace implements CallerWorkspace
{
    public function __construct(private readonly ?string $workspaceId)
    {
    }

    public function resolve(): WorkspaceScope
    {
        if (null === $this->workspaceId) {
            throw new WorkspaceAccessDenied('No workspace for this caller.');
        }

        return WorkspaceScope::fromString($this->workspaceId);
    }
}

final class RecordingAuditTrailReader implements AuditTrailReader
{
    public ?WorkspaceScope $workspace = null;
    public int $findEventCalls = 0;
    public ?int $limit = null;
    public ?AuditTrailCursor $after = null;

    /**
     * @param list<AuditTrailEntry> $entries
     */
    public function __construct(private readonly array $entries)
    {
    }

    public function readPage(WorkspaceScope $workspace, int $limit, ?AuditTrailCursor $after): array
    {
        $this->workspace = $workspace;
        $this->limit = $limit;
        $this->after = $after;

        return array_slice($this->entries, 0, $limit);
    }

    public function findEvent(WorkspaceScope $workspace, string $eventId): ?AuditTrailEntry
    {
        $this->workspace = $workspace;
        ++$this->findEventCalls;

        foreach ($this->entries as $entry) {
            if ($entry->id === $eventId) {
                return $entry;
            }
        }

        return null;
    }
}
