<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Reporting\Application\CaptureMonthSnapshot;
use App\Module\Reporting\Application\LoadAnnualMonths;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Tests\Module\Reporting\Application\Double\CountingMonthlyProjector;
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
    private LoadAnnualMonths $load;

    protected function setUp(): void
    {
        $this->projector = new CountingMonthlyProjector();
        $this->closures = new InMemoryPeriodClosureRepository();
        $this->snapshots = new InMemoryMonthSnapshotStore();
        $this->load = new LoadAnnualMonths($this->projector, $this->closures, $this->snapshots, $this->calendar());
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
