<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\GroupLineage;

/** The order version a roll-up section reads: the sum over its whole subtree. */
final class SubtreeOrderVersion
{
    /**
     * @param list<GroupLineage> $lineages
     * @param array<string, int> $versions order versions keyed by group
     */
    public static function of(string $groupId, array $lineages, array $versions): int
    {
        $sum = 0;
        foreach ($lineages as $lineage) {
            if (in_array($groupId, $lineage->ancestorIdsIncludingSelf, true)) {
                $sum += $versions[$lineage->groupId] ?? 0;
            }
        }

        return $sum;
    }
}
