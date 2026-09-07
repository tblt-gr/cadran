<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\MalformedDecimal;
use PHPUnit\Framework\TestCase;

/**
 * Multiplication is what a rate scale needs: a slice times a percentage,
 * never a binary float in between.
 */
final class ExactDecimalTest extends TestCase
{
    public function testReferenceCasesStayExactAtTheirWidestSubmittedScale(): void
    {
        $cases = self::referenceCases();

        foreach ($cases['add'] as $case) {
            self::assertSame($case['result'], ExactDecimal::add(
                DecimalValue::fromString($case['a']),
                DecimalValue::fromString($case['b']),
            )->toString());
        }

        foreach ($cases['subtract'] as $case) {
            self::assertSame($case['result'], ExactDecimal::subtract(
                DecimalValue::fromString($case['a']),
                DecimalValue::fromString($case['b']),
            )->toString());
        }

        foreach ($cases['sum'] as $case) {
            self::assertSame($case['result'], ExactDecimal::sum(
                ...array_map(DecimalValue::fromString(...), $case['values']),
            )->toString());
        }

        foreach ($cases['compare'] as $case) {
            self::assertSame($case['result'], DecimalValue::fromString($case['a'])->compareTo(
                DecimalValue::fromString($case['b']),
            ));
        }

        foreach ($cases['rejected'] as $literal) {
            try {
                DecimalValue::fromString($literal);
                self::fail('The shared reference fixture must reject every listed literal.');
            } catch (MalformedDecimal) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testNegationAndAbsoluteValueKeepScaleWithoutSigningZero(): void
    {
        self::assertSame('-230.5688', ExactDecimal::negate(DecimalValue::fromString('230.5688'))->toString());
        self::assertSame('230.5688', ExactDecimal::absolute(DecimalValue::fromString('-230.5688'))->toString());
        self::assertSame('0.00', ExactDecimal::negate(DecimalValue::fromString('0.00'))->toString());
        self::assertSame('0.00', ExactDecimal::absolute(DecimalValue::fromString('0.00'))->toString());
    }

    public function testRandomCanonicalOperandsObeyTheExactArithmeticLaws(): void
    {
        for ($attempt = 0; $attempt < 250; ++$attempt) {
            $a = self::randomDecimal();
            $b = self::randomDecimal();
            $c = self::randomDecimal();

            $restored = ExactDecimal::subtract(ExactDecimal::add($a, $b), $b);
            self::assertSame(0, $a->compareTo($restored));
            self::assertTrue($a->equals(ExactDecimal::negate(ExactDecimal::negate($a))));

            $leftToRight = ExactDecimal::add(ExactDecimal::add($a, $b), $c);
            self::assertTrue($leftToRight->equals(ExactDecimal::sum($a, $b, $c)));

            $comparison = $a->compareTo($b);
            self::assertContains($comparison, [-1, 0, 1]);
            self::assertSame(-$comparison, $b->compareTo($a));
            self::assertSame(0, $a->compareTo($a));
        }
    }

    public function testMultiplyKeepsTheProductExact(): void
    {
        $product = ExactDecimal::multiply(
            DecimalValue::fromString('22950'),
            DecimalValue::fromString('1.7'),
        );

        self::assertSame(0, $product->compareTo(DecimalValue::fromString('39015')));
    }

    /**
     * @return array{
     *   add: list<array{a: string, b: string, result: string}>,
     *   subtract: list<array{a: string, b: string, result: string}>,
     *   sum: list<array{values: list<string>, result: string}>,
     *   compare: list<array{a: string, b: string, result: int}>,
     *   rejected: list<string>
     * }
     */
    private static function referenceCases(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 6).'/tests/fixtures/decimal-reference-cases.json');
        self::assertIsString($contents);

        /** @var array{
         *   add: list<array{a: string, b: string, result: string}>,
         *   subtract: list<array{a: string, b: string, result: string}>,
         *   sum: list<array{values: list<string>, result: string}>,
         *   compare: list<array{a: string, b: string, result: int}>,
         *   rejected: list<string>
         * } $cases
         */
        $cases = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $cases;
    }

    private static function randomDecimal(): DecimalValue
    {
        $integer = (string) random_int(0, 999_999);
        $scale = random_int(0, 8);
        $fraction = '';
        for ($index = 0; $index < $scale; ++$index) {
            $fraction .= (string) random_int(0, 9);
        }

        $literal = $integer.('' === $fraction ? '' : '.'.$fraction);
        if ('0' !== rtrim($literal, '.0') && 1 === random_int(0, 1)) {
            $literal = '-'.$literal;
        }

        return DecimalValue::fromString($literal);
    }
}
