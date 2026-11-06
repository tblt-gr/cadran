<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

/** The body of a takeover or of its preview, checked for JSON shape only. */
final readonly class TakeoverInput
{
    public const int MAX_LINES = 200;

    /** @param list<TakeoverLineInput> $lines */
    public function __construct(public string $takenOverOn, public array $lines, public ?string $cash)
    {
    }
}
