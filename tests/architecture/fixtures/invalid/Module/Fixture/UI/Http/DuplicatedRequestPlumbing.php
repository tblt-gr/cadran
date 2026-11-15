<?php

declare(strict_types=1);

namespace App\Module\Fixture\UI\Http;

final class DuplicatedRequestPlumbing
{
    private const int MAX_BODY_BYTES = 16_384;

    private function body(): array
    {
        return [];
    }
}
