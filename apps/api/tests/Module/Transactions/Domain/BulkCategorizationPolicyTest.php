<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain;

use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\BulkCategorizationFacts;
use App\Module\Transactions\Domain\BulkCategorizationPolicy;
use App\Module\Transactions\Domain\BulkCategorizationSkipReason;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class BulkCategorizationPolicyTest extends TestCase
{
    private const string TRANSACTION_ID = '00000000-0000-7000-8000-0000000000f1';
    private const string TARGET = '00000000-0000-7000-8000-0000000000c1';
    private const string OTHER = '00000000-0000-7000-8000-0000000000c2';

    public function testAnOutflowAcceptsAnExpenseCategoryAndRefusesAnIncomeOne(): void
    {
        $outflow = $this->transaction('-42.90');

        self::assertNull(BulkCategorizationPolicy::skipReason($outflow, self::TARGET, CategoryType::EXPENSE, new BulkCategorizationFacts()));
        self::assertSame(
            BulkCategorizationSkipReason::SIGN_MISMATCH,
            BulkCategorizationPolicy::skipReason($outflow, self::TARGET, CategoryType::INCOME, new BulkCategorizationFacts()),
        );
    }

    public function testAnInflowAcceptsAnIncomeCategoryAndRefusesAnExpenseOne(): void
    {
        $inflow = $this->transaction('1200.00', TransactionNature::INCOME);

        self::assertNull(BulkCategorizationPolicy::skipReason($inflow, self::TARGET, CategoryType::INCOME, new BulkCategorizationFacts()));
        self::assertSame(
            BulkCategorizationSkipReason::SIGN_MISMATCH,
            BulkCategorizationPolicy::skipReason($inflow, self::TARGET, CategoryType::EXPENSE, new BulkCategorizationFacts()),
        );
    }

    public function testTheSignRuleHoldsAtTwentyFourDecimalsAndForHugeFigures(): void
    {
        $tiny = $this->transaction('-0.000000000000000000000001');
        $huge = $this->transaction('-9999999999999999999999.999999999999999999999999');

        self::assertNull(BulkCategorizationPolicy::skipReason($tiny, self::TARGET, CategoryType::EXPENSE, new BulkCategorizationFacts()));
        self::assertNull(BulkCategorizationPolicy::skipReason($huge, self::TARGET, CategoryType::EXPENSE, new BulkCategorizationFacts()));
        self::assertSame(
            BulkCategorizationSkipReason::SIGN_MISMATCH,
            BulkCategorizationPolicy::skipReason($tiny, self::TARGET, CategoryType::INCOME, new BulkCategorizationFacts()),
        );
    }

    public function testATransferLegIsSkippedBeforeAnyOtherReason(): void
    {
        $leg = $this->transaction('-10.00', TransactionNature::TRANSFER);

        self::assertSame(
            BulkCategorizationSkipReason::TRANSFER_LEG,
            BulkCategorizationPolicy::skipReason($leg, self::TARGET, CategoryType::INCOME, new BulkCategorizationFacts(transferLeg: true, periodClosed: true)),
        );
    }

    public function testARefundAndAnOriginalWithLiveRefundsAreSkipped(): void
    {
        $transaction = $this->transaction('-10.00');

        self::assertSame(
            BulkCategorizationSkipReason::REFUND,
            BulkCategorizationPolicy::skipReason($transaction, self::TARGET, CategoryType::EXPENSE, new BulkCategorizationFacts(refund: true)),
        );
        self::assertSame(
            BulkCategorizationSkipReason::HAS_REFUNDS,
            BulkCategorizationPolicy::skipReason($transaction, self::TARGET, CategoryType::EXPENSE, new BulkCategorizationFacts(liveRefund: true)),
        );
    }

    public function testAVoidedOrRejectedTransactionIsSkippedAsTerminal(): void
    {
        $rejected = $this->transaction('-10.00', state: TransactionState::REJECTED);

        self::assertSame(
            BulkCategorizationSkipReason::TERMINAL,
            BulkCategorizationPolicy::skipReason($rejected, self::TARGET, CategoryType::EXPENSE, new BulkCategorizationFacts()),
        );
    }

    public function testAMultiCategoryTransactionIsSkippedButASingleSplitOneIsNot(): void
    {
        $single = $this->transaction('-10.00', splits: [$this->split('-10.00', self::OTHER)]);
        $multi = $this->transaction('-10.00', splits: [
            $this->split('-4.00', self::OTHER, '00000000-0000-7000-8000-0000000000e1'),
            $this->split('-6.00', '00000000-0000-7000-8000-0000000000c3', '00000000-0000-7000-8000-0000000000e2'),
        ]);

        self::assertNull(BulkCategorizationPolicy::skipReason($single, self::TARGET, CategoryType::EXPENSE, new BulkCategorizationFacts()));
        self::assertSame(
            BulkCategorizationSkipReason::SPLIT,
            BulkCategorizationPolicy::skipReason($multi, self::TARGET, CategoryType::EXPENSE, new BulkCategorizationFacts()),
        );
    }

    public function testAClosedPeriodIsSkipped(): void
    {
        self::assertSame(
            BulkCategorizationSkipReason::CLOSED_PERIOD,
            BulkCategorizationPolicy::skipReason($this->transaction('-10.00'), self::TARGET, CategoryType::EXPENSE, new BulkCategorizationFacts(periodClosed: true)),
        );
    }

    public function testATransactionAlreadyInTheTargetCategoryIsReportedAsUnchanged(): void
    {
        $already = $this->transaction('-10.00', splits: [$this->split('-10.00', self::TARGET)]);

        self::assertSame(
            BulkCategorizationSkipReason::ALREADY_IN_CATEGORY,
            BulkCategorizationPolicy::skipReason($already, self::TARGET, CategoryType::EXPENSE, new BulkCategorizationFacts()),
        );
    }

    /** @param list<TransactionSplit> $splits */
    private function transaction(
        string $amount,
        TransactionNature $nature = TransactionNature::EXPENSE,
        TransactionState $state = TransactionState::BOOKED,
        array $splits = [],
    ): Transaction {
        $now = new \DateTimeImmutable('2026-03-14T09:12:04+00:00');

        return new Transaction(
            id: self::TRANSACTION_ID, workspace: WorkspaceFixture::own(),
            accountId: '00000000-0000-7000-8000-0000000000d1',
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            originalAmount: null, exchangeRate: null, state: $state, nature: $nature,
            source: TransactionSource::MANUAL, sourceRef: null, bookedOn: new \DateTimeImmutable('2026-03-14'),
            valueOn: null, authorizedOn: null, rawLabel: 'CB CARREFOUR 1234', counterparty: null,
            note: null, paymentMethod: null, mcc: null, maskedCard: null, bankReference: null,
            splits: $splits, version: 1, createdAt: $now, updatedAt: $now,
            voidedAt: TransactionState::VOIDED === $state ? $now : null,
            lastEditorId: WorkspaceFixture::OWNER_ID,
        );
    }

    private function split(string $amount, string $categoryId, string $id = '00000000-0000-7000-8000-0000000000e0'): TransactionSplit
    {
        return new TransactionSplit(
            id: $id, workspace: WorkspaceFixture::own(), transactionId: self::TRANSACTION_ID,
            categoryId: $categoryId,
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            analyticAxes: [], note: null, createdAt: new \DateTimeImmutable('2026-03-14T09:12:04+00:00'),
        );
    }
}
