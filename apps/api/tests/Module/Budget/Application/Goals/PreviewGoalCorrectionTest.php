<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Application\Goals\BuildGoalRevision;
use App\Module\Budget\Application\Goals\BuildGoalSchedule;
use App\Module\Budget\Application\Goals\GoalCorrectionPreviewMonthView;
use App\Module\Budget\Application\Goals\GoalMetricReader;
use App\Module\Budget\Application\Goals\GoalMonthSource;
use App\Module\Budget\Application\Goals\GoalPeriodEvaluator;
use App\Module\Budget\Application\Goals\GoalStatusView;
use App\Module\Budget\Application\Goals\PreviewGoalCorrection;
use App\Module\Budget\Application\Goals\ReadGoalStatus;
use App\Module\Budget\Application\Goals\UpdateGoalInput;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalRepository;
use App\Module\Budget\Domain\Goals\GoalScopeType;
use App\Module\Budget\Domain\Goals\GoalValueType;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Budget\Domain\Goals\GoalVersionRepository;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Module\Transactions\Application\MonthlyTransferPairReader;
use App\Module\Transactions\Application\ReadMonthlyAccountTransferNet;
use App\Module\Transactions\Application\ReadMonthlyTransferPairs;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** A correction preview loads its months once and answers what a load per month answered. */
final class PreviewGoalCorrectionTest extends TestCase
{
    private const string GOAL_ID = '00000000-0000-7000-8000-000000000101';

    /** @return iterable<string, array{GoalValueType, string, string}> */
    public static function definitions(): iterable
    {
        yield 'monthly amount' => [GoalValueType::AMOUNT, '150.00', '250.00'];
        yield 'share of the month income' => [GoalValueType::INCOME_SHARE, '0.05', '0.09'];
        yield 'share of the rolling reference income' => [GoalValueType::ROLLING_INCOME_SHARE, '0.05', '0.09'];
    }

