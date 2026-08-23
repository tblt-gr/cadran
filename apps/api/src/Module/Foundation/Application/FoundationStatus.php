<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

final readonly class FoundationStatus
{
    public function __construct(
        public string $status,
        public string $apiVersion,
    ) {
    }
}
