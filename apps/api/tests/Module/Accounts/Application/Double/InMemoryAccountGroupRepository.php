<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

final class InMemoryAccountGroupRepository implements AccountGroupRepository
{
    /** @var list<AccountGroup> */
    private array $groups;

    public function __construct(AccountGroup ...$groups)
    {
        $this->groups = array_values($groups);
    }

    public function find(WorkspaceScope $workspace, string $id): ?AccountGroup
    {
        foreach ($this->groups as $group) {
            if ($group->id === $id && $group->workspace->equals($workspace)) {
                return $group;
            }
        }

        return null;
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?AccountGroup
    {
        return $this->find($workspace, $id);
    }

    public function list(WorkspaceScope $workspace, bool $includeArchived, int $limit, int $offset): array
    {
        return array_slice($this->matching($workspace, $includeArchived), $offset, $limit);
    }

    public function count(WorkspaceScope $workspace, bool $includeArchived): int
    {
        return count($this->matching($workspace, $includeArchived));
    }

    public function hasActiveSiblingLabel(
        WorkspaceScope $workspace,
        ?string $parentId,
        string $label,
        ?string $excludingId = null,
    ): bool {
        $normalized = mb_strtolower(trim($label));

        foreach ($this->groups as $group) {
            if (!$group->workspace->equals($workspace) || null !== $group->archivedAt) {
                continue;
            }
            if ($group->parentId !== $parentId || $group->id === $excludingId) {
                continue;
            }
            if (mb_strtolower($group->label) === $normalized) {
                return true;
            }
        }

        return false;
    }

    public function hasChildren(WorkspaceScope $workspace, string $id): bool
    {
        foreach ($this->groups as $group) {
            if ($group->workspace->equals($workspace) && $group->parentId === $id && null === $group->archivedAt) {
                return true;
            }
        }

        return false;
    }

    public function descendantsForUpdate(WorkspaceScope $workspace, string $ancestorId): array
    {
        $found = [];
        $frontier = [$ancestorId];
        while ([] !== $frontier) {
            $next = [];
            foreach ($this->groups as $group) {
                if (!$group->workspace->equals($workspace) || !in_array($group->parentId, $frontier, true)) {
                    continue;
                }

                $found[] = $group;
                $next[] = $group->id;
            }

            $frontier = $next;
        }

        return $found;
    }

    public function isReferencedByAccount(WorkspaceScope $workspace, string $id): bool
    {
        return false;
    }

    public function parentIdsWithChildren(WorkspaceScope $workspace, array $ids): array
    {
        $parents = [];
        foreach ($this->groups as $group) {
            if ($group->workspace->equals($workspace) && null !== $group->parentId && null === $group->archivedAt && in_array($group->parentId, $ids, true)) {
                $parents[$group->parentId] = $group->parentId;
            }
        }

        return array_values($parents);
    }

    public function labelsByIds(WorkspaceScope $workspace, array $ids): array
    {
        $labels = [];
        foreach ($this->groups as $group) {
            if ($group->workspace->equals($workspace) && in_array($group->id, $ids, true)) {
                $labels[$group->id] = $group->label;
            }
        }

        return $labels;
    }

    public function add(AccountGroup $group): void
    {
        $this->groups[] = $group;
    }

    public function update(AccountGroup $group, int $expectedVersion): bool
    {
        foreach ($this->groups as $position => $stored) {
            if ($stored->id !== $group->id || !$stored->workspace->equals($group->workspace)) {
                continue;
            }
            if ($stored->version !== $expectedVersion) {
                return false;
            }

            $this->groups[$position] = $group;

            return true;
        }

        return false;
    }

    /** @return list<AccountGroup> */
    private function matching(WorkspaceScope $workspace, bool $includeArchived): array
    {
        return array_values(array_filter(
            $this->groups,
            static fn (AccountGroup $group): bool => $group->workspace->equals($workspace)
                && ($includeArchived || null === $group->archivedAt),
        ));
    }
}
