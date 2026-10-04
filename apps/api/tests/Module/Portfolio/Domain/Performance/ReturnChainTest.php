<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Portfolio\Domain\Performance\ReturnChain;
use App\Module\Portfolio\Domain\Performance\ReturnChainTooLong;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReturnChainTest extends TestCase
{
    #[Test]
    public function itChainsTheReferenceReturnsGeometrically(): void
    {
        // 1.01 x 0.98 x 1.015 - 1 = 0.004647 (exact: 0.0046470).
        $chain = ReturnChain::chain([$this->decimal('0.01'), $this->decimal('-0.02'), $this->decimal('0.015')]);

        self::assertSame(0, $chain->compareTo($this->decimal('0.004647')));
    }

    #[Test]
    public function anEmptyChainIsAKnownZeroNotAMissingValue(): void
    {
        self::assertTrue(ReturnChain::chain([])->isZero());
    }

    #[Test]
    public function itAccepts600MonthsAndRefuses601(): void
    {
        $months = array_fill(0, 600, $this->decimal('0'));
        self::assertTrue(ReturnChain::chain($months)->isZero());

        $this->expectException(ReturnChainTooLong::class);
        ReturnChain::chain([...$months, $this->decimal('0')]);
    }

    #[Test]
    public function chainingTheDisplayedMonthlyValuesStaysWithinNMicroUnitsOfTheChain(): void
    {
        mt_srand(20260701);
        $kept = 0;
        for ($run = 0; $run < 300; ++$run) {
            $exact = [];
            $displayed = [];
            for ($month = 0; $month < 12; ++$month) {
                // Uniform over [-0.05, 0.08] with 18 decimals.
                $micro = mt_rand(0, 130_000) - 50_000;
                $value = $this->decimal(sprintf('%d.%012d', 0, mt_rand(0, 999_999_999_999)))
                    ->proportion($this->decimal('1'), $this->decimal('1000000'), 24, RoundingMode::HALF_EVEN)
                    ->add($this->decimal(sprintf('%s0.%06d', $micro < 0 ? '-' : '', abs($micro))));
                $exact[] = $value;
                $displayed[] = $value->round(6, RoundingMode::HALF_UP);
            }
            $yearly = ReturnChain::chain($exact);
            if ($yearly->compareTo($this->decimal('-0.5')) < 0 || $yearly->compareTo($this->decimal('1')) > 0) {
                continue;
            }
            ++$kept;

            $gap = ReturnChain::chain($displayed)->subtract($yearly->round(6, RoundingMode::HALF_UP));
            $absolute = $gap->compareTo(CalculatedDecimal::zero()) < 0 ? $gap->negate() : $gap;
            self::assertLessThanOrEqual(0, $absolute->compareTo($this->decimal('0.000012')), 'CHAINED_DISPLAY_TOLERANCE exceeded for run '.$run);
        }
        self::assertGreaterThan(100, $kept, 'the property must be exercised, not skipped');
    }

    private function decimal(string $literal): CalculatedDecimal
    {
        return CalculatedDecimal::fromDecimal(DecimalValue::fromString($literal));
    }
}
