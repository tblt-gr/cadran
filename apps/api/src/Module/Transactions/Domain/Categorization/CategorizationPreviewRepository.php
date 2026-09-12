<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

use App\Module\Foundation\Domain\WorkspaceScope;

interface CategorizationPreviewRepository
{
    public function add(CategorizationPreview $preview): void;

    /** The newest unconsumed, unexpired preview carrying this token, locked so a replay waits and then finds it consumed. */
    public function findLiveForUpdate(WorkspaceScope $workspace, string $token, \DateTimeImmutable $now): ?CategorizationPreview;

    public function markConsumed(WorkspaceScope $workspace, string $id, \DateTimeImmutable $consumedAt): void;

    public function purgeExpired(WorkspaceScope $workspace, \DateTimeImmutable $now): void;
}
