<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroupColors;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Effective colors need the whole tree, not the page being shown: a shade
 * depends on the parent, which may sit on another page or be archived.
 */
final readonly class ReadGroupColors
{
    public function __construct(private AccountGroupRepository $groups)
    {
    }

    public function __invoke(WorkspaceScope $workspace): AccountGroupColors
    {
        return AccountGroupColors::of($this->groups->list($workspace, true, ReadNetWorth::MAX_GROUPS + 1, 0));
    }
}
