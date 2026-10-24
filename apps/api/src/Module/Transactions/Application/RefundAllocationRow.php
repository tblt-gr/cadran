<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Categories\Domain\AnalyticAxis;
use Brick\Math\BigInteger;

/** @internal Mutable only while largest remainders receive their remaining units. */
final class RefundAllocationRow
{
    /** @param list<AnalyticAxis> $analyticAxes */
    public function __construct(
        public int $index,
        public string $categoryId,
        public BigInteger $units,
        public BigInteger $remainder,
        public array $analyticAxes,
    ) {
    }
}
