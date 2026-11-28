<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Application\Goals\GoalMetricReader;
use App\Module\Budget\Application\Goals\GoalMonthSource;
use App\Module\Budget\Application\Goals\GoalPeriodEvaluator;
use App\Module\Budget\Application\Goals\GoalStatusInputs;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalEvaluationStatus;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalReason;
use App\Module\Budget\Domain\Goals\GoalSchedule;
use App\Module\Budget\Domain\Goals\GoalScopeType;
use App\Module\Budget\Domain\Goals\GoalStatusQuality;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Transactions\Application\MonthlyTransferPairReader;
use App\Module\Transactions\Application\ReadMonthlyAccountTransferNet;
use App\Module\Transactions\Application\ReadMonthlyTransferPairs;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class GoalPeriodEvaluatorTest extends TestCase
{
    private const string GOAL_ID = '00000000-0000-7000-8000-000000000101';
    private const string VERSION_ID = '00000000-0000-7000-8000-000000000102';

    public function testAnnualCumulatesCompleteMonthsAgainstTheSchedule(): void
    {
        $goal = self::goal(GoalPeriodicity::YEARLY, '2026');
        $inputs = self::inputs([self::month(1, '180.00'), self::month(2, '180.00')]);

        $status = $this->evaluator()->annual($goal, [self::amount('2026-01', '2000.00', true)], new CalendarMonth(2026, 2), false, [], $inputs);

        self::assertSame('360.00', $status->achieved);
        self::assertSame('2000.00', $status->target);
        self::assertCount(2, $status->months);
        self::assertSame('180.00', $status->months[0]->cumulativeValue);
        self::assertSame('360.00', $status->months[1]->cumulativeValue);
        self::assertNull($status->reason);
    }

    public function testAnnualWithAMissingMonthFigureIsNotCalculable(): void
    {
        $goal = self::goal(GoalPeriodicity::YEARLY, '2026');
        $inputs = self::inputs([self::month(1, '180.00'), self::month(2, null)]);

        $status = $this->evaluator()->annual($goal, [self::amount('2026-01', '2000.00', true)], new CalendarMonth(2026, 2), false, [], $inputs);

        self::assertNull($status->achieved);
        self::assertSame(GoalEvaluationStatus::NOT_CALCULABLE, $status->status);
        self::assertSame(GoalReason::MISSING_MONTH_VALUE, $status->reason);
        self::assertNull($status->months[1]->cumulativeValue);
    }

    public function testAnnualExcessAndRemainingNeverGoNegativeAndProgressIsClamped(): void
    {
        $goal = self::goal(GoalPeriodicity::YEARLY, '2026');
        $inputs = self::inputs([self::month(1, '300.00')]);

        $status = $this->evaluator()->annual($goal, [self::amount('2026-01', '200.00', true)], new CalendarMonth(2026, 1), false, [], $inputs);

        self::assertSame('0', $status->remaining);
        self::assertSame('100.00', $status->excess);
        self::assertSame('1', $status->progressRatioDisplay);
    }

    public function testArchivedReferenceKeepsTheStatusCalculable(): void
    {
        $goal = self::goal(GoalPeriodicity::YEARLY, '2026');
        $inputs = self::inputs([self::month(1, '300.00')]);

        $status = $this->evaluator()->annual($goal, [self::amount('2026-01', '200.00', true)], new CalendarMonth(2026, 1), true, [], $inputs);

        self::assertSame(GoalReason::REFERENCE_ARCHIVED, $status->reason);
        self::assertNotSame(GoalEvaluationStatus::NOT_CALCULABLE, $status->status);
    }

    public function testAnnualWithoutVersionIsNotStarted(): void
    {
        $status = $this->evaluator()->annual(self::goal(GoalPeriodicity::YEARLY, '2026'), [], new CalendarMonth(2026, 1), false, [], self::inputs([self::month(1, '1.00')]));

        self::assertSame(GoalReason::GOAL_NOT_STARTED, $status->reason);
        self::assertNull($status->target);
    }

    public function testMonthlyComparesTheSelectedMonthWithItsOwnTarget(): void
    {
        $goal = self::goal(GoalPeriodicity::MONTHLY, '2026-01');
        $inputs = self::inputs([self::month(1, '50.00'), self::month(2, '120.00')]);

        $status = $this->evaluator()->monthly($goal, [self::amount('2026-01', '100.00', false)], new CalendarMonth(2026, 2), false, [], $inputs);

        self::assertSame('120.00', $status->actual);
        self::assertSame('100.00', $status->target);
        self::assertSame('20.00', $status->gap);
        self::assertSame(GoalEvaluationStatus::MET, $status->status);
        self::assertCount(2, $status->months);
    }

    public function testMonthlyWithoutDataIsNotCalculableAndPartial(): void
    {
        $goal = self::goal(GoalPeriodicity::MONTHLY, '2026-01');
        $noData = new AnnualMonth(new CalendarMonth(2026, 1), MonthState::NO_DATA, false, false, null);

        $status = $this->evaluator()->monthly($goal, [self::amount('2026-01', '100.00', false)], new CalendarMonth(2026, 1), false, [], self::inputs([$noData]));

        self::assertSame(GoalEvaluationStatus::NOT_CALCULABLE, $status->status);
        self::assertSame(GoalReason::MISSING_MONTH_VALUE, $status->reason);
        self::assertSame(GoalStatusQuality::PARTIAL, $status->quality);
        self::assertNull($status->gap);
    }

    private function evaluator(): GoalPeriodEvaluator
    {
        $source = new class implements GoalMonthSource {
            public function between(WorkspaceScope $workspace, CalendarMonth $start, CalendarMonth $end, bool $includeUpcoming = false): array
            {
                return [];
            }
        };
        $pairs = new class implements MonthlyTransferPairReader {
            public function read(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
            {
                return [];
            }
        };

        return new GoalPeriodEvaluator(new GoalMetricReader($source, new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs($pairs))));
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

    private static function goal(GoalPeriodicity $periodicity, string $start): Goal
    {
        return new Goal(self::GOAL_ID, WorkspaceFixture::own(), 'Goal', $periodicity, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE, null, AssetCode::fromString('EUR'), $start, null, 1, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID);
    }

    private static function amount(string $effectiveFrom, string $target, bool $scheduled): GoalVersion
    {
        $amount = DecimalValue::fromString($target);

        return GoalVersion::amount(self::VERSION_ID, WorkspaceFixture::own(), self::GOAL_ID, 1, $effectiveFrom, $amount, $scheduled ? GoalSchedule::linear($amount, 2) : null, new \DateTimeImmutable('2026-01-01'), WorkspaceFixture::OWNER_ID);
    }

    private static function month(int $index, ?string $value): AnnualMonth
    {
        $month = new CalendarMonth(2026, $index);

        return new AnnualMonth($month, MonthState::COMPLETE, false, false, new MonthFigures($month->key(), 1, 'CURRENT', 0, $month->lastDay()->format('Y-m-d'), [
            'netSavingsTransfers' => new MonthlyMetricView($value, null === $value ? null : 'EUR', null === $value ? 'NO_ACCOUNT' : null),
        ], [], [], []));
    }
}
