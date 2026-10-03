<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\OperationVoidKind;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PortfolioOperationTest extends TestCase
{
    private const string ID = '00000000-0000-7000-8000-000000000a01';
    private const string INSTRUMENT = '00000000-0000-7000-8000-000000000b01';
    private const string OTHER_INSTRUMENT = '00000000-0000-7000-8000-000000000b02';

    public function testAnActiveOperationVoidsOnceAndKeepsItsSourceAmounts(): void
    {
        $operation = self::operation(self::buy());
        $voided = $operation->void(OperationVoidKind::VOIDED, '  Saisie en double ', new \DateTimeImmutable('2026-03-02T10:00:00Z'), WorkspaceFixture::OWNER_ID);

        self::assertSame(OperationState::VOIDED, $voided->state);
        self::assertSame(OperationVoidKind::VOIDED, $voided->voidKind);
        self::assertSame('Saisie en double', $voided->voidReason);
        self::assertSame(2, $voided->version);
        self::assertSame('2305.690', $voided->details->grossAmount?->toString(), 'scale as entered');
        self::assertSame(1, $operation->version, 'the original instance is immutable');

        try {
            $voided->void(OperationVoidKind::REPLACED, null, new \DateTimeImmutable('2026-03-03T10:00:00Z'), WorkspaceFixture::OWNER_ID);
            self::fail('A voided operation cannot be voided again.');
        } catch (InvalidPortfolioOperation $exception) {
            self::assertSame('operation_voided', $exception->problemCode);
        }
    }

    public function testAVoidNeedsAReasonButAReplacementDoesNot(): void
    {
        $operation = self::operation(self::buy());
        $replaced = $operation->void(OperationVoidKind::REPLACED, null, new \DateTimeImmutable('2026-03-02T10:00:00Z'), WorkspaceFixture::OWNER_ID);
        self::assertSame(OperationVoidKind::REPLACED, $replaced->voidKind);
        self::assertNull($replaced->voidReason);

        foreach ([null, '', '   ', str_repeat('x', 201), "a\u{0007}b", "a\u{200B}b"] as $reason) {
            try {
                $operation->void(OperationVoidKind::VOIDED, $reason, new \DateTimeImmutable('2026-03-02T10:00:00Z'), WorkspaceFixture::OWNER_ID);
                self::fail('A void reason must be 1 to 200 plain characters.');
            } catch (InvalidPortfolioOperation $exception) {
                self::assertSame('invalid_operation_text', $exception->problemCode);
            }
        }
    }

    /** @return iterable<string, array{callable(): OperationDetails, string}> */
    public static function invalidDetails(): iterable
    {
        yield 'zero quantity' => [static fn (): OperationDetails => self::buy(quantity: '0'), 'invalid_operation_quantity'];
        yield 'negative quantity' => [static fn (): OperationDetails => self::buy(quantity: '-1'), 'invalid_operation_quantity'];
        yield 'zero unit price' => [static fn (): OperationDetails => self::buy(unitPrice: '0'), 'invalid_operation_amount'];
        yield 'negative gross amount' => [static fn (): OperationDetails => self::buy(gross: '-5'), 'invalid_operation_amount'];
        yield 'negative fees' => [static fn (): OperationDetails => self::buy(fees: '-0.01'), 'invalid_operation_amount'];
        yield 'zero fee operation' => [static fn (): OperationDetails => self::details(OperationType::FEE, fees: '0'), 'invalid_operation_amount'];
        yield 'negative tax operation' => [static fn (): OperationDetails => self::details(OperationType::TAX, taxes: '-1'), 'invalid_operation_amount'];
        yield 'negative cost basis' => [static fn (): OperationDetails => self::details(OperationType::SECURITY_TRANSFER_IN, instrument: true, quantity: '1', costBasis: '-1'), 'invalid_operation_amount'];
        yield 'settlement before trade' => [static fn (): OperationDetails => self::buy(settlementDate: '2026-02-28'), 'invalid_settlement_date'];
        yield 'settlement too late' => [static fn (): OperationDetails => self::buy(settlementDate: '2026-04-02'), 'invalid_settlement_date'];
        yield 'split ratio equal' => [static fn (): OperationDetails => self::details(OperationType::SPLIT, instrument: true, ratio: ['2', '2.0']), 'invalid_corporate_action'];
        yield 'split ratio zero denominator' => [static fn (): OperationDetails => self::details(OperationType::SPLIT, instrument: true, ratio: ['2', '0']), 'invalid_corporate_action'];
        yield 'spin-off ratio one' => [static fn (): OperationDetails => self::details(OperationType::SPIN_OFF, instrument: true, counter: true, counterQuantity: '1', costAllocationRatio: '1'), 'invalid_corporate_action'];
        yield 'spin-off ratio zero' => [static fn (): OperationDetails => self::details(OperationType::SPIN_OFF, instrument: true, counter: true, counterQuantity: '1', costAllocationRatio: '0'), 'invalid_corporate_action'];
        yield 'merger into itself' => [static fn (): OperationDetails => self::details(OperationType::MERGER, instrument: true, counter: true, counterQuantity: '1', counterIsSame: true), 'invalid_corporate_action'];
        yield 'reference with control character' => [static fn (): OperationDetails => self::buy(reference: "AB\u{0000}C"), 'invalid_operation_text'];
        yield 'note too long' => [static fn (): OperationDetails => self::buy(note: str_repeat('n', 201)), 'invalid_operation_text'];
        yield 'blank reference' => [static fn (): OperationDetails => self::buy(reference: '   '), 'invalid_operation_text'];
        yield 'forbidden field' => [static fn (): OperationDetails => self::details(OperationType::DEPOSIT, gross: '10', fees: '1'), 'operation_field_not_allowed'];
    }

    /** @param callable(): OperationDetails $build */
    #[DataProvider('invalidDetails')]
    public function testInvalidDetailsAreRefusedWithTheirCode(callable $build, string $code): void
    {
        try {
            $build();
            self::fail('Expected '.$code);
        } catch (InvalidPortfolioOperation $exception) {
            self::assertSame($code, $exception->problemCode);
        }
    }

    public function testZeroFeesAndTaxesAreAllowedOnATrade(): void
    {
        $details = self::buy(fees: '0', taxes: '0.00');
        self::assertSame('0.00', $details->taxes?->toString());
    }

    public function testTextIsTrimmed(): void
    {
        $details = self::buy(reference: '  REF-1  ', note: ' hello ');
        self::assertSame('REF-1', $details->reference);
        self::assertSame('hello', $details->note);
    }

    private static function operation(OperationDetails $details): PortfolioOperation
    {
        return new PortfolioOperation(
            self::ID, WorkspaceFixture::own(), '00000000-0000-7000-8000-0000000000d1', AssetCode::fromString('EUR'), $details,
            1, DecimalValue::fromString('-2307.68'), DecimalValue::fromString('0.002'), OperationState::ACTIVE,
            null, null, null, null, null, new \DateTimeImmutable('2026-03-01T10:00:00Z'), WorkspaceFixture::OWNER_ID, 1,
        );
    }

    private static function buy(
        string $quantity = '10', string $unitPrice = '230.5688', string $gross = '2305.690', ?string $fees = '1.99', ?string $taxes = null,
        string $settlementDate = '2026-03-03', ?string $reference = null, ?string $note = null,
    ): OperationDetails {
        return self::details(OperationType::BUY, instrument: true, quantity: $quantity, unitPrice: $unitPrice, gross: $gross, fees: $fees, taxes: $taxes, settlementDate: $settlementDate, reference: $reference, note: $note);
    }

    /** @param array{string, string}|null $ratio */
    private static function details(
        OperationType $type, bool $instrument = false, ?string $quantity = null, ?string $unitPrice = null, ?string $gross = null,
        ?string $fees = null, ?string $taxes = null, ?string $costBasis = null, bool $counter = false, ?string $counterQuantity = null,
        ?array $ratio = null, ?string $costAllocationRatio = null, bool $counterIsSame = false, string $settlementDate = '2026-03-01',
        ?string $reference = null, ?string $note = null,
    ): OperationDetails {
        $d = static fn (?string $value): ?DecimalValue => null === $value ? null : DecimalValue::fromString($value);

        return new OperationDetails(
            type: $type,
            tradeDate: new \DateTimeImmutable('2026-03-01', new \DateTimeZone('UTC')),
            settlementDate: new \DateTimeImmutable($settlementDate, new \DateTimeZone('UTC')),
            instrumentId: $instrument ? self::INSTRUMENT : null,
            counterInstrumentId: $counter ? ($counterIsSame ? self::INSTRUMENT : self::OTHER_INSTRUMENT) : null,
            quantity: $d($quantity), unitPrice: $d($unitPrice), grossAmount: $d($gross), fees: $d($fees), taxes: $d($taxes),
            costBasis: $d($costBasis), counterQuantity: $d($counterQuantity),
            ratioNumerator: $d($ratio[0] ?? null), ratioDenominator: $d($ratio[1] ?? null),
            costAllocationRatio: $d($costAllocationRatio), reference: $reference, note: $note, linkedTransactionId: null,
        );
    }
}
