<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Performance;

use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\Performance\PerformancePeriod;
use App\Module\Portfolio\Domain\Performance\PerformanceScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PerformancePeriodTest extends TestCase
{
    #[Test]
    public function aMonthChainsOnTheLastDayOfThePreviousMonth(): void
    {
        $period = $this->period('MONTH', '2026-03');

        self::assertSame(PerformanceScope::MONTH, $period->scope);
        self::assertSame('2026-03', $period->label);
        self::assertSame('2026-03-01', $period->firstDay?->format('Y-m-d'));
        self::assertSame('2026-03-31', $period->lastDay?->format('Y-m-d'));
        self::assertSame('2026-02-28', $period->startTarget()?->format('Y-m-d'));
    }

    #[Test]
    public function aYearStartsOnDecemberThirtyFirstOfThePreviousYear(): void
    {
        $period = $this->period('YEAR', '2026');

        self::assertSame('2026-01-01', $period->firstDay?->format('Y-m-d'));
        self::assertSame('2026-12-31', $period->lastDay?->format('Y-m-d'));
        self::assertSame('2025-12-31', $period->startTarget()?->format('Y-m-d'));
    }

    #[Test]
    public function inceptionHasNoPeriodAndNoBounds(): void
    {
        $period = $this->period('INCEPTION', null);

        self::assertNull($period->label);
        self::assertNull($period->firstDay);
        self::assertNull($period->lastDay);
        self::assertNull($period->startTarget());
    }

    #[Test]
    public function aLeapYearFebruaryEndsOnTheTwentyNinth(): void
    {
        self::assertSame('2028-02-29', $this->period('MONTH', '2028-02', '2028-06-10', '2026-01-01')->lastDay?->format('Y-m-d'));
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function invalid(): iterable
    {
        yield 'missing scope' => [null, '2026-03', 'invalid_performance_scope'];
        yield 'unknown scope' => ['WEEK', '2026-03', 'invalid_performance_scope'];
        yield 'lowercase scope' => ['month', '2026-03', 'invalid_performance_scope'];
        yield 'month without period' => ['MONTH', null, 'invalid_performance_period'];
        yield 'malformed month' => ['MONTH', '2026-3', 'invalid_performance_period'];
        yield 'month thirteen' => ['MONTH', '2026-13', 'invalid_performance_period'];
        yield 'month zero' => ['MONTH', '2026-00', 'invalid_performance_period'];
        yield 'year with a month' => ['YEAR', '2026-03', 'invalid_performance_period'];
        yield 'future month' => ['MONTH', '2026-04', 'invalid_performance_period'];
        yield 'future year' => ['YEAR', '2027', 'invalid_performance_period'];
        yield 'month before opening' => ['MONTH', '2025-12', 'invalid_performance_period'];
        yield 'year before opening' => ['YEAR', '2025', 'invalid_performance_period'];
        yield 'inception with a period' => ['INCEPTION', '2026-03', 'invalid_performance_period'];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function itRejectsAnInvalidRequest(?string $scope, ?string $period, string $code): void
    {
        try {
            $this->period($scope, $period);
            self::fail('Expected a refusal.');
        } catch (InvalidPortfolioOperation $exception) {
            self::assertSame($code, $exception->problemCode);
        }
    }

    #[Test]
    public function theCurrentAndOpeningMonthsAreAccepted(): void
    {
        self::assertSame('2026-03', $this->period('MONTH', '2026-03')->label);
        self::assertSame('2026-01', $this->period('MONTH', '2026-01')->label);
        self::assertSame('2026', $this->period('YEAR', '2026')->label);
    }

    private function period(?string $scope, ?string $period, string $today = '2026-03-14', string $openedOn = '2026-01-20'): PerformancePeriod
    {
        return PerformancePeriod::of($scope, $period, new \DateTimeImmutable($today), new \DateTimeImmutable($openedOn));
    }
}
