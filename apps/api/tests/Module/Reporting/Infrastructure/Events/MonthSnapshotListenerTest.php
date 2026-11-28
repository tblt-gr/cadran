<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Infrastructure\Events;

use App\Module\Accounts\Application\PeriodClosedEvent;
use App\Module\Accounts\Application\PeriodReopenedEvent;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Reporting\Application\CaptureMonthSnapshot;
use App\Module\Reporting\Application\MonthSnapshotStore;
use App\Module\Reporting\Infrastructure\Events\MonthSnapshotListener;
use App\Tests\Module\Reporting\Application\Double\CountingMonthlyProjector;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;

final class MonthSnapshotListenerTest extends TestCase
{
    private const string MESSAGE = 'Failing row contains (1234.56 EUR, Libelle prive)';

    public function testAFailedCaptureIsLoggedByItsCauseWithoutItsMessage(): void
    {
        $logger = $this->logger();

        $this->listener($logger)->onPeriodClosed(new PeriodClosedEvent(WorkspaceFixture::own(), new CalendarMonth(2025, 1), 'c1'));

        $this->assertSafeWarning($logger->records, 'PeriodClosedEvent');
    }

    public function testAFailedRemovalIsLoggedByItsCauseWithoutItsMessage(): void
    {
        $logger = $this->logger();

        $this->listener($logger)->onPeriodReopened(new PeriodReopenedEvent(WorkspaceFixture::own(), new CalendarMonth(2025, 1), 'c1'));

        $this->assertSafeWarning($logger->records, 'PeriodReopenedEvent');
    }

    /** @param list<array{level: mixed, message: string, context: array<mixed>}> $records */
    private function assertSafeWarning(array $records, string $event): void
    {
        self::assertCount(1, $records);
        self::assertSame('warning', $records[0]['level']);
        $context = $records[0]['context'];
        self::assertSame($event, $context['event']);
        self::assertSame(\RuntimeException::class, $context['exception_class']);
        self::assertIsString($context['location']);
        self::assertStringStartsWith('MonthSnapshotListenerTest.php:', $context['location']);
        self::assertSame(WorkspaceFixture::own()->id, $context['workspace_id']);
        self::assertSame('2025-01', $context['month']);

        $logged = json_encode($records, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('1234.56', $logged);
        self::assertStringNotContainsString('Libelle prive', $logged);
        self::assertStringNotContainsString('Failing row', $logged);
    }

    private function listener(AbstractLogger $logger): MonthSnapshotListener
    {
        $failure = new \RuntimeException(self::MESSAGE);
        $closures = $this->createStub(PeriodClosureRepository::class);
        $closures->method('findActive')->willThrowException($failure);
        $snapshots = $this->createStub(MonthSnapshotStore::class);
        $snapshots->method('deleteForMonth')->willThrowException($failure);
        $calendar = new WorkspaceCalendar(
            new MockClock('2026-09-24T12:00:00+00:00'),
            $this->createStub(CallerWorkspaceContext::class),
            $this->createStub(WorkspaceTimezoneReader::class),
        );

        return new MonthSnapshotListener(
            new CaptureMonthSnapshot(new CountingMonthlyProjector(), $closures, $snapshots, $calendar),
            $snapshots,
            $logger,
        );
    }

    /** @return AbstractLogger&object{records: list<array{level: mixed, message: string, context: array<mixed>}>} */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
