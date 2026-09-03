<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parsing half of the exactness invariant: a financial decimal reaches the
 * domain as a canonical string or not at all. Nothing here computes; a value
 * that would need rounding to be stored is refused, never rounded in silence.
 */
final class DecimalValueTest extends TestCase
{
    #[DataProvider('canonicalLiterals')]
    public function testACanonicalLiteralKeepsEveryDigitAndItsScale(string $literal, int $scale): void
    {
        $value = DecimalValue::fromString($literal);

        self::assertSame($literal, $value->toString());
        self::assertSame($scale, $value->scale());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function canonicalLiterals(): iterable
    {
        yield 'zero' => ['0', 0];
        yield 'integer' => ['1000000', 0];
        yield 'the reference amount' => ['230.5688', 4];
        yield 'negative' => ['-1.5', 1];
        yield 'below one' => ['0.125', 3];
        // Trailing zeros are kept: they state the scale the source used, and
        // dropping them would silently change a declared precision.
        yield 'trailing zeros' => ['12.50', 2];
        yield 'crypto quantity' => ['0.000000000000000001', 18];
        yield 'the deepest storable scale' => ['0.'.str_repeat('0', 23).'1', 24];
        yield 'the widest storable integer part' => [str_repeat('9', 26), 0];
    }

    #[DataProvider('malformedLiterals')]
    public function testANonCanonicalLiteralIsRefused(string $literal): void
    {
        $this->expectException(MalformedDecimal::class);

        DecimalValue::fromString($literal);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedLiterals(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => [' '];
        yield 'leading space' => [' 1'];
        yield 'trailing space' => ['1 '];
        yield 'thousands separator' => ['1 000.00'];
        yield 'french decimal comma' => ['1,5'];
        yield 'explicit plus sign' => ['+1.5'];
        yield 'negative zero' => ['-0'];
        yield 'negative zero with a scale' => ['-0.00'];
        yield 'lowercase exponent' => ['1e3'];
        yield 'uppercase exponent' => ['1E3'];
        yield 'leading zero' => ['01.5'];
        yield 'missing integer digit' => ['.5'];
        yield 'trailing decimal point' => ['1.'];
        yield 'two decimal points' => ['1.2.3'];
        yield 'lone minus' => ['-'];
        yield 'letters' => ['abc'];
        yield 'hexadecimal' => ['0x1f'];
        yield 'infinity' => ['INF'];
        yield 'not a number' => ['NaN'];
        yield 'eastern arabic digits' => ['١٢٣'];
        yield 'null byte' => ["1.5\0"];
        // PCRE lets `$` match before a final newline unless the pattern says
        // otherwise, so this literal is the one a naive anchor lets through.
        yield 'trailing newline' => ["1.5\n"];
        yield 'leading newline' => ["\n1.5"];
    }

    public function testAScaleDeeperThanTheStorageTypeIsRefusedRatherThanRounded(): void
    {
        $twentyFiveDecimals = '0.'.str_repeat('0', 24).'1';

        $this->expectException(PrecisionExceeded::class);

        DecimalValue::fromString($twentyFiveDecimals);
    }

    public function testAnIntegerPartWiderThanTheStorageTypeIsRefused(): void
    {
        $this->expectException(PrecisionExceeded::class);

        DecimalValue::fromString(str_repeat('9', 27));
    }

    public function testAValueIsRefusedAboveTheScaleItsAssetAccepts(): void
    {
        $value = DecimalValue::fromString('1.123456789');

        $this->expectException(PrecisionExceeded::class);

        $value->assertScaleAtMost(8);
    }

    public function testAValueAtTheAcceptedScalePasses(): void
    {
        $value = DecimalValue::fromString('1.12345678');

        $value->assertScaleAtMost(8);

        self::assertSame(8, $value->scale());
    }

    public function testTwoLiteralsOfTheSameValueAndScaleAreEqual(): void
    {
        self::assertTrue(DecimalValue::fromString('12.50')->equals(DecimalValue::fromString('12.50')));
        // Same numeric value, different declared scale: not the same recorded
        // figure, so equality stays a string comparison of canonical forms.
        self::assertFalse(DecimalValue::fromString('12.50')->equals(DecimalValue::fromString('12.5')));
    }

    #[DataProvider('orderedPairs')]
    public function testOneFigureIsOrderedAgainstAnotherExactly(string $left, string $right, int $expected): void
    {
        $smaller = DecimalValue::fromString($left);
        $larger = DecimalValue::fromString($right);

        self::assertSame($expected, $smaller->compareTo($larger));
        self::assertSame(-$expected, $larger->compareTo($smaller));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function orderedPairs(): iterable
    {
        yield 'zero against itself' => ['0', '0', 0];
        yield 'same figure, same scale' => ['22950.00', '22950.00', 0];
        // A trailing zero states a scale, not a different quantity: ordering
        // answers about the value where equality answers about the literal.
        yield 'same value, different scale' => ['12.5', '12.50', 0];
        yield 'more integer digits wins' => ['999', '1000', -1];
        yield 'same width, digits decide' => ['1899', '1900', -1];
        // The comparison a naive lexicographic read gets wrong: 0.5 is above
        // 0.49, and padding the shorter fraction is what makes it say so.
        yield 'fraction padded on the right' => ['0.49', '0.5', -1];
        yield 'deep fractions' => ['0.000000000000000001', '0.000000000000000002', -1];
        yield 'negative below zero' => ['-0.01', '0', -1];
        yield 'two negatives read backwards' => ['-1000', '-999', -1];
        yield 'negative below positive of the same digits' => ['-1.5', '1.5', -1];
    }

    public function testTheSignOfAFigureIsReadable(): void
    {
        self::assertTrue(DecimalValue::fromString('-0.0000000001')->isNegative());
        self::assertFalse(DecimalValue::fromString('0')->isNegative());
        self::assertFalse(DecimalValue::fromString('0.0')->isNegative());
    }
}
