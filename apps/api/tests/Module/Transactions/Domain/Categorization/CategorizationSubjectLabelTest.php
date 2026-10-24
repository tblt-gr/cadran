<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain\Categorization;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\Categorization\CategorizationSubject;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

/** Rules are written against the bank's wording, so a user's own label never changes what they match. */
final class CategorizationSubjectLabelTest extends TestCase
{
    public function testRulesMatchTheSourceLabelEvenWhenTheUserRenamedTheMovement(): void
    {
        $now = new \DateTimeImmutable('2026-03-14T09:12:04+00:00');
        $transaction = (new Transaction(
            id: '00000000-0000-7000-8000-0000000000f1', workspace: WorkspaceFixture::own(),
            accountId: '00000000-0000-7000-8000-0000000000d1',
            amount: new AssetAmount(DecimalValue::fromString('-42.90'), AssetCode::fromString('EUR')),
            originalAmount: null, exchangeRate: null, state: TransactionState::BOOKED, nature: TransactionNature::EXPENSE,
            source: TransactionSource::IMPORT, sourceRef: null, bookedOn: new \DateTimeImmutable('2026-03-14'),
            valueOn: null, authorizedOn: null, rawLabel: 'CB CARREFOUR 1234', counterparty: null, note: null,
            paymentMethod: null, mcc: null, maskedCard: null, bankReference: null, splits: [], version: 1,
            createdAt: $now, updatedAt: $now, voidedAt: null, lastEditorId: WorkspaceFixture::OWNER_ID,
        ))->withUserLabel('Courses du samedi');

        $subject = CategorizationSubject::fromTransaction($transaction);

        self::assertSame('CB CARREFOUR 1234', $subject->rawLabel);
        self::assertSame('cb carrefour 1234', $subject->normalizedLabel());
    }
}
