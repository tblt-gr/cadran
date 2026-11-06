<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

/** One problem of a takeover request: the 1-based line it concerns (null for the request), the field and a stable code. */
final readonly class TakeoverError
{
    public function __construct(public ?int $line, public string $field, public string $code)
    {
    }
}
