<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class RateBracketInput
{
    public function __construct(
        public string $lowerBound,
        public ?string $upperBound,
        public string $percentage,
    ) {
    }
}
