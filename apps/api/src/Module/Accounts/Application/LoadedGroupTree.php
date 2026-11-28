<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\GroupLineage;

/** Every group of one workspace, archived included, with the lineage of each. */
final readonly class LoadedGroupTree
{
    /**
     * @param list<AccountGroup> $groups   in repository order
     * @param list<GroupLineage> $lineages
     */
    public function __construct(public array $groups, public array $lineages)
    {
    }
}
