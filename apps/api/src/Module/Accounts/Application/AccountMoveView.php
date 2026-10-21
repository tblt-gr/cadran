<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class AccountMoveView
{
    public function __construct(
        public ?string $groupId,
        public int $orderVersion,
        public int $rank,
    ) {
    }
}
