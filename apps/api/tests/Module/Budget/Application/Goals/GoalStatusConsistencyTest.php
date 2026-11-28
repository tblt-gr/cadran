<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Goals;

use App\Module\Accounts\Application\ReadAccountReference;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Application\Goals\BuildGoalRevision;
use App\Module\Budget\Application\Goals\BuildGoalSchedule;
use App\Module\Budget\Application\Goals\GoalCorrectionPreviewMonthView;
use App\Module\Budget\Application\Goals\GoalEvaluationView;
use App\Module\Budget\Application\Goals\GoalMetricReader;
use App\Module\Budget\Application\Goals\GoalPeriodEvaluator;
use App\Module\Budget\Application\Goals\ListGoalEvaluations;
use App\Module\Budget\Application\Goals\ListGoals;
use App\Module\Budget\Application\Goals\PresentGoal;
use App\Module\Budget\Application\Goals\PreviewGoalCorrection;
use App\Module\Budget\Application\Goals\ReadGoalStatus;
use App\Module\Budget\Application\Goals\ReadReferenceIncome;
use App\Module\Budget\Application\Goals\ResolveGoalScope;
use App\Module\Budget\Application\Goals\UpdateGoalInput;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalEvaluationStatus;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalReason;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalSchedule;
use App\Module\Budget\Domain\Goals\GoalScopeType;
use App\Module\Budget\Domain\Goals\GoalValueType;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Categories\Application\ReadCategoryReference;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Transactions\Application\ReadMonthlyAccountTransferNet;
use App\Module\Transactions\Application\ReadMonthlyTransferPairs;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The status endpoint, the goal list summary, the evaluations and the correction preview judge one
 * goal and period the same way. Today is 2026-09-24: 2025 is complete, September 2026 is running.
 */
final class GoalStatusConsistencyTest extends TestCase
{
    private const string GOAL_ID = '00000000-0000-7000-8000-000000000101';

    /**
     * @return iterable<string, array{GoalPeriodicity, GoalMetric, string, string, ?string, GoalEvaluationStatus}>
     */
    public static function cases(): iterable
    {
        $savings = GoalMetric::NET_SAVINGS_TRANSFERS;
        $spending = GoalMetric::AXIS_SPENDING;
        // Each month saves 100.00 and spends 100.00; a year therefore totals 1200.00 complete and 900.00 to September.
        foreach ([[null, '-strict'], ['50.00', '-tolerance']] as [$tolerance, $suffix]) {
            $strict = null === $tolerance;
            yield 'monthly minimum short, complete'.$suffix => [GoalPeriodicity::MONTHLY, $savings, '2026-08', '140.00', $tolerance, $strict ? GoalEvaluationStatus::MISSED : GoalEvaluationStatus::MET];
            yield 'monthly minimum short, running'.$suffix => [GoalPeriodicity::MONTHLY, $savings, '2026-09', '140.00', $tolerance, GoalEvaluationStatus::IN_PROGRESS];
            yield 'monthly minimum reached, running'.$suffix => [GoalPeriodicity::MONTHLY, $savings, '2026-09', '100.00', $tolerance, GoalEvaluationStatus::IN_PROGRESS];
            yield 'monthly ceiling at the target, complete'.$suffix => [GoalPeriodicity::MONTHLY, $spending, '2026-08', '100.00', $tolerance, GoalEvaluationStatus::MET];
            yield 'monthly ceiling passed, running'.$suffix => [GoalPeriodicity::MONTHLY, $spending, '2026-09', '60.00', $strict ? null : '10.00', GoalEvaluationStatus::MISSED];
            yield 'monthly ceiling respected, running'.$suffix => [GoalPeriodicity::MONTHLY, $spending, '2026-09', '200.00', $tolerance, GoalEvaluationStatus::IN_PROGRESS];
            yield 'yearly minimum short, complete'.$suffix => [GoalPeriodicity::YEARLY, $savings, '2025-12', '1240.00', $tolerance, $strict ? GoalEvaluationStatus::MISSED : GoalEvaluationStatus::MET];
            yield 'yearly minimum reached, running'.$suffix => [GoalPeriodicity::YEARLY, $savings, '2026-09', '600.00', $tolerance, GoalEvaluationStatus::IN_PROGRESS];
            yield 'yearly ceiling inside the margin, complete'.$suffix => [GoalPeriodicity::YEARLY, $spending, '2025-12', '1180.00', $tolerance, $strict ? GoalEvaluationStatus::MISSED : GoalEvaluationStatus::MET];
            yield 'yearly ceiling passed, running'.$suffix => [GoalPeriodicity::YEARLY, $spending, '2026-09', '600.00', $tolerance, GoalEvaluationStatus::MISSED];
        }
    }

