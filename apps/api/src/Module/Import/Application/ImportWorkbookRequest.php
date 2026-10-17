<?php

declare(strict_types=1);

namespace App\Module\Import\Application;

final readonly class ImportWorkbookRequest
{
    public function __construct(
        public string $workbookPath,
        public string $mappingPath,
        public ?int $year,
        public bool $dryRun,
        public ?string $email,
    ) {
    }
}
