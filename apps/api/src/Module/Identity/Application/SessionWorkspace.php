<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

final readonly class SessionWorkspace
{
    public function __construct(
        public string $id,
        public string $role,
        public string $timeZone,
    ) {
    }
}
