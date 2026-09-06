<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * The account as its own owner sees it on the settings screen. It carries no
 * credential and no workspace figure, so it is safe to serialize.
 */
final readonly class OwnerProfileView
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
    ) {
    }
}
