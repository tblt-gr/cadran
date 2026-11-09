<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalVersion;

/** What one evaluation read loads once and memoizes: versions, scopes, category branches and reference incomes. */
final class EvaluationContext
{
    /** @var array<string, list<GoalVersion>> */
    public array $versions = [];

    /** @var array<string, array{?string, ?string, ?string, string}> */
    public array $stocks = [];

    /** @var array<string, GoalScopeView> */
    private array $scopes = [];

    /** @var array<string, list<string>> */
    private array $branches = [];

    /** @var array<string, ReferenceIncomeView> */
    private array $references = [];

    public function __construct(
        public readonly CalendarMonth $today,
        public readonly GoalStatusInputs $inputs,
    ) {
    }

    public function scope(ResolveGoalScope $resolver, Goal $goal): GoalScopeView
    {
        return $this->scopes[$goal->id] ??= $resolver($goal->workspace, $goal->scopeType, $goal->scopeRef, true);
    }

    /** @return list<string> */
    public function categoryIds(ReadGoalStatus $status, Goal $goal): array
    {
        return $this->branches[$goal->id] ??= $status->categoryBranch($goal, $this->inputs);
    }

    public function reference(CalendarMonth $month): ReferenceIncomeView
    {
        return $this->references[$month->key()] ??= ReadReferenceIncome::fromMonths($month, array_values($this->inputs->months));
    }
}
