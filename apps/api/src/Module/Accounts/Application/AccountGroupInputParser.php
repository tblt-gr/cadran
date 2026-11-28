<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Foundation\Domain\Uuid;

final class AccountGroupInputParser
{
    public static function optionalIdentifier(?string $value): ?string
    {
        if (null !== $value && !Uuid::isValid($value)) {
            throw new InvalidAccountGroupInput('The group parent identifier is malformed.');
        }

        return $value;
    }
}
