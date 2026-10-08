<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Portfolio\Domain\LifeInsurance\ContractAllocation;
use PHPUnit\Framework\TestCase;

final class ContractAllocationTest extends TestCase
{
    public function testReferenceSharesAndVeryLargeExactDecimals(): void
    {
        $items = ContractAllocation::of('9293.00', '0', '4124.20', '5168.80');
        self::assertSame(['0.443796', '0.556204', '0.000000'], array_column($items, 'share'));
        $items = ContractAllocation::of('10000000000000000000000000.000000000000000000000001', '0.000000000000000000000001', '4000000000000000000000000', '6000000000000000000000000');
        self::assertSame(['0.400000', '0.600000', '0.000000'], array_column($items, 'share'));
        self::assertSame('0.000001', ContractAllocation::share('0.0000005', '1'));
    }

    public function testNonPositiveTotalsHaveNoShares(): void
    {
        self::assertSame([null, null, null], array_column(ContractAllocation::of('0', '0', '0', '0'), 'share'));
        self::assertSame([null, null, null], array_column(ContractAllocation::of('-1', '-1', '0', '0'), 'share'));
    }

    public function testAnInconsistentObservationIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        ContractAllocation::of('9293.01', '0', '4124.20', '5168.80');
    }
}
