<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\AccountWeight;
use App\Module\Reporting\Application\CaptureMissingSnapshots;
use App\Module\Reporting\Application\CaptureMonthSnapshot;
use App\Module\Reporting\Application\MonthFigures;
use App\Tests\Module\Reporting\Application\Double\CountingMonthlyProjector;
use App\Tests\Module\Reporting\Application\Double\InMemoryMonthSnapshotStore;
use App\Tests\Module\Reporting\Application\Double\InMemoryPeriodClosureRepository;
use App\Tests\Module\Reporting\Application\Double\MonthlyProjectionFixture;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CaptureMissingSnapshotsTest extends TestCase
{
    private CountingMonthlyProjector $projector;
    private InMemoryPeriodClosureRepository $closures;
    private InMemoryMonthSnapshotStore $snapshots;
    private WorkspaceCalendar $calendar;

    protected function setUp(): void
    {
        $this->projector = new CountingMonthlyProjector();
        $this->closures = new InMemoryPeriodClosureRepository();
        $this->snapshots = new InMemoryMonthSnapshotStore();
        $caller = new readonly class implements CallerWorkspaceContext {
            public function resolveContext(): WorkspaceContext
            {
                return new WorkspaceContext(WorkspaceFixture::own(), WorkspaceFixture::OWNER_ID, true);
            }
        };
        $timezones = $this->createStub(WorkspaceTimezoneReader::class);
        $timezones->method('timezone')->willReturn('UTC');
        $this->calendar = new WorkspaceCalendar(new MockClock('2026-09-24T12:00:00+00:00'), $caller, $timezones);
    }

    public function testItCapturesOnlyTheClosedMonthsThatLackASnapshotAndIsIdempotent(): void
    {
        $this->closures->add($this->closure('c1', WorkspaceFixture::own(), new CalendarMonth(2025, 1)));
        $this->closures->add($this->closure('c2', WorkspaceFixture::own(), new CalendarMonth(2025, 2)));
        $this->closures->add($this->closure('c3', WorkspaceFixture::own(), new CalendarMonth(2025, 3)));
        $this->snapshots->capture(WorkspaceFixture::own(), new CalendarMonth(2025, 2), 'c2', $this->figures('2025-02', true), new \DateTimeImmutable());

        $first = ($this->use())(WorkspaceFixture::own());

        self::assertSame(2, $first->captured);
        self::assertSame(0, $first->skipped);
        self::assertSame(2, $this->projector->calls);
        self::assertNotNull($this->snapshots->find(WorkspaceFixture::own(), new CalendarMonth(2025, 1), 'c1'));
        self::assertNotNull($this->snapshots->find(WorkspaceFixture::own(), new CalendarMonth(2025, 3), 'c3'));

        $second = ($this->use())(WorkspaceFixture::own());

        self::assertSame(0, $second->captured);
        self::assertSame(2, $this->projector->calls);
    }

    public function testASnapshotWrittenBeforeAccountValuesWereKeptIsReplaced(): void
    {
        $month = new CalendarMonth(2025, 1);
        $this->closures->add($this->closure('c1', WorkspaceFixture::own(), $month));
        $this->snapshots->capture(WorkspaceFixture::own(), $month, 'c1', $this->figures('2025-01', false), new \DateTimeImmutable());

        $result = ($this->use())(WorkspaceFixture::own());

        self::assertSame(1, $result->captured);
        self::assertNotNull($this->snapshots->find(WorkspaceFixture::own(), $month, 'c1')?->accountWeights);
    }

    public function testAnOpenMonthIsNeverCaptured(): void
    {
        $this->closures->add($this->closure('c1', WorkspaceFixture::own(), new CalendarMonth(2025, 1))->reopen('Corrections', new \DateTimeImmutable('2026-09-02T10:00:00+00:00')));

        $result = ($this->use())(WorkspaceFixture::own());

        self::assertSame(0, $result->captured);
        self::assertSame(0, $this->projector->calls);
        self::assertSame([], $this->snapshots->snapshots);
    }

    public function testAMonthReopenedWhileTheCommandRunsIsSkipped(): void
    {
        $this->closures->add($this->closure('c1', WorkspaceFixture::own(), new CalendarMonth(2025, 1)));
        $closures = $this->closures;
        $boundary = new class($closures) implements TransactionBoundary {
            public function __construct(private readonly InMemoryPeriodClosureRepository $closures)
            {
            }

            public function transactional(\Closure $callback): mixed
            {
                $this->closures->reopenAll();

                return $callback();
            }
        };

        $result = ($this->use($boundary))(WorkspaceFixture::own());

        self::assertSame(0, $result->captured);
        self::assertSame(1, $result->skipped);
        self::assertSame([], $this->snapshots->snapshots);
    }

    public function testAnUnavailableDefinitionIsSkippedAndKeepsWhateverWasStored(): void
    {
        $month = new CalendarMonth(2025, 1);
        $this->closures->add($this->closure('c1', WorkspaceFixture::own(), $month));
        $this->snapshots->capture(WorkspaceFixture::own(), $month, 'c1', $this->figures('2025-01', false), new \DateTimeImmutable());
        $this->projector->definitionAvailable = false;

        $result = ($this->use())(WorkspaceFixture::own());

        self::assertSame(0, $result->captured);
        self::assertSame(1, $result->skipped);
        self::assertNull($this->snapshots->find(WorkspaceFixture::own(), $month, 'c1')?->accountWeights);
        self::assertCount(1, $this->snapshots->snapshots);
    }

    public function testAnotherWorkspaceIsUntouched(): void
    {
        $this->closures->add($this->closure('c1', WorkspaceFixture::other(), new CalendarMonth(2025, 1)));

        $result = ($this->use())(WorkspaceFixture::own());

        self::assertSame(0, $result->captured);
        self::assertSame(0, $this->projector->calls);
        self::assertSame([], $this->snapshots->snapshots);
    }

    private function use(?TransactionBoundary $boundary = null): CaptureMissingSnapshots
    {
        return new CaptureMissingSnapshots(
            $this->closures,
            $this->snapshots,
            new CaptureMonthSnapshot($this->projector, $this->closures, $this->snapshots, $this->calendar),
            $boundary ?? new class implements TransactionBoundary {
                public function transactional(\Closure $callback): mixed
                {
                    return $callback();
                }
            },
            $this->calendar,
        );
    }

    private function figures(string $month, bool $withWeights): MonthFigures
    {
        $cells = MonthFigures::fromProjection(MonthlyProjectionFixture::view($month))->cells;

        return new MonthFigures($month, 1, 'CURRENT', 0, $month.'-28', $cells, [], [], $withWeights ? [new AccountWeight('a1', '1.00', 'EUR', true)] : null);
    }

    private function closure(string $id, WorkspaceScope $workspace, CalendarMonth $month): PeriodClosure
    {
        return new PeriodClosure($id, $workspace, $month, new \DateTimeImmutable('2026-09-01T10:00:00+00:00'), WorkspaceFixture::OWNER_ID, 1);
    }
}
