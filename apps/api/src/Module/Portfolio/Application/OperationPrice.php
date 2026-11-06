<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\DecimalValue;

/** The unit price a purchase paid for an instrument, on its trade date: the only price a refresh may take from an operation. */
final readonly class OperationPrice
{
    public function __construct(public string $instrumentId, public DecimalValue $unitPrice, public \DateTimeImmutable $tradeDate)
    {
    }
}
