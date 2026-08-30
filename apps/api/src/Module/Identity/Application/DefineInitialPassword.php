<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Domain\PlainPassword;

/**
 * First-run flow: set the provisioned owner's password exactly once. The write
 * is a compare-and-set on password_hash IS NULL, so a replayed or concurrent
 * request cannot overwrite an existing password.
 */
final readonly class DefineInitialPassword
{
    public function __construct(
        private AuthenticationUserRepository $users,
        private OwnerPasswordWriter $passwords,
        private PasswordHasher $hasher,
    ) {
    }

    public function __invoke(DefineInitialPasswordInput $input): void
    {
        $owner = $this->users->findProvisionedOwner();
        if (null === $owner) {
            throw new OwnerAccountNotProvisioned();
        }

        if ($owner->hasPassword) {
            throw new InitialPasswordAlreadyDefined();
        }

        // Validate before hashing so a rejected policy check does no work.
        $password = PlainPassword::fromString($input->plainPassword);
        $hash = $this->hasher->hash($password);

        if (!$this->passwords->storeInitialHash($owner->id, $hash)) {
            throw new InitialPasswordAlreadyDefined();
        }
    }
}
