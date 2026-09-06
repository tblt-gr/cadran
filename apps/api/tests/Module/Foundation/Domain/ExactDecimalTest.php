<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use PHPUnit\Framework\TestCase;

/**
 * Multiplication is what a rate scale needs: a slice times a percentage,
 * never a binary float in between.
 */
final class ExactDecimalTest extends TestCase
{
    public function testMultiplyKeepsTheProductExact(): void
    {
        $product = ExactDecimal::multiply(
            DecimalValue::fromString('22950'),
            DecimalValue::fromString('1.7'),
        );

        self::assertSame(0, $product->compareTo(DecimalValue::fromString('39015')));
    }
}
