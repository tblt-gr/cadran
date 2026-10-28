<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class AccountGroupMoveView
{
    /** @param list<string> $siblingIds the new active order under the parent */
    public function __construct(
        public ?string $parentId,
        public array $siblingIds,
        public int $rank,
    ) {
    }
}
