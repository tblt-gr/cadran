<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Domain;

use App\Module\Budget\Domain\BudgetPeriod;
use App\Module\Budget\Domain\BudgetPeriodType;
use App\Module\Budget\Domain\InvalidBudgetPeriod;
use PHPUnit\Framework\TestCase;

final class BudgetPeriodTest extends TestCase
{
    public function testAMonthPeriodBoundsTheFirstAndLastDayOfThatCalendarMonth(): void
    {
        $period = BudgetPeriod::month(2026, 9);

        self::assertSame(BudgetPeriodType::MONTH, $period->type);
        self::assertSame('2026-09-01', $period->firstDay()->format('Y-m-d'));
        self::assertSame('2026-09-30', $period->lastDay()->format('Y-m-d'));
        self::assertSame('2026-09', $period->key());
    }

    public function testAYearPeriodBoundsTheFirstAndLastDayOfThatCalendarYear(): void
    {
        $period = BudgetPeriod::year(2026);

        self::assertSame(BudgetPeriodType::YEAR, $period->type);
        self::assertSame('2026-01-01', $period->firstDay()->format('Y-m-d'));
        self::assertSame('2026-12-31', $period->lastDay()->format('Y-m-d'));
        self::assertSame('2026', $period->key());
    }

    public function testItRejectsAYearOutOfRange(): void
    {
        $this->expectException(InvalidBudgetPeriod::class);

        BudgetPeriod::year(1899);
    }

    public function testItRejectsAMonthOutOfRange(): void
    {
        $this->expectException(InvalidBudgetPeriod::class);

        BudgetPeriod::month(2026, 13);
    }

    public function testTwoPeriodsOfTheSameTypeAndDateAreEqual(): void
    {
        self::assertTrue(BudgetPeriod::month(2026, 9)->equals(BudgetPeriod::month(2026, 9)));
        self::assertFalse(BudgetPeriod::month(2026, 9)->equals(BudgetPeriod::month(2026, 10)));
        self::assertFalse(BudgetPeriod::month(2026, 9)->equals(BudgetPeriod::year(2026)));
    }

    public function testItParsesAMonthKey(): void
    {
        $period = BudgetPeriod::fromKey(BudgetPeriodType::MONTH, '2026-09');

        self::assertSame('2026-09-01', $period->firstDay()->format('Y-m-d'));
    }

    public function testItParsesAYearKey(): void
    {
        $period = BudgetPeriod::fromKey(BudgetPeriodType::YEAR, '2026');

        self::assertSame('2026-01-01', $period->firstDay()->format('Y-m-d'));
    }

    public function testItRejectsAMalformedKeyForItsType(): void
    {
        $this->expectException(InvalidBudgetPeriod::class);

        BudgetPeriod::fromKey(BudgetPeriodType::MONTH, '2026');
    }
}
