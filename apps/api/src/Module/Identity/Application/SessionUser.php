<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

final readonly class SessionUser
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
    ) {
    }
}
