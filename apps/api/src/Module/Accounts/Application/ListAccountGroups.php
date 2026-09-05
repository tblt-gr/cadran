<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Foundation\Application\CallerWorkspace;

final readonly class ListAccountGroups
{
    public const int DEFAULT_PAGE_SIZE = 50;
    public const int MAX_PAGE_SIZE = 100;
    public const int MAX_PAGE = 1000;

    public function __construct(
        private CallerWorkspace $caller,
        private AccountGroupRepository $groups,
    ) {
    }

    public function __invoke(bool $includeArchived, ?int $page, ?int $perPage, bool $parentEligible = false): AccountGroupPage
    {
        $requestedPage = $page ?? 1;
        $pageSize = $perPage ?? self::DEFAULT_PAGE_SIZE;
        if ($requestedPage < 1 || $requestedPage > self::MAX_PAGE || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidAccountGroupInput('The group page is outside its bounds.');
        }

        $workspace = $this->caller->resolve();
        $groups = $this->groups->list($workspace, $includeArchived && !$parentEligible, $pageSize, ($requestedPage - 1) * $pageSize);
        if ($parentEligible) {
            $groups = array_values(array_filter(
                $groups,
                static fn ($group): bool => null === $group->archivedAt && $group->depth < \App\Module\Accounts\Domain\AccountGroup::MAX_TREE_DEPTH,
            ));
        }

        $parentIds = array_flip($this->groups->parentIdsWithChildren($workspace, array_column($groups, 'id')));
        $parentLabels = $this->groups->labelsByIds(
            $workspace,
            array_values(array_unique(array_filter(array_column($groups, 'parentId')))),
        );

        return new AccountGroupPage(
            items: array_map(
                static fn ($group): AccountGroupView => AccountGroupView::fromGroup(
                    $group,
                    isset($parentIds[$group->id]),
                    null === $group->parentId ? null : ($parentLabels[$group->parentId] ?? null),
                ),
                $groups,
            ),
            page: $requestedPage,
            perPage: $pageSize,
            total: $this->groups->count($workspace, $includeArchived && !$parentEligible),
        );
    }
}
