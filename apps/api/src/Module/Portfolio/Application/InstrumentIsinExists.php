<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final class InstrumentIsinExists extends \RuntimeException
{
    public function __construct(public readonly ?string $existingId, ?\Throwable $previous = null)
    {
        parent::__construct('An instrument already uses this ISIN.', previous: $previous);
    }
}
