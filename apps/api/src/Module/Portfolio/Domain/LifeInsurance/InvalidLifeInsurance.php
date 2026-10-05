<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

/** A life-insurance rule refusal; the message never carries the submitted value. */
final class InvalidLifeInsurance extends \DomainException
{
    public function __construct(string $message, public readonly string $problemCode)
    {
        parent::__construct($message);
    }
}