    #[DataProvider('cases')]
    public function testEveryReadingGivesTheSameVerdict(GoalPeriodicity $periodicity, GoalMetric $metric, string $month, string $target, ?string $tolerance, GoalEvaluationStatus $expected): void
    {
        $yearly = GoalPeriodicity::YEARLY === $periodicity;
        $start = $yearly ? substr($month, 0, 4) : substr($month, 0, 4).'-01';
        $goal = new Goal(self::GOAL_ID, WorkspaceFixture::own(), 'Goal', $periodicity, $metric, GoalMetric::AXIS_SPENDING === $metric ? GoalScopeType::AXIS : GoalScopeType::WORKSPACE, GoalMetric::AXIS_SPENDING === $metric ? 'ESSENTIAL' : null, AssetCode::fromString('EUR'), $start, null, 1, new \DateTimeImmutable('2025-01-01'), WorkspaceFixture::OWNER_ID);
        $amount = DecimalValue::fromString($target);
        $from = substr($month, 0, 4).'-01';
        $version = GoalVersion::amount(self::GOAL_ID, WorkspaceFixture::own(), self::GOAL_ID, 1, $from, $amount, $yearly ? GoalSchedule::linear($amount, 2) : null, new \DateTimeImmutable('2025-01-01'), WorkspaceFixture::OWNER_ID, tolerance: null === $tolerance ? null : DecimalValue::fromString($tolerance));

        [$status, $summary, $evaluation, $preview] = $this->read($goal, $version, $month);

        self::assertSame($expected, $status->status);
        foreach ([$summary->status, $evaluation->status, $preview->before->status] as $other) {
            self::assertSame($status->status, $other);
        }
        self::assertSame($status->target, $summary->target);
        self::assertSame($status->target, $evaluation->target?->value);
        self::assertSame($status->gap, $summary->gap);
        self::assertSame($status->gap, $evaluation->gap);
        self::assertSame($status->reason, $summary->reason);
        self::assertSame($status->reason, $evaluation->reason);
        self::assertSame($status->pace, $preview->before->pace);
        self::assertSame($yearly, null !== $status->pace);
    }

    public function testAZeroCashIncomeIsNotCalculableEverywhere(): void
    {
        $goal = new Goal(self::GOAL_ID, WorkspaceFixture::own(), 'Goal', GoalPeriodicity::MONTHLY, GoalMetric::AXIS_SPENDING, GoalScopeType::AXIS, 'ESSENTIAL', AssetCode::fromString('EUR'), '2026-01', null, 1, new \DateTimeImmutable('2025-01-01'), WorkspaceFixture::OWNER_ID);
        $version = GoalVersion::share(self::GOAL_ID, WorkspaceFixture::own(), self::GOAL_ID, 1, '2026-01', GoalValueType::INCOME_SHARE, DecimalValue::fromString('0.40'), new \DateTimeImmutable('2025-01-01'), WorkspaceFixture::OWNER_ID);

        [$status, $summary, $evaluation, $preview] = $this->read($goal, $version, '2026-08', '0', '0.30');

        foreach ([$status->status, $summary->status, $evaluation->status, $preview->before->status] as $verdict) {
            self::assertSame(GoalEvaluationStatus::NOT_CALCULABLE, $verdict);
        }
        foreach ([$status->reason, $summary->reason, $evaluation->reason] as $reason) {
            self::assertSame(GoalReason::ZERO_CASH_INCOME, $reason);
        }
        self::assertNull($status->target);
        self::assertNull($summary->target);
        self::assertNull($evaluation->target);
        self::assertNull($status->gap);
        self::assertNull($evaluation->gap);
    }

