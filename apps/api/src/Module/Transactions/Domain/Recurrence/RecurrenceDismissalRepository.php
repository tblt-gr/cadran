<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The candidates one workspace asked not to be offered again. A dismissal
 * stores only a fingerprint: it says which proposal to hide, never what the
 * movements behind it were.
 */
interface RecurrenceDismissalRepository
{
    /** @return list<string> the dismissed fingerprints, in order */
    public function fingerprints(WorkspaceScope $workspace): array;

    public function dismiss(WorkspaceScope $workspace, string $id, string $fingerprint, \DateTimeImmutable $dismissedAt): void;

    public function findId(WorkspaceScope $workspace, string $fingerprint): ?string;

    /** Returns false when nothing was dismissed under that fingerprint. */
    public function restore(WorkspaceScope $workspace, string $fingerprint): bool;
}
