<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class SaveRecapPreferencesInput
{
    /**
     * @param list<string> $visibleCategoryIds
     * @param list<string> $visibleAxes
     */
    public function __construct(
        public array $visibleCategoryIds,
        public array $visibleAxes,
        public int $version,
    ) {
    }
}