    /**
     * @return array{\App\Module\Budget\Application\Goals\GoalStatusView, \App\Module\Budget\Application\Goals\GoalStatusView, GoalEvaluationView, GoalCorrectionPreviewMonthView}
     */
    private function read(Goal $goal, GoalVersion $version, string $month, string $income = '2500.00', ?string $correctedRatio = null): array
    {
        $source = new CountingGoalMonthSource(self::months($income));
        $caller = $this->createStub(CallerWorkspace::class);
        $caller->method('resolve')->willReturn(WorkspaceFixture::own());
        $context = $this->createStub(CallerWorkspaceContext::class);
        $context->method('resolveContext')->willReturn(new WorkspaceContext(WorkspaceFixture::own(), WorkspaceFixture::OWNER_ID, true));
        $goals = $this->createStub(GoalRepository::class);
        $goals->method('find')->willReturn($goal);
        $goals->method('search')->willReturn([$goal]);
        $goals->method('count')->willReturn(1);
        $goals->method('covering')->willReturn([$goal]);
        $versions = $this->createStub(GoalVersionRepository::class);
        $versions->method('listByGoal')->willReturn([$version]);
        $versions->method('listByGoals')->willReturn([$goal->id => [$version]]);
        $scope = new ResolveGoalScope(new ReadCategoryReference($this->createStub(CategoryRepository::class)), new ReadAccountReference(new InMemoryAccountRepository()));
        $metrics = new GoalMetricReader($source, new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs(new EmptyTransferPairReader())));
        $calendar = self::calendar();
        $read = new ReadGoalStatus($caller, $goals, $versions, $metrics, new GoalPeriodEvaluator($metrics), $calendar, $scope);
        $list = new ListGoals($caller, $goals, $versions, new PresentGoal($scope, new ReadReferenceIncome($source), $calendar), $read, $metrics, $scope, $calendar);
        $evaluations = new ListGoalEvaluations($caller, $goals, $versions, $metrics, $scope, $calendar);

        $yearly = GoalPeriodicity::YEARLY === $goal->periodicity;
        $status = $read($goal->id, $month);
        $summary = $list(null, $yearly ? substr($month, 0, 4) : $month)->items[0]->status ?? self::fail('A listed goal carries its status.');
        $result = $evaluations($month, $month);
        $evaluation = ($yearly ? $result->years : $result->months)[0]->items[0];

        $uuid = $this->createStub(UuidGenerator::class);
        $uuid->method('generate')->willReturn('00000000-0000-7000-8000-000000000103');
        $revisions = new BuildGoalRevision($calendar, $uuid, new BuildGoalSchedule(InMemoryAssetCatalog::withCodes('EUR')));
        // The preview compares the stored definition ("before") with any correction at all.
        $amount = null === $version->targetAmount ? null : ExactDecimal::addForResponse($version->targetAmount->toString(), '1');
        $preview = (new PreviewGoalCorrection($context, $goals, $versions, $revisions, $read, $metrics, $calendar))(new UpdateGoalInput(
            $goal->id, 1, 'Goal',
            $amount,
            null === $amount ? null : 'EUR',
            $correctedRatio,
            false, null, $version->effectiveFrom, 'Correct the recorded target.',
        ));
        $months = array_values(array_filter($preview->months, static fn (GoalCorrectionPreviewMonthView $view): bool => $view->month === $month));

        return [$status, $summary, $evaluation, $months[0] ?? self::fail('The preview covers the read month.')];
    }

    /** @return list<AnnualMonth> January 2024 to September 2026, the last one running */
    private static function months(string $income): array
    {
        $months = [];
        for ($ordinal = 0; $ordinal < 33; ++$ordinal) {
            $month = CalendarMonth::containing((new \DateTimeImmutable('2024-01-01'))->modify(sprintf('+%d months', $ordinal)));
            $figures = new MonthFigures($month->key(), 1, 'CURRENT', 0, $month->lastDay()->format('Y-m-d'), [
                'cashIncome' => new MonthlyMetricView($income, 'EUR', null),
                'netSavingsTransfers' => new MonthlyMetricView('100.00', 'EUR', null),
                'axis:ESSENTIAL' => new MonthlyMetricView('100.00', 'EUR', null),
            ], [], [], []);
            $months[] = new AnnualMonth($month, 32 === $ordinal ? MonthState::PROVISIONAL : MonthState::COMPLETE, false, false, $figures);
        }

        return $months;
    }

    private static function calendar(): WorkspaceCalendar
    {
        $context = new class implements CallerWorkspaceContext {
            public function resolveContext(): WorkspaceContext
            {
                return new WorkspaceContext(WorkspaceFixture::own(), WorkspaceFixture::OWNER_ID, true);
            }
        };
        $timezones = new class implements WorkspaceTimezoneReader {
            public function timezone(WorkspaceScope $workspace): string
            {
                return 'Europe/Paris';
            }
        };

        return new WorkspaceCalendar(new MockClock('2026-09-24T12:00:00+02:00'), $context, $timezones);
    }
}
