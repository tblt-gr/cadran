<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Application;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\CashWarningDetector;
use App\Module\Portfolio\Domain\Cash\CashLedger;
use App\Module\Portfolio\Domain\Cash\CashWarning;
use App\Module\Portfolio\Domain\Cash\CashWarningCode;
use App\Module\Portfolio\Domain\OperationCashEffect;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Transactions\Application\AccountTransactionFact;
use App\Module\Transactions\Application\TransferLegFact;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CashWarningDetectorTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string LEG = '00000000-0000-7000-8000-0000000000e1';

    #[Test]
    public function aCleanLedgerRaisesNothing(): void
    {
        $deposit = $this->operation('01', OperationType::DEPOSIT, '2026-03-02', gross: '500.00', link: self::LEG);
        $leg = $this->leg(self::LEG, '500.00', '2026-03-02');

        self::assertSame([], $this->detect([$deposit], transfers: [$this->fact(self::LEG, 'TRANSFER', '500.00', '2026-03-02')], linkedIds: [self::LEG], legs: [$leg]));
    }

    #[Test]
    public function negativeCashNamesItsOperationsAndFirstDate(): void
    {
        $warnings = $this->detect([$this->operation('01', OperationType::WITHDRAWAL, '2026-03-03', gross: '10.00')]);

        $negative = $this->warning($warnings, CashWarningCode::NEGATIVE_CASH);
        self::assertSame(1, $negative->count);
        self::assertSame(['00000000-0000-7000-8000-000000000001'], $negative->sourceIds);
        self::assertSame('2026-03-03', $negative->firstDate?->format('Y-m-d'));
    }

    #[Test]
    public function unlinkedIncomingAndOutgoingLegsAreSeparateWarningsAndIgnoreVoidedOnes(): void
    {
        $in = '00000000-0000-7000-8000-0000000000e2';
        $out = '00000000-0000-7000-8000-0000000000e3';
        $voided = '00000000-0000-7000-8000-0000000000e4';
        $warnings = $this->detect([], transfers: [
            $this->fact($in, 'TRANSFER', '500.00', '2026-03-02'),
            $this->fact($out, 'TRANSFER', '-20.00', '2026-03-03'),
            $this->fact($voided, 'TRANSFER', '70.00', '2026-03-04', voided: true),
            $this->fact(self::LEG, 'TRANSFER', '30.00', '2026-03-05'),
        ], linkedIds: [self::LEG]);

        self::assertSame([$in], $this->warning($warnings, CashWarningCode::UNLINKED_TRANSFER_IN)->sourceIds);
        self::assertSame([$out], $this->warning($warnings, CashWarningCode::UNLINKED_TRANSFER_OUT)->sourceIds);
        self::assertCount(2, $warnings);
    }

    #[Test]
    public function anExternalFlowWithoutALegIsInformative(): void
    {
        $warnings = $this->detect([
            $this->operation('01', OperationType::DEPOSIT, '2026-03-02', gross: '100.00'),
            $this->operation('02', OperationType::WITHDRAWAL, '2026-03-03', gross: '10.00'),
            $this->operation('03', OperationType::FEE, '2026-03-04', fees: '1.00'),
        ]);

        self::assertSame(2, $this->warning($warnings, CashWarningCode::UNLINKED_EXTERNAL_FLOW)->count);
    }

    #[Test]
    public function aLinkedLegThatWasVoidedOrChangedIsReported(): void
    {
        $ids = [];
        $operations = [];
        $legs = [];
        $cases = [
            'voided' => $this->leg('00000000-0000-7000-8000-0000000000a1', '100.00', '2026-03-02', voided: true),
            'amount' => $this->leg('00000000-0000-7000-8000-0000000000a2', '100.01', '2026-03-02'),
            'date' => $this->leg('00000000-0000-7000-8000-0000000000a3', '100.00', '2026-03-03'),
            'direction' => $this->leg('00000000-0000-7000-8000-0000000000a4', '-100.00', '2026-03-02'),
            'account' => $this->leg('00000000-0000-7000-8000-0000000000a5', '100.00', '2026-03-02', account: '00000000-0000-7000-8000-0000000000d9'),
        ];
        $n = 10;
        foreach ($cases as $leg) {
            $operation = $this->operation((string) $n++, OperationType::DEPOSIT, '2026-03-02', gross: '100.00', link: $leg->id, sequence: $n);
            $operations[] = $operation;
            $ids[] = $operation->id;
            $legs[] = $leg;
        }
        $missing = $this->operation('20', OperationType::DEPOSIT, '2026-03-02', gross: '100.00', link: '00000000-0000-7000-8000-0000000000a6', sequence: 20);
        $operations[] = $missing;
        $ids[] = $missing->id;

        $warnings = $this->detect($operations, legs: $legs);

        self::assertSame($ids, $this->warning($warnings, CashWarningCode::LINKED_TRANSFER_CHANGED)->sourceIds);
        self::assertNull($this->find($warnings, CashWarningCode::UNLINKED_EXTERNAL_FLOW));
    }

    #[Test]
    public function aPreExistingIncomeOrExpenseIsReportedUnlessVoided(): void
    {
        $warnings = $this->detect([], nonTransfer: [
            $this->fact('00000000-0000-7000-8000-0000000000b1', 'EXPENSE', '-9.00', '2026-03-05'),
            $this->fact('00000000-0000-7000-8000-0000000000b2', 'FEE', '-1.00', '2026-03-06', voided: true),
            $this->fact('00000000-0000-7000-8000-0000000000b3', 'INCOME', '4.00', '2026-03-07'),
        ]);

        self::assertSame(
            ['00000000-0000-7000-8000-0000000000b1', '00000000-0000-7000-8000-0000000000b3'],
            $this->warning($warnings, CashWarningCode::NON_TRANSFER_TRANSACTION)->sourceIds,
        );
    }

    #[Test]
    public function roundingResidualsReturnTheirSignedTotalExactly(): void
    {
        $warnings = $this->detect([
            $this->operation('01', OperationType::DEPOSIT, '2026-03-01', gross: '5000.00'),
            $this->operation('02', OperationType::BUY, '2026-03-02', quantity: '10', unitPrice: '230.5688', gross: '2305.69', fees: '1.99'),
            $this->operation('03', OperationType::SELL, '2026-03-03', quantity: '1', unitPrice: '10.004', gross: '10.00'),
            $this->operation('04', OperationType::SELL, '2026-03-04', quantity: '1', unitPrice: '10', gross: '10.00'),
        ]);

        $residuals = $this->warning($warnings, CashWarningCode::ROUNDING_RESIDUALS);
        self::assertSame(2, $residuals->count);
        self::assertSame('-0.002', $residuals->amount?->toString());
    }

    #[Test]
    public function sourcesAreCappedAtTwentyIdentifiersButTheCountIsExact(): void
    {
        $facts = [];
        for ($i = 0; $i < 25; ++$i) {
            $facts[] = $this->fact(sprintf('00000000-0000-7000-8000-0000000001%02d', $i), 'EXPENSE', '-1.00', '2026-03-05');
        }
        $warning = $this->warning($this->detect([], nonTransfer: $facts), CashWarningCode::NON_TRANSFER_TRANSACTION);

        self::assertSame(25, $warning->count);
        self::assertCount(20, $warning->sourceIds);
    }

    /**
     * @param list<PortfolioOperation>     $operations
     * @param list<AccountTransactionFact> $transfers
     * @param list<AccountTransactionFact> $nonTransfer
     * @param list<string>                 $linkedIds
     * @param list<TransferLegFact>        $legs
     *
     * @return list<CashWarning>
     */
    private function detect(array $operations, array $transfers = [], array $nonTransfer = [], array $linkedIds = [], array $legs = []): array
    {
        return CashWarningDetector::detect(CashLedger::replay([], $operations), $operations, $transfers, $nonTransfer, $linkedIds, $legs);
    }

    /** @param list<CashWarning> $warnings */
    private function find(array $warnings, CashWarningCode $code): ?CashWarning
    {
        foreach ($warnings as $warning) {
            if ($warning->code === $code) {
                return $warning;
            }
        }

        return null;
    }

    /** @param list<CashWarning> $warnings */
    private function warning(array $warnings, CashWarningCode $code): CashWarning
    {
        $found = $this->find($warnings, $code);
        self::assertNotNull($found, $code->value);

        return $found;
    }

    private function fact(string $id, string $nature, string $amount, string $date, bool $voided = false): AccountTransactionFact
    {
        return new AccountTransactionFact($id, $nature, new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')), new \DateTimeImmutable($date), $voided ? 'VOIDED' : 'BOOKED', $voided);
    }

    private function leg(string $id, string $amount, string $date, bool $voided = false, string $account = self::ACCOUNT): TransferLegFact
    {
        return new TransferLegFact($id, $account, new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')), new \DateTimeImmutable($date), $voided ? 'VOIDED' : 'BOOKED', $voided, null);
    }

    private function operation(
        string $suffix,
        OperationType $type,
        string $date,
        ?string $quantity = null,
        ?string $unitPrice = null,
        ?string $gross = null,
        ?string $fees = null,
        ?string $link = null,
        int $sequence = 1,
    ): PortfolioOperation {
        $trade = in_array($type, [OperationType::BUY, OperationType::SELL], true);
        $details = new OperationDetails(
            $type, new \DateTimeImmutable($date), null, $trade ? '00000000-0000-7000-8000-000000000b01' : null, null,
            null === $quantity ? null : DecimalValue::fromString($quantity),
            null === $unitPrice ? null : DecimalValue::fromString($unitPrice),
            null === $gross ? null : DecimalValue::fromString($gross),
            null === $fees ? ($trade ? DecimalValue::zero() : null) : DecimalValue::fromString($fees),
            $trade ? DecimalValue::zero() : null,
            null, null, null, null, null, null, null, $link,
        );
        $effect = OperationCashEffect::derive(
            $type, $details->quantity, $details->unitPrice, $details->grossAmount,
            $details->fees ?? DecimalValue::zero(), $details->taxes ?? DecimalValue::zero(), 2,
        );

        return new PortfolioOperation(
            sprintf('00000000-0000-7000-8000-%012d', (int) $suffix),
            WorkspaceScope::fromString('00000000-0000-7000-8000-000000000001'), self::ACCOUNT, AssetCode::fromString('EUR'),
            $details, $sequence, $effect->cashAmount, $effect->roundingResidual, OperationState::ACTIVE, null, null, null, null,
            null, new \DateTimeImmutable('2026-01-01'), '00000000-0000-7000-8000-000000000002', 1,
        );
    }
}
