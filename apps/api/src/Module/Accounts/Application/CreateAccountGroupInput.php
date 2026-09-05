<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class CreateAccountGroupInput
{
    public function __construct(
        public string $label,
        public ?string $parentId,
        public int $sortOrder,
    ) {
    }
}
