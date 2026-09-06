<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

/**
 * Turns a candidate password into an opaque verifier string, and checks a
 * candidate against one. The concrete algorithm (Argon2id and its cost
 * parameters) is an infrastructure concern.
 */
interface PasswordHasher
{
    public function hash(PlainPassword $password): string;

    /**
     * Constant-time comparison of a candidate against a stored verifier.
     *
     * The candidate is a raw string, not a {@see PlainPassword}: a password
     * already in use was accepted under whatever policy held when it was set,
     * so re-running today's policy over it would refuse the very verification
     * that proves the caller owns the account.
     */
    public function verify(string $passwordHash, string $candidate): bool;
}
