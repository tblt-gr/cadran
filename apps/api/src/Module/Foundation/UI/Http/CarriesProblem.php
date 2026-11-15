<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

/** An exception that names its own problem code, so a table only needs to give the status. */
interface CarriesProblem
{
    public function problemCode(): string;

    /** @return array<string, mixed> */
    public function problemExtensions(): array;
}
