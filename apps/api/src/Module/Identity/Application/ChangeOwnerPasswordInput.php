<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * Raw change-password request. Both passwords are credentials: they are never
 * logged, serialized or echoed back, and this object exists only long enough
 * for the use case to verify one and hash the other.
 */
final readonly class ChangeOwnerPasswordInput
{
    /**
     * @param string|null $sessionIdToKeep the caller's own session, spared by the
     *                                     revocation. Not user input: the HTTP
     *                                     adapter reads it from the request it is
     *                                     already authenticated on. Null revokes
     *                                     every session, including the caller's.
     */
    public function __construct(
        public string $currentPassword,
        public string $newPassword,
        public ?string $sessionIdToKeep = null,
    ) {
    }
}
