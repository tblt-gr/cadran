<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

final readonly class DefineInitialPasswordInput
{
    public function __construct(
        public string $plainPassword,
    ) {
    }
}
