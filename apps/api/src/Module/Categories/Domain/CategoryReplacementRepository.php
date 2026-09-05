<?php

declare(strict_types=1);

namespace App\Module\Categories\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface CategoryReplacementRepository
{
    /**
     * The database trigger refuses a longer chain. The application walks to the same
     * ceiling so an over-long chain is reported as a named blocker rather than as the
     * statement failure the trigger would raise.
     */
    public const int MAX_CHAIN_LENGTH = 16;

    public function findBySource(WorkspaceScope $workspace, string $sourceCategoryId): ?CategoryReplacement;

    /**
     * The redirections pointing *into* $targetCategoryId. Retiring a category without
     * looking at these is how a redirection ends up naming a category nobody can select.
     *
     * @return list<CategoryReplacement>
     */
    public function findByTarget(WorkspaceScope $workspace, string $targetCategoryId, bool $lock = false): array;

    /**
     * @param list<string> $sourceCategoryIds
     *
     * @return array<string, CategoryReplacement> keyed by source category identifier
     */
    public function findBySources(WorkspaceScope $workspace, array $sourceCategoryIds): array;

    /**
     * Walks the redirection chain from $sourceCategoryId so a use case can refuse
     * a redirection that would close a loop before the database trigger does.
     *
     * A chain of {@see MAX_CHAIN_LENGTH} entries that has not terminated is reported as
     * too long rather than truncated silently.
     *
     * @return list<string> every category the source already redirects into, in order
     */
    public function chainFrom(WorkspaceScope $workspace, string $sourceCategoryId): array;

    public function add(CategoryReplacement $replacement): void;

    /** Rewrites where an existing redirection points, keeping its identity and its kind. */
    public function repoint(CategoryReplacement $replacement): void;
}
