<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Application\ReadAccountReference;
use App\Module\Budget\Domain\Goals\GoalScopeType;
use App\Module\Categories\Application\ReadCategoryReference;
use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class ResolveGoalScope
{
    public function __construct(private ReadCategoryReference $categories, private ReadAccountReference $accounts)
    {
    }

    public function __invoke(WorkspaceScope $workspace, GoalScopeType $type, ?string $ref, bool $allowArchived): GoalScopeView
    {
        if (GoalScopeType::WORKSPACE === $type) {
            return new GoalScopeView($type->value, null, null, 'Workspace', false);
        }
        if (null === $ref) {
            throw new GoalInputError('unknown_goal_reference', 'A scoped goal requires a reference.');
        }
        if (GoalScopeType::AXIS === $type) {
            $axis = AnalyticAxis::tryFrom($ref);
            if (null === $axis) {
                throw new GoalInputError('unknown_goal_reference', 'The goal axis is unknown.');
            }

            return new GoalScopeView($type->value, null, $axis->value, ucfirst(strtolower($axis->value)), false);
        }
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $ref)) {
            throw new GoalInputError('unknown_goal_reference', 'The goal reference is unknown.');
        }
        if (GoalScopeType::CATEGORY === $type) {
            $fact = ($this->categories)($workspace, $ref);
            if (null === $fact || 'EXPENSE' !== $fact->type || (!$allowArchived && $fact->archived)) {
                throw new GoalInputError('unknown_goal_reference', 'The goal category is unavailable.');
            }

            return new GoalScopeView($type->value, $fact->id, null, $fact->label, $fact->archived);
        }
        $fact = ($this->accounts)($workspace, $ref);
        if (null === $fact || (!$allowArchived && $fact->archived)) {
            throw new GoalInputError('unknown_goal_reference', 'The goal account is unavailable.');
        }

        return new GoalScopeView($type->value, $fact->id, null, $fact->label, $fact->archived);
    }
}
