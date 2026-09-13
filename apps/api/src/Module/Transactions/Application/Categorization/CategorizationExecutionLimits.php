<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

final class CategorizationExecutionLimits
{
    public const int MAX_ACTIVE_RULES = 200;
    public const int MAX_RULE_EVALUATIONS = 1_000_000;
    public const int MAX_RUN_NANOSECONDS = 2_000_000_000;
    public const int MAX_CONFLICTS = 20;

    private function __construct()
    {
    }
}
