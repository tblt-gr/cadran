<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

use App\Module\Foundation\Domain\WorkspaceScope;

interface CategorizationRuleRepository
{
    public function find(WorkspaceScope $workspace, string $id): ?CategorizationRule;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?CategorizationRule;

    /** @return list<CategorizationRule> in resolution order: priority, creation time, identifier */
    public function list(WorkspaceScope $workspace, bool $includeArchived, int $limit, int $offset): array;

    public function count(WorkspaceScope $workspace, bool $includeArchived): int;

    /** @return list<CategorizationRule> active, non-archived rules, in resolution order and bounded by the caller */
    public function activeInOrder(WorkspaceScope $workspace, int $limit): array;

    /** @return list<CategorizationRule> the active rules targeting a category, locked */
    public function activeTargetingForUpdate(WorkspaceScope $workspace, string $categoryId): array;

    public function add(CategorizationRule $rule): void;

    /** Returns false when the expected version is stale. */
    public function update(CategorizationRule $rule, int $expectedVersion): bool;

    /** A counter, not an edit: it leaves the rule version untouched so it never races a person editing the rule. */
    public function incrementAppliedCount(WorkspaceScope $workspace, string $id, int $by): void;
}
