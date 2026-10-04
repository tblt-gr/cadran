<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Valuation;

use App\Module\Portfolio\Domain\Valuation\DueMonthWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Hand-computed windows: a month is a candidate once its last day has come, at most 12 months back, never before opening. */
final class DueMonthWindowTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?string, list<string>}> */
    public static function windows(): iterable
    {
        yield 'last day of September' => ['2026-09-30', '2026-01-01', null, ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09']];
        yield 'day before the last day' => ['2026-09-29', '2026-01-01', null, ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08']];
        yield 'first day of October' => ['2026-10-01', '2026-09-10', null, ['2026-09']];
        yield 'opened on the last day counts' => ['2026-09-30', '2026-09-30', null, ['2026-09']];
        yield 'twelve months back at most' => ['2026-09-30', '2020-01-01', null, [
            '2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09',
        ]];
        yield 'opening month limits the window' => ['2026-09-30', '2026-07-15', null, ['2026-07', '2026-08', '2026-09']];
        yield 'opened this month, not yet last day' => ['2026-09-15', '2026-09-01', null, []];
        yield 'opened in the future' => ['2026-09-30', '2026-11-02', null, []];
        yield 'closed before a month starts excludes it' => ['2026-09-30', '2026-01-01', '2026-06-30', ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06']];
        yield 'closed on the first day keeps that month' => ['2026-09-30', '2026-01-01', '2026-06-01', ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06']];
        yield 'leap year February' => ['2028-02-29', '2028-01-01', null, ['2028-01', '2028-02']];
        yield 'year boundary' => ['2027-01-31', '2026-11-05', null, ['2026-11', '2026-12', '2027-01']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('windows')]
    public function testCandidateMonths(string $today, string $openedOn, ?string $closedOn, array $expected): void
    {
        $months = DueMonthWindow::candidates(self::day($today), self::day($openedOn), null === $closedOn ? null : self::day($closedOn));

        self::assertSame($expected, array_map(static fn ($month): string => $month->key(), $months));
    }

    private static function day(string $literal): \DateTimeImmutable
    {
        return new \DateTimeImmutable($literal, new \DateTimeZone('UTC'));
    }
}
