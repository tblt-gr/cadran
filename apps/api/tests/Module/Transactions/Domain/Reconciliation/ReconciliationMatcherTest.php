<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain\Reconciliation;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Reconciliation\ReconciliationMatcher;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use PHPUnit\Framework\TestCase;

/**
 * The worked example every case below varies: an incoming booked movement of
 * -128.45 EUR dated 2026-04-14, against pending rows of the same account. The
 * window is five days, so 2026-04-09 and 2026-04-19 are the last eligible
 * days on either side.
 */
final class ReconciliationMatcherTest extends TestCase
{
    private const string WORKSPACE = '00000000-0000-7000-8000-0000000000a1';
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';

    public function testTheNearestPendingCandidateInTheWindowSettlesTheMovement(): void
    {
        $far = self::pending('01', '-128.45', '2026-04-11');
        $near = self::pending('02', '-128.45', '2026-04-12');

        $selected = ReconciliationMatcher::select([$far, $near], self::amount('-128.45'), self::day('2026-04-14'));

        self::assertNotNull($selected);
        self::assertSame($near->id, $selected->id);
    }

    public function testTwoEquallyCloseCandidatesAreNotAMatch(): void
    {
        $before = self::pending('01', '-128.45', '2026-04-11');
        $after = self::pending('02', '-128.45', '2026-04-17');

        self::assertNull(ReconciliationMatcher::select([$before, $after], self::amount('-128.45'), self::day('2026-04-14')));
        self::assertCount(2, ReconciliationMatcher::candidates([$before, $after], self::amount('-128.45'), self::day('2026-04-14')));
    }

    public function testNoCandidateAtAllIsNotAMatch(): void
    {
        self::assertNull(ReconciliationMatcher::select([], self::amount('-128.45'), self::day('2026-04-14')));
        self::assertSame([], ReconciliationMatcher::candidates([], self::amount('-128.45'), self::day('2026-04-14')));
    }

    public function testTheWindowEndsAfterFiveDays(): void
    {
        self::assertNotNull(ReconciliationMatcher::select([self::pending('01', '-128.45', '2026-04-19')], self::amount('-128.45'), self::day('2026-04-14')));
        self::assertNull(ReconciliationMatcher::select([self::pending('01', '-128.45', '2026-04-20')], self::amount('-128.45'), self::day('2026-04-14')));
        self::assertNotNull(ReconciliationMatcher::select([self::pending('01', '-128.45', '2026-04-09')], self::amount('-128.45'), self::day('2026-04-14')));
        self::assertNull(ReconciliationMatcher::select([self::pending('01', '-128.45', '2026-04-08')], self::amount('-128.45'), self::day('2026-04-14')));
    }

    public function testTheWindowIsThisDomainsOwnConstant(): void
    {
        // The two windows are allowed to diverge, so reconciliation never reads
        // the recurrence window even while both happen to be a few days wide.
        self::assertSame(5, ReconciliationMatcher::MATCH_WINDOW_DAYS);
    }

    public function testOnlyTheIdenticalExactSignedAmountIsACandidate(): void
    {
        $candidate = self::pending('01', '-128.45', '2026-04-14');

        self::assertNull(ReconciliationMatcher::select([$candidate], self::amount('-128.46'), self::day('2026-04-14')));
        self::assertNull(ReconciliationMatcher::select([$candidate], self::amount('-128.44'), self::day('2026-04-14')));
    }

    public function testTheSameAmountWrittenWithAnotherScaleStillMatches(): void
    {
        $candidate = self::pending('01', '-128.450000000000000000000000', '2026-04-14');

        $selected = ReconciliationMatcher::select([$candidate], self::amount('-128.45'), self::day('2026-04-14'));

        self::assertNotNull($selected);
        self::assertSame($candidate->id, $selected->id);
    }

    public function testAnOppositeSignIsNeverTheSameMovement(): void
    {
        $income = self::pending('01', '128.45', '2026-04-14', TransactionNature::INCOME);

        self::assertNull(ReconciliationMatcher::select([$income], self::amount('-128.45'), self::day('2026-04-14')));
    }

    public function testAVeryLargeDecimalIsComparedToItsLastStoredDigit(): void
    {
        $exact = '-12345678901234567890.123456789012345678901234';
        $off = '-12345678901234567890.123456789012345678901235';
        $candidate = self::pending('01', $exact, '2026-04-14');

        self::assertNotNull(ReconciliationMatcher::select([$candidate], self::amount($exact), self::day('2026-04-14')));
        self::assertNull(ReconciliationMatcher::select([$candidate], self::amount($off), self::day('2026-04-14')));
    }

    public function testAnotherDenominationIsNeverTheSameMovement(): void
    {
        $candidate = self::pending('01', '-128.45', '2026-04-14');
        $dollars = new AssetAmount(DecimalValue::fromString('-128.45'), AssetCode::fromString('USD'));

        self::assertNull(ReconciliationMatcher::select([$candidate], $dollars, self::day('2026-04-14')));
    }

    public function testOnlyAPendingRowIsACandidate(): void
    {
        $booked = self::pending('01', '-128.45', '2026-04-14', TransactionNature::EXPENSE, TransactionState::BOOKED);

        self::assertNull(ReconciliationMatcher::select([$booked], self::amount('-128.45'), self::day('2026-04-14')));
        self::assertSame([], ReconciliationMatcher::candidates([$booked], self::amount('-128.45'), self::day('2026-04-14')));
    }

    private static function pending(
        string $suffix,
        string $amount,
        string $bookedOn,
        TransactionNature $nature = TransactionNature::EXPENSE,
        TransactionState $state = TransactionState::PENDING,
    ): Transaction {
        $now = new \DateTimeImmutable('2026-04-14T09:12:04+00:00');

        return new Transaction(
            id: '00000000-0000-7000-8000-0000000000f'.$suffix[1], workspace: WorkspaceScope::fromString(self::WORKSPACE),
            accountId: self::ACCOUNT, amount: self::amount($amount), originalAmount: null, exchangeRate: null,
            state: $state, nature: $nature, source: TransactionSource::PROVIDER, sourceRef: null,
            bookedOn: self::day($bookedOn), valueOn: null, authorizedOn: null, rawLabel: 'CB CARREFOUR 1234',
            counterparty: null, note: null, paymentMethod: null, mcc: null, maskedCard: null, bankReference: null,
            splits: [], version: 1, createdAt: $now, updatedAt: $now, voidedAt: null, lastEditorId: null,
        );
    }

    private static function amount(string $literal): AssetAmount
    {
        return new AssetAmount(DecimalValue::fromString($literal), AssetCode::fromString('EUR'));
    }

    private static function day(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
