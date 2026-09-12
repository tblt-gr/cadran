<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use Brick\Math\BigInteger;

/** @internal Mutable only while largest remainders receive their remaining units. */
final class RefundAllocationRow
{
    public function __construct(
        public int $index,
        public string $categoryId,
        public BigInteger $units,
        public BigInteger $remainder,
    ) {
    }
}
