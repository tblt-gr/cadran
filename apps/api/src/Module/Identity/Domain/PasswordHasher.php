<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

/**
 * Turns a candidate password into an opaque verifier string. The concrete
 * algorithm (Argon2id and its cost parameters) is an infrastructure concern.
 */
interface PasswordHasher
{
    public function hash(PlainPassword $password): string;
}
