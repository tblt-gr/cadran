<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/** A booked refund with the day of the original it reduces: a replay must re-check both months. */
final readonly class CreatedRefund
{
    public function __construct(public TransactionView $refund, public string $originalBookedOn)
    {
    }
}
