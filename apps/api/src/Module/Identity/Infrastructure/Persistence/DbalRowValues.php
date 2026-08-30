<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Persistence;

/**
 * Narrows the mixed scalars a DBAL associative row yields into the string and
 * nullable-string shapes the read models expect.
 */
trait DbalRowValues
{
    private static function asString(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }

    private static function asNullableString(mixed $value): ?string
    {
        return null === $value ? null : self::asString($value);
    }
}
