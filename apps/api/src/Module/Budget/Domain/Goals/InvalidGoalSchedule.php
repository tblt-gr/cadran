<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

/**
 * Unlike {@see InvalidGoal}, a refused schedule carries its public error code:
 * the client must tell a sum mismatch from a precision or sign problem, and a
 * mismatch also carries the exact figures the modal shows.
 */
final class InvalidGoalSchedule extends \DomainException
{
    /** @param array<string, string> $details */
    public function __construct(public readonly string $errorCode, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
