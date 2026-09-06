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

    /**
     * Replaces an existing hash with a new one, but only while the stored hash
     * is still the one the caller verified.
     *
     * @return bool true when this call replaced the hash, false when another
     *              change landed first (compare-and-set on the expected hash)
     */
    public function replaceHash(string $userId, string $expectedHash, string $newPasswordHash): bool;
}
