<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain;

use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\BulkCategorizationFacts;
use App\Module\Transactions\Domain\BulkEditPolicy;
use App\Module\Transactions\Domain\BulkEditSkipReason;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class BulkEditPolicyTest extends TestCase
{
    private const string TARGET = '00000000-0000-7000-8000-0000000000c1';

    public function testAPlainRowIsEligible(): void
    {
        self::assertNull(BulkEditPolicy::rowReason($this->transaction(), new BulkCategorizationFacts(), null));
        self::assertNull(BulkEditPolicy::rowReason($this->transaction(), new BulkCategorizationFacts(), 'Free Mobile'));
    }

    public function testRowReasonsFollowTheSingleEditRules(): void
    {
        self::assertSame(BulkEditSkipReason::TRANSFER_LEG, BulkEditPolicy::rowReason($this->transaction(), new BulkCategorizationFacts(transferLeg: true, periodClosed: true), null));
        self::assertSame(BulkEditSkipReason::HAS_REFUNDS, BulkEditPolicy::rowReason($this->transaction(), new BulkCategorizationFacts(liveRefund: true, periodClosed: true), null));
        self::assertSame(BulkEditSkipReason::TERMINAL, BulkEditPolicy::rowReason($this->transaction(state: TransactionState::VOIDED), new BulkCategorizationFacts(periodClosed: true), null));
        self::assertSame(BulkEditSkipReason::CLOSED_PERIOD, BulkEditPolicy::rowReason($this->transaction(), new BulkCategorizationFacts(periodClosed: true), ''));
    }

    public function testARefundIsNotAWholeRowReasonSinceOnlyItsCategoryIsFrozen(): void
    {
        self::assertNull(BulkEditPolicy::rowReason($this->transaction(), new BulkCategorizationFacts(refund: true), 'Rembourse'));
        self::assertSame(
            BulkEditSkipReason::REFUND,
            BulkEditPolicy::categoryReason($this->transaction(), self::TARGET, CategoryType::EXPENSE, new BulkCategorizationFacts(refund: true)),
        );
    }

    public function testAnEmptyLabelIsRefusedForOwnLabelsAndAcceptedForExternalOnes(): void
    {
        $facts = new BulkCategorizationFacts();

        self::assertSame(BulkEditSkipReason::LABEL_REQUIRED, BulkEditPolicy::rowReason($this->transaction(), $facts, ''));
        self::assertSame(BulkEditSkipReason::LABEL_REQUIRED, BulkEditPolicy::rowReason($this->transaction(TransactionSource::RECURRENCE), $facts, ''));
        self::assertNull(BulkEditPolicy::rowReason($this->transaction(TransactionSource::IMPORT), $facts, ''));
        self::assertNull(BulkEditPolicy::rowReason($this->transaction(TransactionSource::PROVIDER), $facts, ''));
    }

    public function testTheCategoryReasonReusesTheCategorizationPolicy(): void
    {
        self::assertNull(BulkEditPolicy::categoryReason($this->transaction(), self::TARGET, CategoryType::EXPENSE, new BulkCategorizationFacts()));
        self::assertSame(
            BulkEditSkipReason::SIGN_MISMATCH,
            BulkEditPolicy::categoryReason($this->transaction(amount: '-0.000000000000000000000001'), self::TARGET, CategoryType::INCOME, new BulkCategorizationFacts()),
        );
    }

    private function transaction(
        TransactionSource $source = TransactionSource::MANUAL,
        TransactionState $state = TransactionState::BOOKED,
        string $amount = '-42.90',
    ): Transaction {
        $now = new \DateTimeImmutable('2026-03-14T09:12:04+00:00');

        return new Transaction(
            id: '00000000-0000-7000-8000-0000000000f1', workspace: WorkspaceFixture::own(),
            accountId: '00000000-0000-7000-8000-0000000000d1',
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            originalAmount: null, exchangeRate: null, state: $state, nature: TransactionNature::EXPENSE,
            source: $source, sourceRef: TransactionSource::MANUAL === $source ? null : 'ref', bookedOn: new \DateTimeImmutable('2026-03-14'),
            valueOn: null, authorizedOn: null, rawLabel: 'CB CARREFOUR 1234', counterparty: null,
            note: null, paymentMethod: null, mcc: null, maskedCard: null, bankReference: null,
            splits: [], version: 1, createdAt: $now, updatedAt: $now,
            voidedAt: TransactionState::VOIDED === $state ? $now : null,
            lastEditorId: WorkspaceFixture::OWNER_ID,
        );
    }
}
