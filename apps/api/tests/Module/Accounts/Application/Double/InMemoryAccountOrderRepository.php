<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Accounts\Domain\AccountOrdering;
use App\Module\Accounts\Domain\AccountOrderRepository;
use App\Module\Foundation\Domain\WorkspaceScope;

final class InMemoryAccountOrderRepository implements AccountOrderRepository
{
    /** @var array<string, int> */
    private array $versions = [];
    /** @var array<string, array<string, int>> */
    private array $positions = [];

    public function lockVersion(WorkspaceScope $workspace, ?string $groupId): int
    {
        return $this->version($workspace, $groupId);
    }

    public function bump(WorkspaceScope $workspace, ?string $groupId): void
    {
        $slot = self::slot($workspace, $groupId);
        $this->versions[$slot] = ($this->versions[$slot] ?? 0) + 1;
    }

    public function version(WorkspaceScope $workspace, ?string $groupId): int
    {
        return $this->versions[self::slot($workspace, $groupId)] ?? 0;
    }

    public function versions(WorkspaceScope $workspace): array
    {
        $versions = [];
        foreach ($this->versions as $slot => $version) {
            if (str_starts_with($slot, $workspace->id.'|') && $version > 0) {
                $versions[substr($slot, strlen($workspace->id) + 1)] = $version;
            }
        }

        return $versions;
    }

    public function save(WorkspaceScope $workspace, ?string $groupId, array $orderedAccountIds, int $newVersion): void
    {
        $slot = self::slot($workspace, $groupId);
        $this->positions[$slot] = array_flip($orderedAccountIds);
        $this->versions[$slot] = $newVersion;
    }

    /** @return array<string, int> */
    public function positions(WorkspaceScope $workspace, ?string $groupId): array
    {
        return $this->positions[self::slot($workspace, $groupId)] ?? [];
    }

    private static function slot(WorkspaceScope $workspace, ?string $groupId): string
    {
        return $workspace->id.'|'.AccountOrdering::key($groupId);
    }
}
