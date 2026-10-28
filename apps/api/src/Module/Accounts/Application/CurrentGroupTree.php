<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\GroupLineage;

/**
 * The exclusive group tree as it stands today: what a reader needs to place
 * an account in the groups it rolls into, whatever the date of the figures.
 */
final readonly class CurrentGroupTree
{
    /**
     * @param list<GroupLineage>     $lineages              active groups in depth-first saved order
     * @param array<string, ?string> $primaryGroupByAccount primary group id by account id, null when ungrouped
     */
    public function __construct(public array $lineages, public array $primaryGroupByAccount)
    {
    }
}
