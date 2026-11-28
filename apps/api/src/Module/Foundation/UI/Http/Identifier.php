<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

use App\Module\Foundation\Domain\Uuid;

/** The shape check applied to every identifier taken from a URL or a JSON body. */
final class Identifier
{
    public static function isValid(string $value): bool
    {
        return Uuid::isValid($value);
    }
}
