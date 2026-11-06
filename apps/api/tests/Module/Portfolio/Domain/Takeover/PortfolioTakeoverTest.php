<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Takeover;

use App\Module\Portfolio\Domain\Takeover\PortfolioTakeover;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PortfolioTakeoverTest extends TestCase
{
    /** @return iterable<string, array{string, bool, string}> */
    public static function days(): iterable
    {
        yield 'month end starts the next month' => ['2026-10-31', true, '2026-11-01'];
        yield 'mid month starts its own month' => ['2026-11-03', false, '2026-11-01'];
        yield 'first of month starts its own month' => ['2026-11-01', false, '2026-11-01'];
        yield 'leap day is a month end' => ['2028-02-29', true, '2028-03-01'];
        yield 'year end rolls over the year' => ['2026-12-31', true, '2027-01-01'];
    }

    #[DataProvider('days')]
    public function testTheHistoryStartsAfterAMonthEndOrInsideItsMonth(string $day, bool $atMonthEnd, string $firstMonth): void
    {
        $takeover = $this->takeover($day);

        self::assertSame($atMonthEnd, $takeover->atMonthEnd());
        self::assertSame($firstMonth, $takeover->historyFirstMonth()->format('Y-m-d'));
    }

    public function testAPeriodEndingOnOrBeforeTheTakeoverDayPrecedesTheHistory(): void
    {
        $takeover = $this->takeover('2026-11-03');

        self::assertTrue($takeover->precedes(new \DateTimeImmutable('2026-10-31', new \DateTimeZone('UTC'))));
        self::assertTrue($takeover->precedes(new \DateTimeImmutable('2026-11-03', new \DateTimeZone('UTC'))));
        self::assertFalse($takeover->precedes(new \DateTimeImmutable('2026-11-30', new \DateTimeZone('UTC'))));
    }

    private function takeover(string $day): PortfolioTakeover
    {
        return new PortfolioTakeover(
            '00000000-0000-7000-8000-0000000000f1', WorkspaceFixture::own(), '00000000-0000-7000-8000-0000000000d1',
            new \DateTimeImmutable($day, new \DateTimeZone('UTC')), '00000000-0000-7000-8000-0000000000f2', new \DateTimeImmutable('2026-11-03T12:00:00+00:00'),
        );
    }
}
