<?php

declare(strict_types=1);

namespace App\Module\Import\Application;

/** A category of the workspace: `type` is `INCOME` or `EXPENSE`, and the block axis only applies when it has no default axis. */
final readonly class ImportCategory
{
    public function __construct(
        public string $id,
        public string $type,
        public bool $hasDefaultAxes,
    ) {
    }
}
