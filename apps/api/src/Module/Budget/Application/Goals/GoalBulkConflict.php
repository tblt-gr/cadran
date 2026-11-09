<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

final class GoalBulkConflict extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly string $goalId, public readonly string $field)
    {
        parent::__construct('Refresh the selected goals before trying again.');
    }
}
