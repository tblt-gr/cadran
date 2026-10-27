<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final class AccountGroupInputParser
{
    public static function optionalIdentifier(?string $value): ?string
    {
        if (null !== $value && 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
            throw new InvalidAccountGroupInput('The group parent identifier is malformed.');
        }

        return $value;
    }
}
