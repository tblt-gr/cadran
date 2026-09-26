<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\AssessPeriodClosing;
use App\Module\Accounts\Application\ClosePeriod;
use App\Module\Accounts\Application\ClosePeriodInput;
use App\Module\Accounts\Application\PeriodClosed;
use App\Module\Accounts\Application\PeriodClosedEvent;
use App\Module\Accounts\Application\PeriodClosureConflict;
use App\Module\Accounts\Application\PeriodClosureForbidden;
use App\Module\Accounts\Application\PeriodReopenedEvent;
use App\Module\Accounts\Application\ReopenPeriod;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Accounts\Infrastructure\Persistence\DbalPeriodClosureRepository;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Identity\Infrastructure\Workspace\DbalWorkspaceTimezoneReader;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * What only PostgreSQL can prove about a closure: one active row per month,
 * optimistic updates, workspace isolation of the guard, and the lock that
 * makes a closing and an in-flight write see each other.
 */
final class PeriodClosurePersistenceTest extends KernelTestCase
{
    private Connection $connection;
    private WorkspaceFixture $fixture;
    private DbalPeriodClosureRepository $closures;
    private EventDispatcher $events;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $this->closures = new DbalPeriodClosureRepository($connection);
        $this->events = new EventDispatcher();
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testTheGuardChecksBothTheDayItLeavesAndTheDayItEnters(): void
    {
        $this->closures->add($this->closure('c01', 2026, 3));
        $guard = new AssertPeriodOpen($this->closures);
        $own = WorkspaceFixture::own();

        $guard($own, new \DateTimeImmutable('2026-02-10'), new \DateTimeImmutable('2026-04-10'));
        $guard($own);
        foreach ([
            'writing into it' => ['2026-03-01'],
            'entering it' => ['2026-02-10', '2026-03-31'],
            'leaving it' => ['2026-03-31', '2026-04-10'],
        ] as $name => $days) {
            try {
                $guard($own, ...array_map(static fn (string $day): \DateTimeImmutable => new \DateTimeImmutable($day), $days));
                self::fail('A write '.$name.' a closed month must be refused.');
            } catch (PeriodClosed $refusal) {
                self::assertStringNotContainsString('2026', $refusal->getMessage(), 'The refusal never names the month.');
            }
        }
    }

    public function testAnotherWorkspaceIsNotRefusedByThisWorkspacesClosure(): void
    {
        $this->closures->add($this->closure('c01', 2026, 3));

        (new AssertPeriodOpen($this->closures))(WorkspaceFixture::other(), new \DateTimeImmutable('2026-03-15'));
        self::assertSame([], $this->closures->closedAmong(WorkspaceFixture::other(), [new CalendarMonth(2026, 3)]));
    }