    #[DataProvider('definitions')]
    public function testOneLoadOverOneHundredAndTwentyMonthsMatchesTheLoadPerMonth(GoalValueType $type, string $before, string $after): void
    {
        $source = new class(self::months()) implements GoalMonthSource {
            public int $calls = 0;

            /** @param list<AnnualMonth> $months */
            public function __construct(private readonly array $months)
            {
            }

            public function between(WorkspaceScope $workspace, CalendarMonth $start, CalendarMonth $end, bool $includeUpcoming = false): array
            {
                ++$this->calls;

                return array_values(array_filter($this->months, static fn (AnnualMonth $month): bool => $month->month->key() >= $start->key() && $month->month->key() <= $end->key()));
            }
        };
        $pairs = $this->createStub(MonthlyTransferPairReader::class);
        $metrics = new GoalMetricReader($source, new ReadMonthlyAccountTransferNet(new ReadMonthlyTransferPairs($pairs)));
        $calendar = self::calendar();
        $goal = new Goal(self::GOAL_ID, WorkspaceFixture::own(), 'Goal', GoalPeriodicity::MONTHLY, GoalMetric::NET_SAVINGS_TRANSFERS, GoalScopeType::WORKSPACE, null, AssetCode::fromString('EUR'), '2017-01', null, 1, new \DateTimeImmutable('2017-01-01'), WorkspaceFixture::OWNER_ID);
        $created = new \DateTimeImmutable('2017-01-01');
        $version = DecimalValue::fromString($before);
        $versions = [GoalValueType::AMOUNT === $type
            ? GoalVersion::amount('00000000-0000-7000-8000-000000000102', WorkspaceFixture::own(), self::GOAL_ID, 1, '2017-01', $version, null, $created, WorkspaceFixture::OWNER_ID)
            : GoalVersion::share('00000000-0000-7000-8000-000000000102', WorkspaceFixture::own(), self::GOAL_ID, 1, '2017-01', $type, $version, $created, WorkspaceFixture::OWNER_ID)];

        $caller = $this->createStub(CallerWorkspace::class);
        $caller->method('resolve')->willReturn(WorkspaceFixture::own());
        $context = $this->createStub(CallerWorkspaceContext::class);
        $context->method('resolveContext')->willReturn(new WorkspaceContext(WorkspaceFixture::own(), WorkspaceFixture::OWNER_ID, true));
        $goals = $this->createStub(GoalRepository::class);
        $goals->method('find')->willReturn($goal);
        $repository = $this->createStub(GoalVersionRepository::class);
        $repository->method('listByGoal')->willReturn($versions);
        $uuid = $this->createStub(UuidGenerator::class);
        $uuid->method('generate')->willReturn('00000000-0000-7000-8000-000000000103');
        $revisions = new BuildGoalRevision($calendar, $uuid, new BuildGoalSchedule($this->createStub(AssetCatalog::class)));
        $statuses = new ReadGoalStatus($caller, $goals, $repository, $metrics, new GoalPeriodEvaluator($metrics), $calendar);
        $input = new UpdateGoalInput(
            self::GOAL_ID, 1, 'Goal',
            GoalValueType::AMOUNT === $type ? $after : null,
            GoalValueType::AMOUNT === $type ? 'EUR' : null,
            GoalValueType::AMOUNT === $type ? null : $after,
            false, null, '2017-01', 'Correct the recorded target.',
        );

        $preview = (new PreviewGoalCorrection($context, $goals, $repository, $revisions, $statuses, $metrics, $calendar))($input);

        self::assertSame(1, $source->calls);
        self::assertCount(120, $preview->months);
        $revision = $revisions($goal, $versions, $input, WorkspaceFixture::OWNER_ID);
        self::assertNotNull($revision->definition);
        $expected = [];
        for ($ordinal = 0; $ordinal < 120; ++$ordinal) {
            $month = CalendarMonth::containing((new \DateTimeImmutable('2017-01-01'))->modify(sprintf('+%d months', $ordinal)));
            $inputs = $metrics->inputs(WorkspaceFixture::own(), $month, [$goal]);
            $expected[] = [
                $month->key(),
                self::state($statuses->forGoal($goal, $versions, $month, $inputs)),
                self::state($statuses->forGoal($goal, [$revision->definition, ...$versions], $month, $inputs)),
            ];
        }
        self::assertSame($expected, array_map(static fn (GoalCorrectionPreviewMonthView $view): array => [
            $view->month,
            [$view->before->goalVersion, $view->before->expected, $view->before->status],
            [$view->after->goalVersion, $view->after->expected, $view->after->status],
        ], $preview->months));
        // The scenario discriminates: the corrected target changes at least one month.
        self::assertNotSame(array_column($expected, 1), array_column($expected, 2));
    }

    /** @return array{?int, ?string, string} */
    private static function state(GoalStatusView $status): array
    {
        return [$status->goalVersion, $status->expected ?? $status->target, $status->status];
    }

    /** @return list<AnnualMonth> January 2016 to December 2026, income and savings varying month by month */
    private static function months(): array
    {
        $months = [];
        for ($ordinal = 0; $ordinal < 132; ++$ordinal) {
            $month = CalendarMonth::containing((new \DateTimeImmutable('2016-01-01'))->modify(sprintf('+%d months', $ordinal)));
            $figures = new MonthFigures($month->key(), 1, 'CURRENT', 0, $month->lastDay()->format('Y-m-d'), [
                'cashIncome' => new MonthlyMetricView((string) (2000 + 10 * $ordinal), 'EUR', null),
                'netSavingsTransfers' => new MonthlyMetricView((string) (100 + 7 * ($ordinal % 40)), 'EUR', null),
            ], [], [], []);
            $months[] = new AnnualMonth($month, MonthState::COMPLETE, false, false, $figures);
        }

        return $months;
    }

    private static function calendar(): WorkspaceCalendar
    {
        $caller = new readonly class implements CallerWorkspaceContext {
            public function resolveContext(): WorkspaceContext
            {
                return new WorkspaceContext(WorkspaceFixture::own(), WorkspaceFixture::OWNER_ID, true);
            }
        };
        $timezones = new readonly class implements WorkspaceTimezoneReader {
            public function timezone(WorkspaceScope $workspace): string
            {
                return 'Europe/Paris';
            }
        };

        return new WorkspaceCalendar(new MockClock('2026-12-24T12:00:00+01:00'), $caller, $timezones);
    }
}
