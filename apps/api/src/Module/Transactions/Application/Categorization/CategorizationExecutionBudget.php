<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

final readonly class CategorizationExecutionBudget
{
    public function __construct(
        public int $maxActiveRules = CategorizationExecutionLimits::MAX_ACTIVE_RULES,
        public int $maxRuleEvaluations = CategorizationExecutionLimits::MAX_RULE_EVALUATIONS,
        public int $maxRunNanoseconds = CategorizationExecutionLimits::MAX_RUN_NANOSECONDS,
    ) {
    }
}
