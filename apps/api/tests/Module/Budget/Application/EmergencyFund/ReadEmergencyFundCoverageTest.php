<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\EmergencyFund;

use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Application\EmergencyFund\EmergencyFundInputError;
use App\Module\Budget\Application\EmergencyFund\EmergencyFundScopeTooLarge;
use App\Module\Budget\Application\EmergencyFund\LiquidAccountSource;
use App\Module\Budget\Application\EmergencyFund\ReadEmergencyFundCoverage;
use App\Module\Budget\Application\Goals\GoalMonthSource;
use App\Module\Budget\Domain\EmergencyFund\EmergencyFundPolicy;
use App\Module\Budget\Domain\EmergencyFund\EmergencyFundPolicyRepository;
use App\Module\Budget\Domain\EmergencyFund\EssentialMode;
use App\Module\Budget\Domain\EmergencyFund\LiquidAccount;
use App\Module\Budget\Domain\EmergencyFund\ReferenceMode;
use App\Module\Budget\Domain\EmergencyFund\TargetMode;
use App\Module\Categories\Application\ReadBudgetCategoryFacts;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Domain\Aggregation\MonthState;
use App\Tests\Module\Categories\Application\Double\InMemoryCategoryRepository;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ReadEmergencyFundCoverageTest extends TestCase
{
    private const string PARENT = '00000000-0000-7000-8000-000000000201';
    private const string CHILD = '00000000-0000-7000-8000-000000000202';
    private const string OTHER = '00000000-0000-7000-8000-000000000203';

    public function testTheDefaultPolicyAveragesTheEssentialAxisOfThePreviousYear(): void
    {
        $months = self::months(2025, 1, 12, ['axis:ESSENTIAL' => '2100.00', 'axis:DISCRETIONARY' => '800.00']);
        $source = new FixedLiquidAccounts([self::account('3000.00'), self::account('9600.00', 'b')]);

        $view = $this->reader(null, $months, $source)(null);

        self::assertSame('2026-09-29', $view->asOf);
        self::assertSame('2026-09-29', $source->requestedOn?->format('Y-m-d'));
        self::assertSame('2025-01', $view->referenceStart);
        self::assertSame('2025-12', $view->referenceEnd);
        self::assertSame(0, DecimalValue::fromString('2100')->compareTo(DecimalValue::fromString((string) $view->average)));
        self::assertSame('6.0', $view->coveredMonthsDisplay);
        self::assertSame('6', $view->targetMonths);
        self::assertCount(12, $view->months);
        self::assertSame(['month' => '2025-01', 'value' => '2100.00'], $view->months[0]);
    }

    public function testAMonthWithoutEssentialMovementCountsAsAnExactZero(): void
    {
        $months = self::months(2025, 1, 12, ['axis:ESSENTIAL' => '2400.00']);
        $months[0] = self::monthWith(2025, 1, ['axis:ESSENTIAL' => null], []);

        $view = $this->reader(null, $months, new FixedLiquidAccounts([self::account('2200.00')]))(null);

        self::assertSame(12, $view->usedMonths);
        self::assertSame(0, DecimalValue::fromString('2200')->compareTo(DecimalValue::fromString((string) $view->average)));
        self::assertSame(['month' => '2025-01', 'value' => '0'], $view->months[0]);
    }

    public function testReferenceSixAParentCategoryIncludesItsDescendantsWithoutCountingThemTwice(): void
    {
        $policy = EmergencyFundPolicy::default(WorkspaceFixture::own())->revise(EssentialMode::CATEGORIES, [self::PARENT, self::CHILD], ReferenceMode::PREVIOUS_YEAR, null, DecimalValue::fromString('6'), new \DateTimeImmutable('2026-09-01'), WorkspaceFixture::OWNER_ID);
        $cells = ['category:'.self::PARENT => '100.00', 'category:'.self::CHILD => '50.00', 'category:'.self::OTHER => '999.00'];
        $months = self::months(2025, 1, 12, $cells, [self::PARENT, self::CHILD, self::OTHER]);

        $view = $this->reader($policy, $months, new FixedLiquidAccounts([self::account('900.00')]))(null);

        self::assertSame(0, DecimalValue::fromString('150')->compareTo(DecimalValue::fromString((string) $view->average)));
        self::assertSame('6.0', $view->coveredMonthsDisplay);
    }

    public function testAnIncomeRatioTargetsAShareOfTwelveTimesTheAverageMonthlyIncome(): void
    {
        $policy = EmergencyFundPolicy::default(WorkspaceFixture::own())->revise(EssentialMode::AXIS, [], ReferenceMode::PREVIOUS_YEAR, null, DecimalValue::fromString('6'), new \DateTimeImmutable('2026-09-01'), WorkspaceFixture::OWNER_ID, TargetMode::INCOME_RATIO, null, null, DecimalValue::fromString('0.5'));
        // Essentials of 2025 are 2100 a month; the twelve months before September 2026 earn 3000 a month.
        $months = [
            ...self::months(2025, 1, 12, ['axis:ESSENTIAL' => '2100.00', 'cashIncome' => '3000.00']),
            ...self::months(2026, 1, 8, ['cashIncome' => '3000.00']),
        ];

        $view = $this->reader($policy, $months, new FixedLiquidAccounts([self::account('10000.00')]))(null);

        // 0.5 x 12 x 3000 = 18000 required; 10000 - 18000 = -8000; 18000 / 2100 = 8.6 months.
        self::assertSame(0, DecimalValue::fromString('18000')->compareTo(DecimalValue::fromString((string) $view->requiredAmount)));
        self::assertSame(0, DecimalValue::fromString('-8000')->compareTo(DecimalValue::fromString((string) $view->gap)));
        self::assertSame('INCOME_RATIO', $view->targetMode);
        self::assertSame('8.6', $view->impliedTargetMonths);
        self::assertSame(0, DecimalValue::fromString('3000')->compareTo(DecimalValue::fromString((string) $view->incomeAverage)));
    }

    public function testAnIncomeRatioWithoutAnyCompleteIncomeMonthIsNotCalculable(): void
    {
        $policy = EmergencyFundPolicy::default(WorkspaceFixture::own())->revise(EssentialMode::AXIS, [], ReferenceMode::PREVIOUS_YEAR, null, DecimalValue::fromString('6'), new \DateTimeImmutable('2026-09-01'), WorkspaceFixture::OWNER_ID, TargetMode::INCOME_RATIO, null, null, DecimalValue::fromString('0.5'));

        $view = $this->reader($policy, self::months(2025, 1, 12, ['axis:ESSENTIAL' => '2100.00']), new FixedLiquidAccounts([self::account('10000.00')]))(null);

        self::assertNull($view->requiredAmount);
        self::assertNull($view->gap);
        self::assertSame('NO_REFERENCE_INCOME', $view->reason);
        self::assertNull($view->incomeAverage);
    }

    public function testAFixedAmountIsTheRequiredAmountWhateverTheExpenses(): void
    {
        $policy = EmergencyFundPolicy::default(WorkspaceFixture::own())->revise(EssentialMode::AXIS, [], ReferenceMode::PREVIOUS_YEAR, null, DecimalValue::fromString('6'), new \DateTimeImmutable('2026-09-01'), WorkspaceFixture::OWNER_ID, TargetMode::AMOUNT, DecimalValue::fromString('15000'), AssetCode::fromString('EUR'));

        $view = $this->reader($policy, self::months(2025, 1, 12, ['axis:ESSENTIAL' => '2100.00']), new FixedLiquidAccounts([self::account('10000.00')]))(null);

        self::assertSame(0, DecimalValue::fromString('15000')->compareTo(DecimalValue::fromString((string) $view->requiredAmount)));
        self::assertSame(0, DecimalValue::fromString('-5000')->compareTo(DecimalValue::fromString((string) $view->gap)));
        self::assertSame('7.1', $view->impliedTargetMonths);
    }

    public function testATrailingReferenceNeverContainsTheAsOfMonth(): void
    {
        $policy = EmergencyFundPolicy::default(WorkspaceFixture::own())->revise(EssentialMode::AXIS, [], ReferenceMode::TRAILING_MONTHS, 3, DecimalValue::fromString('3'), new \DateTimeImmutable('2026-09-01'), WorkspaceFixture::OWNER_ID);
        $months = [...self::months(2026, 5, 8, ['axis:ESSENTIAL' => '1000.00']), self::monthWith(2026, 9, ['axis:ESSENTIAL' => '50000.00'], [], MonthState::PROVISIONAL)];

        $view = $this->reader($policy, $months, new FixedLiquidAccounts([self::account('3000.00')]))(null);

        self::assertSame(['2026-06', '2026-07', '2026-08'], array_column($view->months, 'month'));
        self::assertSame(3, $view->expectedMonths);
        self::assertSame(0, DecimalValue::fromString('1000')->compareTo(DecimalValue::fromString((string) $view->average)));
    }

    public function testAnExplicitAsOfDateAnchorsTheReferenceAndTheValuations(): void
    {
        $source = new FixedLiquidAccounts([self::account('3000.00')]);

        $view = $this->reader(null, self::months(2024, 1, 12, ['axis:ESSENTIAL' => '500.00']), $source)('2025-03-15');

        self::assertSame('2025-03-15', $source->requestedOn?->format('Y-m-d'));
        self::assertSame('2024-01', $view->referenceStart);
        self::assertSame('6.0', $view->coveredMonthsDisplay);
    }

    public function testAFutureOrMalformedAsOfDateIsRejected(): void
    {
        foreach (['2026-09-30' => 'future_emergency_fund_as_of', '2026-02-30' => 'invalid_emergency_fund_as_of', '26-09-01' => 'invalid_emergency_fund_as_of'] as $asOf => $code) {
            try {
                $this->reader(null, [], new FixedLiquidAccounts([]))($asOf);
                self::fail('The as-of date '.$asOf.' was accepted.');
            } catch (EmergencyFundInputError $error) {
                self::assertSame($code, $error->errorCode);
            }
        }
    }

    public function testAnOversizedWorkspaceIsRefusedAsOneScopeError(): void
    {
        $this->expectException(EmergencyFundScopeTooLarge::class);

        $this->reader(null, self::months(2025, 1, 12, ['axis:ESSENTIAL' => '100.00']), new OversizedLiquidAccounts())(null);
    }

    /** @param list<AnnualMonth> $months */
    private function reader(?EmergencyFundPolicy $policy, array $months, LiquidAccountSource $accounts): ReadEmergencyFundCoverage
    {
        $caller = $this->createStub(CallerWorkspace::class);
        $caller->method('resolve')->willReturn(WorkspaceFixture::own());
        $policies = $this->createStub(EmergencyFundPolicyRepository::class);
        $policies->method('find')->willReturn($policy);
        $categories = new InMemoryCategoryRepository();
        $now = new \DateTimeImmutable('2026-01-01');
        $categories->add(new Category(self::PARENT, WorkspaceFixture::own(), CategoryType::EXPENSE, 'Housing', null, null, null, [], true, 0, 1, 1, $now, $now));
        $categories->add(new Category(self::CHILD, WorkspaceFixture::own(), CategoryType::EXPENSE, 'Rent', self::PARENT, null, null, [], true, 0, 2, 1, $now, $now));
        $categories->add(new Category(self::OTHER, WorkspaceFixture::own(), CategoryType::EXPENSE, 'Leisure', null, null, null, [], true, 0, 1, 1, $now, $now));

        return new ReadEmergencyFundCoverage($caller, $policies, new FixedMonths($months), $accounts, new ReadBudgetCategoryFacts($categories), self::calendar());
    }

    private static function account(string $value, string $id = 'a'): LiquidAccount
    {
        return new LiquidAccount($id, 'Livret '.$id, DecimalValue::fromString($value), AssetCode::fromString('EUR'), new \DateTimeImmutable('2026-09-29'), 0);
    }

    /**
     * @param array<string, ?string> $cells
     * @param list<string>           $budgetCategoryIds
     *
     * @return list<AnnualMonth>
     */
    private static function months(int $year, int $from, int $to, array $cells, array $budgetCategoryIds = []): array
    {
        return array_map(static fn (int $index): AnnualMonth => self::monthWith($year, $index, $cells, $budgetCategoryIds), range($from, $to));
    }

    /**
     * @param array<string, ?string> $cells
     * @param list<string>           $budgetCategoryIds
     */
    private static function monthWith(int $year, int $index, array $cells, array $budgetCategoryIds, MonthState $state = MonthState::COMPLETE): AnnualMonth
    {
        $month = new CalendarMonth($year, $index);
        $views = array_map(static fn (?string $value): MonthlyMetricView => new MonthlyMetricView($value, null === $value ? null : 'EUR', null === $value ? 'NO_MOVEMENTS' : null), $cells);

        return new AnnualMonth($month, $state, false, false, new MonthFigures($month->key(), 1, 'CURRENT', 0, $month->lastDay()->format('Y-m-d'), $views, $budgetCategoryIds, [], []));
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

        // 23:30 UTC on the 28th is already the 29th in Paris.
        return new WorkspaceCalendar(new MockClock('2026-09-28T23:30:00+00:00'), $context, $timezones);
    }
}

final readonly class FixedMonths implements GoalMonthSource
{
    /** @param list<AnnualMonth> $months */
    public function __construct(private array $months)
    {
    }

    public function between(WorkspaceScope $workspace, CalendarMonth $start, CalendarMonth $end): array
    {
        return array_values(array_filter($this->months, static fn (AnnualMonth $month): bool => $month->month->key() >= $start->key() && $month->month->key() <= $end->key()));
    }
}

final class FixedLiquidAccounts implements LiquidAccountSource
{
    public ?\DateTimeImmutable $requestedOn = null;

    /** @param list<LiquidAccount> $accounts */
    public function __construct(private readonly array $accounts)
    {
    }

    public function eligibleOn(WorkspaceScope $workspace, \DateTimeImmutable $asOf): array
    {
        $this->requestedOn = $asOf;

        return $this->accounts;
    }
}

final class OversizedLiquidAccounts implements LiquidAccountSource
{
    public function eligibleOn(WorkspaceScope $workspace, \DateTimeImmutable $asOf): array
    {
        throw new NetWorthScopeTooLarge('Too many accounts.');
    }
}
