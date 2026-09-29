<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\EmergencyFund;

final class EmergencyFundConflict extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
