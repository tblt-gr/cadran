<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

interface OwnerProfileWriter
{
    /**
     * @return bool true when the row was updated, false when the account no
     *              longer exists
     */
    public function updateDisplayName(string $userId, string $displayName): bool;
}
