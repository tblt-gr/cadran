<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Domain;

use App\Module\Import\Domain\Amount\AmountCellReader;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Import\Domain\Sheet\Cell;
use App\Module\Import\Domain\Sheet\CellKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Worked references (EUR, precision 2): `=12.5+48.2+7.9` is 12.50 + 48.20 +
 * 7.90 = 68.60. A cached 68.6 or 68.60000000000001 (difference 1e-14, under
 * 10^-3) is accepted, a cached 68.7 is not.
 */
final class AmountCellReaderTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function amounts(?Cell $cell, int $precision = 2): array
    {
        $reading = (new AmountCellReader())->read($cell, $precision);
        self::assertNull($reading->rejection, 'unexpected rejection');

        return array_map(static fn ($term): string => $term->value->toString(), $reading->terms);
    }

    public function testASumOfTermsMatchingItsCachedValueGivesOneMovementPerTerm(): void
    {
        $reading = (new AmountCellReader())->read(Cell::number('68.6', '12.5+48.2+7.9'), 2);

        self::assertSame(['12.50', '48.20', '7.90'], array_map(static fn ($term): string => $term->value->toString(), $reading->terms));
        self::assertFalse($reading->floatArtifact);
        self::assertSame([], $reading->excluded);
    }

    public function testABinaryFloatArtifactOnTheCachedValueIsToleratedWithAWarning(): void
    {
        $reading = (new AmountCellReader())->read(Cell::number('68.60000000000001', '12.5+48.2+7.9'), 2);

        self::assertCount(3, $reading->terms);
        self::assertTrue($reading->floatArtifact);
    }

    public function testACachedValueAtTheToleranceBoundaryIsAccepted(): void
    {
        self::assertCount(3, (new AmountCellReader())->read(Cell::number('68.601', '12.5+48.2+7.9'), 2)->terms);
    }

    #[DataProvider('mismatchingCachedValues')]
    public function testACachedValueBeyondTheToleranceIsAMismatch(?string $cached): void
    {
        $cell = null === $cached ? Cell::formulaWithoutValue('12.5+48.2+7.9') : Cell::number($cached, '12.5+48.2+7.9');

        self::assertSame(ReasonCode::CACHED_VALUE_MISMATCH, (new AmountCellReader())->read($cell, 2)->rejection);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function mismatchingCachedValues(): iterable
    {
        yield 'a different amount' => ['68.7'];
        yield 'just beyond the tolerance' => ['68.6011'];
        yield 'no cached value at all' => [null];
    }

    public function testAFormulaWhoseCachedValueIsAnErrorOrTextIsAMismatch(): void
    {
        $reader = new AmountCellReader();

        self::assertSame(ReasonCode::CACHED_VALUE_MISMATCH, $reader->read(new Cell(CellKind::ERROR, '#N/A', '12.5+48.2+7.9'), 2)->rejection);
        self::assertSame(ReasonCode::CACHED_VALUE_MISMATCH, $reader->read(Cell::text('68.6', '12.5+48.2+7.9'), 2)->rejection);
    }

    public function testAFormulaOutsideTheGrammarIsRejectedBeforeAnyComparison(): void
    {
        self::assertSame(ReasonCode::FORMULA_REFERENCE, (new AmountCellReader())->read(Cell::number('5', 'B3'), 2)->rejection);
        self::assertSame(ReasonCode::FORMULA_NOT_SUPPORTED, (new AmountCellReader())->read(Cell::number('3.33', '10/3'), 2)->rejection);
        self::assertSame(ReasonCode::PRECISION_EXCEEDED, (new AmountCellReader())->read(Cell::number('12.345', '12.345'), 2)->rejection);
    }

    public function testAZeroTermIsReportedAsExcludedAndNeverDropped(): void
    {
        $reading = (new AmountCellReader())->read(Cell::number('3', '0+3'), 2);

        self::assertSame(['3.00'], array_map(static fn ($term): string => $term->value->toString(), $reading->terms));
        self::assertSame([ReasonCode::ZERO_AMOUNT], $reading->excluded);
    }

    public function testAPlainZeroIsExcluded(): void
    {
        $reading = (new AmountCellReader())->read(Cell::number('0'), 2);

        self::assertSame([], $reading->terms);
        self::assertSame([ReasonCode::ZERO_AMOUNT], $reading->excluded);
        self::assertFalse($reading->empty);
    }

    public function testAPlainNumberIsReadFromItsStoredText(): void
    {
        self::assertSame(['90.49'], self::amounts(Cell::number('90.49')));
        self::assertSame(['-12.50'], self::amounts(Cell::number('-12.5')));
        self::assertSame(['1250.00'], self::amounts(Cell::number('1.25E3')));
        self::assertSame(['0.50'], self::amounts(Cell::number('.5')));
    }

    public function testAPlainNumberWithAFloatArtifactIsNormalizedWithAWarning(): void
    {
        $reading = (new AmountCellReader())->read(Cell::number('90.4899999999998'), 2);

        self::assertSame('90.49', $reading->terms[0]->value->toString());
        self::assertTrue($reading->floatArtifact);
    }

    public function testAPlainNumberBetweenTwoRepresentableAmountsIsRefused(): void
    {
        self::assertSame(ReasonCode::PRECISION_EXCEEDED, (new AmountCellReader())->read(Cell::number('90.484'), 2)->rejection);
    }

    public function testATinyFloatResidueIsAZeroAmount(): void
    {
        $reading = (new AmountCellReader())->read(Cell::number('1.1102230246251565E-16'), 2);

        self::assertSame([ReasonCode::ZERO_AMOUNT], $reading->excluded);
        self::assertTrue($reading->floatArtifact);
    }

    public function testVeryLargeAmountsAreExactOrRefused(): void
    {
        self::assertSame(['99999999999999999999999999.00'], self::amounts(Cell::number('99999999999999999999999999')));
        self::assertSame(ReasonCode::PRECISION_EXCEEDED, (new AmountCellReader())->read(Cell::number('999999999999999999999999999'), 2)->rejection);
        self::assertSame(ReasonCode::PRECISION_EXCEEDED, (new AmountCellReader())->read(Cell::number('1E+30'), 2)->rejection);
        self::assertSame(ReasonCode::PRECISION_EXCEEDED, (new AmountCellReader())->read(Cell::number('1E400'), 2)->rejection);
    }

    public function testAnotherPrecisionIsHonoured(): void
    {
        self::assertSame(['1234'], self::amounts(Cell::number('1234'), 0));
        self::assertSame(['0.00000001'], self::amounts(Cell::number('1E-8'), 8));
    }

    public function testAnAbsentOrBlankCellIsEmptyNotZero(): void
    {
        self::assertTrue((new AmountCellReader())->read(null, 2)->empty);
        self::assertTrue((new AmountCellReader())->read(Cell::text('   '), 2)->empty);
    }

    #[DataProvider('unsupportedCells')]
    public function testAStringErrorBooleanOrSharedFormulaChildIsNotAnAmount(Cell $cell): void
    {
        self::assertSame(ReasonCode::FORMULA_NOT_SUPPORTED, (new AmountCellReader())->read($cell, 2)->rejection);
    }

    /**
     * @return iterable<string, array{Cell}>
     */
    public static function unsupportedCells(): iterable
    {
        yield 'text with a comma decimal' => [Cell::text('12,5')];
        yield 'error value' => [Cell::error('#REF!')];
        yield 'boolean' => [Cell::boolean(true)];
        yield 'shared formula child' => [Cell::sharedFormulaChild('5')];
        yield 'garbage number' => [Cell::number('abc')];
        yield 'a trailing decimal point' => [Cell::number('5.')];
    }

    public function testABalanceKeepsAZeroAndReadsTheCachedValueOfAFormula(): void
    {
        $reader = new AmountCellReader();

        $zero = $reader->readBalance(Cell::number('0'), 2);
        self::assertSame('0.00', $zero->value?->toString());

        $cached = $reader->readBalance(Cell::number('1520.3000000000002', 'K5+L5'), 2);
        self::assertSame('1520.30', $cached->value?->toString());
        self::assertTrue($cached->floatArtifact);

        self::assertTrue($reader->readBalance(null, 2)->empty);
        self::assertNull($reader->readBalance(null, 2)->value);
        self::assertSame(ReasonCode::FORMULA_NOT_SUPPORTED, $reader->readBalance(Cell::error('#REF!'), 2)->rejection);
        self::assertSame(ReasonCode::PRECISION_EXCEEDED, $reader->readBalance(Cell::number('1.234'), 2)->rejection);
    }
}
