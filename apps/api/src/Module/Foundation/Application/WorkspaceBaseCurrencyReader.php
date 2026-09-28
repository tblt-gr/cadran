<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;

interface WorkspaceBaseCurrencyReader
{
    public function currency(WorkspaceScope $workspace): AssetCode;
}
