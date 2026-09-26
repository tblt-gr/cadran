<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualColumnView
{
    public function __construct(
        public string $id,
        public string $label,
        public string $kind,
        public ?string $assetCode,
    ) {
    }
}
