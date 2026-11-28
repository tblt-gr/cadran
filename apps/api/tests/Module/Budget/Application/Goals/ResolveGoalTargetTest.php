<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Application\Goals\GoalStatusInputs;
use App\Module\Budget\Application\Goals\ResolveGoalTarget;
use App\Module\Budget\Domain\Goals\GoalReason;
use App\Module\Budget\Domain\Goals\GoalValueType;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResolveGoalTargetTest extends TestCase
{
    private const string ID = '00000000-0000-7000-8000-000000000101';

    public function testAnAmountAndARateTargetTheirOwnValueAndTolerance(): void
    {
        $amount = GoalVersion::amount(self::ID, WorkspaceFixture::own(), self::ID, 1, '2026-01', DecimalValue::fromString('1200.00'), null, self::created(), WorkspaceFixture::OWNER_ID, tolerance: DecimalValue::fromString('50.00'));
        $rate = GoalVersion::rate(self::ID, WorkspaceFixture::own(), self::ID, 1, '2026-01', DecimalValue::fromString('0.10'), self::created(), WorkspaceFixture::OWNER_ID);

        $fixed = (new ResolveGoalTarget())->forMonth($amount, self::month(null), new CalendarMonth(2026, 3), self::inputs([]));
        $year = (new ResolveGoalTarget())->forYear($rate, null);

        self::assertSame(['1200.00', '50.00', null, null], [$fixed->target, $fixed->tolerance, $fixed->reason, $fixed->income]);
        self::assertSame(['0.10', null, null], [$year->target, $year->tolerance, $year->reason]);
    }

    /** @return iterable<string, array{?string, ?string, ?GoalReason}> */
    public static function incomes(): iterable
    {
        yield 'no cash income cell' => [null, null, GoalReason::NO_DATA];
        yield 'zero income, never a zero target' => ['0', null, GoalReason::ZERO_CASH_INCOME];
        yield 'zero income at any scale' => ['0.00', null, GoalReason::ZERO_CASH_INCOME];
        yield 'a share of the income' => ['3000.00', '1200', null];
    }

    #[DataProvider('incomes')]
    public function testAShareOfIncomeHasOneRuleForTheMonthAndTheYear(?string $income, ?string $target, ?GoalReason $reason): void
    {
        $version = self::share(GoalValueType::INCOME_SHARE);

        foreach ([
            (new ResolveGoalTarget())->forMonth($version, self::month($income), new CalendarMonth(2026, 3), self::inputs([])),
            (new ResolveGoalTarget())->forYear($version, $income),
        ] as $resolved) {
            self::assertSame($reason, $resolved->reason);
            self::assertSame($income, $resolved->income);
            if (null === $target) {
                self::assertNull($resolved->target);
                self::assertNull($resolved->tolerance);
            } else {
                self::assertSame(0, ExactDecimal::compareForResponse((string) $resolved->target, $target));
                // The margin is a share of the same income: 3000.00 x 0.01 = 30.
                self::assertSame(0, ExactDecimal::compareForResponse((string) $resolved->tolerance, '30'));
            }
        }
    }

    public function testARollingShareReadsTheReferenceIncomeOrSaysItHasNone(): void
    {
        $version = self::share(GoalValueType::ROLLING_INCOME_SHARE);
        $selected = new CalendarMonth(2026, 3);

        $none = (new ResolveGoalTarget())->forMonth($version, self::month('9000.00'), $selected, self::inputs([]));
        $some = (new ResolveGoalTarget())->forMonth($version, self::month('9000.00'), $selected, self::inputs([self::month('2500.00', 2)]));

        self::assertSame(GoalReason::NO_REFERENCE_INCOME, $none->reason);
        self::assertNull($none->target);
        self::assertNull($none->tolerance);
        self::assertSame('NO_REFERENCE_INCOME', $none->reference?->reason?->value);
        // 2500.00 x 0.40 = 1000, the month being read never entering its own reference.
        self::assertSame(0, ExactDecimal::compareForResponse((string) $some->target, '1000'));
        self::assertSame(0, ExactDecimal::compareForResponse((string) $some->tolerance, '25'));
    }

    public function testAnUnknownReportReasonNeverLeaks(): void
    {
        self::assertSame(GoalReason::NO_DATA, GoalReason::fromReport('SOME_INTERNAL_CODE'));
        self::assertNull(GoalReason::fromReport(null));
        self::assertSame(GoalReason::MIXED_ASSETS, GoalReason::fromReport('MIXED_ASSETS'));
        self::assertSame(GoalReason::NO_DATA, GoalReason::MISSING_MONTH_VALUE->forEvaluation());
        self::assertSame(GoalReason::ZERO_CASH_INCOME, GoalReason::ZERO_CASH_INCOME->forEvaluation());
    }

    private static function share(GoalValueType $type): GoalVersion
    {
        return GoalVersion::share(self::ID, WorkspaceFixture::own(), self::ID, 1, '2026-01', $type, DecimalValue::fromString('0.40'), self::created(), WorkspaceFixture::OWNER_ID, tolerance: DecimalValue::fromString('0.01'));
    }

    private static function created(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-01');
    }

    private static function month(?string $income, int $index = 3): AnnualMonth
    {
        $month = new CalendarMonth(2026, $index);
        $cells = null === $income ? [] : ['cashIncome' => new MonthlyMetricView($income, 'EUR', null)];

        return new AnnualMonth($month, MonthState::COMPLETE, false, false, new MonthFigures($month->key(), 1, 'CURRENT', 0, $month->lastDay()->format('Y-m-d'), $cells, [], [], []));
    }

    /** @param list<AnnualMonth> $months */
    private static function inputs(array $months): GoalStatusInputs
    {
        $indexed = [];
        foreach ($months as $month) {
            $indexed[$month->month->key()] = $month;
        }

        return new GoalStatusInputs($indexed, null);
    }
}
