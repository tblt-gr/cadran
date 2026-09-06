<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

final readonly class UpdateOwnerProfileInput
{
    public function __construct(public string $displayName)
    {
    }
}