    public function testAccountLifecycleChangesAreRefusedOnlyWhenTheyChangeAClosedMonthsMembership(): void
    {
        $this->closures->add($this->closure('c01', 2026, 3));
        $guard = new AssertPeriodOpen($this->closures);
        $workspace = WorkspaceFixture::own();

        $guard->assertAccountLifecycleUnchangedForClosures(
            $workspace,
            null,
            null,
            new \DateTimeImmutable('2026-04-01'),
            null,
        );
        $guard->assertAccountLifecycleUnchangedForClosures(
            $workspace,
            new \DateTimeImmutable('2026-01-01'),
            null,
            new \DateTimeImmutable('2026-02-01'),
            null,
        );

        /** @var array<string, array{?string, ?string, string, ?string}> $lifecycleChanges */
        $lifecycleChanges = [
            'backdated creation' => [null, null, '2026-01-01', null],
            'opening moved after closure' => ['2026-01-01', null, '2026-04-01', null],
            'closing moved before closure' => ['2026-01-01', null, '2026-01-01', '2026-02-28'],
        ];
        foreach ($lifecycleChanges as $name => [$oldOpened, $oldClosed, $newOpened, $newClosed]) {
            try {
                $guard->assertAccountLifecycleUnchangedForClosures(
                    $workspace,
                    null === $oldOpened ? null : new \DateTimeImmutable($oldOpened),
                    null === $oldClosed ? null : new \DateTimeImmutable($oldClosed),
                    new \DateTimeImmutable($newOpened),
                    null === $newClosed ? null : new \DateTimeImmutable($newClosed),
                );
                self::fail('A lifecycle change affecting a closed month must be refused: '.$name);
            } catch (PeriodClosed) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheDatabaseKeepsOneActiveClosurePerMonthAndAllowsAReclosureAfterReopening(): void
    {
        $first = $this->closure('c01', 2026, 3);
        $this->closures->add($first);

        try {
            $this->closures->add($this->closure('c02', 2026, 3));
            self::fail('A second active closure of the same month must be refused.');
        } catch (PeriodClosureConflict) {
            self::addToAssertionCount(1);
        }

        $reopened = $first->reopen('Late invoice', new \DateTimeImmutable('2026-09-02 10:00:00+00:00'));
        self::assertTrue($this->closures->update($reopened, 1));
        $this->closures->add($this->closure('c03', 2026, 3));

        self::assertCount(2, $this->closures->listForYear(WorkspaceFixture::own(), 2026));
        self::assertSame('c03', substr((string) $this->closures->findActive(WorkspaceFixture::own(), new CalendarMonth(2026, 3))?->id, -3));
    }

    public function testAStaleUpdateChangesNothing(): void
    {
        $first = $this->closure('c01', 2026, 3);
        $this->closures->add($first);
        $reopened = $first->reopen('Late invoice', new \DateTimeImmutable('2026-09-02 10:00:00+00:00'));

        self::assertFalse($this->closures->update($reopened, 7));
        self::assertTrue($this->closures->findActive(WorkspaceFixture::own(), new CalendarMonth(2026, 3))?->isActive() ?? false);
    }

    public function testOnlyTheOwnerOverridesAConditionOrReopens(): void
    {
        $caller = new FixedCallerWorkspace(WorkspaceFixture::OWN_WORKSPACE, WorkspaceFixture::OWNER_ID, false);

        // An unverified month needs a confirmation to close; a non-owner cannot give one.
        try {
            $this->closePeriod($caller)(new ClosePeriodInput('2026-03', ['UNRECONCILED_ACCOUNT' => 'Statement not received']));
            self::fail('A non-owner must not override a closing condition.');
        } catch (PeriodClosureForbidden) {
            self::addToAssertionCount(1);
        }
        self::assertSame([], $this->connection->fetchFirstColumn('SELECT id FROM account_period_closures'));

        $this->closures->add($this->closure('c01', 2026, 3));
        try {
            $this->reopenPeriod($caller)('2026-03', 1, 'Late invoice');
            self::fail('A non-owner must not reopen a period.');
        } catch (PeriodClosureForbidden) {
            self::addToAssertionCount(1);
        }
        self::assertTrue($this->closures->findActive(WorkspaceFixture::own(), new CalendarMonth(2026, 3))?->isActive() ?? false);

        $owner = new FixedCallerWorkspace(WorkspaceFixture::OWN_WORKSPACE, WorkspaceFixture::OWNER_ID, true);
        self::assertFalse($this->reopenPeriod($owner)('2026-03', 1, 'Late invoice')->isActive());
    }

    public function testClosingDispatchesItsEventOnlyAfterTheClosingCommitted(): void
    {
        $seen = [];
        $this->events->addListener(PeriodClosedEvent::class, function (PeriodClosedEvent $event) use (&$seen): void {
            $seen[] = [
                $event->month->key(),
                $event->closureId,
                $this->connection->isTransactionActive(),
                $this->closures->findActive(WorkspaceFixture::own(), $event->month)?->id,
            ];
        });
        $owner = new FixedCallerWorkspace(WorkspaceFixture::OWN_WORKSPACE, WorkspaceFixture::OWNER_ID, true);

        $closure = $this->closePeriod($owner)(new ClosePeriodInput('2026-03', []));

        self::assertSame([['2026-03', $closure->id, false, $closure->id]], $seen);
    }

    public function testReopeningDispatchesItsEventOnlyAfterTheReopeningCommitted(): void
    {
        $this->closures->add($this->closure('c02', 2026, 3));
        $seen = [];
        $this->events->addListener(PeriodReopenedEvent::class, function (PeriodReopenedEvent $event) use (&$seen): void {
            $seen[] = [
                $event->month->key(),
                $event->closureId,
                $this->connection->isTransactionActive(),
                null === $this->closures->findActive(WorkspaceFixture::own(), $event->month),
            ];
        });
        $owner = new FixedCallerWorkspace(WorkspaceFixture::OWN_WORKSPACE, WorkspaceFixture::OWNER_ID, true);

        $reopened = $this->reopenPeriod($owner)('2026-03', 1, 'Late invoice');

        self::assertSame([['2026-03', $reopened->id, false, true]], $seen);
    }

    public function testAClosingWaitsForAnInFlightWriteAndAWriteWaitsForAClosing(): void
    {
        $own = WorkspaceFixture::own();
        $other = DriverManager::getConnection($this->connection->getParams());
        $otherClosures = new DbalPeriodClosureRepository($other);

        try {
            // A write in flight holds the shared lock; a closing must wait for it to commit.
            $this->connection->beginTransaction();
            $this->closures->lockShared($own);
            $other->beginTransaction();
            $other->executeStatement("SET LOCAL lock_timeout = '300ms'");
            try {
                $otherClosures->lockExclusive($own);
                self::fail('A closing must wait for the in-flight write.');
            } catch (DriverException) {
                self::addToAssertionCount(1);
            }
            $other->rollBack();
            $this->connection->rollBack();

            // A closing in progress holds the exclusive lock; a write must wait for it.
            $this->connection->beginTransaction();
            $this->closures->lockExclusive($own);
            $other->beginTransaction();
            $other->executeStatement("SET LOCAL lock_timeout = '300ms'");
            try {
                $otherClosures->lockShared($own);
                self::fail('A write must wait for the closing in progress.');
            } catch (DriverException) {
                self::addToAssertionCount(1);
            }
            $other->rollBack();
            $this->connection->rollBack();

            // Writers never wait on each other.
            $this->connection->beginTransaction();
            $this->closures->lockShared($own);
            $other->beginTransaction();
            $other->executeStatement("SET LOCAL lock_timeout = '300ms'");
            $otherClosures->lockShared($own);
            $other->rollBack();
            $this->connection->rollBack();

            // Another workspace is never blocked by this one's closing.
            $this->connection->beginTransaction();
            $this->closures->lockExclusive($own);
            $other->beginTransaction();
            $other->executeStatement("SET LOCAL lock_timeout = '300ms'");
            $otherClosures->lockShared(WorkspaceFixture::other());
            $other->rollBack();
            $this->connection->rollBack();
        } finally {
            $other->close();
        }
    }

    private function closure(string $suffix, int $year, int $month): PeriodClosure
    {
        return new PeriodClosure(
            id: '00000000-0000-7000-8000-000000000'.$suffix,
            workspace: WorkspaceFixture::own(),
            month: new CalendarMonth($year, $month),
            closedAt: new \DateTimeImmutable('2026-09-01 10:00:00+00:00'),
            closedBy: WorkspaceFixture::OWNER_ID,
            version: 1,
        );
    }

    private function closePeriod(FixedCallerWorkspace $caller): ClosePeriod
    {
        return new ClosePeriod(
            $caller, $this->closures, $this->service(AssessPeriodClosing::class), $this->service(UuidGenerator::class),
            $this->service(TransactionBoundary::class), $this->service(RecordAuditEvent::class), $this->calendar($caller),
            $this->events,
        );
    }

    private function reopenPeriod(FixedCallerWorkspace $caller): ReopenPeriod
    {
        return new ReopenPeriod(
            $caller, $this->closures, $this->service(TransactionBoundary::class), $this->service(RecordAuditEvent::class), $this->calendar($caller),
            $this->events,
        );
    }

    private function calendar(FixedCallerWorkspace $caller): WorkspaceCalendar
    {
        return new WorkspaceCalendar(new MockClock('2026-09-19T10:00:00+00:00'), $caller, new DbalWorkspaceTimezoneReader($this->connection));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
