<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\PageRequest;

final readonly class ListAccountGroups
{
    public const int DEFAULT_PAGE_SIZE = 50;

    public function __construct(
        private CallerWorkspace $caller,
        private AccountGroupRepository $groups,
        private ReadGroupColors $readGroupColors,
    ) {
    }

    public function __invoke(bool $includeArchived, ?int $page, ?int $pageSize, bool $parentEligible = false): AccountGroupPage
    {
        $request = PageRequest::tryFrom($page, $pageSize, self::DEFAULT_PAGE_SIZE)
            ?? throw new InvalidAccountGroupInput('The group page is outside its bounds.');

        $workspace = $this->caller->resolve();
        $groups = $this->groups->list($workspace, $includeArchived, $request->pageSize, $request->offset(), $parentEligible);

        $parentIds = array_flip($this->groups->parentIdsWithChildren($workspace, array_column($groups, 'id')));
        $parentLabels = $this->groups->labelsByIds(
            $workspace,
            array_values(array_unique(array_filter(array_column($groups, 'parentId')))),
        );

        $colors = ($this->readGroupColors)($workspace);

        return new AccountGroupPage(
            items: array_map(
                fn ($group): AccountGroupView => AccountGroupView::fromGroup(
                    $group,
                    isset($parentIds[$group->id]),
                    null === $group->parentId ? null : ($parentLabels[$group->parentId] ?? null),
                    $colors,
                ),
                $groups,
            ),
            page: $request->page,
            pageSize: $request->pageSize,
            total: $this->groups->count($workspace, $includeArchived, $parentEligible),
        );
    }
}
