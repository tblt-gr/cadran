<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

/** The shape checks of a raw query string value, where an absent parameter reads as the empty string. */
final class QueryParameters
{
    public static function isUnsignedIntegerOrEmpty(string $value, int $maxDigits = 4): bool
    {
        return '' === $value || (strlen($value) <= $maxDigits && 1 === preg_match('/^[0-9]+$/D', $value));
    }

    /** Only for a value already accepted by {@see isUnsignedIntegerOrEmpty()}. */
    public static function integerOrNull(string $value): ?int
    {
        return '' === $value ? null : (int) $value;
    }

    public static function isFlagOrEmpty(string $value): bool
    {
        return in_array($value, ['', 'true', 'false'], true);
    }
}
