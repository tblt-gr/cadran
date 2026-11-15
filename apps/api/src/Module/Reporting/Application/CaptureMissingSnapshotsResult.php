<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class CaptureMissingSnapshotsResult
{
    public function __construct(
        public int $captured,
        public int $skipped,
    ) {
    }
}
