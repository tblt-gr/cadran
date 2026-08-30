<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

interface UserRepository
{
    public function save(User $user): void;
}
