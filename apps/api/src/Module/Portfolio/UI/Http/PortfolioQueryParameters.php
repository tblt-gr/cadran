<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use Symfony\Component\HttpFoundation\Request;

/** The path and query values the portfolio reads share, refused with the exceptions their tables map. */
final class PortfolioQueryParameters
{
    /** A malformed identifier answers like an unknown account. */
    public static function accountId(string $literal): string
    {
        if (!Identifier::isValid($literal)) {
            throw new PortfolioAccountNotFound();
        }

        return $literal;
    }

    /** A single query value, `null` when absent; a repeated one is refused with the given problem code. */
    public static function string(Request $request, string $key, string $code): ?string
    {
        $value = $request->query->all()[$key] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new InvalidPortfolioOperation('A query parameter must be a single value.', $code);
        }

        return $value;
    }
}
