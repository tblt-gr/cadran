<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

final readonly class SaveInterfacePreferencesInput
{
    public function __construct(
        public bool $includeUpcoming,
        public int $version,
    ) {
    }
}
