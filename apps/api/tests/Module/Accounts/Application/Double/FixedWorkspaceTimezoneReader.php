<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class FixedWorkspaceTimezoneReader implements WorkspaceTimezoneReader
{
    public function __construct(private string $timezone = 'UTC')
    {
    }

    public function timezone(WorkspaceScope $workspace): string
    {
        return $this->timezone;
    }
}
