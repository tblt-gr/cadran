<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualAllocationItemView
{
    public function __construct(
        public string $groupId,
        public string $label,
        public ?string $value,
        public ?string $share,
        public ?string $reason,
    ) {
    }
}
