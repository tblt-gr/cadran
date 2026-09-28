<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Domain\Goals;

use App\Module\Budget\Domain\Goals\GoalSchedule;
use App\Module\Budget\Domain\Goals\GoalScheduleMode;
use App\Module\Budget\Domain\Goals\InvalidGoalSchedule;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoalScheduleTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function linearReferenceExamples(): iterable
    {
        // Hand-computed: part = round(T / 12, 2, HALF_UP), part 12 = T - 11 x part.
        yield '2000' => ['2000', '166.67', '166.63'];
        yield '1200' => ['1200', '100.00', '100.00'];
        yield '1000' => ['1000', '83.33', '83.37'];
        yield '100000.01' => ['100000.01', '8333.33', '8333.38'];
    }

    #[DataProvider('linearReferenceExamples')]
    public function testALinearSplitPutsTheResidualOnDecember(string $target, string $part, string $december): void
    {
        $schedule = GoalSchedule::linear(DecimalValue::fromString($target), 2);

        self::assertSame(GoalScheduleMode::LINEAR, $schedule->mode);
        self::assertSame([...array_fill(0, 11, $part), $december], self::literals($schedule->parts));
        self::assertSame(0, ExactDecimal::sum(...$schedule->parts)->compareTo(DecimalValue::fromString($target)));
    }

    public function testCumulativeTotalsRunExactlyToTheTarget(): void
    {
        $cumulative = GoalSchedule::linear(DecimalValue::fromString('2000'), 2)->cumulative();

        self::assertSame('166.67', $cumulative[0]->toString());
        self::assertSame('1833.37', $cumulative[10]->toString());
        self::assertSame('2000.00', $cumulative[11]->toString());
    }

    public function testALinearSplitThatWouldMakeDecemberNegativeIsRefused(): void
    {
        // 0.10 / 12 rounds to 0.01, so December would be 0.10 - 0.11 = -0.01.
        self::assertSame('linear_schedule_impossible', self::codeOf(static fn () => GoalSchedule::linear(DecimalValue::fromString('0.10'), 2)));
    }

    public function testALinearSplitOfATargetFinerThanTheDisplayPrecisionIsRefused(): void
    {
        self::assertSame('schedule_precision_exceeded', self::codeOf(static fn () => GoalSchedule::linear(DecimalValue::fromString('15000.125'), 2)));
    }

    public function testATargetWrittenWithTrailingZerosBeyondThePrecisionStillSplits(): void
    {
        $schedule = GoalSchedule::linear(DecimalValue::fromString('1200.000'), 2);

        self::assertSame('100.00', $schedule->parts[11]->toString());
    }

    public function testTheLinearSplitIsDeterministic(): void
    {
        $target = DecimalValue::fromString('98765.43');

        self::assertSame(self::literals(GoalSchedule::linear($target, 2)->parts), self::literals(GoalSchedule::linear($target, 2)->parts));
    }

    public function testTheLinearSplitHoldsForRandomTargetsUpToTenToTheTwelfth(): void
    {
        mt_srand(20260928);
        for ($i = 0; $i < 500; ++$i) {
            $scale = mt_rand(0, 2);
            $integer = (string) mt_rand(1, 999_999);
            if (0 === mt_rand(0, 1)) {
                $integer .= str_pad((string) mt_rand(0, 999_999), 6, '0', STR_PAD_LEFT);
                $integer = ltrim($integer, '0');
            }
            $literal = 0 === $scale ? $integer : $integer.'.'.str_pad((string) mt_rand(0, 10 ** $scale - 1), $scale, '0', STR_PAD_LEFT);
            $target = DecimalValue::fromString($literal);

            $parts = GoalSchedule::linear($target, 2)->parts;

            self::assertCount(12, $parts);
            self::assertSame(0, ExactDecimal::sum(...$parts)->compareTo($target), $literal);
            self::assertCount(1, array_unique(self::literals(array_slice($parts, 0, 11))), $literal);
            $gap = ExactDecimal::absolute(ExactDecimal::subtract($parts[11], $parts[0]));
            self::assertLessThanOrEqual(0, $gap->compareTo(DecimalValue::fromString('0.06')), $literal);
        }
    }

    public function testTheLargestStorableTargetSplitsExactly(): void
    {
        $target = DecimalValue::fromString(str_repeat('9', 26));

        $parts = GoalSchedule::linear($target, 2)->parts;

        self::assertSame(0, ExactDecimal::sum(...$parts)->compareTo($target));
    }

    public function testACustomScheduleKeepsItsPartsAtTheDisplayPrecision(): void
    {
        $parts = ['0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '1000', '1000.5'];

        $schedule = GoalSchedule::custom(DecimalValue::fromString('2000.50'), self::decimals($parts), 2);

        self::assertSame(GoalScheduleMode::CUSTOM, $schedule->mode);
        self::assertSame([...array_fill(0, 10, '0.00'), '1000.00', '1000.50'], self::literals($schedule->parts));
    }

    public function testACustomScheduleOffByOneCentReportsTheExactDifference(): void
    {
        $parts = [...array_fill(0, 11, '166.67'), '166.62'];

        try {
            GoalSchedule::custom(DecimalValue::fromString('2000'), self::decimals($parts), 2);
            self::fail('A mismatched sum must be refused.');
        } catch (InvalidGoalSchedule $error) {
            self::assertSame('schedule_sum_mismatch', $error->errorCode);
            self::assertSame(['expected' => '2000', 'actual' => '1999.99', 'difference' => '0.01'], $error->details);
        }
    }

    public function testACustomScheduleNeedsExactlyTwelveParts(): void
    {
        self::assertSame('schedule_part_count', self::codeOf(static fn () => GoalSchedule::custom(DecimalValue::fromString('2000'), self::decimals(array_fill(0, 11, '0')), 2)));
        self::assertSame('schedule_part_count', self::codeOf(static fn () => GoalSchedule::custom(DecimalValue::fromString('2000'), [], 2)));
    }

    public function testACustomScheduleRefusesANegativePart(): void
    {
        $parts = ['-1', '2001', ...array_fill(0, 10, '0')];

        self::assertSame('schedule_negative_part', self::codeOf(static fn () => GoalSchedule::custom(DecimalValue::fromString('2000'), self::decimals($parts), 2)));
    }

    public function testACustomScheduleRefusesAPartFinerThanTheDisplayPrecision(): void
    {
        $parts = ['1999.995', '0.005', ...array_fill(0, 10, '0')];

        self::assertSame('schedule_precision_exceeded', self::codeOf(static fn () => GoalSchedule::custom(DecimalValue::fromString('2000'), self::decimals($parts), 2)));
    }

    public function testRestoringAStoredScheduleStillChecksTheSum(): void
    {
        $parts = self::decimals([...array_fill(0, 11, '100.00'), '99.99']);

        self::assertSame('schedule_sum_mismatch', self::codeOf(static fn () => GoalSchedule::restore(GoalScheduleMode::LINEAR, DecimalValue::fromString('1200'), $parts)));
    }

    /**
     * @param list<DecimalValue> $values
     *
     * @return list<string>
     */
    private static function literals(array $values): array
    {
        return array_map(static fn (DecimalValue $value): string => $value->toString(), $values);
    }

    /**
     * @param list<string> $values
     *
     * @return list<DecimalValue>
     */
    private static function decimals(array $values): array
    {
        return array_map(DecimalValue::fromString(...), $values);
    }

    private static function codeOf(\Closure $build): string
    {
        try {
            $build();
        } catch (InvalidGoalSchedule $error) {
            return $error->errorCode;
        }
        self::fail('The schedule must be refused.');
    }
}
