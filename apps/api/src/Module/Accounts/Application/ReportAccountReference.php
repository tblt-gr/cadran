<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class ReportAccountReference
{
    public function __construct(
        public string $id,
        public string $label,
        public string $kind,
        public bool $includeInNetWorth,
        public bool $archived,
    ) {
    }
}
