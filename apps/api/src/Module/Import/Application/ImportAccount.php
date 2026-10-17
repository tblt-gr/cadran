<?php

declare(strict_types=1);

namespace App\Module\Import\Application;

/** An account of the workspace as the planner needs it. `valuationMode` is `TRANSACTIONS`, `SNAPSHOTS` or `PORTFOLIO`. */
final readonly class ImportAccount
{
    public function __construct(
        public string $id,
        public string $valuationMode,
        public string $assetCode,
        public string $openedOn,
    ) {
    }

    public function isPortfolio(): bool
    {
        return 'PORTFOLIO' === $this->valuationMode;
    }
}
