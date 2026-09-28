<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final class GoalInputError extends \DomainException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
