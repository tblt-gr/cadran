<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The display preferences of one workspace, with the version a concurrent
 * editor has to match.
 *
 * Version 0 means "nothing stored yet": the workspace reads the defaults and
 * its first save inserts the row.
 */
final readonly class InterfacePreferences
{
    public const int UNSAVED_VERSION = 0;

    public function __construct(
        public WorkspaceScope $workspace,
        public bool $includeUpcoming,
        public int $version,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
        if ($version < self::UNSAVED_VERSION) {
            throw new \InvalidArgumentException('An interface preference version is never negative.');
        }
    }

    public static function unsaved(WorkspaceScope $workspace): self
    {
        return new self($workspace, true, self::UNSAVED_VERSION);
    }

    public function saved(bool $includeUpcoming, \DateTimeImmutable $at): self
    {
        return new self($this->workspace, $includeUpcoming, $this->version + 1, $at);
    }
}
