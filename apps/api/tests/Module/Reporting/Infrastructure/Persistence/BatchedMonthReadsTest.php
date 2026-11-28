<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Infrastructure\Persistence;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Accounts\Infrastructure\Persistence\DbalPeriodClosureRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Infrastructure\Persistence\DbalMonthSnapshotStore;
use App\Tests\Module\Reporting\Application\Double\MonthlyProjectionFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The batched closure and snapshot reads of the reports, against PostgreSQL. */
final class BatchedMonthReadsTest extends KernelTestCase
{
    private WorkspaceFixture $fixture;
    private DbalPeriodClosureRepository $closures;
    private DbalMonthSnapshotStore $snapshots;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $this->closures = new DbalPeriodClosureRepository($connection);
        $this->snapshots = new DbalMonthSnapshotStore($connection);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testOnlyTheActiveClosuresOfTheWorkspaceAndRangeComeBackOldestFirst(): void
    {
        $own = WorkspaceFixture::own();
        $other = WorkspaceFixture::other();
        $this->add('c01', $own, new CalendarMonth(2026, 3));
        $this->add('c02', $own, new CalendarMonth(2025, 11));
        $this->add('c03', $own, new CalendarMonth(2025, 10));
        $this->add('c04', $other, new CalendarMonth(2025, 12), WorkspaceFixture::OTHER_OWNER_ID);
        $reopened = $this->add('c05', $own, new CalendarMonth(2026, 1));
        self::assertTrue($this->closures->update($reopened->reopen('Late invoice', new \DateTimeImmutable('2026-09-02T10:00:00+00:00')), 1));

        $found = $this->closures->activeClosuresBetween($own, new CalendarMonth(2025, 11), new CalendarMonth(2026, 3));

        self::assertSame(['2025-11', '2026-03'], array_map(static fn (PeriodClosure $closure): string => $closure->month->key(), $found));
        self::assertSame([self::id('c02'), self::id('c01')], array_column($found, 'id'));
    }

    public function testASnapshotIsServedOnlyToItsWorkspaceAndItsExpectedClosure(): void
    {
        $own = WorkspaceFixture::own();
        $other = WorkspaceFixture::other();
        $this->capture($own, '2026-01', self::id('a1'));
        $this->capture($own, '2026-02', self::id('a2'));
        $this->capture($other, '2026-03', self::id('a3'));

        $found = $this->snapshots->findMany($own, [
            '2026-01' => self::id('a1'),
            '2026-02' => self::id('ff'),
            '2026-03' => self::id('a3'),
        ]);

        self::assertSame(['2026-01'], array_keys($found));
        self::assertSame('2026-01', $found['2026-01']->month);
        self::assertSame([], $this->snapshots->findMany($own, []));
    }

    private function add(string $suffix, WorkspaceScope $workspace, CalendarMonth $month, string $author = WorkspaceFixture::OWNER_ID): PeriodClosure
    {
        $closure = new PeriodClosure(self::id($suffix), $workspace, $month, new \DateTimeImmutable('2026-09-01T10:00:00+00:00'), $author, 1);
        $this->closures->add($closure);

        return $closure;
    }

    private function capture(WorkspaceScope $workspace, string $month, string $closureId): void
    {
        $this->snapshots->capture($workspace, CalendarMonth::fromString($month), $closureId, MonthFigures::fromProjection(MonthlyProjectionFixture::view($month)), new \DateTimeImmutable());
    }

    private static function id(string $suffix): string
    {
        return '00000000-0000-7000-8000-'.str_pad($suffix, 12, '0', STR_PAD_LEFT);
    }
}
