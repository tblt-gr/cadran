<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final class InvalidInstrumentInput extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly string $problemCode, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
