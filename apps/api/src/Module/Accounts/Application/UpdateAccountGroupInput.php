<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroupColorMode;

final readonly class UpdateAccountGroupInput
{
    public function __construct(
        public string $label,
        public ?string $parentId,
        public int $version,
        public ?string $color = null,
        public bool $colorProvided = true,
        public ?AccountGroupColorMode $colorMode = null,
        /** Deprecated: the order is changed by moving the group among its siblings. */
        public ?int $sortOrder = null,
    ) {
    }
}
