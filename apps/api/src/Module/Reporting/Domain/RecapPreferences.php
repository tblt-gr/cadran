<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The stored recap selection of one workspace, with the version a concurrent
 * editor has to match.
 *
 * Version 0 means "nothing stored yet": the workspace reads the default
 * selection and its first save inserts the row.
 */
final readonly class RecapPreferences
{
    public const int UNSAVED_VERSION = 0;

    public function __construct(
        public WorkspaceScope $workspace,
        public RecapVisibility $visibility,
        public int $version,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
        if ($version < self::UNSAVED_VERSION) {
            throw new InvalidRecapVisibility('A recap preference version is never negative.');
        }
    }

    /** @param list<string> $knownAxes */
    public static function unsaved(WorkspaceScope $workspace, array $knownAxes): self
    {
        return new self($workspace, RecapVisibility::default($knownAxes), self::UNSAVED_VERSION);
    }

    public function saved(RecapVisibility $visibility, \DateTimeImmutable $at): self
    {
        return new self($this->workspace, $visibility, $this->version + 1, $at);
    }
}
