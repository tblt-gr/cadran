<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class AccountReferenceFact
{
    public function __construct(public string $id, public string $label, public bool $archived)
    {
    }
}
