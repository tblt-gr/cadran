<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Accounts\Application\CurrentGroupTree;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\GroupLineage;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Reporting\Application\AccountWeight;
use App\Module\Reporting\Application\AnnualColumn;
use App\Module\Reporting\Application\AnnualColumnSeries;
use App\Module\Reporting\Application\CaptureMonthSnapshot;
use App\Module\Reporting\Application\LoadAnnualMonths;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Application\MonthlyProjectionScopeTooLarge;
use App\Module\Reporting\Application\RegroupMonthFigures;
use App\Module\Reporting\Domain\Aggregation\ColumnKind;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Tests\Module\Reporting\Application\Double\CountingMonthlyProjector;
use App\Tests\Module\Reporting\Application\Double\FixedGroupTree;
use App\Tests\Module\Reporting\Application\Double\InMemoryMonthSnapshotStore;
use App\Tests\Module\Reporting\Application\Double\InMemoryPeriodClosureRepository;
use App\Tests\Module\Reporting\Application\Double\MonthlyProjectionFixture;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class LoadAnnualMonthsTest extends TestCase
{
    private CountingMonthlyProjector $projector;
    private InMemoryPeriodClosureRepository $closures;
    private InMemoryMonthSnapshotStore $snapshots;
    private FixedGroupTree $groupTree;
    private LoadAnnualMonths $load;

    protected function setUp(): void
    {
        $this->projector = new CountingMonthlyProjector();
        $this->closures = new InMemoryPeriodClosureRepository();
        $this->snapshots = new InMemoryMonthSnapshotStore();
        $this->groupTree = new FixedGroupTree();
        $this->load = new LoadAnnualMonths($this->projector, $this->closures, $this->snapshots, $this->calendar(), new RegroupMonthFigures($this->groupTree));
    }

    public function testAnOpenYearComputesEveryMonthLive(): void
    {
        $months = ($this->load)(WorkspaceFixture::own(), 2025, new CalendarMonth(2025, 1));

        self::assertSame(12, $this->projector->calls);
        self::assertCount(12, $months);
        self::assertSame(MonthState::COMPLETE, $months[11]->state);
        self::assertFalse($months[0]->closed);
        self::assertSame([], $this->snapshots->snapshots);
    }

    public function testAFullySnapshottedYearComputesNothing(): void
    {
        foreach (range(1, 12) as $number) {
            $month = new CalendarMonth(2025, $number);
            $this->closures->add($this->closure('c'.$number, $month));
            $this->snapshots->capture(WorkspaceFixture::own(), $month, 'c'.$number, MonthFigures::fromProjection(MonthlyProjectionFixture::view($month->key())), new \DateTimeImmutable());
        }

        $months = ($this->load)(WorkspaceFixture::own(), 2025, new CalendarMonth(2025, 1));

        self::assertSame(0, $this->projector->calls);
        self::assertTrue($months[5]->closed);
        self::assertTrue($months[5]->snapshot);
        self::assertSame('2000', $months[5]->figures?->cells['cashIncome']->value);
    }

    public function testAClosedMonthWithoutSnapshotIsCapturedOnceOnRead(): void
    {
        $month = new CalendarMonth(2025, 3);
        $this->closures->add($this->closure('c3', $month));

        ($this->load)(WorkspaceFixture::own(), 2025, new CalendarMonth(2025, 1));
        self::assertSame(12, $this->projector->calls);
        self::assertNotNull($this->snapshots->find(WorkspaceFixture::own(), $month, 'c3'));

        ($this->load)(WorkspaceFixture::own(), 2025, new CalendarMonth(2025, 1));
        self::assertSame(23, $this->projector->calls);
    }

    public function testAClosedMonthIsRegroupedAtReadTimeFromItsFrozenAccountValues(): void
    {
        $month = new CalendarMonth(2025, 3);
        $this->closures->add($this->closure('c3', $month));
        // Frozen while the account sat in Investissement: Bourse was empty.
        $this->snapshots->capture(WorkspaceFixture::own(), $month, 'c3', new MonthFigures(
            '2025-03', 1, 'CURRENT', 0, '2025-03-31',
            [
                'endNetWorth' => new MonthlyMetricView('1000.00', 'EUR', null),
                'group:invest' => new MonthlyMetricView('1000.00', 'EUR', null),
                'group:bourse' => new MonthlyMetricView('0', 'EUR', null),
            ],
            [],
            [],
            [new AccountWeight('a1', '1000.00', 'EUR', true)],
        ), new \DateTimeImmutable());
        $this->groupTree->with(WorkspaceFixture::own(), new CurrentGroupTree(
            [new GroupLineage('invest', ['invest']), new GroupLineage('bourse', ['bourse', 'invest'])],
            ['a1' => 'bourse'],
        ));

        $figures = ($this->load)(WorkspaceFixture::own(), 2025, new CalendarMonth(2025, 1))[2]->figures;

        self::assertSame('1000.00', $figures?->cells['group:bourse']->value);
        self::assertSame('1000.00', $figures->cells['group:invest']->value);
        self::assertSame('1000.00', $figures->cells['endNetWorth']->value);
        // The stored snapshot is untouched: regrouping happens on read only.
        self::assertSame('0', $this->snapshots->find(WorkspaceFixture::own(), $month, 'c3')?->cells['group:bourse']->value);
    }

    public function testASnapshotWrittenBeforeAccountWeightsIsRecapturedOnce(): void
    {
        $month = new CalendarMonth(2025, 3);
        $this->closures->add($this->closure('c3', $month));
        $this->snapshots->capture(WorkspaceFixture::own(), $month, 'c3', new MonthFigures(
            '2025-03', 1, 'CURRENT', 0, '2025-03-31',
            ['cashIncome' => new MonthlyMetricView('1', 'EUR', null)],
            [],
            [],
            null,
        ), new \DateTimeImmutable());

        $months = ($this->load)(WorkspaceFixture::own(), 2025, new CalendarMonth(2025, 1));
        $afterFirstRead = $this->projector->calls;

        self::assertSame('2000', $months[2]->figures?->cells['cashIncome']->value);
        self::assertSame([], $this->snapshots->find(WorkspaceFixture::own(), $month, 'c3')?->accountWeights);

        ($this->load)(WorkspaceFixture::own(), 2025, new CalendarMonth(2025, 1));
        // Only the eleven open months are projected again, the recaptured month is not.
        self::assertSame($afterFirstRead + 11, $this->projector->calls);
    }

    public function testAnOversizedWorkspaceIsAScopeErrorNotAServerError(): void
    {
        $this->closures->add($this->closure('c3', new CalendarMonth(2025, 3)));
        $this->groupTree->tooLarge = true;

        $this->expectException(MonthlyProjectionScopeTooLarge::class);
        ($this->load)(WorkspaceFixture::own(), 2025, new CalendarMonth(2025, 1));
    }

    public function testAnArchivedGroupColumnOfAClosedMonthIsNonCalculableNotMissing(): void
    {
        $month = new CalendarMonth(2025, 3);
        $this->closures->add($this->closure('c3', $month));
        $this->snapshots->capture(WorkspaceFixture::own(), $month, 'c3', new MonthFigures(
            '2025-03', 1, 'CURRENT', 0, '2025-03-31',
            ['group:archived' => new MonthlyMetricView('5', 'EUR', null)],
            [],
            [],
            [],
        ), new \DateTimeImmutable());
        $this->groupTree->with(WorkspaceFixture::own(), new CurrentGroupTree([new GroupLineage('invest', ['invest'])], []));

        $months = ($this->load)(WorkspaceFixture::own(), 2025, new CalendarMonth(2025, 1));
        $cell = AnnualColumnSeries::cell(new AnnualColumn('group:archived', 'Ancien', ColumnKind::STOCK, true), $months[2]);

        self::assertNull($cell->value);
        self::assertSame('NOT_IN_PROJECTION', $cell->reason);
    }

    public function testTheTreeIsReadOnlyWhenAMonthIsClosed(): void
    {
        ($this->load)(WorkspaceFixture::own(), 2025, new CalendarMonth(2025, 1));
        self::assertSame(0, $this->groupTree->reads);

        $this->closures->add($this->closure('c3', new CalendarMonth(2025, 3)));
        $this->closures->add($this->closure('c4', new CalendarMonth(2025, 4)));
        ($this->load)(WorkspaceFixture::own(), 2025, new CalendarMonth(2025, 1));
        self::assertSame(1, $this->groupTree->reads);
    }

    public function testASnapshotOfAnEarlierClosureIsIgnored(): void
    {
        $month = new CalendarMonth(2025, 3);
        $this->closures->add($this->closure('new-closure', $month));
        $this->snapshots->capture(WorkspaceFixture::own(), $month, 'old-closure', MonthFigures::fromProjection(MonthlyProjectionFixture::view('2025-03', '999')), new \DateTimeImmutable());

        $months = ($this->load)(WorkspaceFixture::own(), 2025, new CalendarMonth(2025, 1));

        self::assertSame('2000', $months[2]->figures?->cells['cashIncome']->value);
        self::assertNotNull($this->snapshots->find(WorkspaceFixture::own(), $month, 'new-closure'));
    }

    public function testTheRunningMonthIsProvisionalAndLaterMonthsAreNeverRead(): void
    {
        $months = ($this->load)(WorkspaceFixture::own(), 2026, new CalendarMonth(2026, 1));

        self::assertSame(9, $this->projector->calls);
        self::assertSame(MonthState::COMPLETE, $months[7]->state);
        self::assertSame(MonthState::PROVISIONAL, $months[8]->state);
        self::assertSame(MonthState::FUTURE, $months[9]->state);
        self::assertNull($months[9]->figures);
    }

    public function testAYearBeforeTheFirstDataMonthIsNeverRead(): void
    {
        $months = ($this->load)(WorkspaceFixture::own(), 2024, new CalendarMonth(2025, 1));

        self::assertSame(0, $this->projector->calls);
        foreach ($months as $month) {
            self::assertSame(MonthState::NO_DATA, $month->state);
        }
    }

    public function testASnapshotIsCapturedOnlyForTheActiveClosure(): void
    {
        $month = new CalendarMonth(2025, 3);
        $this->closures->add($this->closure('active', $month));
        $capture = new CaptureMonthSnapshot($this->projector, $this->closures, $this->snapshots, $this->calendar());

        $capture(WorkspaceFixture::own(), $month, 'reopened-closure');
        self::assertSame([], $this->snapshots->snapshots);
        self::assertSame(0, $this->projector->calls);

        $capture(WorkspaceFixture::own(), $month, 'active');
        self::assertNotNull($this->snapshots->find(WorkspaceFixture::own(), $month, 'active'));
    }

    private function closure(string $id, CalendarMonth $month): PeriodClosure
    {
        return new PeriodClosure($id, WorkspaceFixture::own(), $month, new \DateTimeImmutable('2026-09-01T10:00:00+00:00'), WorkspaceFixture::OWNER_ID, 1);
    }

    private function calendar(): WorkspaceCalendar
    {
        $caller = new readonly class implements CallerWorkspaceContext {
            public function resolveContext(): WorkspaceContext
            {
                return new WorkspaceContext(WorkspaceFixture::own(), WorkspaceFixture::OWNER_ID, true);
            }
        };
        $timezones = $this->createStub(WorkspaceTimezoneReader::class);
        $timezones->method('timezone')->willReturn('UTC');

        return new WorkspaceCalendar(new MockClock('2026-09-24T12:00:00+00:00'), $caller, $timezones);
    }
}
