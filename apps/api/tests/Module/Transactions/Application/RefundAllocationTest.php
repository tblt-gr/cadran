<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Application\RefundAllocation;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class RefundAllocationTest extends TestCase
{
    private const string TRANSACTION_ID = '00000000-0000-7000-8000-0000000000f1';
    private const string GROCERIES = '00000000-0000-7000-8000-0000000000c1';
    private const string HOUSEHOLD = '00000000-0000-7000-8000-0000000000c2';
    private const string EXTRAS = '00000000-0000-7000-8000-0000000000c3';

    /**
     * The original's own amount literal ("-87.400") carries a wider scale than
     * every one of its splits ("-62.10" etc, scale 2): a client may submit
     * either literal since {@see DecimalValue::fromString} accepts trailing
     * zeros and {@see TransactionReferences::splits()} compares the sum by
     * value, not by scale. The allocation must still be exact instead of
     * silently treating the original as ten times larger than its splits.
     */
    public function testAllocationIsExactWhenTheOriginalAmountCarriesMoreFractionDigitsThanItsSplits(): void
    {
        $allocation = new RefundAllocation();
        $original = $this->original('-87.400');

        $proposal = $allocation->propose($original, $this->amount('30.00'), displayPrecision: 2);

        self::assertSame([
            self::GROCERIES => '21.32',
            self::HOUSEHOLD => '6.28',
            self::EXTRAS => '2.40',
        ], $this->byCategory($proposal));
    }

    public function testASmallRefundDoesNotCrashOrMisallocateAgainstAWiderOriginalScale(): void
    {
        $allocation = new RefundAllocation();
        $original = $this->original('-87.400');

        $proposal = $allocation->propose($original, $this->amount('0.02'), displayPrecision: 2);

        self::assertSame([self::GROCERIES => '0.02'], $this->byCategory($proposal));
    }

    /**
     * @param list<array{categoryId: string, amount: AssetAmount}> $proposal
     *
     * @return array<string, string>
     */
    private function byCategory(array $proposal): array
    {
        $byCategory = [];
        foreach ($proposal as $row) {
            $byCategory[$row['categoryId']] = $row['amount']->value->toString();
        }

        return $byCategory;
    }

    private function original(string $amount): Transaction
    {
        $workspace = WorkspaceFixture::own();
        $now = new \DateTimeImmutable('2026-03-14T09:12:04+00:00');

        return new Transaction(
            id: self::TRANSACTION_ID, workspace: $workspace, accountId: '00000000-0000-7000-8000-0000000000d1',
            amount: $this->amount($amount), originalAmount: null, exchangeRate: null, state: TransactionState::BOOKED,
            nature: TransactionNature::EXPENSE, source: TransactionSource::MANUAL, sourceRef: null,
            bookedOn: new \DateTimeImmutable('2026-03-14'), valueOn: null, authorizedOn: null,
            rawLabel: 'CB CARREFOUR 1234', counterparty: null, note: null, paymentMethod: null,
            mcc: null, maskedCard: null, bankReference: null,
            splits: [
                new TransactionSplit(
                    '00000000-0000-7000-8000-0000000000e1', $workspace, self::TRANSACTION_ID,
                    self::GROCERIES, $this->amount('-62.10'), [], null, $now, 0,
                ),
                new TransactionSplit(
                    '00000000-0000-7000-8000-0000000000e2', $workspace, self::TRANSACTION_ID,
                    self::HOUSEHOLD, $this->amount('-18.30'), [], null, $now, 1,
                ),
                new TransactionSplit(
                    '00000000-0000-7000-8000-0000000000e3', $workspace, self::TRANSACTION_ID,
                    self::EXTRAS, $this->amount('-7.00'), [], null, $now, 2,
                ),
            ],
            version: 1, createdAt: $now, updatedAt: $now, voidedAt: null, lastEditorId: WorkspaceFixture::OWNER_ID,
        );
    }

    private function amount(string $value): AssetAmount
    {
        return new AssetAmount(DecimalValue::fromString($value), AssetCode::fromString('EUR'));
    }
}
