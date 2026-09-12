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
    private const string GROCERIES = '00000000-0000-7000-8000-0000000000c1';
    private const string HOUSEHOLD = '00000000-0000-7000-8000-0000000000c2';
    private const string EXTRAS = '00000000-0000-7000-8000-0000000000c3';

    public function testAllocationIsExactWhenTheOriginalAmountCarriesMoreFractionDigitsThanItsSplits(): void
    {
        $proposal = (new RefundAllocation())->propose($this->defaultOriginal('-87.400'), $this->amount('30.00'), 2);

        self::assertSame([
            self::GROCERIES => '21.32',
            self::HOUSEHOLD => '6.28',
            self::EXTRAS => '2.40',
        ], $this->byCategory($proposal));
    }

    public function testASmallRefundDoesNotCrashOrMisallocateAgainstAWiderOriginalScale(): void
    {
        $proposal = (new RefundAllocation())->propose($this->defaultOriginal('-87.400'), $this->amount('0.02'), 2);

        self::assertSame([self::GROCERIES => '0.02'], $this->byCategory($proposal));
    }

    public function testItOmitsZeroRowsWhenARefundIsSmallerThanOneOriginalSplitUnit(): void
    {
        $allocation = (new RefundAllocation())->propose(
            $this->original('-100.00', [
                $this->split('00000000-0000-7000-8000-0000000000c1', '-99.99', 0),
                $this->split('00000000-0000-7000-8000-0000000000c2', '-0.01', 1),
            ]),
            $this->amount('0.01'),
            2,
        );

        self::assertCount(1, $allocation);
        self::assertSame('00000000-0000-7000-8000-0000000000c1', $allocation[0]['categoryId']);
        self::assertSame('0.01', $allocation[0]['amount']->value->toString());
    }

    public function testItBreaksEqualRemaindersByThePersistedSplitPosition(): void
    {
        $allocation = (new RefundAllocation())->propose(
            $this->original('-100.00', [
                $this->split('00000000-0000-7000-8000-0000000000c2', '-50.00', 0),
                $this->split('00000000-0000-7000-8000-0000000000c1', '-50.00', 1),
            ]),
            $this->amount('0.01'),
            2,
        );

        self::assertSame('00000000-0000-7000-8000-0000000000c2', $allocation[0]['categoryId']);
        self::assertSame('0.01', $allocation[0]['amount']->value->toString());
    }

    /** @param list<TransactionSplit> $splits */
    private function original(string $amount, array $splits): Transaction
    {
        $now = new \DateTimeImmutable('2026-03-14T09:12:04+00:00');

        return new Transaction(
            '00000000-0000-7000-8000-0000000000f1', WorkspaceFixture::own(), '00000000-0000-7000-8000-0000000000d1',
            $this->amount($amount), null, null, TransactionState::BOOKED, TransactionNature::EXPENSE, TransactionSource::MANUAL,
            null, new \DateTimeImmutable('2026-03-14'), null, null, 'Original expense', null, null, null, null, null,
            null, $splits, 1, $now, $now, null, WorkspaceFixture::OWNER_ID,
        );
    }

    private function split(string $categoryId, string $amount, int $position): TransactionSplit
    {
        return new TransactionSplit(
            '00000000-0000-7000-8000-0000000000a'.($position + 1), WorkspaceFixture::own(), '00000000-0000-7000-8000-0000000000f1',
            $categoryId, $this->amount($amount), [], null, new \DateTimeImmutable('2026-03-14T09:12:04+00:00'), $position,
        );
    }

    private function defaultOriginal(string $amount): Transaction
    {
        return $this->original($amount, [
            $this->split(self::GROCERIES, '-62.10', 0),
            $this->split(self::HOUSEHOLD, '-18.30', 1),
            $this->split(self::EXTRAS, '-7.00', 2),
        ]);
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

    private function amount(string $value): AssetAmount
    {
        return new AssetAmount(DecimalValue::fromString($value), AssetCode::fromString('EUR'));
    }
}
