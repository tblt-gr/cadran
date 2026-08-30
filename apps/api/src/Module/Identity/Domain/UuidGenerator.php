<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

interface UuidGenerator
{
    public function generate(): string;
}
