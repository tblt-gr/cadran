<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\ComputeBulkMoveImpact;
use App\Module\Transactions\Domain\BulkMoveTarget;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use PHPUnit\Framework\TestCase;

final class ComputeBulkMoveImpactTest extends TestCase
{
    private const string COURANT = '00000000-0000-7000-8000-0000000000d1';
    private const string JOINT = '00000000-0000-7000-8000-0000000000d5';

    public function testWorkedExampleMovesSixtyEurosFromCourantToJoint(): void
    {
        $courant = $this->account(self::COURANT, 'Courant');
        $joint = $this->account(self::JOINT, 'Joint');

        $impacts = ComputeBulkMoveImpact::of(
            [$this->row('1', '-42.10', '2026-10-03'), $this->row('2', '-17.90', '2026-10-15')],
            [self::COURANT => $courant],
            $joint,
            null,
        );

        self::assertCount(2, $impacts);
        self::assertSame(['Courant', '60.00', '2026-10-03', null], [$impacts[0]->label, $impacts[0]->delta, $impacts[0]->from, $impacts[0]->to]);
        self::assertSame(['Joint', '-60.00', '2026-10-03', null], [$impacts[1]->label, $impacts[1]->delta, $impacts[1]->from, $impacts[1]->to]);
    }

    public function testNothingMovingYieldsNoImpact(): void
    {
        self::assertSame([], ComputeBulkMoveImpact::of([], [], $this->account(self::JOINT, 'Joint'), null));
        self::assertSame([], ComputeBulkMoveImpact::of([$this->row('1', '-1.00', '2026-10-03')], [self::COURANT => $this->account(self::COURANT, 'Courant')], null, new \DateTimeImmutable('2026-10-03')));
    }

    public function testADateChangeSpansTheEarliestAndLatestOfOldAndNewDays(): void
    {
        $impacts = ComputeBulkMoveImpact::of(
            [$this->row('1', '-1.00', '2026-10-10'), $this->row('2', '-2.00', '2026-10-25')],
            [self::COURANT => $this->account(self::COURANT, 'Courant')],
            null,
            new \DateTimeImmutable('2026-10-20'),
        );

        self::assertCount(1, $impacts);
        self::assertSame(['0', '2026-10-10', '2026-10-25'], [$impacts[0]->delta, $impacts[0]->from, $impacts[0]->to]);
    }

    private function account(string $id, string $label): BulkMoveTarget
    {
        return new BulkMoveTarget($id, $label, 'EUR', new \DateTimeImmutable('2026-01-01'), null, false);
    }

    private function row(string $suffix, string $amount, string $bookedOn): Transaction
    {
        $at = new \DateTimeImmutable('2026-10-01 09:00:00');

        return new Transaction(
            id: '00000000-0000-7000-8000-00000000000'.$suffix, workspace: WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            accountId: self::COURANT, amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            originalAmount: null, exchangeRate: null, state: TransactionState::BOOKED, nature: TransactionNature::EXPENSE,
            source: TransactionSource::MANUAL, sourceRef: null, bookedOn: new \DateTimeImmutable($bookedOn), valueOn: null, authorizedOn: null,
            rawLabel: 'CB', counterparty: null, note: null, paymentMethod: null, mcc: null, maskedCard: null, bankReference: null,
            splits: [], version: 1, createdAt: $at, updatedAt: $at, voidedAt: null, lastEditorId: null,
        );
    }
}
