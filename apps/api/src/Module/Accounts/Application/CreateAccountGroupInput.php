<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroupColorMode;

final readonly class CreateAccountGroupInput
{
    public function __construct(
        public string $label,
        public ?string $parentId,
        public int $sortOrder,
        public ?string $color = null,
        public ?AccountGroupColorMode $colorMode = null,
    ) {
    }
}
