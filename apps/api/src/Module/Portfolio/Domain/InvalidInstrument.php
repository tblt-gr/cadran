<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

final class InvalidInstrument extends \DomainException
{
    public function __construct(string $message, public readonly string $problemCode)
    {
        parent::__construct($message);
    }
}
