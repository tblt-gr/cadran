<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountBalanceFloor;
use App\Module\Accounts\Domain\BalanceSeries;
use App\Module\Accounts\Domain\DayBalance;
use App\Module\Foundation\Domain\DecimalValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountBalanceFloorTest extends TestCase
{
    /** @return iterable<string, array{list<array{string, string}>, string|null}> */
    public static function writes(): iterable
    {
        // Snapshot 500.00 on 2026-11-01, then -200.00 on 11-05 and +100.00 on 11-10.
        yield 'expense before the first movement' => [[['2026-11-03', '-450.00']], '2026-11-05'];
        yield 'expense on the last day' => [[['2026-11-10', '-450.00']], '2026-11-10'];
        yield 'expense down to exactly zero' => [[['2026-11-10', '-400.00']], null];
        yield 'same day income then larger expense' => [[['2026-11-03', '100.00'], ['2026-11-03', '-700.00']], '2026-11-03'];
        yield 'void of the later income' => [[['2026-11-10', '-100.00']], null];
        yield 'void of the income that funded the expense' => [[['2026-11-02', '-400.00']], '2026-11-05'];
        yield 'harmless deposit' => [[['2026-11-04', '10.00']], null];
        yield 'no delta' => [[], null];
    }

    /** @param list<array{string, string}> $deltas */
    #[DataProvider('writes')]
    public function testWorkedExamples(array $deltas, ?string $expected): void
    {
        $anchors = [self::day('2026-11-01', '500.00')];
        $movements = [self::day('2026-11-05', '-200.00'), self::day('2026-11-10', '100.00')];
        $from = new \DateTimeImmutable([] === $deltas ? '2026-11-01' : min(array_column($deltas, 0)), new \DateTimeZone('UTC'));
        $dayDeltas = array_map(static fn (array $d): DayBalance => self::day($d[0], $d[1]), $deltas);

        $before = BalanceSeries::compute($anchors, $movements, [], $from);
        $after = BalanceSeries::compute($anchors, $movements, $dayDeltas, $from);

        self::assertSame($expected, AccountBalanceFloor::breach($before, $after)?->format('Y-m-d'));
    }

    public function testSeriesOfTheWorkedAccount(): void
    {
        $series = BalanceSeries::compute(
            [self::day('2026-11-01', '500.00')],
            [self::day('2026-11-05', '-200.00'), self::day('2026-11-10', '100.00')],
            [],
            new \DateTimeImmutable('2026-11-01', new \DateTimeZone('UTC')),
        );

        self::assertSame(['2026-11-05' => '300.00', '2026-11-10' => '400.00'], self::flatten($series));
    }

    public function testExactDecimalsNeverRoundThroughFloat(): void
    {
        $anchors = [self::day('2026-11-01', '0.1')];
        $from = new \DateTimeImmutable('2026-11-02', new \DateTimeZone('UTC'));
        $after = BalanceSeries::compute($anchors, [], [self::day('2026-11-02', '-0.1')], $from);
        self::assertNull(AccountBalanceFloor::breach([], $after));

        $after = BalanceSeries::compute($anchors, [], [self::day('2026-11-02', '-0.100000000000000000000001')], $from);
        self::assertSame('2026-11-02', AccountBalanceFloor::breach([], $after)?->format('Y-m-d'));
    }

    public function testAlreadyNegativeAccountAcceptsADepositButNotADeeperExpense(): void
    {
        $anchors = [self::day('2026-11-01', '-150.00')];
        $from = new \DateTimeImmutable('2026-11-04', new \DateTimeZone('UTC'));
        $movements = [self::day('2026-11-05', '0.00')];

        $before = BalanceSeries::compute($anchors, $movements, [], $from);
        $deposit = BalanceSeries::compute($anchors, $movements, [self::day('2026-11-04', '100.00')], $from);
        self::assertNull(AccountBalanceFloor::breach($before, $deposit));

        $expense = BalanceSeries::compute($anchors, $movements, [self::day('2026-11-20', '-10.00')], $from);
        self::assertSame('2026-11-20', AccountBalanceFloor::breach($before, $expense)?->format('Y-m-d'));
    }

    public function testNoAnchorMeansNoCalculableBalance(): void
    {
        $from = new \DateTimeImmutable('2026-11-03', new \DateTimeZone('UTC'));
        $after = BalanceSeries::compute([], [self::day('2026-11-05', '-200.00')], [self::day('2026-11-03', '-450.00')], $from);

        self::assertSame([], $after);
        self::assertNull(AccountBalanceFloor::breach([], $after));
    }

    public function testMovementsBeforeTheAnchorDoNotCount(): void
    {
        $series = BalanceSeries::compute(
            [self::day('2026-11-10', '100.00')],
            [self::day('2026-11-05', '-900.00'), self::day('2026-11-10', '-50.00'), self::day('2026-11-12', '-30.00')],
            [],
            new \DateTimeImmutable('2026-11-01', new \DateTimeZone('UTC')),
        );

        self::assertSame(['2026-11-10' => '100.00', '2026-11-12' => '70.00'], self::flatten($series));
    }

    public function testALaterSnapshotReanchorsTheSeries(): void
    {
        $series = BalanceSeries::compute(
            [self::day('2026-11-01', '500.00'), self::day('2026-11-08', '40.00')],
            [self::day('2026-11-05', '-200.00'), self::day('2026-11-10', '-10.00')],
            [],
            new \DateTimeImmutable('2026-11-01', new \DateTimeZone('UTC')),
        );

        self::assertSame(['2026-11-05' => '300.00', '2026-11-10' => '30.00'], self::flatten($series));
    }

    public function testTheLimitIsZero(): void
    {
        self::assertSame('0', AccountBalanceFloor::LIMIT);
    }

    private static function day(string $day, string $amount): DayBalance
    {
        return new DayBalance(new \DateTimeImmutable($day, new \DateTimeZone('UTC')), DecimalValue::fromString($amount));
    }

    /**
     * @param list<DayBalance> $series
     *
     * @return array<string, string>
     */
    private static function flatten(array $series): array
    {
        $flat = [];
        foreach ($series as $entry) {
            $flat[$entry->day->format('Y-m-d')] = $entry->amount->toString();
        }

        return $flat;
    }
}
