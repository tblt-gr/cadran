<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\BulkCategorizationFacts;
use App\Module\Transactions\Domain\BulkEditSkipReason;
use App\Module\Transactions\Domain\BulkMovePolicy;
use App\Module\Transactions\Domain\BulkMoveTarget;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use PHPUnit\Framework\TestCase;

final class BulkMovePolicyTest extends TestCase
{
    private const string COURANT = '00000000-0000-7000-8000-0000000000d1';
    private const string JOINT = '00000000-0000-7000-8000-0000000000d5';

    public function testARowWithNothingInTheWayMoves(): void
    {
        self::assertNull(BulkMovePolicy::reason($this->row(), new BulkCategorizationFacts(), $this->account(self::JOINT), $this->account(self::COURANT), null, $this->today()));
    }

    public function testEachFactRefusesTheWholeRowWithItsOwnReason(): void
    {
        $joint = $this->account(self::JOINT);
        $courant = $this->account(self::COURANT);
        $cases = [
            'CLOSED_PERIOD' => new BulkCategorizationFacts(targetPeriodClosed: true),
            'PORTFOLIO_LINKED' => new BulkCategorizationFacts(portfolioLinked: true),
            'RECONCILED' => new BulkCategorizationFacts(reconciled: true),
        ];
        foreach ($cases as $reason => $facts) {
            $day = 'CLOSED_PERIOD' === $reason ? new \DateTimeImmutable('2026-10-04') : null;
            self::assertSame($reason, BulkMovePolicy::reason($this->row(), $facts, $joint, $courant, $day, $this->today())?->value);
        }
        self::assertSame(
            BulkEditSkipReason::PORTFOLIO_ACCOUNT,
            BulkMovePolicy::reason($this->row(), new BulkCategorizationFacts(), new BulkMoveTarget(self::JOINT, 'Titres', 'EUR', new \DateTimeImmutable('2026-01-01'), null, true), $courant, null, $this->today()),
        );
        self::assertSame(
            BulkEditSkipReason::ALREADY_ON_ACCOUNT,
            BulkMovePolicy::reason($this->row(), new BulkCategorizationFacts(), $courant, $courant, null, $this->today()),
        );
    }

    public function testTheTargetMustBeOpenOnTheNewDayAndAClosedAccountRefusesTheDay(): void
    {
        $closed = new BulkMoveTarget(self::COURANT, 'Courant', 'EUR', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-10-10'), false);

        self::assertSame(
            BulkEditSkipReason::ACCOUNT_NOT_OPEN,
            BulkMovePolicy::reason($this->row(), new BulkCategorizationFacts(), null, $closed, new \DateTimeImmutable('2026-10-11'), $this->today()),
        );
        self::assertNull(BulkMovePolicy::reason($this->row(), new BulkCategorizationFacts(), null, $closed, new \DateTimeImmutable('2026-10-10'), $this->today()));
    }

    public function testAClosedTargetMonthOnlyRefusesARowWhoseDateActuallyChanges(): void
    {
        $facts = new BulkCategorizationFacts(targetPeriodClosed: true);
        $courant = $this->account(self::COURANT);

        self::assertNull(BulkMovePolicy::reason($this->row(), $facts, null, $courant, new \DateTimeImmutable('2026-10-03'), $this->today()));
        self::assertSame(
            BulkEditSkipReason::CLOSED_PERIOD,
            BulkMovePolicy::reason($this->row(), $facts, null, $courant, new \DateTimeImmutable('2026-10-04'), $this->today()),
        );
    }

    public function testAlreadyOnAccountWinsOverAnAccountClosedOnTheDay(): void
    {
        $closed = new BulkMoveTarget(self::COURANT, 'Courant', 'EUR', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-10-01'), false);

        self::assertSame(
            BulkEditSkipReason::ALREADY_ON_ACCOUNT,
            BulkMovePolicy::reason($this->row(), new BulkCategorizationFacts(), $closed, $closed, null, $this->today()),
        );
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-06');
    }

    private function account(string $id): BulkMoveTarget
    {
        return new BulkMoveTarget($id, 'Compte', 'EUR', new \DateTimeImmutable('2026-01-01'), null, false);
    }

    private function row(): Transaction
    {
        $at = new \DateTimeImmutable('2026-10-01 09:00:00');

        return new Transaction(
            id: '00000000-0000-7000-8000-000000000001', workspace: WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            accountId: self::COURANT, amount: new AssetAmount(DecimalValue::fromString('-1.00'), AssetCode::fromString('EUR')),
            originalAmount: null, exchangeRate: null, state: TransactionState::BOOKED, nature: TransactionNature::EXPENSE,
            source: TransactionSource::MANUAL, sourceRef: null, bookedOn: new \DateTimeImmutable('2026-10-03'), valueOn: null, authorizedOn: null,
            rawLabel: 'CB', counterparty: null, note: null, paymentMethod: null, mcc: null, maskedCard: null, bankReference: null,
            splits: [], version: 1, createdAt: $at, updatedAt: $at, voidedAt: null, lastEditorId: null,
        );
    }
}
