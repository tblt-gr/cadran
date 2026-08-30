<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

interface WorkspaceRepository
{
    public function save(Workspace $workspace): void;
}
