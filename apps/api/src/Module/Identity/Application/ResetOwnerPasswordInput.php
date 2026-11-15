<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * Console reset request. The password is a credential: never logged,
 * serialized or echoed back.
 */
final readonly class ResetOwnerPasswordInput
{
    public function __construct(public string $newPassword)
    {
    }
}
