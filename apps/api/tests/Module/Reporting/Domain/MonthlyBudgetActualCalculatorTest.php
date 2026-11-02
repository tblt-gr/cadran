<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Domain\MonthlyBudgetActualCalculator;
use App\Module\Reporting\Domain\MonthlyMovement;
use App\Module\Reporting\Domain\MonthlyMovementKind;
use App\Module\Reporting\Domain\MonthlySplit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonthlyBudgetActualCalculatorTest extends TestCase
{
    private const string PARENT = '00000000-0000-7000-8000-0000000000c0';
    private const string FOOD = '00000000-0000-7000-8000-0000000000c1';
    private const string LEISURE = '00000000-0000-7000-8000-0000000000c2';

    /** @param list<string> $expectedIds */
    #[DataProvider('scopes')]
    public function testItDerivesEachScopedActualFromTheMonthlyProjectionPolicy(
        string $scopeType,
        string $scopeId,
        string $expected,
        array $expectedIds,
        int $expectedPending,
    ): void {
        $booked = [
            self::movement('a1', '-150.00', MonthlyMovementKind::EXPENSE, [
                self::split(self::FOOD, '-100.00', ['ESSENTIAL']),
                self::split(self::LEISURE, '-50.00', ['DISCRETIONARY']),
            ]),
            self::movement('a2', '20.00', MonthlyMovementKind::REFUND, [
                self::split(self::FOOD, '20.00', ['ESSENTIAL']),
            ]),
            self::movement('a3', '-9.00', MonthlyMovementKind::EXPENSE, [
                self::split(self::LEISURE, '-9.00', ['DISCRETIONARY']),
            ]),
            self::movement('a4', '-999.00', MonthlyMovementKind::TRANSFER, [
                self::split(self::FOOD, '-999.00', ['ESSENTIAL']),
            ]),
            self::movement('a5', '-888.00', MonthlyMovementKind::ADJUSTMENT, [
                self::split(self::FOOD, '-888.00', ['ESSENTIAL']),
            ]),
        ];
        $pending = [
            self::movement('p1', '-30.00', MonthlyMovementKind::EXPENSE, [self::split(self::FOOD, '-30.00', ['ESSENTIAL'])]),
            self::movement('p2', '-40.00', MonthlyMovementKind::EXPENSE, [self::split(self::LEISURE, '-40.00', ['DISCRETIONARY'])]),
        ];

        $actual = MonthlyBudgetActualCalculator::compute(
            $booked,
            $pending,
            [self::FOOD => true, self::LEISURE => false],
            [self::FOOD => [self::PARENT], self::LEISURE => [self::PARENT]],
            $scopeType,
            $scopeId,
            'EUR',
            true,
        );

        self::assertSame($expected, $actual->amount);
        self::assertSame('EUR', $actual->asset?->toString());
        self::assertNull($actual->reason);
        self::assertSame($expectedIds, array_map(
            static fn ($source): string => $source->transactionId,
            $actual->sources,
        ));
        self::assertSame(['-100.00', '20.00'], array_map(
            static fn ($source): string => $source->amount,
            $actual->sources,
        ));
        self::assertSame($expectedPending, $actual->pendingCount);
    }

    /** @return iterable<string, array{string, string, string, list<string>, int}> */
    public static function scopes(): iterable
    {
        yield 'category' => ['CATEGORY', self::FOOD, '80.00', ['a1', 'a2'], 1];
        yield 'group' => ['GROUP', self::PARENT, '80.00', ['a1', 'a2'], 1];
        yield 'axis' => ['AXIS', 'ESSENTIAL', '80.00', ['a1', 'a2'], 1];
    }

    public function testOnlyAssetsOfSelectedScopedMovementsDetermineCalculability(): void
    {
        $unrelatedUsd = self::movement('u1', '-700.00', MonthlyMovementKind::EXPENSE, [
            self::split(self::LEISURE, '-700.00', ['DISCRETIONARY']),
        ], 'USD');
        $eur = self::movement('e1', '-10.00', MonthlyMovementKind::EXPENSE, [
            self::split(self::FOOD, '-10.00', ['ESSENTIAL']),
        ]);

        $actual = MonthlyBudgetActualCalculator::compute(
            [$unrelatedUsd, $eur],
            [],
            [self::FOOD => true, self::LEISURE => true],
            [],
            'CATEGORY',
            self::FOOD,
            'EUR',
            true,
        );

        self::assertSame('10.00', $actual->amount);
        self::assertSame('EUR', $actual->asset?->toString());

        $mixed = MonthlyBudgetActualCalculator::compute(
            [$eur, self::movement('u2', '-5.00', MonthlyMovementKind::EXPENSE, [self::split(self::FOOD, '-5.00', [])], 'USD')],
            [],
            [self::FOOD => true],
            [],
            'CATEGORY',
            self::FOOD,
            'EUR',
            true,
        );

        self::assertNull($mixed->amount);
        self::assertSame('MIXED_ASSETS', $mixed->reason?->value);
    }

    public function testSourceEvidenceSumsOnlyTheSplitsRetainedByTheScope(): void
    {
        $actual = MonthlyBudgetActualCalculator::compute(
            [self::movement('a1', '-175.00', MonthlyMovementKind::EXPENSE, [
                self::split(self::FOOD, '-100.00', ['ESSENTIAL']),
                self::split(self::LEISURE, '-50.00', ['ESSENTIAL']),
                self::split(self::LEISURE, '-25.00', ['DISCRETIONARY']),
            ])],
            [],
            [self::FOOD => true, self::LEISURE => true],
            [],
            'AXIS',
            'ESSENTIAL',
            'EUR',
            true,
        );

        self::assertSame('150.00', $actual->amount);
        self::assertCount(1, $actual->sources);
        self::assertSame('a1', $actual->sources[0]->transactionId);
        self::assertSame('-150.00', $actual->sources[0]->amount);
        self::assertSame('EUR', $actual->sources[0]->asset->toString());
    }

    public function testACalculatedTotalMayExceedTheStorageIntegerWidth(): void
    {
        $maximum = '-99999999999999999999999999.999999999999999999999999';
        $actual = MonthlyBudgetActualCalculator::compute(
            [
                self::movement('l1', $maximum, MonthlyMovementKind::EXPENSE, [self::split(self::FOOD, $maximum, [])]),
                self::movement('l2', $maximum, MonthlyMovementKind::EXPENSE, [self::split(self::FOOD, $maximum, [])]),
            ],
            [],
            [self::FOOD => true],
            [],
            'CATEGORY',
            self::FOOD,
            'EUR',
            true,
        );

        self::assertSame('199999999999999999999999999.999999999999999999999998', $actual->amount);
        self::assertSame(['l1', 'l2'], array_map(
            static fn ($source): string => $source->transactionId,
            $actual->sources,
        ));
        self::assertSame([$maximum, $maximum], array_map(
            static fn ($source): string => $source->amount,
            $actual->sources,
        ));
    }

    public function testNoAccountIsDifferentFromAnEmptyScopedAmount(): void
    {
        $missing = MonthlyBudgetActualCalculator::compute(
            [],
            [],
            [self::FOOD => true],
            [],
            'CATEGORY',
            self::FOOD,
            'EUR',
            false,
        );
        self::assertNull($missing->amount);
        self::assertSame('NO_ACCOUNT', $missing->reason?->value);

        $empty = MonthlyBudgetActualCalculator::compute(
            [],
            [],
            [self::FOOD => true],
            [],
            'CATEGORY',
            self::FOOD,
            'EUR',
            true,
        );
        self::assertSame('0', $empty->amount);
        self::assertSame('EUR', $empty->asset?->toString());
        self::assertNull($empty->reason);
    }

    /** Worked example 1 of the planning ticket: -13.99 booked, -29.99 planned in the same category. */
    public function testPlannedMovementsAreAddedAsIfBookedAndReportedApart(): void
    {
        $booked = [self::movement('b1', '-13.99', MonthlyMovementKind::EXPENSE, [self::split(self::FOOD, '-13.99', [])])];
        $planned = [self::movement('p1', '-29.99', MonthlyMovementKind::EXPENSE, [self::split(self::FOOD, '-29.99', [])])];

        $actual = MonthlyBudgetActualCalculator::compute(
            $booked, [], [self::FOOD => true], [], 'CATEGORY', self::FOOD, 'EUR', true, $planned,
        );

        self::assertSame('43.98', $actual->amount);
        self::assertSame('29.99', $actual->upcomingAmount);
        self::assertSame(1, $actual->upcomingCount);
        self::assertSame(0, $actual->pendingCount);
        self::assertSame(['b1' => false, 'p1' => true], array_column(array_map(
            static fn ($source): array => [$source->transactionId, $source->upcoming],
            $actual->sources,
        ), 1, 0));
    }

    public function testPlannedRowsLeftInThePendingListCountNowhereButAsPending(): void
    {
        $booked = [self::movement('b1', '-13.99', MonthlyMovementKind::EXPENSE, [self::split(self::FOOD, '-13.99', [])])];
        $pending = [self::movement('p1', '-29.99', MonthlyMovementKind::EXPENSE, [self::split(self::FOOD, '-29.99', [])])];

        $actual = MonthlyBudgetActualCalculator::compute($booked, $pending, [self::FOOD => true], [], 'CATEGORY', self::FOOD, 'EUR', true);

        self::assertSame('13.99', $actual->amount);
        self::assertNull($actual->upcomingAmount);
        self::assertSame(0, $actual->upcomingCount);
        self::assertSame(1, $actual->pendingCount);
    }

    public function testBookingAPlannedRowChangesNoFigure(): void
    {
        $row = static fn (): MonthlyMovement => self::movement('p1', '-29.99', MonthlyMovementKind::EXPENSE, [self::split(self::FOOD, '-29.99', [])]);
        $before = MonthlyBudgetActualCalculator::compute([], [], [self::FOOD => true], [], 'CATEGORY', self::FOOD, 'EUR', true, [$row()]);
        $after = MonthlyBudgetActualCalculator::compute([$row()], [], [self::FOOD => true], [], 'CATEGORY', self::FOOD, 'EUR', true);

        self::assertSame($before->amount, $after->amount);
        self::assertNull($after->upcomingAmount);
    }

    public function testAPlannedMovementOutsideTheScopeIsNotCounted(): void
    {
        $planned = [self::movement('p1', '-29.99', MonthlyMovementKind::EXPENSE, [self::split(self::LEISURE, '-29.99', [])])];

        $actual = MonthlyBudgetActualCalculator::compute([], [], [self::FOOD => true, self::LEISURE => false], [], 'CATEGORY', self::FOOD, 'EUR', true, $planned);

        self::assertSame('0', $actual->amount);
        self::assertNull($actual->upcomingAmount);
        self::assertSame(0, $actual->upcomingCount);
    }

    public function testPlannedAmountsKeepEveryDigitOfAVeryLargeDecimal(): void
    {
        $large = '1234567890123456789012.123456789012345678901234';
        $booked = [self::movement('b1', '-'.$large, MonthlyMovementKind::EXPENSE, [self::split(self::FOOD, '-'.$large, [])])];
        $planned = [self::movement('p1', '-'.$large, MonthlyMovementKind::EXPENSE, [self::split(self::FOOD, '-'.$large, [])])];

        $actual = MonthlyBudgetActualCalculator::compute($booked, [], [self::FOOD => true], [], 'CATEGORY', self::FOOD, 'EUR', true, $planned);

        self::assertSame('2469135780246913578024.246913578024691357802468', $actual->amount);
        self::assertSame($large, $actual->upcomingAmount);
    }

    public function testPlannedRowsInAnotherAssetMakeTheActualNonCalculableWithoutLosingTheCount(): void
    {
        $booked = [self::movement('b1', '-10.00', MonthlyMovementKind::EXPENSE, [self::split(self::FOOD, '-10.00', [])])];
        $planned = [self::movement('p1', '-5.00', MonthlyMovementKind::EXPENSE, [self::split(self::FOOD, '-5.00', [])], 'USD')];

        $actual = MonthlyBudgetActualCalculator::compute($booked, [], [self::FOOD => true], [], 'CATEGORY', self::FOOD, 'EUR', true, $planned);

        self::assertNull($actual->amount);
        self::assertSame('MIXED_ASSETS', $actual->reason?->value);
        self::assertNull($actual->upcomingAmount);
        self::assertSame(1, $actual->upcomingCount);
    }

    /** @param list<MonthlySplit> $splits */
    private static function movement(
        string $id,
        string $amount,
        MonthlyMovementKind $kind,
        array $splits,
        string $asset = 'EUR',
    ): MonthlyMovement {
        return new MonthlyMovement(
            DecimalValue::fromString($amount),
            AssetCode::fromString($asset),
            $kind,
            $splits,
            false,
            $id,
        );
    }

    /** @param list<string> $axes */
    private static function split(string $categoryId, string $amount, array $axes): MonthlySplit
    {
        return new MonthlySplit($categoryId, DecimalValue::fromString($amount), $axes);
    }
}
