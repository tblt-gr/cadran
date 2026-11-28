<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

/**
 * The one structural check for an identifier: canonical lower case, RFC 4122
 * variant. Every layer applies it, so none accepts what another refuses.
 */
final class Uuid
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public static function isValid(string $value): bool
    {
        return 1 === preg_match(self::PATTERN, $value);
    }
}
