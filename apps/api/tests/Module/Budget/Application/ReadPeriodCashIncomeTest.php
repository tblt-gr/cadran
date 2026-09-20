<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application;

use App\Module\Budget\Application\ReadPeriodCashIncome;
use App\Module\Budget\Domain\BudgetPeriod;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\MonthlyProjectionReason;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Module\Accounts\Application\Double\FixedWorkspaceTimezoneReader;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Module\Budget\Application\Double\InMemoryTransactionRepository;
use PHPUnit\Framework\TestCase;

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
        $read = new ReadPeriodCashIncome($accounts, $transactions, new FixedWorkspaceTimezoneReader());

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
        $read = new ReadPeriodCashIncome($accounts, $transactions, new FixedWorkspaceTimezoneReader());

        $income = ($read)(WorkspaceScope::fromString(self::WORKSPACE), BudgetPeriod::year(2026));

        self::assertSame('5000.00', $income->value?->toString());
    }

    public function testItIsNonCalculableWithNoOpenAccount(): void
    {
        $read = new ReadPeriodCashIncome(new InMemoryAccountRepository(), new InMemoryTransactionRepository(), new FixedWorkspaceTimezoneReader());

        $income = ($read)(WorkspaceScope::fromString(self::WORKSPACE), BudgetPeriod::month(2026, 9));

        self::assertNull($income->value);
        self::assertSame(MonthlyProjectionReason::NO_ACCOUNT, $income->reason);
    }

    private function transaction(string $bookedOn, string $amount, TransactionNature $nature): Transaction
    {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');

        return new Transaction(
            id: '00000000-0000-7000-8000-'.substr(md5($bookedOn.$amount), 0, 12),
            workspace: WorkspaceScope::fromString(self::WORKSPACE),
            accountId: AccountFixture::ID,
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
