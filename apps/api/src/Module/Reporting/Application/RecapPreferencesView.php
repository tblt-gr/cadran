<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Reporting\Domain\RecapPreferences;

final readonly class RecapPreferencesView
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

    public static function of(RecapPreferences $preferences): self
    {
        return new self(
            $preferences->visibility->categoryIds,
            $preferences->visibility->axes,
            $preferences->version,
        );
    }
}
