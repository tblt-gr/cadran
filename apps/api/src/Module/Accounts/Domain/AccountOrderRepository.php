<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The saved order of accounts inside one primary group (null for the
 * ungrouped section) and the version that protects it from lost updates.
 *
 * An account the order never mentioned, such as one created or regrouped
 * since the last move, sorts after every placed account, oldest first.
 */
interface AccountOrderRepository
{
    /** Takes the row lock of the group order, creating it at version 0 when absent. */
    public function lockVersion(WorkspaceScope $workspace, ?string $groupId): int;

    /**
     * Advances the version of a group whose membership changed, so a cursor
     * read before the change is refused instead of skipping a shifted row.
     */
    public function bump(WorkspaceScope $workspace, ?string $groupId): void;

    public function version(WorkspaceScope $workspace, ?string $groupId): int;

    /** @return array<string, int> versions keyed by {@see AccountOrdering::key()}, absent keys are 0 */
    public function versions(WorkspaceScope $workspace): array;

    /**
     * Rewrites the dense sequence of the whole group and stores the new version.
     *
     * @param list<string> $orderedAccountIds
     */
    public function save(WorkspaceScope $workspace, ?string $groupId, array $orderedAccountIds, int $newVersion): void;
}
