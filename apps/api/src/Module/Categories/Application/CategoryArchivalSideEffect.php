<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

interface CategoryArchivalSideEffect
{
    public function apply(WorkspaceScope $workspace, string $categoryId, ?string $actorId, \DateTimeImmutable $at): void;
}
