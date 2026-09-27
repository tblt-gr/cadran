<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualAllocationView
{
    /** @param list<AnnualAllocationItemView> $items */
    public function __construct(
        public ?string $asOf,
        public ?string $assetCode,
        public array $items,
        public ?string $reason,
    ) {
    }
}
