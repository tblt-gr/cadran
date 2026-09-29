<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\EmergencyFund;

final class InvalidEmergencyFundPolicy extends \DomainException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
