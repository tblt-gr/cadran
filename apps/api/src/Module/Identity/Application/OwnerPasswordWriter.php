<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

interface OwnerPasswordWriter
{
    /**
     * Stores the initial password hash for a user, but only while none is set.
     *
     * @return bool true when this call set the hash, false when a concurrent
     *              call had already set one (compare-and-set on password_hash IS NULL)
     */
    public function storeInitialHash(string $userId, string $passwordHash): bool;
}
