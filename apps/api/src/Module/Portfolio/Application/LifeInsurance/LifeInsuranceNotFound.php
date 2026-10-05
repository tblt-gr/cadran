<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

/** An unknown or foreign contract, fee terms or rate: one refusal, so a caller cannot probe other workspaces. */
final class LifeInsuranceNotFound extends \RuntimeException
{
    public function __construct(public readonly string $problemCode)
    {
        parent::__construct('Life-insurance record not found.');
    }
}
