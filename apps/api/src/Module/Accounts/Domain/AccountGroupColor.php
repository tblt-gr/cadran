<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/** Stable presentation defaults derived from the immutable UUID, never list position. */
final class AccountGroupColor
{
    public const array PALETTE = ['#486DF0', '#803078', '#58B8C0', '#F0A8D8'];

    public static function normalize(?string $color): ?string
    {
        if (!self::isValid($color)) {
            throw new InvalidAccountGroup('A group color must be null or a six-digit hexadecimal color.');
        }

        return null === $color ? null : strtoupper($color);
    }

    public static function isValid(mixed $color): bool
    {
        return null === $color || (is_string($color) && 1 === preg_match('/^#[0-9a-fA-F]{6}$/D', $color));
    }

    public static function defaultFor(string $id): string
    {
        // Two hash digits keep the integer bounded on every supported platform.
        return self::PALETTE[intval(substr(hash('sha256', $id), 0, 2), 16) % count(self::PALETTE)];
    }

    public static function resolve(AccountGroup $group): string
    {
        return $group->color ?? self::defaultFor($group->id);
    }
}
