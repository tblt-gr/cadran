<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Identity\Domain\CommonPasswordList;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Domain\PlainPassword;

/**
 * The rules a new owner password must satisfy, shared by the profile form and
 * the console reset so the two paths cannot drift.
 */
final readonly class OwnerPasswordPolicy
{
    public function __construct(
        private CommonPasswordList $commonPasswords,
        private PasswordHasher $hasher,
    ) {
    }

    /**
     * Length and common-password rules. Free to evaluate, so it runs before any
     * credential work.
     *
     * @throws \App\Module\Identity\Domain\WeakPassword
     */
    public function choose(string $candidate): PlainPassword
    {
        return PlainPassword::chosen($candidate, $this->commonPasswords);
    }

    /**
     * For a caller that holds only the stored hash: costs one Argon2id verification.
     *
     * @throws NewPasswordReused
     */
    public function assertDiffersFromHash(string $currentHash, string $candidate): void
    {
        if ($this->hasher->verify($currentHash, $candidate)) {
            throw new NewPasswordReused();
        }
    }
}
