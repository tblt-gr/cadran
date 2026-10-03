<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final readonly class TransferCandidateView
{
    /** @param array{value: string, assetCode: string} $amount unsigned: the direction is the one asked for */
    public function __construct(public string $transactionId, public string $bookedOn, public array $amount, public ?string $label)
    {
    }
}
