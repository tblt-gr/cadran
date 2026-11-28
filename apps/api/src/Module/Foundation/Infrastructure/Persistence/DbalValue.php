<?php

declare(strict_types=1);

namespace App\Module\Foundation\Infrastructure\Persistence;

/**
 * Narrows the `mixed` DBAL yields for a column. A value of the wrong shape
 * raises: a corrupt row is never read as a default.
 */
final class DbalValue
{
    public static function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }

    public static function nullableText(mixed $value): ?string
    {
        return null === $value ? null : self::text($value);
    }

    public static function boolean(mixed $value): bool
    {
        return match ($value) {
            true, 1, '1', 't', 'true' => true,
            false, 0, '0', 'f', 'false' => false,
            default => throw new \UnexpectedValueException('Expected a boolean database value.'),
        };
    }

    public static function integer(mixed $value): int
    {
        $text = self::text($value);
        if (1 !== preg_match('/^-?[0-9]+$/D', $text)) {
            throw new \UnexpectedValueException('Expected an integer database value.');
        }

        return (int) $text;
    }

    /**
     * A `DATE` column carries no time and no zone; it is read as the UTC
     * calendar day it is, rather than as midnight in the server timezone.
     */
    public static function businessDay(mixed $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', self::text($value), new \DateTimeZone('UTC'));
        if (false === $date) {
            throw new \UnexpectedValueException('Expected an ISO calendar day database value.');
        }

        return $date;
    }

    public static function instant(mixed $value): \DateTimeImmutable
    {
        $text = self::text($value);
        // An empty string would silently read as the current instant.
        if ('' === $text) {
            throw new \UnexpectedValueException('Expected a database instant.');
        }

        return new \DateTimeImmutable($text);
    }

    public static function nullableInstant(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : self::instant($value);
    }
}
