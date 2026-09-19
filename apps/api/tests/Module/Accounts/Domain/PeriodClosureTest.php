<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\InvalidCalendarMonth;
use App\Module\Accounts\Domain\InvalidPeriodClosure;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PeriodClosureTest extends TestCase
{
    private const string WORKSPACE = '00000000-0000-7000-8000-0000000000a1';

    public function testAMonthIsReadFromTheBusinessDayWithoutAnyTimezone(): void
    {
        // The last second of March, in any zone, is still March: a booking date is a day, not an instant.
        self::assertSame('2026-03', CalendarMonth::containing(new \DateTimeImmutable('2026-03-31 23:59:59', new \DateTimeZone('Pacific/Kiritimati')))->key());
        self::assertSame('2026-04', CalendarMonth::containing(new \DateTimeImmutable('2026-04-01'))->key());
    }

    public function testTheBoundsAreTheFirstAndLastDayOfTheMonthIncludingLeapFebruary(): void
    {
        $february = new CalendarMonth(2028, 2);

        self::assertSame('2028-02-01', $february->firstDay()->format('Y-m-d'));
        self::assertSame('2028-02-29', $february->lastDay()->format('Y-m-d'));
        self::assertSame('2026-02-28', (new CalendarMonth(2026, 2))->lastDay()->format('Y-m-d'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedMonths(): iterable
    {
        yield 'no zero pad' => ['2026-4'];
        yield 'month 13' => ['2026-13'];
        yield 'month 00' => ['2026-00'];
        yield 'full date' => ['2026-04-01'];
        yield 'trailing newline' => ["2026-04\n"];
        yield 'empty' => [''];
    }

    #[DataProvider('malformedMonths')]
    public function testAMalformedMonthIsRefused(string $value): void
    {
        $this->expectException(InvalidCalendarMonth::class);

        CalendarMonth::fromString($value);
    }

    public function testAClosureIsActiveUntilItIsReopenedWithItsReason(): void
    {
        $closure = $this->closure();
        self::assertTrue($closure->isActive());

        $reopened = $closure->reopen('  Late invoice  ', new \DateTimeImmutable('2026-05-02 10:00:00+00:00'));

        self::assertFalse($reopened->isActive());
        self::assertSame('Late invoice', $reopened->reopenReason);
        self::assertSame(2, $reopened->version);
        self::assertSame($closure->id, $reopened->id);
        self::assertTrue($closure->isActive(), 'The original value is unchanged.');
    }

    public function testAReopenedClosureCannotBeReopenedAgain(): void
    {
        $reopened = $this->closure()->reopen('Late invoice', new \DateTimeImmutable('2026-05-02 10:00:00+00:00'));

        $this->expectException(InvalidPeriodClosure::class);

        $reopened->reopen('Again', new \DateTimeImmutable('2026-05-03 10:00:00+00:00'));
    }

    public function testAReasonIsRequiredAndBounded(): void
    {
        foreach (['', '   ', str_repeat('x', 201)] as $reason) {
            try {
                $this->closure()->reopen($reason, new \DateTimeImmutable('2026-05-02 10:00:00+00:00'));
                self::fail('A reason of "'.strlen($reason).'" characters must be refused.');
            } catch (InvalidPeriodClosure) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(200, mb_strlen($this->closure()->reopen(str_repeat('é', 200), new \DateTimeImmutable('2026-05-02 10:00:00+00:00'))->reopenReason ?? ''));
    }

    public function testAClosureCannotBeReopenedBeforeItWasClosed(): void
    {
        $this->expectException(InvalidPeriodClosure::class);

        $this->closure()->reopen('Time travel', new \DateTimeImmutable('2026-04-30 10:00:00+00:00'));
    }

    private function closure(): PeriodClosure
    {
        return new PeriodClosure(
            id: '00000000-0000-7000-8000-000000000c01',
            workspace: WorkspaceScope::fromString(self::WORKSPACE),
            month: new CalendarMonth(2026, 3),
            closedAt: new \DateTimeImmutable('2026-05-01 10:00:00+00:00'),
            closedBy: '00000000-0000-7000-8000-000000000001',
            version: 1,
        );
    }
}
