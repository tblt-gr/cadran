<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

interface MembershipRepository
{
    public function save(Membership $membership): void;
}
