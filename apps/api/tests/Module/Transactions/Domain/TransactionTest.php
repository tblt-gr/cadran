<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionTest extends TestCase
{
    #[DataProvider('invalidSigns')]
    public function testTheDomainRejectsZeroAndSignsThatContradictNature(string $amount, TransactionNature $nature): void
    {
        $this->expectException(InvalidTransaction::class);
        $this->transaction($amount, $nature);
    }

    /** @return iterable<string, array{string, TransactionNature}> */
    public static function invalidSigns(): iterable
    {
        yield 'zero' => ['0.00', TransactionNature::ADJUSTMENT];
        yield 'negative income' => ['-1.00', TransactionNature::INCOME];
        yield 'positive expense' => ['1.00', TransactionNature::EXPENSE];
        yield 'positive fee' => ['1.00', TransactionNature::FEE];
    }

    public function testEditingKeepsIdentityAccountAssetAndProvenanceWhileChangingAManualRawLabel(): void
    {
        $original = $this->transaction('-42.90', TransactionNature::EXPENSE);
        $edited = $original->edit(
            amount: $this->amount('-40.00'), nature: TransactionNature::EXPENSE, state: TransactionState::BOOKED,
            bookedOn: new \DateTimeImmutable('2026-03-15'), valueOn: null, authorizedOn: null,
            rawLabel: 'CARREFOUR MARKET', counterparty: 'Carrefour', note: 'Corrigé', paymentMethod: null, mcc: null,
            maskedCard: null, bankReference: null, splits: [],
            updatedAt: new \DateTimeImmutable('2026-03-16T10:00:00+00:00'),
            lastEditorId: WorkspaceFixture::OWNER_ID,
        );

        self::assertSame($original->id, $edited->id);
        self::assertSame($original->accountId, $edited->accountId);
        self::assertSame('CARREFOUR MARKET', $edited->rawLabel);
        self::assertSame(TransactionSource::MANUAL, $edited->source);
        self::assertSame(2, $edited->version);
        self::assertSame('-40.00', $edited->amount->value->toString());
    }

    #[DataProvider('externalSources')]
    public function testEditingKeepsAnExternalRawLabelImmutable(TransactionSource $source): void
    {
        $original = $this->transaction('-42.90', TransactionNature::EXPENSE, $source);

        $this->expectException(InvalidTransaction::class);
        $original->edit(
            amount: $this->amount('-42.90'), nature: TransactionNature::EXPENSE, state: TransactionState::BOOKED,
            bookedOn: new \DateTimeImmutable('2026-03-15'), valueOn: null, authorizedOn: null,
            rawLabel: 'AUTRE LIBELLÉ', counterparty: null, note: null, paymentMethod: null, mcc: null,
            maskedCard: null, bankReference: null, splits: [],
            updatedAt: new \DateTimeImmutable('2026-03-16T10:00:00+00:00'),
            lastEditorId: WorkspaceFixture::OWNER_ID,
        );
    }

    /** @return iterable<string, array{TransactionSource}> */
    public static function externalSources(): iterable
    {
        yield 'import' => [TransactionSource::IMPORT];
        yield 'provider' => [TransactionSource::PROVIDER];
    }

    public function testARejectedTransactionCannotBeVoided(): void
    {
        $now = new \DateTimeImmutable('2026-03-14T09:12:04+00:00');
        $rejected = new Transaction(
            id: '00000000-0000-7000-8000-0000000000f1', workspace: WorkspaceFixture::own(),
            accountId: '00000000-0000-7000-8000-0000000000d1', amount: $this->amount('-42.90'),
            originalAmount: null, exchangeRate: null, state: TransactionState::REJECTED,
            nature: TransactionNature::EXPENSE, source: TransactionSource::MANUAL, sourceRef: null,
            bookedOn: new \DateTimeImmutable('2026-03-14'), valueOn: null, authorizedOn: null,
            rawLabel: 'CB CARREFOUR 1234', counterparty: null, note: null, paymentMethod: null,
            mcc: null, maskedCard: null, bankReference: null, splits: [], version: 1,
            createdAt: $now, updatedAt: $now, voidedAt: null, lastEditorId: WorkspaceFixture::OWNER_ID,
        );

        $this->expectException(InvalidTransaction::class);
        $rejected->void(new \DateTimeImmutable('2026-03-16T10:00:00+00:00'), WorkspaceFixture::OWNER_ID);
    }

    public function testTerminalTransactionsCannotBeEditedOrVoidedAgain(): void
    {
        $voided = $this->transaction('-42.90', TransactionNature::EXPENSE)
            ->void(new \DateTimeImmutable('2026-03-16T10:00:00+00:00'), WorkspaceFixture::OWNER_ID);

        $this->expectException(InvalidTransaction::class);
        $voided->void(new \DateTimeImmutable('2026-03-17T10:00:00+00:00'), WorkspaceFixture::OWNER_ID);
    }

    public function testSplitsSummingExactlyToTheAmountAreAccepted(): void
    {
        $transaction = $this->transactionWithSplits('-87.40', TransactionNature::EXPENSE, [
            $this->split('00000000-0000-7000-8000-0000000000e1', '00000000-0000-7000-8000-0000000000c1', '-62.10'),
            $this->split('00000000-0000-7000-8000-0000000000e2', '00000000-0000-7000-8000-0000000000c2', '-18.30'),
            $this->split('00000000-0000-7000-8000-0000000000e3', '00000000-0000-7000-8000-0000000000c3', '-7.00'),
        ]);

        self::assertCount(3, $transaction->splits);
    }

    public function testASplitSetOffByOneSmallestUnitIsRefused(): void
    {
        $this->expectException(InvalidTransaction::class);
        $this->transactionWithSplits('-87.40', TransactionNature::EXPENSE, [
            $this->split('00000000-0000-7000-8000-0000000000e1', '00000000-0000-7000-8000-0000000000c1', '-62.10'),
            $this->split('00000000-0000-7000-8000-0000000000e2', '00000000-0000-7000-8000-0000000000c2', '-18.29'),
        ]);
    }

    public function testASplitOfTheOppositeSignIsRefused(): void
    {
        $this->expectException(InvalidTransaction::class);
        $this->transactionWithSplits('-87.40', TransactionNature::EXPENSE, [
            $this->split('00000000-0000-7000-8000-0000000000e1', '00000000-0000-7000-8000-0000000000c1', '-100.00'),
            $this->split('00000000-0000-7000-8000-0000000000e2', '00000000-0000-7000-8000-0000000000c2', '12.60'),
        ]);
    }

    public function testTheSameCategoryTwiceIsRefused(): void
    {
        $this->expectException(InvalidTransaction::class);
        $this->transactionWithSplits('-87.40', TransactionNature::EXPENSE, [
            $this->split('00000000-0000-7000-8000-0000000000e1', '00000000-0000-7000-8000-0000000000c1', '-60.00'),
            $this->split('00000000-0000-7000-8000-0000000000e2', '00000000-0000-7000-8000-0000000000c1', '-27.40'),
        ]);
    }

    public function testMoreThanTwentySplitsAreRefused(): void
    {
        $splits = [];
        for ($i = 0; $i < 21; ++$i) {
            $splits[] = $this->split(
                sprintf('00000000-0000-7000-8000-0000000001%02d', $i),
                sprintf('00000000-0000-7000-8000-0000000002%02d', $i),
                '-1.00',
            );
        }

        $this->expectException(InvalidTransaction::class);
        $this->transactionWithSplits('-21.00', TransactionNature::EXPENSE, $splits);
    }

    /** @param list<TransactionSplit> $splits */
    private function transactionWithSplits(string $amount, TransactionNature $nature, array $splits): Transaction
    {
        $now = new \DateTimeImmutable('2026-03-14T09:12:04+00:00');

        return new Transaction(
            id: '00000000-0000-7000-8000-0000000000f1', workspace: WorkspaceFixture::own(),
            accountId: '00000000-0000-7000-8000-0000000000d1', amount: $this->amount($amount),
            originalAmount: null, exchangeRate: null, state: TransactionState::BOOKED, nature: $nature,
            source: TransactionSource::MANUAL, sourceRef: null, bookedOn: new \DateTimeImmutable('2026-03-14'),
            valueOn: null, authorizedOn: null, rawLabel: 'CB CARREFOUR 1234', counterparty: null,
            note: null, paymentMethod: null, mcc: null, maskedCard: null, bankReference: null,
            splits: $splits, version: 1, createdAt: $now, updatedAt: $now, voidedAt: null,
            lastEditorId: WorkspaceFixture::OWNER_ID,
        );
    }

    private function split(string $id, string $categoryId, string $amount): TransactionSplit
    {
        return new TransactionSplit(
            id: $id, workspace: WorkspaceFixture::own(), transactionId: '00000000-0000-7000-8000-0000000000f1',
            categoryId: $categoryId, amount: $this->amount($amount), analyticAxes: [], note: null,
            createdAt: new \DateTimeImmutable('2026-03-14T09:12:04+00:00'),
        );
    }

    private function transaction(
        string $amount,
        TransactionNature $nature,
        TransactionSource $source = TransactionSource::MANUAL,
    ): Transaction {
        $now = new \DateTimeImmutable('2026-03-14T09:12:04+00:00');

        return new Transaction(
            id: '00000000-0000-7000-8000-0000000000f1', workspace: WorkspaceFixture::own(),
            accountId: '00000000-0000-7000-8000-0000000000d1', amount: $this->amount($amount),
            originalAmount: null, exchangeRate: null, state: TransactionState::BOOKED, nature: $nature,
            source: $source, sourceRef: null, bookedOn: new \DateTimeImmutable('2026-03-14'),
            valueOn: null, authorizedOn: null, rawLabel: 'CB CARREFOUR 1234', counterparty: null,
            note: null, paymentMethod: null, mcc: null, maskedCard: null, bankReference: null,
            splits: [], version: 1, createdAt: $now, updatedAt: $now, voidedAt: null,
            lastEditorId: WorkspaceFixture::OWNER_ID,
        );
    }

    private function amount(string $literal): AssetAmount
    {
        return new AssetAmount(DecimalValue::fromString($literal), AssetCode::fromString('EUR'));
    }
}
