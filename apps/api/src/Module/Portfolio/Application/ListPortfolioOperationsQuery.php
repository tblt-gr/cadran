<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

/** The raw query string values of the list; the use case checks every bound. */
final readonly class ListPortfolioOperationsQuery
{
    /** @param list<string> $types */
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public array $types = [],
        public ?string $instrumentId = null,
        public ?string $includeVoided = null,
        public ?string $page = null,
        public ?string $pageSize = null,
    ) {
    }
}
