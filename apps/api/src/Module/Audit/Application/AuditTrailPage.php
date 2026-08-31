<?php

declare(strict_types=1);

namespace App\Module\Audit\Application;

final readonly class AuditTrailPage
{
    /**
     * @param list<AuditTrailEntry> $entries
     * @param string|null           $nextCursor Opaque cursor for the following
     *                                          page, or null on the last one
     */
    public function __construct(
        public array $entries,
        public ?string $nextCursor,
    ) {
    }
}
