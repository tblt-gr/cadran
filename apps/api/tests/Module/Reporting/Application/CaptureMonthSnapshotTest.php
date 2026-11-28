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
use App\Tests\Module\Reporting\Application\Double\CountingMonthlyProjector;
use App\Tests\Module\Reporting\Application\Double\InMemoryMonthSnapshotStore;
use App\Tests\Module\Reporting\Application\Double\InMemoryPeriodClosureRepository;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CaptureMonthSnapshotTest extends TestCase
{
    private CountingMonthlyProjector $projector;
    private InMemoryMonthSnapshotStore $snapshots;
    private CaptureMonthSnapshot $capture;
    private CalendarMonth $month;

    protected function setUp(): void
    {
        $this->projector = new CountingMonthlyProjector();
        $this->snapshots = new InMemoryMonthSnapshotStore();
        $this->month = new CalendarMonth(2025, 1);
        $closures = new InMemoryPeriodClosureRepository();
        $closures->add(new PeriodClosure('c1', WorkspaceFixture::own(), $this->month, new \DateTimeImmutable('2026-09-01T10:00:00+00:00'), WorkspaceFixture::OWNER_ID, 1));
        $caller = new readonly class implements CallerWorkspaceContext {
            public function resolveContext(): WorkspaceContext
            {
                return new WorkspaceContext(WorkspaceFixture::own(), WorkspaceFixture::OWNER_ID, true);
            }
        };
        $timezones = $this->createStub(WorkspaceTimezoneReader::class);
        $timezones->method('timezone')->willReturn('UTC');
        $calendar = new WorkspaceCalendar(new MockClock('2026-09-24T12:00:00+00:00'), $caller, $timezones);
        $this->capture = new CaptureMonthSnapshot($this->projector, $closures, $this->snapshots, $calendar);
    }

    public function testAMonthWhoseDefinitionIsAvailableIsWritten(): void
    {
        self::assertTrue(($this->capture)(WorkspaceFixture::own(), $this->month, 'c1'));
        self::assertNotNull($this->snapshots->find(WorkspaceFixture::own(), $this->month, 'c1'));
    }

    public function testAnUnavailableDefinitionIsNeverFrozen(): void
    {
        $this->projector->definitionAvailable = false;

        self::assertFalse(($this->capture)(WorkspaceFixture::own(), $this->month, 'c1'));
        self::assertSame([], $this->snapshots->snapshots);
    }
}
