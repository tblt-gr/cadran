<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Identity\Domain\InterfacePreferences;

final readonly class InterfacePreferencesView
{
    public function __construct(
        public bool $includeUpcoming,
        public int $version,
    ) {
    }

    public static function of(InterfacePreferences $preferences): self
    {
        return new self($preferences->includeUpcoming, $preferences->version);
    }
}
