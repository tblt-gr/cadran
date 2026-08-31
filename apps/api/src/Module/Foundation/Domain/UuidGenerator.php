<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

interface UuidGenerator
{
    public function generate(): string;
}
