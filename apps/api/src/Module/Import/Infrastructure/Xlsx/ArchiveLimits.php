<?php

declare(strict_types=1);

namespace App\Module\Import\Infrastructure\Xlsx;

/**
 * The hard limits of the archive controls; tests lower them. Real workbooks carry four entries per chart (chart, style,
 * colors, relationships) and reach about 160 entries, so the entry cap leaves room above that; entries outside the
 * listed parts are counted, never opened.
 */
final readonly class ArchiveLimits
{
    public function __construct(
        public int $maxFileBytes = 20 * 1024 * 1024,
        public int $maxEntries = 512,
        public int $maxPartBytes = 50 * 1024 * 1024,
        public int $maxCompressionRatio = 100,
        public int $maxCellsPerSheet = 200_000,
    ) {
    }
}
