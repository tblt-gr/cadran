<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final class IdempotencyConflict extends \RuntimeException
{
    public const string KEY_REUSED = 'key_reused';
    public const string IN_FLIGHT = 'in_flight';

    public function __construct(public readonly string $ruleCode)
    {
        parent::__construct($ruleCode);
    }
}
