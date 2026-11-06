<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Transactions\Domain\BulkEditSkipReason;

final readonly class BulkPreview
{
    /**
     * @param array<string, BulkEditSkipReason> $skipped reason per refused transaction identifier
     * @param list<BulkMoveImpact>              $impacts
     */
    public function __construct(
        public int $eligible,
        public array $skipped,
        public array $impacts,
        public string $token,
    ) {
    }
}
