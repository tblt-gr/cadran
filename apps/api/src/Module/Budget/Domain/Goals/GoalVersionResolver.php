<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

use App\Module\Accounts\Domain\CalendarMonth;

final class GoalVersionResolver
{
    /** @param list<GoalVersion> $versions */
    public static function forMonth(array $versions, CalendarMonth $month): ?GoalVersion
    {
        $selected = null;
        foreach ($versions as $version) {
            if ($version->effectiveFrom > $month->key() || (null !== $version->year && $version->year !== $month->year)) {
                continue;
            }
            if (null === $selected
                || $version->effectiveFrom > $selected->effectiveFrom
                || ($version->effectiveFrom === $selected->effectiveFrom && $version->versionNo > $selected->versionNo)
            ) {
                $selected = $version;
            }
        }

        return $selected;
    }
}
