<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

/** The shape of a stored line of text: no surrounding whitespace and a bounded length. */
final class BoundedText
{
    public static function isValid(string $value, int $minLength, int $maxLength): bool
    {
        $length = mb_strlen($value);

        return $value === trim($value) && $length >= $minLength && $length <= $maxLength;
    }

    /** Control and format characters, the latter including the zero-width ones. */
    public static function hasControlCharacters(string $value): bool
    {
        return 1 === preg_match('/[\p{Cc}\p{Cf}]/u', $value);
    }
}
