<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

/** The shape check applied to every identifier taken from a URL or a JSON body. */
final class Identifier
{
    public static function isValid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value);
    }
}
