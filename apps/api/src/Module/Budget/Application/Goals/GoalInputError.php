<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final class GoalInputError extends \DomainException
{
    /** @param array<string, string> $details exact figures the client shows next to the error */
    public function __construct(public readonly string $errorCode, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
