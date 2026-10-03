<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

/** An amount as the client wrote it: a canonical decimal string and its asset. */
final readonly class AmountInput
{
    public function __construct(public string $value, public string $assetCode)
    {
    }
}
