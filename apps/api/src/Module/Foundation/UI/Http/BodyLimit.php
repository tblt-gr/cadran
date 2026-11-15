<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

/**
 * The only place where JSON request size ceilings are declared, in bytes.
 * A body over the ceiling is refused, never truncated.
 */
enum BodyLimit: int
{
    case Tiny = 1_024;
    case Small = 4_096;
    case Medium = 8_192;
    case Standard = 16_384;
    case Large = 32_768;
    case XLarge = 65_536;
    case Bulk = 131_072;
    case Batch = 500_000;
}
