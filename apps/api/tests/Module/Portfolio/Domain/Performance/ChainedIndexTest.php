<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Performance;

use App\Module\Portfolio\Domain\Performance\ChainedIndex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChainedIndexTest extends TestCase
{
    #[Test]
    public function itChainsTheReferenceReturnsFromBaseOneHundred(): void
    {
        // 100 x 1.01 = 101.00 ; x 0.98 = 98.98 ; x 1.015 = 100.4647 -> 100.46.
        self::assertSame(['101.00', '98.98', '100.46'], ChainedIndex::series(['0.01', '-0.02', '0.015'], 0));
    }

    #[Test]
    public function itStopsAtTheFirstMonthWithoutReturnAndNeverResumes(): void
    {
        self::assertSame(['101.00', null, null], ChainedIndex::series(['0.01', null, '0.02'], 0));
    }

    #[Test]
    public function monthsBeforeTheChainStartHaveNoIndex(): void
    {
        self::assertSame([null, null, '101.00', '103.02'], ChainedIndex::series([null, null, '0.01', '0.02'], 2));
    }

    #[Test]
    public function itRoundsHalfUpToTwoDecimalsOnlyAtTheEnd(): void
    {
        // 100 x 1.000050 = 100.0050 -> 100.01 ; the next month starts from the unrounded 100.0050.
        self::assertSame(['100.01', '100.01'], ChainedIndex::series(['0.00005', '0'], 0));
    }

    #[Test]
    public function itKeepsOneHundredWhenTheUnroundedRateIsBelowFiveHundredthsOfAPercent(): void
    {
        // R = 0.50 / 10100 = 0.000049504950495049504950. Display 0.000050 would yield 100.01.
        self::assertSame(['100.00'], ChainedIndex::series(['0.000049504950495049504950'], 0));
        self::assertSame(['100.01'], ChainedIndex::series(['0.000050'], 0));
    }

    #[Test]
    public function aZeroReturnKeepsTheIndexAndALargeLossStaysExact(): void
    {
        self::assertSame(['100.00', null, null], ChainedIndex::series(['0', '-1', '0.02'], 0));
        self::assertSame(['101.00', null], ChainedIndex::series(['0.01', '-1.5'], 0));
        self::assertSame(['123456789012345778.90'], ChainedIndex::series(['1234567890123456.789'], 0));
    }
}
