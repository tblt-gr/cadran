<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Domain;

use App\Module\Import\Domain\Formula\ConstantFormula;
use App\Module\Import\Domain\Formula\FormulaRejected;
use App\Module\Import\Domain\ReasonCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hand-computed references: `12.5 + 48.2 + 7.9` is three purchases of 12.50,
 * 48.20 and 7.90 (sum 68.60); `(10 - (2 * 3)) + 5` is two movements, 4.00 and
 * 5.00, because only the top-level `+` splits.
 */
final class ConstantFormulaTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function values(string $formula, int $precision = 2): array
    {
        return array_map(
            static fn ($term): string => $term->value->toString(),
            ConstantFormula::parse($formula, $precision),
        );
    }

    public function testEachTopLevelTermIsOneMovement(): void
    {
        self::assertSame(['12.50', '48.20', '7.90'], self::values('=12.5+48.2+7.9'));
    }

    public function testTheLeadingEqualsSignIsOptionalBecauseSpreadsheetFilesStoreTheTextWithoutIt(): void
    {
        self::assertSame(['12.50', '48.20'], self::values('12.5+48.2'));
    }

    public function testSpacesAreAllowed(): void
    {
        self::assertSame(['5.00', '6.00'], self::values(' = 5 + 6 '));
    }

    public function testAProductIsOneMovementAndKeepsItsFactors(): void
    {
        $terms = ConstantFormula::parse('=3*95.12', 2);

        self::assertCount(1, $terms);
        self::assertSame('285.36', $terms[0]->value->toString());
        self::assertTrue($terms[0]->isProduct);
        self::assertSame(['3', '95.12'], array_map(static fn ($factor): string => $factor->toString(), $terms[0]->factors));
    }

    public function testAGroupedTermIsOneMovementEvenWhenItHoldsAnOperator(): void
    {
        $terms = ConstantFormula::parse('=(10-(2*3))+5', 2);

        self::assertSame(['4.00', '5.00'], array_map(static fn ($term): string => $term->value->toString(), $terms));
        self::assertFalse($terms[0]->isProduct);
    }

    public function testASubtractedTopLevelTermIsNegative(): void
    {
        self::assertSame(['12.50', '48.20', '-7.90'], self::values('=12.5+48.2-7.9'));
    }

    public function testAPlainNegativeNumberIsOneNegativeMovement(): void
    {
        self::assertSame(['-5.00'], self::values('=-5'));
    }

    public function testAFactorMayBeNegativeAfterAMultiplication(): void
    {
        self::assertSame(['-6.00'], self::values('=2*-3'));
    }

    public function testADivisionIsAcceptedWhenExact(): void
    {
        self::assertSame(['2.50'], self::values('=10/4'));
    }

    public function testTrailingZerosBeyondThePrecisionAreNotAnExcess(): void
    {
        self::assertSame(['12.50'], self::values('=12.500'));
        self::assertSame(['1.00'], self::values('=0.001*1000'));
    }

    public function testAZeroTermIsKeptForTheCallerToExclude(): void
    {
        self::assertSame(['0.00'], self::values('=0'));
        self::assertSame(['0.00', '3.00'], self::values('=0+3'));
    }

    public function testTheLargestStorableIntegerPartFits(): void
    {
        self::assertSame(['99999999999999999999999999.00'], self::values('=99999999999999999999999999'));
    }

    public function testAnotherAssetPrecisionIsHonoured(): void
    {
        self::assertSame(['0.12345678'], self::values('=0.12345678', 8));
        self::assertSame(['1000'], self::values('=1000', 0));
    }

    #[DataProvider('rejectedFormulas')]
    public function testAFormulaOutsideTheGrammarIsRejectedWithItsReason(string $formula, ReasonCode $reason): void
    {
        try {
            ConstantFormula::parse($formula, 2);
            self::fail('The formula must be rejected.');
        } catch (FormulaRejected $rejected) {
            self::assertSame($reason, $rejected->reason);
            // The rejection must never quote the formula: it can carry a financial amount.
            self::assertStringNotContainsString('12', $rejected->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, ReasonCode}>
     */
    public static function rejectedFormulas(): iterable
    {
        yield 'cell reference' => ['=B3', ReasonCode::FORMULA_REFERENCE];
        yield 'absolute cell reference' => ['=$B$3+1', ReasonCode::FORMULA_REFERENCE];
        yield 'sheet reference' => ['=Janvier!L2', ReasonCode::FORMULA_REFERENCE];
        yield 'quoted sheet reference' => ["='Recap Annuel'!C4*2", ReasonCode::FORMULA_REFERENCE];
        yield 'function' => ['=SUM(B2:B5)', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'function call that looks like code' => ["=system('ls')", ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'string literal' => ['="12"', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'comma decimal' => ['=12,5', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'exponent' => ['=1e3', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'no leading digit' => ['=.5', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'no trailing digit' => ['=5.', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'unary plus is outside the grammar' => ['=+5', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'double sign' => ['=--5', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'empty' => ['=', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'dangling operator' => ['=5+', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'unclosed group' => ['=(5', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'unopened group' => ['=5)', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'two numbers' => ['=5 5', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'newline' => ["=5+\n6", ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'inexact division' => ['=10/3', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'division by zero' => ['=1/0', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'too deeply nested' => ['='.str_repeat('(', 200).'1'.str_repeat(')', 200), ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'too long' => ['='.str_repeat('1+', 2000).'1', ReasonCode::FORMULA_NOT_SUPPORTED];
        yield 'more decimals than the asset' => ['=12.345', ReasonCode::PRECISION_EXCEEDED];
        yield 'a term beyond the precision inside a sum' => ['=1+0.005', ReasonCode::PRECISION_EXCEEDED];
        yield 'integer part beyond the storage width' => ['=999999999999999999999999999', ReasonCode::PRECISION_EXCEEDED];
        yield 'a product beyond the storage scale' => ['=0.00000001*0.00000001*0.00000001*0.00000001', ReasonCode::PRECISION_EXCEEDED];
    }
}
