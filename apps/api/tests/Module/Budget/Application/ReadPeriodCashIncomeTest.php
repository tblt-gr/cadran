<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Budget\Application\ReadPeriodCashIncome;
use App\Module\Budget\Domain\BudgetPeriod;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\ResolveMetricPolicy;
use App\Module\Reporting\Domain\MetricPolicy;
use App\Module\Reporting\Domain\MetricPolicyActivation;
use App\Module\Reporting\Domain\MonthlyProjectionReason;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Module\Accounts\Application\Double\FixedWorkspaceTimezoneReader;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Module\Budget\Application\Double\InMemoryTransactionRepository;
use App\Tests\Module\Reporting\Application\Double\InMemoryMetricPolicyRepository;
use App\Tests\Module\Reporting\Application\Double\InMemoryPeriodClosureRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ReadPeriodCashIncomeTest extends TestCase
{
    private const string WORKSPACE = AccountFixture::WORKSPACE;

    public function testItSumsIncomeAcrossAWholeMonth(): void
    {
        // Hand-computed: a 2500.00 salary booked on 2026-09-03 is the only
        // income movement of September, so cash income is exactly 2500.00.
        $accounts = new InMemoryAccountRepository(AccountFixture::account());
        $transactions = new InMemoryTransactionRepository(
            $this->transaction('2026-09-03', '2500.00', TransactionNature::INCOME),
            $this->transaction('2026-08-31', '999.00', TransactionNature::INCOME), // outside the month
        );
        $read = new ReadPeriodCashIncome($accounts, $transactions, new FixedWorkspaceTimezoneReader(), new ResolveMetricPolicy(new InMemoryMetricPolicyRepository(), new InMemoryPeriodClosureRepository(), new MockClock('2026-09-19T10:00:00+00:00')));

        $income = ($read)(WorkspaceScope::fromString(self::WORKSPACE), BudgetPeriod::month(2026, 9));

        self::assertSame('2500.00', $income->value?->toString());
    }

    public function testItSumsIncomeAcrossAWholeYear(): void
    {
        // Hand-computed: two monthly salaries of 2500.00 across the year sum
        // to 5000.00 — a YEAR period is not scoped like a single CalendarMonth.
        $accounts = new InMemoryAccountRepository(AccountFixture::account());
        $transactions = new InMemoryTransactionRepository(
            $this->transaction('2026-01-05', '2500.00', TransactionNature::INCOME),
            $this->transaction('2026-11-05', '2500.00', TransactionNature::INCOME),
            $this->transaction('2025-12-31', '999.00', TransactionNature::INCOME), // outside the year
        );
        $read = new ReadPeriodCashIncome($accounts, $transactions, new FixedWorkspaceTimezoneReader(), new ResolveMetricPolicy(new InMemoryMetricPolicyRepository(), new InMemoryPeriodClosureRepository(), new MockClock('2026-09-19T10:00:00+00:00')));

        $income = ($read)(WorkspaceScope::fromString(self::WORKSPACE), BudgetPeriod::year(2026));

        self::assertSame('5000.00', $income->value?->toString());
    }

    public function testItIsNonCalculableWithNoOpenAccount(): void
    {
        $read = new ReadPeriodCashIncome(new InMemoryAccountRepository(), new InMemoryTransactionRepository(), new FixedWorkspaceTimezoneReader(), new ResolveMetricPolicy(new InMemoryMetricPolicyRepository(), new InMemoryPeriodClosureRepository(), new MockClock('2026-09-19T10:00:00+00:00')));

        $income = ($read)(WorkspaceScope::fromString(self::WORKSPACE), BudgetPeriod::month(2026, 9));

        self::assertNull($income->value);
        self::assertSame(MonthlyProjectionReason::NO_ACCOUNT, $income->reason);
    }

    public function testIncomeOnAnExcludedAccountKindLeavesTheCashPerimeter(): void
    {
        // Hand-computed under version 1: 2000.00 on CURRENT counts, the 200.00 voucher credit does not.
        $voucherId = '00000000-0000-7000-8000-0000000000b2';
        $accounts = new InMemoryAccountRepository(
            AccountFixture::account(kind: AccountKind::CURRENT),
            AccountFixture::account(kind: AccountKind::EMPLOYEE_BENEFIT, id: $voucherId),
        );
        $transactions = new InMemoryTransactionRepository(
            $this->transaction('2026-09-03', '2000.00', TransactionNature::INCOME),
            $this->transaction('2026-09-04', '200.00', TransactionNature::INCOME, $voucherId),
        );

        $income = ($this->reader($accounts, $transactions))(WorkspaceScope::fromString(self::WORKSPACE), BudgetPeriod::month(2026, 9));

        self::assertSame('2000.00', $income->value?->toString());
    }

    public function testAYearWhoseMonthsFollowDifferentPoliciesHasNoCashIncome(): void
    {
        $workspace = WorkspaceScope::fromString(self::WORKSPACE);
        $policies = new InMemoryMetricPolicyRepository();
        $policies->add($workspace, 'id', MetricPolicy::create(2, 'Tout', [], new \DateTimeImmutable('2026-05-01T00:00:00Z'), 'user'));
        $policies->addActivation($workspace, 'a', new MetricPolicyActivation(2, new \DateTimeImmutable('2026-05-10T08:00:00Z')));
        // January was closed before the activation, so it keeps version 1 while the open months follow version 2.
        $closures = new InMemoryPeriodClosureRepository();
        $closures->add(new PeriodClosure('closure', $workspace, new CalendarMonth(2026, 1), new \DateTimeImmutable('2026-02-01T00:00:00Z'), 'user', 1));
        $reader = $this->reader(
            new InMemoryAccountRepository(AccountFixture::account(kind: AccountKind::CURRENT)),
            new InMemoryTransactionRepository($this->transaction('2026-01-05', '2500.00', TransactionNature::INCOME)),
            $policies,
            $closures,
        );

        $year = ($reader)($workspace, BudgetPeriod::year(2026));
        $policy = $reader->policy($workspace, BudgetPeriod::year(2026));

        self::assertNull($year->value);
        self::assertSame(MonthlyProjectionReason::MIXED_METRIC_POLICIES, $year->reason);
        self::assertTrue($policy->mixed);
        self::assertNull($policy->reference->version);
        self::assertSame(2, $reader->policy($workspace, BudgetPeriod::month(2026, 9))->reference->version);
    }

    public function testAnUnloadableActiveVersionMakesTheIncomeNonCalculable(): void
    {
        $workspace = WorkspaceScope::fromString(self::WORKSPACE);
        $policies = new InMemoryMetricPolicyRepository();
        $policies->addActivation($workspace, 'a', new MetricPolicyActivation(7, new \DateTimeImmutable('2026-01-01T00:00:00Z')));
        $reader = $this->reader(
            new InMemoryAccountRepository(AccountFixture::account(kind: AccountKind::CURRENT)),
            new InMemoryTransactionRepository($this->transaction('2026-09-03', '2500.00', TransactionNature::INCOME)),
            $policies,
        );

        $income = ($reader)($workspace, BudgetPeriod::month(2026, 9));

        self::assertNull($income->value);
        self::assertSame(MonthlyProjectionReason::UNKNOWN_METRIC_POLICY, $income->reason);
    }

    private function reader(InMemoryAccountRepository $accounts, InMemoryTransactionRepository $transactions, ?InMemoryMetricPolicyRepository $policies = null, ?InMemoryPeriodClosureRepository $closures = null): ReadPeriodCashIncome
    {
        return new ReadPeriodCashIncome(
            $accounts,
            $transactions,
            new FixedWorkspaceTimezoneReader(),
            new ResolveMetricPolicy($policies ?? new InMemoryMetricPolicyRepository(), $closures ?? new InMemoryPeriodClosureRepository(), new MockClock('2026-09-19T10:00:00+00:00')),
        );
    }

    private function transaction(string $bookedOn, string $amount, TransactionNature $nature, string $accountId = AccountFixture::ID): Transaction
    {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');

        return new Transaction(
            id: '00000000-0000-7000-8000-'.substr(md5($bookedOn.$amount.$accountId), 0, 12),
            workspace: WorkspaceScope::fromString(self::WORKSPACE),
            accountId: $accountId,
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            originalAmount: null,
            exchangeRate: null,
            state: TransactionState::BOOKED,
            nature: $nature,
            source: TransactionSource::MANUAL,
            sourceRef: null,
            bookedOn: new \DateTimeImmutable($bookedOn, new \DateTimeZone('UTC')),
            valueOn: null,
            authorizedOn: null,
            rawLabel: 'Salary',
            counterparty: null,
            note: null,
            paymentMethod: null,
            mcc: null,
            maskedCard: null,
            bankReference: null,
            splits: [],
            version: 1,
            createdAt: $now,
            updatedAt: $now,
            voidedAt: null,
            lastEditorId: null,
        );
    }
}
