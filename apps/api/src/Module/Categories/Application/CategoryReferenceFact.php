<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

/** Publishes only the category facts a scope reference from another module needs. */
final readonly class CategoryReferenceFact
{
    /** @param list<string> $ancestorIds nearest ancestor first */
    public function __construct(
        public string $id,
        public bool $archived,
        public array $ancestorIds,
    ) {
    }
}
