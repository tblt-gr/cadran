<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Mapping;

/**
 * Where a labelled line of an income, fixed or variable block goes: a category
 * path from the root (`Parent/Child`) and optionally an account label (the main
 * account when absent). A null category is a label not mapped yet.
 */
final readonly class LineMapping
{
    public function __construct(
        public ?string $category,
        public ?string $account,
        public ?string $transferFrom = null,
    ) {
    }
}
