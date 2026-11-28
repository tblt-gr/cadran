<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Accounts\Application\AccountBalanceDelta;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Application\BalanceFloorDeltas;
use App\Module\Transactions\Domain\Reconciliation\ReviewReason;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class BalanceFloorDeltasTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';

    public function testARemovalNegatesTheRowAsItStood(): void
    {
        self::assertSame([[self::ACCOUNT, '2026-03-14', '42.90', 'amount']], self::describe(BalanceFloorDeltas::removal($this->row('-42.90'))));
    }

    public function testAnAdditionKeepsTheSignOfTheNewRow(): void
    {
        self::assertSame(
            [[self::ACCOUNT, '2026-03-14', '-42.90', 'sourceAmount']],
            self::describe(BalanceFloorDeltas::addition($this->row('-42.90', TransactionState::PENDING), AccountBalanceDelta::FIELD_SOURCE_AMOUNT)),
        );
    }

    public function testAReplacementRemovesTheOldRowAndAddsTheNewOne(): void
    {
        self::assertSame(
            [[self::ACCOUNT, '2026-03-14', '42.90', 'amount'], [self::ACCOUNT, '2026-03-14', '-40.00', 'amount']],
            self::describe(BalanceFloorDeltas::replacement($this->row('-42.90'), $this->row('-40.00'))),
        );
    }

    public function testAMissingVoidedRejectedOrReviewedRowCountsNothing(): void
    {
        $voided = $this->row('-42.90')->void(new \DateTimeImmutable('2026-03-15T09:00:00+00:00'), null);

        self::assertSame([], BalanceFloorDeltas::replacement(null, null));
        self::assertSame([], BalanceFloorDeltas::replacement($voided, $this->row('-42.90', TransactionState::REJECTED)));
        // A row held for review is still pending: it counts like any pending row until it is resolved.
        self::assertCount(1, BalanceFloorDeltas::addition($this->row('-42.90', TransactionState::PENDING, ReviewReason::cases()[0])));
    }

    private function row(string $amount, TransactionState $state = TransactionState::BOOKED, ?ReviewReason $reviewReason = null): Transaction
    {
        $now = new \DateTimeImmutable('2026-03-14T09:12:04+00:00');

        return new Transaction(
            '00000000-0000-7000-8000-0000000000f1', WorkspaceFixture::own(), self::ACCOUNT,
            new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')), null, null, $state,
            TransactionNature::EXPENSE, TransactionSource::MANUAL, null, new \DateTimeImmutable('2026-03-14'), null, null,
            'Courses', null, null, null, null, null, null, [], 1, $now, $now, null, null, $reviewReason,
        );
    }

    /**
     * @param list<AccountBalanceDelta> $deltas
     *
     * @return list<array{string, string, string, string}>
     */
    private static function describe(array $deltas): array
    {
        return array_map(static fn (AccountBalanceDelta $delta): array => [
            $delta->accountId, $delta->day->format('Y-m-d'), $delta->amount->toString(), $delta->field,
        ], $deltas);
    }
}
