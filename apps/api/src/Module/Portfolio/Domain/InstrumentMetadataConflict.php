<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

final readonly class InstrumentMetadataConflict
{
    public function __construct(
        public string $field,
        public string $current,
        public string $proposed,
    ) {
    }
}
