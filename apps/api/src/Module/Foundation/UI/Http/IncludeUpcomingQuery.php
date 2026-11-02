<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The optional `includeUpcoming` query parameter of the monthly reads. Absent means false, so a read
 * without it behaves exactly as before planned transactions existed.
 */
final class IncludeUpcomingQuery
{
    public const string NAME = 'includeUpcoming';

    /** @return bool|null null when the parameter is present but is neither `true` nor `false` */
    public static function of(Request $request): ?bool
    {
        if (!$request->query->has(self::NAME)) {
            return false;
        }

        return match ($request->query->get(self::NAME)) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }
}
